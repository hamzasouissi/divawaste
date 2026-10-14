<?php

namespace App\Modules\Pickups\Support;

use App\Modules\Documents\Actions\ManifestWorkflow;
use App\Modules\Documents\Enums\HazardousWasteManifestStatus as Status;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where the documents of a pickup stand, apart from where the pickup itself stands. Closing a pickup is operational —
 * the waste left, was received and treated (F21) — and does not wait for paper that may lag; the tracking slips each
 * waste needs (M5-02, one per regulatory code), and the signature of every party on each of them, are followed here.
 *
 * - not_due: nothing has left yet; the slips are issued at loading;
 * - incomplete: a lot that left has no slip (a hazardous waste type without its regulatory code, or a slip not issued);
 * - pending: every lot has its slip, and a signature or the scan of a slip signed by hand is still missing;
 * - complete: every slip is signed by every party and archived — and only then.
 */
final class PickupCompliance
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Pickup $pickup): array
    {
        $slips = DB::table('hazardous_waste_manifests')->where('pickup_id', $pickup->id)->where('status', '!=', Status::Cancelled->value)->orderBy('id')
            ->get(['ulid', 'manifest_number', 'status']);
        $withoutCode = ManifestWorkflow::withoutCode($pickup->id);
        $coded = $withoutCode->flatMap(fn (array $type) => $type['lots'])->all();
        $unissued = DB::table('pickup_lots')->where('pickup_id', $pickup->id)->whereNotNull('loaded_at')->whereNull('hazardous_waste_manifest_id')
            ->whereNotIn('lot_number_snapshot', $coded)->orderBy('id')->pluck('lot_number_snapshot')->map(fn ($n) => (string) $n)->all();
        $pending = $slips->where('status', '!=', Status::Completed->value)->map(fn (\stdClass $slip) => [
            'ulid' => $slip->ulid, 'number' => $slip->manifest_number,
            // Whose signature the slip waits for, or 'signed_copy': everyone signed, one of them by hand, and the scan is missing.
            'waiting_for' => match ($slip->status) {
                Status::PendingTransporterSignature->value => 'transporter',
                Status::PendingReceiverSignature->value => 'receiver',
                Status::PendingSignedCopy->value => 'signed_copy',
                default => 'producer',
            },
        ])->values()->all();

        return [
            'state' => self::state($pickup, count($pending), $withoutCode->isNotEmpty() || $unissued !== []),
            'slips' => $slips->count(),
            'slips_completed' => $slips->where('status', Status::Completed->value)->count(),
            'pending_slips' => $pending,
            // Hazardous lots no slip can carry yet: their waste type has no regulatory code. Completed on the type, never guessed.
            'missing_codes' => $withoutCode->all(),
            // Lots that left and are on no slip for another reason (a slip not issued yet): issue the slips again.
            'lots_without_slip' => $unissued,
        ];
    }

    /**
     * The same answer for a list, read from the counts the query adds (see `withState`).
     */
    public static function fromCounts(Pickup $pickup): ?string
    {
        $attributes = $pickup->getAttributes();
        if (! array_key_exists('open_slips', $attributes)) {
            return null;
        }

        return self::state($pickup, (int) $attributes['open_slips'], (int) ($attributes['lots_without_slip'] ?? 0) > 0);
    }

    /**
     * @param  Builder<Pickup>  $query
     * @return Builder<Pickup>
     */
    public static function withState(Builder $query): Builder
    {
        return $query->withCount(['lines as lots_without_slip' => fn ($lines) => $lines->whereNotNull('loaded_at')->whereNull('hazardous_waste_manifest_id')])
            ->selectSub(DB::table('hazardous_waste_manifests as m')->selectRaw('COUNT(*)')->whereColumn('m.pickup_id', 'pickups.id')
                ->whereNotIn('m.status', [Status::Completed->value, Status::Cancelled->value]), 'open_slips');
    }

    /**
     * Pickups whose waste has left and whose documents are not complete.
     *
     * @param  Builder<Pickup>  $query
     * @return Builder<Pickup>
     */
    public static function whereOutstanding(Builder $query): Builder
    {
        return $query->whereNotNull('collected_at')->where(fn ($q) => $q
            ->whereExists(fn ($slips) => $slips->from('hazardous_waste_manifests as m')->whereColumn('m.pickup_id', 'pickups.id')
                ->whereNotIn('m.status', [Status::Completed->value, Status::Cancelled->value]))
            ->orWhereExists(fn ($lines) => $lines->from('pickup_lots as pl')->whereColumn('pl.pickup_id', 'pickups.id')
                ->whereNotNull('pl.loaded_at')->whereNull('pl.hazardous_waste_manifest_id')));
    }

    private static function state(Pickup $pickup, int $pending, bool $missing): string
    {
        return match (true) {
            in_array($pickup->status, [PickupStatus::Refused, PickupStatus::Cancelled], true) => 'not_applicable',
            $pickup->collected_at === null => 'not_due',
            $missing => 'incomplete',
            $pending > 0 => 'pending',
            default => 'complete',
        };
    }
}
