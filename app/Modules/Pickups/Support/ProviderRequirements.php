<?php

namespace App\Modules\Pickups\Support;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\Measure;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Models\User;
use App\Modules\Pickups\Models\Pickup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a provider asks of a collection for the waste it accepts: a minimum quantity, in the unit it states, and
 * sometimes a packaging. Each requirement is judged on the whole collection — the lots it concerns, together — never
 * lot by lot, and each on its own: a weight and a number of bales are two answers.
 *
 * A requirement concerns a family of waste, or one waste type: an item of the shared catalogue when the provider
 * publishes it, one of the company's own types when the company writes down what a partner asks. For the lots of that
 * type, its requirement replaces the one of the family of the same kind (a minimum replaces a minimum, a packaging a
 * packaging) among the requirements of the same author, unless it is said to come in addition to it. One minimum is
 * thus never counted twice for one lot by mistake.
 *
 * A requirement warns unless the provider made it blocking. A blocking one that is not met, or cannot be verified
 * because a quantity is missing, stops the request; someone entitled to may still send it with a reason, and the
 * provider, who reads that reason, accepts or refuses the pickup as always. At loading the same requirements are judged
 * again on what actually left, and kept beside the first answer: nothing is refused then — the truck is loaded — but a
 * blocking requirement no longer met is an exception someone explains. Which providers may be chosen is decided
 * elsewhere and is not changed here.
 */
final class ProviderRequirements
{
    public function __construct(private readonly ReferenceCodes $codes) {}

