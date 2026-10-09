<?php

namespace App\Modules\Documents\Support;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Documents\Models\WasteAuthorization;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of an authorisation is used, and whether a pickup is covered. Like the stock, usage is never stored: it is
 * counted from the pickup lines that draw on the authorisation, so a cancelled or refused pickup gives its share back
 * by itself, and a lot left on the dock at loading stops counting.
 *
 * The allowed quantity is read the way the document states it — a weight, packages per packaging, a number of lots —
 * and each of these limits is checked on its own, in its own unit. A pickup is covered when none of them is exceeded and
 * none is left unverifiable; the percentages of two different limits are never compared or merged.
 */
final class AuthorizationQuota
{
    /** A pickup holds its share from the moment it is requested until it is cancelled or refused. */
    private const HOLDING = [PickupStatus::Requested, PickupStatus::Confirmed, PickupStatus::Collected, PickupStatus::Received, PickupStatus::Completed];

    public function __construct(private readonly ReferenceCodes $codes, private readonly NotifyUsers $notify) {}

    /**
     * Every limit of the authorisation with what is drawn on it, each answered separately.
     *
     * @param  array{kg: string, lots: int, counts: array<string, int>, unweighed: list<string>, kinds: array<string, list<string>>}|null  $extra  what a pickup being prepared would add
     * @return array{constraints: list<array<string, mixed>>, outcome: string|null, blocked: bool, failing: list<string>, used: array{kg: string, lots: int, counts: array<string, int>}}
     */
    public function usage(WasteAuthorization $authorization, ?int $exceptPickupId = null, ?array $extra = null): array
    {
        $used = $this->consumed($authorization->id, $exceptPickupId);
        if ($extra !== null) {
            $used['kg'] = bcadd($used['kg'], $extra['kg'], 3);
            $used['lots'] += $extra['lots'];
            $used['unweighed'] = array_merge($used['unweighed'], $extra['unweighed']);
            foreach ($extra['counts'] as $kind => $count) {
                $used['counts'][$kind] = ($used['counts'][$kind] ?? 0) + $count;
            }
            foreach ($extra['kinds'] as $kind => $numbers) {
                $used['kinds'][$kind] = array_merge($used['kinds'][$kind] ?? [], $numbers);
            }
        }

        $limits = $this->limits($authorization);
        $hasWeight = $authorization->max_kg !== null && bccomp((string) $authorization->max_kg, '0', 3) > 0;
        $constraints = [];

        if ($hasWeight) {
            // Any packaging, and loose waste, is covered by a weight — as long as the weight is known and not a guess.
            $constraints[] = Constraint::quantity('weight', 'kg', $used['kg'], (string) $authorization->max_kg, $authorization->alert_pct, $used['unweighed'], true);
        }
        if ($limits !== []) {
            // Loose waste and packagings the document does not name cannot be counted in packages. When the document
            // also states a weight or a number of lots, that limit covers them; stated in packages only, nothing does.
            $outside = array_merge([], ...array_values(array_diff_key($used['kinds'], $limits)));
            $elsewhere = $hasWeight || $authorization->max_lots !== null;
            $constraints[] = Constraint::packages('packages', $limits, $used['counts'], $authorization->alert_pct, $elsewhere ? [] : $outside, true)
                + ['outside_count' => $elsewhere ? count($outside) : 0, 'outside_lots' => $elsewhere ? array_slice($outside, 0, 50) : []];
        }
        if ($authorization->max_lots !== null) {
            $constraints[] = Constraint::quantity('lots', 'lot', (string) $used['lots'], (string) $authorization->max_lots, $authorization->alert_pct, [], true, 0);
        }

        return ['constraints' => $constraints] + Constraint::overall($constraints)
            + ['used' => ['kg' => $used['kg'], 'lots' => $used['lots'], 'counts' => $used['counts']]];
    }

