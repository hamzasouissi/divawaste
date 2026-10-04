<?php

namespace App\Modules\Sync\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Actions\EvaluateStockThresholds;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Sync\Enums\SyncOperationStatus as Status;
use App\Modules\Sync\Models\SyncOperation;
use App\Modules\Waste\Actions\CreateWasteLot;
use App\Modules\Waste\Actions\TransitionLot;
use App\Modules\Waste\Enums\LotStatus;
use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Requests\StoreWasteLotRequest;
use App\Modules\Waste\Resources\WasteLotResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * M2-08: replays what a device recorded while offline, without loss and without duplicate.
 *
 * - No duplicate: each operation carries a client-generated id. Its outcome is stored in the same transaction as
 *   its effect, so replaying it (lost answer, retry, double tap) returns the first outcome instead of acting again.
 *   A lot also carries a client-generated ULID: a second creation with it is recognised as the same lot.
 * - No loss: an operation that cannot be applied is stored as rejected or in conflict with the reason, and returned
 *   to the device, which keeps it visible to the operator. Nothing is dropped silently.
 * - Operations are applied in the order the device recorded them; one failing does not block the next ones.
 */
final class ApplyOfflineOperations
{
    public const TYPES = ['lot.create', 'lot.store'];

    public function __construct(
        private readonly CreateWasteLot $createLot,
        private readonly TransitionLot $transition,
        private readonly EvaluateStockThresholds $thresholds,
    ) {}