    /**
     * @param  Collection<int, \stdClass>  $lines  each with lot_number, waste_type_id, family_id, catalog_item_id, regulatory_code_id, channel_id and what `Measure` reads
     * @param  Collection<int, \stdClass>|null  $left  lots that were on the request and did not leave, read the same way
     * @return array{constraints: list<array<string, mixed>>, outcome: string|null, blocked: bool, failing: list<string>}
     */
    public function evaluate(int $ownerCompanyId, ?int $providerCompanyId, ?int $externalPartnerId, Collection $lines, ?Collection $left = null): array
    {
        $asks = fn ($query) => $query->where('min_quantity', '>', 0)->orWhereNotNull('required_packaging_type_id');
        // What the provider itself published: its own word.
        $published = $providerCompanyId === null ? collect() : DB::table('provider_accepted_wastes')->where('company_id', $providerCompanyId)->where('is_active', true)
            ->where($asks)->orderBy('id')->get()->each(function (\stdClass $rule): void {
                $rule->source = 'provider';
                $rule->set = 'provider';
                $rule->waste_type_id = null;
            });
        // What the company wrote down itself about a partner without an account — from a contract, a phone call. The
        // partner never confirmed it on the platform, and every answer says so. Once that partner has an account, what
        // was written down keeps applying to its pickups until someone reviews it: nothing is dropped silently.
        $partners = DB::table('external_partners')->where('company_id', $ownerCompanyId)
            ->where(fn ($query) => $query->where('id', $externalPartnerId ?? 0)->when($providerCompanyId !== null, fn ($q) => $q->orWhere('linked_company_id', $providerCompanyId)))
            ->get(['id', 'linked_company_id', 'linked_at'])->keyBy('id');
        $internal = DB::table('partner_requirements')->where('company_id', $ownerCompanyId)->whereIn('external_partner_id', $partners->keys())->where($asks)->orderBy('id')->get()
            ->each(function (\stdClass $rule) use ($partners): void {
                $partner = $partners[$rule->external_partner_id];
                $rule->source = 'internal';
                $rule->set = 'internal:'.$rule->external_partner_id;
                $rule->waste_catalog_item_id = $rule->regulatory_waste_code_id = null;
                $rule->needs_review = $partner->linked_company_id !== null && ($rule->reviewed_at === null || $rule->reviewed_at < $partner->linked_at);
            });
        $rules = $published->concat($internal)->values();
        $constraints = [];

        foreach ($rules as $index => $rule) {
            $asksMinimum = self::asksMinimum($rule);
            $minimumLots = $asksMinimum ? $this->concerned($rule, 'minimum', $rules, $lines) : collect();
            $packagingLots = $rule->required_packaging_type_id !== null ? $this->concerned($rule, 'packaging', $rules, $lines) : collect();
            if ($minimumLots->isEmpty() && $packagingLots->isEmpty()) {
                continue;
            }
            $blocking = (bool) $rule->is_blocking;
            // The rule as it stands now, kept with the answer: the provider may change it afterwards.
            $snapshot = [
                'channel' => $this->codes->code('treatment_channels', (int) $rule->treatment_channel_id),
                'family' => $this->codes->code('waste_families', $rule->waste_family_id === null ? null : (int) $rule->waste_family_id),
                'catalog_item' => $this->codes->code('waste_catalog_items', $rule->waste_catalog_item_id === null ? null : (int) $rule->waste_catalog_item_id),
                // One of the company's own waste types (internal requirements only), by its code and its name.
                'waste_type' => $rule->waste_type_id === null ? null : (array) DB::table('waste_types')->where('id', $rule->waste_type_id)->first(['code', 'name']),
                // 'family': every lot of the family, except those of a type with its own requirement; 'type': the lots of one type.
                'scope' => self::specific($rule) ? 'type' : 'family',
                'adds_to_family' => (bool) ($rule->adds_to_family ?? false),
                'notes' => $rule->notes,
                // 'provider': published by the provider. 'internal': written down by the company, not confirmed by the partner.
                'source' => $rule->source,
                'contract_reference' => $rule->contract_reference ?? null,
                'needs_review' => (bool) ($rule->needs_review ?? false),
            ];
            // Lots of the family that a requirement of their own type answers for instead, and the lots of the request
            // that stayed on site: the answer says which lots it was not given on.
            $aside = fn (string $kind, Collection $concerned) => [
                'own_rule_lots' => self::specific($rule) ? [] : $this->matching($rule, $lines)->diffKeys($concerned)->pluck('lot_number')->map(fn ($n) => (string) $n)->values()->all(),
                'left_lots' => $left === null ? [] : $this->concerned($rule, $kind, $rules, $left)->pluck('lot_number')->map(fn ($n) => (string) $n)->values()->all(),
            ];

            if ($asksMinimum && $minimumLots->isNotEmpty()) {
                $unit = (string) ($rule->min_unit_code ?? 'kg');
                $packaging = $this->codes->code('packaging_types', $rule->min_packaging_type_id === null ? null : (int) $rule->min_packaging_type_id);
                $scale = Measure::scale($unit);
                // An estimated weight is not a figure a requirement is met with.
                $total = Measure::total($minimumLots, $unit, $packaging, true);
                $constraints[] = Constraint::minimum('minimum:'.($index + 1), $unit, $total['used'], bcadd((string) $rule->min_quantity, '0', $scale), $total['missing'], $blocking, $scale) + [
                    'packaging_type' => $packaging, 'lots' => $minimumLots->count(),
                    // Under a minimum in packages, the lots in another packaging are not counted, and are said not to be.
                    'outside_count' => count($total['outside']), 'outside_lots' => array_slice($total['outside'], 0, 50),
                    'source' => $rule->source, 'rule' => $snapshot,
                ] + $aside('minimum', $minimumLots);
            }
            if ($rule->required_packaging_type_id !== null && $packagingLots->isNotEmpty()) {
                $required = (string) $this->codes->code('packaging_types', (int) $rule->required_packaging_type_id);
                $other = $packagingLots->filter(fn (\stdClass $line) => (string) ($line->packaging ?? '') !== $required)->map(fn (\stdClass $line) => (string) $line->lot_number)->values()->all();
                $constraints[] = Constraint::packaging('packaging:'.($index + 1), $required, $packagingLots->count(), $other, $blocking)
                    + ['source' => $rule->source, 'rule' => $snapshot] + $aside('packaging', $packagingLots);
            }
        }

        return ['constraints' => $constraints] + Constraint::overall($constraints);
    }

    /**
     * Lots about to be requested, read the way the lines of the pickup will be.
     *
     * @param  list<string>  $lotUlids
     * @return Collection<int, \stdClass>
     */
    public function linesOfLots(int $companyId, array $lotUlids, ?int $channelId): Collection
    {
        return DB::table('waste_lots as l')->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->leftJoin('units as u', 'u.id', '=', 'l.unit_id')->leftJoin('packaging_types as p', 'p.id', '=', 'l.packaging_type_id')
            ->where('l.company_id', $companyId)->whereIn('l.ulid', $lotUlids)->orderBy('l.id')
            ->get(['l.lot_number', 'l.waste_type_id', 't.waste_family_id as family_id', 't.waste_catalog_item_id as catalog_item_id', 'l.regulatory_waste_code_id as regulatory_code_id',
                DB::raw(($channelId === null ? 't.default_treatment_channel_id' : (string) $channelId).' as channel_id'),
                'l.net_weight_kg as kg', 'l.weight_basis', 'l.quantity', 'u.code as unit', 't.density_kg_m3', 't.unit_weight_kg', 'p.code as packaging',
                DB::raw('CASE WHEN l.packaging_type_id IS NULL THEN NULL ELSE COALESCE(l.package_count, 1) END as packages')]);
    }