    /**
     * Which authorisation covers each waste type of a set of lots on a date, and what it would leave.
     *
     * `reason` is null when the pickup is covered. Otherwise it names the first cause ('missing', 'packaging', 'exceeded',
     * 'unverifiable'), and `constraints` always carries every limit with its own answer, so one failure hides no other.
     *
     * @param  Collection<int, \stdClass>  $lots  each with waste_type_id, packaging_type_id, packages (null for loose waste), kg (null when unknown), weight_basis and lot_number
     * @return list<array{waste_type_id: int, waste_type: string, authorization: WasteAuthorization|null, reason: string|null, constraints: list<array<string, mixed>>, before: list<array<string, mixed>>, unweighed: list<string>, uncounted: list<string>}>
     */
    public function cover(Collection $lots, string $date, ?int $exceptPickupId = null): array
    {
        $types = DB::table('waste_types')->whereIn('id', $lots->pluck('waste_type_id')->unique())->where('requires_authorization', true)->orderBy('name')->pluck('name', 'id');
        $cover = [];

        foreach ($types as $typeId => $name) {
            $extra = ['kg' => '0.000', 'lots' => 0, 'counts' => [], 'unweighed' => [], 'kinds' => []];
            foreach ($lots->where('waste_type_id', $typeId) as $lot) {
                // A weight that is unknown, or only estimated, cannot be checked against a limit in kilograms.
                if ($lot->kg === null || ($lot->weight_basis ?? 'measured') === 'estimated') {
                    $extra['unweighed'][] = (string) ($lot->lot_number ?? '');
                }
                $kind = (string) $this->codes->code('packaging_types', $lot->packaging_type_id === null ? null : (int) $lot->packaging_type_id);
                $extra['kg'] = bcadd($extra['kg'], (string) ($lot->kg ?? '0'), 3);
                $extra['lots']++;
                // A lot is as many packages as it says. Loose waste has no packages: it is kept under '' and can only be
                // covered by a weight or a number of lots.
                $extra['counts'][$kind] = ($extra['counts'][$kind] ?? 0) + ($kind === '' ? 1 : (int) ($lot->packages ?? 1));
                $extra['kinds'][$kind][] = (string) ($lot->lot_number ?? '');
            }

            // The one that ends first is used first; a later one is taken when the first has no room left.
            $candidates = WasteAuthorization::query()->where('waste_type_id', $typeId)->where('is_active', true)
                ->whereDate('valid_from', '<=', $date)->whereDate('valid_until', '>=', $date)->orderBy('valid_until')->orderBy('id')->get();
            $choice = ['waste_type_id' => (int) $typeId, 'waste_type' => (string) $name, 'authorization' => null, 'reason' => 'missing', 'constraints' => [], 'before' => [], 'unweighed' => [], 'uncounted' => []];

            foreach ($candidates as $candidate) {
                $after = $this->usage($candidate, $exceptPickupId, $extra);
                $by = array_column($after['constraints'], null, 'key');
                $outcomes = array_column($after['constraints'], 'outcome');
                $reason = match (true) {
                    ! $after['blocked'] => null,
                    ($by['packages']['missing_count'] ?? 0) > 0 => 'packaging',
                    in_array(Constraint::EXCEEDED, $outcomes, true) => 'exceeded',
                    default => 'unverifiable',
                };
                if ($reason === null || $choice['authorization'] === null) {
                    $choice = ['authorization' => $candidate, 'reason' => $reason, 'constraints' => $after['constraints'], 'before' => $this->usage($candidate, $exceptPickupId)['constraints'],
                        'unweighed' => $by['weight']['missing_lots'] ?? [], 'uncounted' => $by['packages']['missing_lots'] ?? []] + $choice;
                }
                if ($reason === null) {
                    break;
                }
            }
            $cover[] = $choice;
        }

        return $cover;
    }

    /**
     * Ties each line of the pickup to the authorisation that covers it, or refuses the pickup: no waste that needs an
     * authorisation is requested, or confirmed for another date, without one that still has room.
     */
    public function reserve(Pickup $pickup): void
    {
        $date = ($pickup->confirmed_date ?? $pickup->requested_date)->toDateString();
        $lines = DB::table('pickup_lots as pl')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')
            ->where('pl.pickup_id', $pickup->id)->where('pl.line_status', '!=', 'not_loaded')
            ->get(['pl.id', 'l.waste_type_id', 'l.packaging_type_id', 'pl.lot_number_snapshot as lot_number', 'pl.weight_basis', 'pl.packaging_code',
                DB::raw('COALESCE(pl.corrected_package_count, pl.package_count) as packages'), DB::raw('COALESCE(pl.departure_weight_kg, pl.declared_weight_kg) as kg')]);

        foreach ($this->cover($lines, $date, $pickup->id) as $item) {
            if ($item['reason'] !== null) {
                // Every limit is in the answer, each with its own figures: the first failure does not hide the others.
                throw new BusinessRuleViolation('AUTHORIZATION_'.strtoupper($item['reason']), $item['reason'] === 'missing'
                    ? "No authorisation covers {$item['waste_type']} on {$date}: record one before requesting this pickup."
                    : "Authorisation {$item['authorization']?->reference} does not cover this pickup for {$item['waste_type']} — ".implode('; ', array_map(self::sentence(...), $item['constraints'])).'.',
                    ['waste_type' => $item['waste_type'], 'reference' => $item['authorization']?->reference, 'reason' => $item['reason'], 'constraints' => $item['constraints']]);
            }
            $authorization = $item['authorization'];
            // What was asked and what was answered is kept with each line: a later correction or a new pickup does not
            // rewrite why this one was accepted.
            foreach ($lines->where('waste_type_id', $item['waste_type_id']) as $line) {
                DB::table('pickup_lots')->where('id', $line->id)->update(['waste_authorization_id' => $authorization?->id, 'authorization_decision' => json_encode([
                    'authorization' => $authorization?->ulid, 'reference' => $authorization?->reference, 'decided_at' => now()->toIso8601ZuluString(), 'pickup_date' => $date,
                    'kg' => $line->kg === null ? null : (string) $line->kg, 'weight_basis' => $line->weight_basis, 'packaging' => $line->packaging_code,
                    'package_count' => $line->packages === null ? null : (int) $line->packages,
                    'limits' => ['max_kg' => $authorization?->max_kg, 'max_lots' => $authorization?->max_lots, 'packages' => $authorization === null ? [] : $this->limits($authorization)],
                    // Every limit of the document as it stood before this pickup and with it: figures, unit and answer.
                    'constraints_before' => $item['before'], 'constraints_after' => $item['constraints'],
                ])]);
            }

            // One alert per document, when one of its limits reaches its alert percentage: the figure sent is that limit's own.
            $near = array_values(array_filter($item['constraints'], fn (array $constraint) => $constraint['outcome'] === Constraint::WARNING));
            if ($authorization !== null && $near !== [] && $authorization->getRawOriginal('notified_usage_at') === null) {
                $this->notify->toCompanyPermission((int) $pickup->company_id, 'pickups.request', 'authorization.usage_high',
                    ['reference' => $authorization->reference, 'waste_type' => $item['waste_type'], 'pct' => (string) round((float) $near[0]['pct'])], '/compliance/authorizations');
                DB::table('waste_authorizations')->where('id', $authorization->id)->update(['notified_usage_at' => now()]);
            }
        }
    }