    /**
     * @param  list<array{id: string, sequence: int, type: string, occurred_at: string, payload: array<string, mixed>}>  $operations
     * @return list<array<string, mixed>>
     */
    public function handle(array $operations, int $deviceId, User $user, ?string $deviceSentAt): array
    {
        // What the device clock is off by, measured when the batch arrives; applied to every recorded time.
        $skewMs = $deviceSentAt === null ? 0 : (int) round(Carbon::parse($deviceSentAt)->diffInMilliseconds(now(), false));
        usort($operations, fn (array $a, array $b) => $a['sequence'] <=> $b['sequence']);
        $results = [];
        $sites = [];

        foreach ($operations as $operation) {
            $id = strtoupper($operation['id']);
            $hash = SyncOperation::hashPayload($operation['payload']);
            $known = SyncOperation::query()->where('client_operation_id', $id)->first();

            if ($known !== null) {
                $results[] = $known->payload_hash === $hash
                    ? $this->result($known, duplicate: true)
                    : ['id' => $id, 'status' => 'rejected', 'error_code' => 'IDEMPOTENCY_KEY_REUSED', 'message' => 'This operation id was already used for different data.'];

                continue;
            }

            $occurredAt = Carbon::parse($operation['occurred_at'])->addMilliseconds($skewMs)->min(now());
            $record = fn (Status $status, array $attributes) => SyncOperation::query()->create($attributes + [
                'device_id' => $deviceId, 'user_id' => $user->id, 'client_operation_id' => $id, 'client_sequence' => $operation['sequence'],
                'operation_type' => $operation['type'], 'payload' => $operation['payload'], 'payload_hash' => $hash,
                'client_occurred_at' => Carbon::parse($operation['occurred_at']), 'adjusted_occurred_at' => $occurredAt,
                'received_at' => now(), 'processed_at' => now(), 'status' => $status,
            ]);

            try {
                $results[] = $this->result(DB::transaction(function () use ($operation, $occurredAt, $deviceId, $user, $record, &$sites) {
                    [$lot, $conflict] = match ($operation['type']) {
                        'lot.create' => $this->createLot($operation['payload'], $occurredAt, $deviceId, $user),
                        'lot.store' => $this->storeLot($operation['payload'], $user),
                    };
                    $sites[$lot->site_id] = true;

                    return $conflict === null
                        ? $record(Status::Applied, ['entity_ulid' => $lot->ulid, 'result' => (new WasteLotResource($lot))->resolve()])
                        : $record(Status::Conflict, ['entity_ulid' => $lot->ulid, 'conflict_type' => $conflict, 'resolution_status' => 'open',
                            'server_state' => (new WasteLotResource($lot))->resolve()]);
                }));
            } catch (ValidationException $e) {
                $results[] = $this->result($record(Status::Rejected, ['error_code' => 'VALIDATION_FAILED', 'result' => ['errors' => $e->errors()]]));
            } catch (AuthorizationException) {
                $results[] = $this->result($record(Status::Rejected, ['error_code' => 'FORBIDDEN']));
            } catch (BusinessRuleViolation $e) {
                $results[] = $this->result($record(Status::Rejected, ['error_code' => $e->errorCode, 'result' => ['message' => $e->getMessage()]]));
            } catch (Throwable $e) {
                // Unexpected failure: nothing is stored, the device keeps the operation and retries it.
                report($e);
                $results[] = ['id' => $id, 'status' => 'retry', 'error_code' => 'SERVER_ERROR'];
            }
        }

        foreach (array_keys($sites) as $siteId) {
            $this->thresholds->forSite((int) $siteId);
        }
        DB::table('devices')->where('id', $deviceId)->update([
            'last_sync_at' => now(), 'last_seen_at' => now(), 'clock_skew_ms' => $skewMs,
            'last_applied_sequence' => DB::raw('GREATEST(last_applied_sequence, '.(int) max(array_column($operations, 'sequence') ?: [0]).')'),
        ]);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{WasteLot, ?string}
     */
    private function createLot(array $payload, Carbon $occurredAt, int $deviceId, User $user): array
    {
        // The same lot arriving twice (first answer lost before the device stored it) is the same lot.
        $existing = isset($payload['ulid']) && is_string($payload['ulid']) ? WasteLot::query()->where('ulid', strtoupper($payload['ulid']))->first() : null;
        if ($existing !== null) {
            return [$existing, null];
        }

        $rules = StoreWasteLotRequest::lotRules(is_string($payload['site'] ?? null) ? $payload['site'] : '');
        $rules['ulid'] = ['required', 'ulid'];
        $validated = Validator::make($payload, $rules)->validate();

        $site = Site::query()->where('ulid', strtoupper($validated['site']))->firstOrFail(['id']);
        if (! $user->can('lots.create', $site->id)) {
            throw new AuthorizationException;
        }

        return [$this->createLot->handle(StoreWasteLotRequest::dataFrom(['generated_at' => $occurredAt->toDateTimeString()] + $validated, $deviceId, 'sync'), $user), null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{WasteLot, ?string}
     */
    private function storeLot(array $payload, User $user): array
    {
        $validated = Validator::make($payload, ['lot' => ['required', 'ulid'], 'zone' => ['nullable', 'ulid']])->validate();
        $lot = WasteLot::query()->where('ulid', strtoupper($validated['lot']))->first();
        if ($lot === null) {
            throw ValidationException::withMessages(['lot' => [__('validation.exists')]]);
        }
        if (! $user->can('lots.move', $lot->site_id)) {
            throw new AuthorizationException;
        }
        // Someone else moved the lot on while the device was offline: the server state wins and the operator is told.
        if (! $lot->status->canTransitionTo(LotStatus::Stored)) {
            return [$lot, $lot->status === LotStatus::Stored ? null : 'lot_state_changed'];
        }

        $zoneId = isset($validated['zone']) ? Zone::query()->where('ulid', strtoupper($validated['zone']))->where('site_id', $lot->site_id)->value('id') : null;

        return [$this->transition->handle($lot, LotStatus::Stored, $user, $zoneId === null ? null : (int) $zoneId), null];
    }

    /**
     * @return array<string, mixed>
     */
    private function result(SyncOperation $operation, bool $duplicate = false): array
    {
        return array_filter([
            'id' => $operation->client_operation_id,
            'status' => $operation->status->value,
            'duplicate' => $duplicate ?: null,
            'entity' => $operation->entity_ulid,
            'error_code' => $operation->error_code,
            'conflict_type' => $operation->conflict_type,
            'result' => $operation->result,
            'server_state' => $operation->server_state,
        ], fn ($value) => $value !== null);
    }
}