    /**
     * The lines of a pickup at one stage: as requested (what each lot declared), as they left (what was loaded, with
     * the weight and quantity of departure and the packages counted then), or those that stayed on site.
     *
     * @param  'request'|'departure'|'left'  $stage
     * @return Collection<int, \stdClass>
     */
    public function linesOfPickup(Pickup $pickup, string $stage = 'request'): Collection
    {
        $departure = $stage === 'departure';
        $prefix = $departure ? 'departure' : 'declared';

        return DB::table('pickup_lots as pl')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->where('pl.pickup_id', $pickup->id)
            ->when($stage === 'request', fn ($query) => $query->where('pl.line_status', '!=', 'not_loaded'))
            ->when($departure, fn ($query) => $query->whereNotNull('pl.loaded_at'))
            ->when($stage === 'left', fn ($query) => $query->where('pl.line_status', 'not_loaded'))
            ->orderBy('pl.id')
            ->get(['pl.lot_number_snapshot as lot_number', 'l.waste_type_id', 't.waste_family_id as family_id', 't.waste_catalog_item_id as catalog_item_id', 'pl.regulatory_waste_code_id as regulatory_code_id',
                'pl.planned_treatment_channel_id as channel_id', "pl.{$prefix}_weight_kg as kg", 'pl.weight_basis',
                // A quantity is declared only for waste counted in pieces or litres, in the unit of the line.
                "pl.{$prefix}_quantity as quantity", DB::raw("CASE WHEN pl.{$prefix}_quantity IS NULL THEN NULL ELSE pl.unit_code END as unit"),
                't.density_kg_m3', 't.unit_weight_kg', 'pl.packaging_code as packaging', 'pl.package_count as packages']);
    }

    /**
     * Judges the pickup when it is requested and keeps the answer on it. A blocking requirement that fails refuses the
     * request, unless someone entitled to sends it all the same and says why.
     */
    public function decide(Pickup $pickup, User $actor, ?string $overrideReason, bool $mayOverride): void
    {
        $result = $this->evaluate((int) $pickup->company_id, $pickup->provider_company_id, $pickup->external_provider_id, $this->linesOfPickup($pickup));
        if ($result['constraints'] === []) {
            return;
        }
        $overridden = $result['blocked'] && $overrideReason !== null && trim($overrideReason) !== '';
        if ($result['blocked'] && ! ($overridden && $mayOverride)) {
            $provider = $this->providerName($pickup);
            $failing = array_values(array_filter($result['constraints'], fn (array $constraint) => in_array($constraint['key'], $result['failing'], true)));
            $unverifiable = ! in_array(Constraint::EXCEEDED, array_column($failing, 'outcome'), true);
            throw new BusinessRuleViolation($overridden ? 'PROVIDER_REQUIREMENT_NEEDS_AUTHORIZATION' : ($unverifiable ? 'PROVIDER_REQUIREMENT_UNVERIFIABLE' : 'PROVIDER_REQUIREMENT_NOT_MET'),
                "{$provider} does not take this collection as it stands — ".implode('; ', array_map(self::sentence(...), $failing)).'.'
                .($overridden ? ' Sending it all the same must be done by someone authorised to.' : ''),
                ['provider' => $provider, 'constraints' => $result['constraints'], 'failing' => $result['failing'], 'may_override' => $mayOverride]);
        }

        $pickup->forceFill(['provider_requirements' => [
            'decided_at' => now()->toIso8601ZuluString(), 'constraints' => $result['constraints'], 'outcome' => $result['outcome'], 'blocked' => $result['blocked'],
            'failing' => $result['failing'],
            // Sent although a blocking requirement failed: who decided it, and why.
            'override' => $overridden ? ['reason' => trim((string) $overrideReason), 'by' => trim("{$actor->first_name} {$actor->last_name}"), 'at' => now()->toIso8601ZuluString()] : null,
        ]]);
        if ($overridden) {
            // Its own line in the audit trail, beside the change of the pickup: who decided against which requirement, and why.
            $pickup->audit('requirement_overridden', [], ['reason' => trim((string) $overrideReason), 'failing' => $result['failing'], 'outcome' => $result['outcome']]);
        }
    }