    /**
     * The packages the document allows, per packaging.
     *
     * @return array<string, int>
     */
    private function limits(WasteAuthorization $authorization): array
    {
        return DB::table('waste_authorization_limits')->where('waste_authorization_id', $authorization->id)->orderBy('id')->get(['packaging_type_id', 'max_count'])
            ->mapWithKeys(fn ($row) => [(string) $this->codes->code('packaging_types', (int) $row->packaging_type_id) => (int) $row->max_count])->all();
    }

    /**
     * One limit in plain words, for the refusal message.
     *
     * @param  array<string, mixed>  $constraint
     */
    private static function sentence(array $constraint): string
    {
        $figures = match ($constraint['key']) {
            'weight' => "weight {$constraint['used']}/{$constraint['max']} kg",
            'lots' => "lots {$constraint['used']}/{$constraint['max']}",
            default => 'packages '.implode(' + ', array_map(fn (array $line) => "{$line['used']}/{$line['max']} {$line['packaging_type']}", $constraint['lines'])),
        };
        $missing = implode(', ', $constraint['missing_lots']);

        return $figures.': '.match ($constraint['outcome']) {
            Constraint::EXCEEDED => 'exceeded'.(isset($constraint['excess']) ? " by {$constraint['excess']}" : (isset($constraint['lines'][0]['excess']) ? " by {$constraint['lines'][0]['excess']}" : " ({$constraint['pct']} % of the allowance)"))
                .($missing === '' ? '' : ", and {$constraint['missing_count']} lot(s) could not be counted ({$missing})"),
            Constraint::UNVERIFIABLE => $constraint['key'] === 'weight'
                ? "cannot be verified, {$constraint['missing_count']} lot(s) have no measured weight ({$missing})"
                : "cannot be verified, {$constraint['missing_count']} lot(s) are loose or in a packaging the document does not name ({$missing})",
            Constraint::WARNING => 'respected, alert level reached',
            default => 'respected',
        };
    }

    /**
     * What the pickups holding a share draw, line by line: a line whose weight is unknown or only estimated is named, not
     * counted as zero.
     *
     * @return array{kg: string, lots: int, counts: array<string, int>, unweighed: list<string>, kinds: array<string, list<string>>}
     */
    private function consumed(int $authorizationId, ?int $exceptPickupId): array
    {
        $rows = DB::table('pickup_lots as pl')->join('pickups as p', 'p.id', '=', 'pl.pickup_id')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')
            ->where('pl.waste_authorization_id', $authorizationId)->where('pl.line_status', '!=', 'not_loaded')
            ->whereIn('p.status', array_map(fn (PickupStatus $status) => $status->value, self::HOLDING))
            ->when($exceptPickupId !== null, fn ($query) => $query->where('p.id', '!=', $exceptPickupId))
            ->orderBy('pl.id')
            // Packages as they really left: a count corrected after departure counts, the figure decided with stays on the line.
            ->get(['l.packaging_type_id', 'pl.lot_number_snapshot as lot_number', 'pl.departure_weight_kg', 'pl.weight_basis',
                DB::raw('COALESCE(pl.departure_weight_kg, pl.declared_weight_kg) as kg'), DB::raw('COALESCE(pl.corrected_package_count, pl.package_count, 1) as packages')]);

        $used = ['kg' => '0.000', 'lots' => 0, 'counts' => [], 'unweighed' => [], 'kinds' => []];
        foreach ($rows as $row) {
            $kind = (string) $this->codes->code('packaging_types', $row->packaging_type_id === null ? null : (int) $row->packaging_type_id);
            if ($row->kg === null || ($row->departure_weight_kg === null && $row->weight_basis === 'estimated')) {
                $used['unweighed'][] = (string) $row->lot_number;
            }
            $used['kg'] = bcadd($used['kg'], (string) ($row->kg ?? '0'), 3);
            $used['lots']++;
            $used['counts'][$kind] = ($used['counts'][$kind] ?? 0) + ($kind === '' ? 1 : (int) $row->packages);
            $used['kinds'][$kind][] = (string) $row->lot_number;
        }

        return $used;
    }
}
