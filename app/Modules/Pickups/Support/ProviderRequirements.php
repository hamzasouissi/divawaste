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
 * A requirement warns unless the provider made it blocking. A blocking one that is not met, or cannot be verified
 * because a quantity is missing, stops the request; someone entitled to may still send it with a reason, and the
 * provider, who reads that reason, accepts or refuses the pickup as always. Which providers may be chosen is decided
 * elsewhere and is not changed here.
 */
final class ProviderRequirements
{
    public function __construct(private readonly ReferenceCodes $codes) {}

    /**
     * @param  Collection<int, \stdClass>  $lines  each with lot_number, family_id, catalog_item_id, regulatory_code_id, channel_id and what `Measure` reads
     * @return array{constraints: list<array<string, mixed>>, outcome: string|null, blocked: bool, failing: list<string>}
     */
    public function evaluate(int $ownerCompanyId, ?int $providerCompanyId, ?int $externalPartnerId, Collection $lines): array
    {
        $asks = fn ($query) => $query->where('min_quantity', '>', 0)->orWhereNotNull('required_packaging_type_id');
        // What the provider itself published: its own word.
        $published = $providerCompanyId === null ? collect() : DB::table('provider_accepted_wastes')->where('company_id', $providerCompanyId)->where('is_active', true)
            ->where($asks)->orderBy('id')->get()->each(fn (\stdClass $rule) => $rule->source = 'provider');
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
                $rule->waste_catalog_item_id = $rule->regulatory_waste_code_id = null;
                $rule->needs_review = $partner->linked_company_id !== null && ($rule->reviewed_at === null || $rule->reviewed_at < $partner->linked_at);
            });
        $constraints = [];

        foreach ($published->concat($internal)->values() as $index => $rule) {
            $concerned = $lines->filter(fn (\stdClass $line) => (int) $line->channel_id === (int) $rule->treatment_channel_id && match (true) {
                $rule->waste_catalog_item_id !== null => (int) $line->catalog_item_id === (int) $rule->waste_catalog_item_id,
                $rule->regulatory_waste_code_id !== null => (int) $line->regulatory_code_id === (int) $rule->regulatory_waste_code_id,
                default => (int) $line->family_id === (int) $rule->waste_family_id,
            })->values();
            if ($concerned->isEmpty()) {
                continue;
            }
            $blocking = (bool) $rule->is_blocking;
            // The rule as it stands now, kept with the answer: the provider may change it afterwards.
            $snapshot = [
                'channel' => $this->codes->code('treatment_channels', (int) $rule->treatment_channel_id),
                'family' => $this->codes->code('waste_families', $rule->waste_family_id === null ? null : (int) $rule->waste_family_id),
                'catalog_item' => $this->codes->code('waste_catalog_items', $rule->waste_catalog_item_id === null ? null : (int) $rule->waste_catalog_item_id),
                'notes' => $rule->notes,
                // 'provider': published by the provider. 'internal': written down by the company, not confirmed by the partner.
                'source' => $rule->source,
                'contract_reference' => $rule->contract_reference ?? null,
                'needs_review' => (bool) ($rule->needs_review ?? false),
            ];

            if ($rule->min_quantity !== null && bccomp((string) $rule->min_quantity, '0', 3) > 0) {
                $unit = (string) ($rule->min_unit_code ?? 'kg');
                $packaging = $this->codes->code('packaging_types', $rule->min_packaging_type_id === null ? null : (int) $rule->min_packaging_type_id);
                $scale = Measure::scale($unit);
                // An estimated weight is not a figure a requirement is met with.
                $total = Measure::total($concerned, $unit, $packaging, true);
                $constraints[] = Constraint::minimum('minimum:'.($index + 1), $unit, $total['used'], bcadd((string) $rule->min_quantity, '0', $scale), $total['missing'], $blocking, $scale) + [
                    'packaging_type' => $packaging, 'lots' => $concerned->count(),
                    // Under a minimum in packages, the lots in another packaging are not counted, and are said not to be.
                    'outside_count' => count($total['outside']), 'outside_lots' => array_slice($total['outside'], 0, 50),
                    'source' => $rule->source, 'rule' => $snapshot,
                ];
            }
            if ($rule->required_packaging_type_id !== null) {
                $required = (string) $this->codes->code('packaging_types', (int) $rule->required_packaging_type_id);
                $other = $concerned->filter(fn (\stdClass $line) => (string) ($line->packaging ?? '') !== $required)->map(fn (\stdClass $line) => (string) $line->lot_number)->values()->all();
                $constraints[] = Constraint::packaging('packaging:'.($index + 1), $required, $concerned->count(), $other, $blocking) + ['source' => $rule->source, 'rule' => $snapshot];
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
            ->get(['l.lot_number', 't.waste_family_id as family_id', 't.waste_catalog_item_id as catalog_item_id', 'l.regulatory_waste_code_id as regulatory_code_id',
                DB::raw(($channelId === null ? 't.default_treatment_channel_id' : (string) $channelId).' as channel_id'),
                'l.net_weight_kg as kg', 'l.weight_basis', 'l.quantity', 'u.code as unit', 't.density_kg_m3', 't.unit_weight_kg', 'p.code as packaging',
                DB::raw('CASE WHEN l.packaging_type_id IS NULL THEN NULL ELSE COALESCE(l.package_count, 1) END as packages')]);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function linesOfPickup(Pickup $pickup): Collection
    {
        return DB::table('pickup_lots as pl')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->where('pl.pickup_id', $pickup->id)->where('pl.line_status', '!=', 'not_loaded')->orderBy('pl.id')
            ->get(['pl.lot_number_snapshot as lot_number', 't.waste_family_id as family_id', 't.waste_catalog_item_id as catalog_item_id', 'pl.regulatory_waste_code_id as regulatory_code_id',
                'pl.planned_treatment_channel_id as channel_id', 'pl.declared_weight_kg as kg', 'pl.weight_basis',
                // A quantity is declared only for waste counted in pieces or litres, in the unit of the line.
                'pl.declared_quantity as quantity', DB::raw('CASE WHEN pl.declared_quantity IS NULL THEN NULL ELSE pl.unit_code END as unit'),
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
            $provider = (string) ($pickup->provider_company_id !== null ? DB::table('companies')->where('id', $pickup->provider_company_id)->value('legal_name')
                : DB::table('external_partners')->where('id', $pickup->external_provider_id)->value('legal_name'));
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