    /**
     * Judges the same requirements again on what was loaded — lots left on site no longer count, weights and quantities
     * are those of departure — and keeps that answer beside the one of the request, which is not touched. Called once,
     * inside the loading itself: a loading sent twice is refused before reaching here, so nothing is judged twice.
     *
     * Nothing is refused: the waste has physically left. A blocking requirement that is no longer met, or can no longer
     * be verified, is recorded as an exception that someone entitled to explains afterwards.
     *
     * @return bool whether a requirement is not met, or cannot be verified, on what left
     */
    public function atLoading(Pickup $pickup): bool
    {
        if ($pickup->loading_requirements !== null) {
            return false;
        }
        $requested = $pickup->provider_requirements['constraints'] ?? [];
        $left = $this->linesOfPickup($pickup, 'left');
        $result = $this->evaluate((int) $pickup->company_id, $pickup->provider_company_id, $pickup->external_provider_id, $this->linesOfPickup($pickup, 'departure'), $left);
        if ($result['constraints'] === [] && $requested === []) {
            return false;
        }

        $constraints = array_map(function (array $constraint) use ($requested): array {
            // The same requirement, as it was answered at request time — if it has not changed since.
            $before = collect($requested)->first(fn (array $old) => $old['key'] === $constraint['key'] && $old['kind'] === $constraint['kind']
                && ($old['unit'] ?? null) === ($constraint['unit'] ?? null) && array_diff_key($old['rule'] ?? [], ['needs_review' => 0]) == array_diff_key($constraint['rule'], ['needs_review' => 0]));

            return $constraint + ['at_request' => $before === null ? null : ['outcome' => $before['outcome'], 'used' => $before['used'] ?? null, 'outside_count' => $before['outside_count'] ?? null]];
        }, $result['constraints']);
        $unmet = array_values(array_map(fn (array $constraint) => (string) $constraint['key'], array_filter($constraints, fn (array $constraint) => $constraint['outcome'] !== Constraint::RESPECTED)));

        $pickup->forceFill(['loading_requirements' => [
            'evaluated_at' => now()->toIso8601ZuluString(), 'constraints' => $constraints, 'outcome' => $result['outcome'],
            'unmet' => $unmet, 'failing' => $result['failing'],
            'left_behind' => $left->pluck('lot_number')->map(fn ($n) => (string) $n)->values()->all(),
            // A blocking requirement not met on what left: the departure stands, someone says why.
            'exception' => $result['blocked'] ? ['failing' => $result['failing'], 'acknowledged' => null] : null,
        ]]);
        if ($unmet !== []) {
            $pickup->audit('requirements_checked_at_loading', [], ['outcome' => $result['outcome'], 'unmet' => $unmet, 'failing' => $result['failing']]);
        }

        return $unmet !== [];
    }

    /**
     * Who explains a blocking requirement that was no longer met when the waste left, and why. Kept once, with its author.
     */
    public function acknowledgeLoadingException(Pickup $pickup, string $reason, User $actor): Pickup
    {
        return DB::transaction(function () use ($pickup, $reason, $actor) {
            $pickup = Pickup::query()->whereKey($pickup->id)->lockForUpdate()->firstOrFail();
            $loading = $pickup->loading_requirements;
            if (($loading['exception'] ?? null) === null || $loading['exception']['acknowledged'] !== null) {
                throw new BusinessRuleViolation('NO_REQUIREMENT_EXCEPTION', 'No blocking requirement of the receiver is waiting for an explanation on this pickup.');
            }
            $loading['exception']['acknowledged'] = ['reason' => trim($reason), 'by' => trim("{$actor->first_name} {$actor->last_name}"), 'at' => now()->toIso8601ZuluString()];
            $pickup->forceFill(['loading_requirements' => $loading])->save();
            $pickup->audit('requirement_exception_acknowledged', [], ['reason' => trim($reason), 'failing' => $loading['exception']['failing']]);

            return $pickup;
        });
    }

    /**
     * Lines of one list of requirements that ask the same thing twice of the same waste and channel: two minimums in the
     * same dimension (two weights; packages of the same packaging), or two packagings. One of them would always be
     * counted for nothing — or the two would contradict each other — so a list holds one of each.
     *
     * @param  array<int, array<string, mixed>>  $lines  each with channel, family, the key named by `$specific`, and what it asks
     * @return array<int, string> index of the second line => what it asks again
     */
    public static function duplicates(array $lines, string $specific): array
    {
        $seen = [];
        $twice = [];
        foreach ($lines as $index => $line) {
            $scope = ($line['channel'] ?? '').'|'.(($line[$specific] ?? null) !== null ? 'type:'.$line[$specific] : 'family:'.($line['family'] ?? ''));
            $minimum = $line['min_quantity'] ?? null;
            $unit = $minimum !== null ? (string) ($line['min_unit'] ?? 'kg') : (bccomp((string) ($line['min_quantity_kg'] ?? '0'), '0', 3) > 0 ? 'kg' : null);
            $asks = [];
            if ($unit !== null) {
                $asks['minimum '.(Measure::dimension($unit) === Measure::PACKAGE ? 'package:'.($line['min_packaging_type'] ?? '') : Measure::dimension($unit))] = 'minimum '.($unit === Measure::PACKAGE ? (string) ($line['min_packaging_type'] ?? '') : $unit);
            }
            if (($line['required_packaging_type'] ?? null) !== null) {
                $asks['packaging'] = (string) $line['required_packaging_type'];
            }
            foreach ($asks as $ask => $what) {
                if (isset($seen["{$scope}|{$ask}"])) {
                    $twice[$index] = $what;
                }
                $seen["{$scope}|{$ask}"] = true;
            }
        }

        return $twice;
    }

    public function providerName(Pickup $pickup): string
    {
        return (string) ($pickup->provider_company_id !== null ? DB::table('companies')->where('id', $pickup->provider_company_id)->value('legal_name')
            : DB::table('external_partners')->where('id', $pickup->external_provider_id)->value('legal_name'));
    }

    /**
     * The lines a rule is judged on for one kind of requirement: those of its scope, less — for a rule of a family —
     * the lines of a type whose own rule of the same author asks the same kind of thing instead.
     *
     * @param  'minimum'|'packaging'  $kind
     * @param  Collection<int, \stdClass>  $rules
     * @param  Collection<int, \stdClass>  $lines
     * @return Collection<int, \stdClass>
     */
    private function concerned(\stdClass $rule, string $kind, Collection $rules, Collection $lines): Collection
    {
        $matching = $this->matching($rule, $lines);
        if (self::specific($rule)) {
            return $matching;
        }
        $own = $rules->filter(fn (\stdClass $other) => $other !== $rule && $other->set === $rule->set && self::specific($other) && ! (bool) ($other->adds_to_family ?? false)
            && ($kind === 'minimum' ? self::asksMinimum($other) : $other->required_packaging_type_id !== null));

        return $matching->reject(fn (\stdClass $line) => $own->contains(fn (\stdClass $other) => $this->matches($other, $line)));
    }

    /**
     * @param  Collection<int, \stdClass>  $lines
     * @return Collection<int, \stdClass>
     */
    private function matching(\stdClass $rule, Collection $lines): Collection
    {
        return $lines->filter(fn (\stdClass $line) => $this->matches($rule, $line));
    }

    private function matches(\stdClass $rule, \stdClass $line): bool
    {
        return (int) $line->channel_id === (int) $rule->treatment_channel_id && match (true) {
            $rule->waste_type_id !== null => (int) $line->waste_type_id === (int) $rule->waste_type_id,
            $rule->waste_catalog_item_id !== null => (int) $line->catalog_item_id === (int) $rule->waste_catalog_item_id,
            $rule->regulatory_waste_code_id !== null => (int) $line->regulatory_code_id === (int) $rule->regulatory_waste_code_id,
            default => (int) $line->family_id === (int) $rule->waste_family_id,
        };
    }

    private static function specific(\stdClass $rule): bool
    {
        return $rule->waste_type_id !== null || $rule->waste_catalog_item_id !== null || $rule->regulatory_waste_code_id !== null;
    }

    private static function asksMinimum(\stdClass $rule): bool
    {
        return $rule->min_quantity !== null && bccomp((string) $rule->min_quantity, '0', 3) > 0;
    }

    /**
     * One requirement in plain words, for the refusal message.
     *
     * @param  array<string, mixed>  $constraint
     */
    private static function sentence(array $constraint): string
    {
        if ($constraint['kind'] === 'packaging') {
            return "{$constraint['outside_count']} lot(s) are not in {$constraint['packaging_type']}, the packaging asked for (".implode(', ', $constraint['outside_lots']).')';
        }
        $unit = $constraint['unit'] === Measure::PACKAGE ? (string) $constraint['packaging_type'] : $constraint['unit'];
        $figures = "minimum {$constraint['used']}/{$constraint['min']} {$unit}";

        return $constraint['outcome'] === Constraint::UNVERIFIABLE
            ? "{$figures}: cannot be verified, {$constraint['missing_count']} lot(s) have no usable quantity (".implode(', ', $constraint['missing_lots']).')'
            : "{$figures}: {$constraint['shortfall']} {$unit} short";
    }
}
