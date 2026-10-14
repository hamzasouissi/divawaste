<?php

namespace App\Modules\Pickups\Actions;

use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\NumberSequence;
use App\Modules\Documents\Support\AuthorizationQuota;
use App\Modules\Identity\Models\User;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Pickups\Models\PickupLot;
use App\Modules\Pickups\Support\ProviderRequirements;
use App\Modules\Providers\Models\ExternalPartner;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Actions\TransitionLot;
use App\Modules\Waste\Enums\LotStatus;
use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Support\Tolerance;
use App\Modules\Waste\Support\ToleranceRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pickup lifecycle (M4-03/04, blueprint §8.2): draft → requested → confirmed|refused → collected → received;
 * cancel from draft/requested/confirmed. Lots follow via TransitionLot.
 */
final class PickupWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly TransitionLot $lots, private readonly AuthorizationQuota $authorizations,
        private readonly ToleranceRules $rules, private readonly ProviderRequirements $requirements) {}

    /**
     * The receiver and the transporter are each a company of the platform or a partner without an account.
     * Without a transporter, the receiver carries the waste itself.
     *
     * @param  array{provider_company_id?: int|null, external_provider_id?: int|null, transporter_company_id?: int|null, external_transporter_id?: int|null}  $parties
     * @param  list<string>  $lotUlids
     * @param  int|null  $channelId  what the receiver is to do with the waste; by default what each waste type plans
     */
    public function create(int $siteId, array $parties, string $requestedDate, array $lotUlids, User $actor, ?int $channelId = null): Pickup
    {
        return DB::transaction(function () use ($siteId, $parties, $requestedDate, $lotUlids, $actor, $channelId) {
            $year = now()->format('Y');
            $pickup = Pickup::query()->create([
                'pickup_number' => NumberSequence::next($this->context->companyId(), 'pickup', "ENL-{$year}-", $year, 4),
                'site_id' => $siteId, 'provider_company_id' => $parties['provider_company_id'] ?? null, 'external_provider_id' => $parties['external_provider_id'] ?? null,
                'transporter_company_id' => $parties['transporter_company_id'] ?? null, 'external_transporter_id' => $parties['external_transporter_id'] ?? null,
                'requested_date' => $requestedDate, 'currency_code' => DB::table('companies')->where('id', $this->context->companyId())->value('currency_code'),
                'created_by_user_id' => $actor->id,
            ]);

            $lots = WasteLot::query()->whereIn('ulid', $lotUlids)->where('site_id', $siteId)->lockForUpdate()->get();
            if ($lots->count() !== count(array_unique($lotUlids))) {
                throw new BusinessRuleViolation('PICKUP_LOTS_INVALID', 'All lots must exist on the pickup site.');
            }
            foreach ($lots as $lot) {
                if (! in_array($lot->status, [LotStatus::Created, LotStatus::Stored], true)) {
                    throw new BusinessRuleViolation('LOT_NOT_AVAILABLE', "Lot {$lot->lot_number} is not available for a pickup.");
                }
                if (PickupLot::query()->where('waste_lot_id', $lot->id)->where('is_active_flag', true)->exists()) {
                    throw new BusinessRuleViolation('LOT_IN_PICKUP', "Lot {$lot->lot_number} is already in an active pickup.");
                }
                $this->addLine($pickup, $lot, $channelId);
            }

            return $pickup;
        });
    }

    /**
     * @param  string|null  $overrideReason  why the request is sent although a blocking requirement of the receiver failed
     * @param  bool  $mayOverride  whether the person sending it is entitled to do that
     */
    public function submit(Pickup $pickup, User $actor, ?string $overrideReason = null, bool $mayOverride = false): Pickup
    {
        return $this->step($pickup, [PickupStatus::Draft], PickupStatus::Requested, function (Pickup $pickup) use ($actor, $overrideReason, $mayOverride) {
            $this->assertEligible($pickup);
            $this->assertRegulatoryCodes($pickup);
            // What the receiver asks of a collection: a minimum, a packaging. Judged on the whole pickup, kept with it.
            $this->requirements->decide($pickup, $actor, $overrideReason, $mayOverride);
            // Waste that needs an authorisation is requested only if one covers it; the request holds its share.
            $this->authorizations->reserve($pickup);
            foreach ($this->lines($pickup) as $line) {
                $this->lots->handle(WasteLot::query()->findOrFail($line->waste_lot_id), LotStatus::AwaitingPickup, $actor);
            }
            $pickup->forceFill(['submitted_at' => now(), 'requested_by_user_id' => $actor->id]);
        });
    }

    public function confirm(Pickup $pickup, string $confirmedDate, User $actor): Pickup
    {
        return $this->step($pickup, [PickupStatus::Requested], PickupStatus::Confirmed, function (Pickup $p) use ($confirmedDate, $actor) {
            $p->forceFill(['confirmed_date' => $confirmedDate, 'confirmed_at' => now(), 'confirmed_by_user_id' => $actor->id]);
            $this->assertEligible($p);
            // The confirmed date may fall outside the period of the authorisation held at request time.
            $this->authorizations->reserve($p);
        });
    }

    public function refuse(Pickup $pickup, string $reason, User $actor): Pickup
    {
        return $this->step($pickup, [PickupStatus::Requested], PickupStatus::Refused, function (Pickup $p) use ($reason, $actor) {
            $this->releaseLots($p, $actor);
            $p->forceFill(['refusal_reason' => $reason, 'refused_at' => now(), 'refused_by_user_id' => $actor->id]);
        });
    }

    public function cancel(Pickup $pickup, string $reason, User $actor): Pickup
    {
        return $this->step($pickup, [PickupStatus::Draft, PickupStatus::Requested, PickupStatus::Confirmed], PickupStatus::Cancelled,
            function (Pickup $p) use ($reason, $actor) {
                $this->releaseLots($p, $actor);
                $p->forceFill(['cancellation_reason' => $reason, 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->id]);
            });
    }

    /**
     * Loading: listed lots are collected with their departure weight (default = lot net weight); others go back to stock.
     *
     * Waste counted in pieces or litres leaves with a quantity in its own unit (default = what the lot declares).
     *
     * @param  array<string, string|null>  $loaded  lot ULID => departure weight kg
     * @param  array<string, string>  $quantities  lot ULID => departure quantity, in the unit of the line
     */
    public function collect(Pickup $pickup, array $loaded, User $actor, array $quantities = []): Pickup
    {
        return $this->step($pickup, [PickupStatus::Confirmed], PickupStatus::Collected, function (Pickup $p) use ($loaded, $actor, $quantities) {
            $total = '0';
            $known = false;
            foreach ($this->lines($p) as $line) {
                $lot = WasteLot::query()->findOrFail($line->waste_lot_id);
                if (! array_key_exists($lot->ulid, $loaded)) {
                    $line->forceFill(['line_status' => 'not_loaded', 'is_active_flag' => null])->save();
                    $this->lots->handle($lot, LotStatus::Stored, $actor);

                    continue;
                }
                // A weight typed at loading is a measure. Otherwise the lot leaves with the weight it has — possibly none.
                $typed = $loaded[$lot->ulid] ?? null;
                $weight = $typed ?? ($lot->net_weight_kg === null ? null : (string) $lot->net_weight_kg);
                $line->forceFill(['line_status' => 'loaded', 'departure_weight_kg' => $weight, 'loaded_at' => now(), 'loaded_by_user_id' => $actor->id,
                    'weight_basis' => $typed !== null ? 'measured' : ($lot->weight_basis ?? 'measured'),
                    'departure_quantity' => $line->measure_dimension === 'mass' ? null : ($quantities[$lot->ulid] ?? $line->declared_quantity)])->save();
                $this->lots->handle($lot, LotStatus::Collected, $actor);
                $total = bcadd($total, $weight ?? '0', 3);
                $known = $known || $weight !== null;
            }
            // The total is the sum of the weights that are known; the lots without one are counted apart, never as 0 kg.
            // When no lot that left has a weight there is no total at all.
            $p->forceFill(['departure_weight_kg' => $known ? $total : null, 'departure_weight_source' => 'lots_sum', 'collected_at' => now(), 'collected_by_user_id' => $actor->id]);
            // What the receiver asks, judged again on what actually left. Kept beside the request-time answer; never refuses.
            $this->requirements->atLoading($p);
        });
    }

    /**
     * Reception (M4-04). Each lot is compared in the unit its waste type is counted in — the received weight against
     * the weight that left, or the received pieces against the pieces that left — with the tolerance that applies to
     * this type handled this way. The rule and the figures are stored with the line: the decision stays explainable.
     *
     * @param  array<string, string>  $received  lot number => received weight kg
     * @param  array<string, string>  $quantities  lot number => received quantity, in the unit of the line
     */
    public function receive(Pickup $pickup, array $received, User $actor, array $quantities = []): Pickup
    {
        return $this->step($pickup, [PickupStatus::Collected], PickupStatus::Received, function (Pickup $p) use ($received, $actor, $quantities) {
            $total = '0';
            $known = false;
            $weighed = ['departure' => '0', 'received' => '0', 'missing' => false, 'unknown' => false, 'lines' => 0];
            $statuses = [];
            foreach ($this->lines($p) as $line) {
                if ($line->line_status !== 'loaded') {
                    continue;
                }
                $mass = $line->measure_dimension === 'mass';
                $kg = $received[$line->lot_number_snapshot] ?? null;
                $quantity = $quantities[$line->lot_number_snapshot] ?? null;
                if ($mass && $line->departure_weight_kg === null) {
                    $weighed['unknown'] = true;
                } elseif ($mass) {
                    $weighed['departure'] = bcadd($weighed['departure'], (string) $line->departure_weight_kg, 3);
                }
                if ($kg === null && $quantity === null) {
                    $weighed['missing'] = $weighed['missing'] || $mass;

                    continue;
                }
                // Pieces are not judged on a weight, nor a weight on a count: the line needs the measure of its type.
                if (($mass ? $kg : $quantity) === null) {
                    throw new BusinessRuleViolation('MEASURE_REQUIRED', $mass
                        ? "Lot {$line->lot_number_snapshot} is weighed: its received weight is required."
                        : "Lot {$line->lot_number_snapshot} is counted in {$line->unit_code}: the received quantity is required, a weight cannot replace it.");
                }

                $rule = $this->rules->resolve((int) $p->company_id, (int) DB::table('waste_lots')->where('id', $line->waste_lot_id)->value('waste_type_id'), $line->planned_treatment_channel_id);
                $departure = $mass ? $line->departure_weight_kg : $line->departure_quantity;
                $reference = $departure === null ? null : (string) $departure;
                $result = Tolerance::evaluate($reference, $mass ? $kg : $quantity, $rule);
                // A weight that left as an estimate is no measure: the gap is shown as an indication, and the tolerance
                // is not said to be met. Someone looks at it, as for a lot that left with no weight at all.
                $estimated = $mass && $reference !== null && $line->weight_basis === 'estimated';
                if ($estimated) {
                    $result['status'] = Tolerance::NO_REFERENCE;
                }
                $line->forceFill([
                    'line_status' => 'received', 'received_weight_kg' => $kg, 'received_quantity' => $mass ? null : $quantity,
                    'weight_variance_kg' => $mass ? $result['difference'] : null, 'weight_variance_pct' => $mass ? $result['pct'] : null,
                    'quantity_variance' => $mass ? null : $result['difference'],
                    'variance_flagged' => Tolerance::needsReview($result['status']), 'tolerance_status' => $result['status'],
                    'tolerance_rule' => $rule + ['unit' => $line->unit_code ?? 'kg', 'reference_basis' => $estimated ? 'estimated' : 'measured', 'reference_quantity' => $reference, 'measured_quantity' => $mass ? $kg : $quantity,
                        'difference' => $result['difference'], 'pct' => $result['pct'], 'pct_abs' => $result['pct_abs']],
                    'received_at' => now(), 'received_by_user_id' => $actor->id,
                ])->save();
                $statuses[] = $result['status'];
                $total = bcadd($total, (string) ($kg ?? '0'), 3);
                $known = $known || $kg !== null;
                if ($mass) {
                    $weighed['received'] = bcadd($weighed['received'], (string) $kg, 3);
                    $weighed['lines']++;
                }
            }

            // The weighed lots as a whole, for the record. It decides something only when a weighed lot that left was
            // not received at all — each lot received was already judged by its own rule.
            $default = $this->rules->companyDefault((int) $p->company_id);
            // With a weighed lot that left unweighed, the total that left is not known: no percentage is given for the whole.
            $whole = Tolerance::evaluate($weighed['unknown'] ? null : $weighed['departure'], $weighed['received'], $default);
            if ($weighed['missing']) {
                $statuses[] = $whole['status'];
            }
            $status = Tolerance::worst($statuses);
            // No lot received with a weight = no received total; no weighed lot compared = no difference in kilograms.
            $p->forceFill(['received_weight_kg' => $known ? $total : null,
                'weight_variance_kg' => $weighed['unknown'] || $weighed['lines'] === 0 ? null : ($whole['difference'] ?? bcsub($weighed['received'], $weighed['departure'], 3)),
                'weight_variance_pct' => $whole['pct'], 'variance_threshold_pct' => $default['warning_pct'], 'tolerance_status' => $status,
                'variance_flagged' => Tolerance::needsReview($status), 'received_at' => now(), 'received_by_user_id' => $actor->id]);
            $p->save();
        });
    }

    /**
     * Before the pickup can be closed, a gap that was flagged is explained (M4-04). Above the blocking limit of the
     * rule an explanation is not enough: someone entitled to accept it must do so, and the reason is kept.
     */
    public function acknowledgeVariance(Pickup $pickup, string $comment, User $actor, bool $mayAuthorize = true): Pickup
    {
        return DB::transaction(function () use ($pickup, $comment, $actor, $mayAuthorize) {
            $pickup = Pickup::query()->whereKey($pickup->id)->lockForUpdate()->firstOrFail();
            if (! $pickup->variance_flagged || $pickup->status !== PickupStatus::Received) {
                throw new BusinessRuleViolation('NO_VARIANCE_TO_ACKNOWLEDGE', 'This pickup has no weight discrepancy waiting for acknowledgement.');
            }
            $blocking = $pickup->tolerance_status === Tolerance::BLOCKING;
            if ($blocking && ! $mayAuthorize) {
                throw new BusinessRuleViolation('VARIANCE_NEEDS_AUTHORIZATION', 'The gap is above the blocking limit: it must be accepted by someone authorised to.');
            }
            $pickup->forceFill(['variance_acknowledged_at' => now(), 'variance_acknowledged_by_user_id' => $actor->id, 'variance_comment' => $comment,
                'variance_decision' => $blocking ? 'authorized' : 'acknowledged'])->save();

            return $pickup;
        });
    }

    /**
     * M4-05: sale price or disposal cost per lot. `net_amount` is signed: positive = revenue, negative = cost.
     * Priced on the received weight when known (what the provider actually took), otherwise on the departure weight.
     *
     * @param  array<string, array{direction: string, pricing_mode: string, unit_price: string}>  $prices  lot number => price
     */
    public function price(Pickup $pickup, array $prices, User $actor): Pickup
    {
        return DB::transaction(function () use ($pickup, $prices) {
            $pickup = Pickup::query()->whereKey($pickup->id)->lockForUpdate()->firstOrFail();
            if (! in_array($pickup->status, [PickupStatus::Collected, PickupStatus::Received, PickupStatus::Completed], true)) {
                throw new BusinessRuleViolation('PICKUP_NOT_PRICEABLE', 'Prices are entered once the lots have left the site.');
            }

            foreach ($this->lines($pickup) as $line) {
                $price = $prices[$line->lot_number_snapshot] ?? null;
                if ($price === null || ! in_array($line->line_status, ['loaded', 'received', 'treated'], true)) {
                    continue;
                }

                $received = $line->received_weight_kg !== null;
                $known = $received ? $line->received_weight_kg : $line->departure_weight_kg;
                // A price per kilogram needs a weight that was measured or computed: an unknown one is not 0 kg, and an
                // estimate is not a measure. A flat amount needs nothing.
                if ($price['pricing_mode'] !== 'flat' && ($known === null || (! $received && $line->weight_basis === 'estimated'))) {
                    throw new BusinessRuleViolation('PRICE_NEEDS_WEIGHT', "Lot {$line->lot_number_snapshot} has no measured weight: weigh it to price it by weight, or enter a flat amount.");
                }
                $weight = (string) ($known ?? '0');
                $amount = match ($price['pricing_mode']) {
                    'per_kg' => bcmul($weight, $price['unit_price'], 3),
                    'per_tonne' => bcdiv(bcmul($weight, $price['unit_price'], 6), '1000', 3),
                    default => bcadd($price['unit_price'], '0', 3),
                };

                $line->forceFill([
                    'pricing_mode' => $price['pricing_mode'],
                    'unit_price' => $price['unit_price'],
                    'price_basis' => $price['pricing_mode'] === 'flat' ? 'flat' : ($received ? 'received' : 'departure'),
                    'net_amount' => $price['direction'] === 'cost' ? bcsub('0', $amount, 3) : $amount,
                    'currency_code' => $pickup->currency_code,
                ])->save();
            }

            return $pickup;
        });
    }

    /**
     * The provider declares what was done with each received lot (channel), which drives the recovery rate (M6-01).
     *
     * @param  array<string, int>  $channels  lot number => treatment channel id
     */
    public function treat(Pickup $pickup, array $channels, User $actor): Pickup
    {
        return DB::transaction(function () use ($pickup, $channels, $actor) {
            $pickup = Pickup::query()->whereKey($pickup->id)->lockForUpdate()->firstOrFail();
            if ($pickup->status !== PickupStatus::Received) {
                throw new BusinessRuleViolation('INVALID_PICKUP_TRANSITION', 'Treatment is declared on a received pickup.');
            }

            $this->context->runAs(Company::query()->findOrFail($pickup->company_id), function () use ($pickup, $channels, $actor) {
                foreach ($this->lines($pickup) as $line) {
                    if ($line->line_status !== 'received' || ! isset($channels[$line->lot_number_snapshot])) {
                        continue;
                    }
                    $line->forceFill(['line_status' => 'treated', 'actual_treatment_channel_id' => $channels[$line->lot_number_snapshot], 'treated_at' => now()])->save();
                    $this->lots->handle(WasteLot::query()->findOrFail($line->waste_lot_id), LotStatus::Treated, $actor);
                }
            });

            return $pickup;
        });
    }

    /**
     * Closure by the industrial: every received lot has a declared treatment and any discrepancy was acknowledged.
     */
    public function complete(Pickup $pickup, User $actor): Pickup
    {
        return $this->step($pickup, [PickupStatus::Received], PickupStatus::Completed, function (Pickup $p) use ($actor) {
            if ($p->variance_flagged && $p->variance_acknowledged_at === null) {
                throw new BusinessRuleViolation('VARIANCE_NOT_ACKNOWLEDGED', 'The weight discrepancy must be acknowledged before closing the pickup.');
            }

            foreach ($this->lines($p) as $line) {
                if ($line->line_status === 'received') {
                    throw new BusinessRuleViolation('TREATMENT_MISSING', "The treatment of lot {$line->lot_number_snapshot} has not been declared by the provider.");
                }
                if ($line->line_status === 'treated') {
                    $this->lots->handle(WasteLot::query()->findOrFail($line->waste_lot_id), LotStatus::Closed, $actor);
                }
                $line->forceFill(['is_active_flag' => null])->save();
            }
            $p->forceFill(['completed_at' => now(), 'completed_by_user_id' => $actor->id]);
        });
    }

    /**
     * @return array{string, string|null, bool} variance kg, variance % (null if no departure weight), flagged
     */
    public static function variance(string $departure, string $received, string $thresholdPct): array
    {
        $result = Tolerance::evaluate($departure, $received, ['warning_pct' => $thresholdPct]);

        return [bcsub($received, $departure, 3), $result['pct'], $result['status'] === Tolerance::WARNING];
    }

    /**
     * Blueprint §8.5: active partner, published provider, approved accreditation valid on the date (hazardous coverage if needed).
     */
    private function assertEligible(Pickup $pickup): void
    {
        $date = $pickup->confirmed_date ?? $pickup->requested_date;
        $hazardous = DB::table('pickup_lots')->where('pickup_id', $pickup->id)->where('is_hazardous', true)->exists();

        foreach (array_filter([$pickup->provider_company_id, $pickup->transporter_company_id]) as $providerId) {
            $partner = DB::table('provider_partnerships')->where('company_id', $pickup->company_id)
                ->where('provider_company_id', $providerId)->where('status', 'active')->exists();
            $published = DB::table('provider_profiles as p')->join('companies as c', 'c.id', '=', 'p.company_id')
                ->where('p.company_id', $providerId)->where('p.is_published', true)->where('c.status', 'active')->exists();
            $accredited = DB::table('provider_accreditations as a')->join('accreditation_types as t', 't.id', '=', 'a.accreditation_type_id')
                ->where('a.company_id', $providerId)->where('a.review_status', 'approved')->whereNull('a.deleted_at')
                ->whereDate('a.valid_from', '<=', $date)->whereDate('a.expires_on', '>=', $date)
                ->when($hazardous, fn ($q) => $q->where('t.covers_hazardous', true))->exists();

            if (! $partner || ! $published || ! $accredited) {
                throw new BusinessRuleViolation('PROVIDER_NOT_ELIGIBLE', 'The provider is not an active partner with a valid accreditation on the pickup date.');
            }
        }

        // A partner without an account is not reviewed by the platform: the company answers for it. It must still be
        // in use, have the right role, and — for hazardous waste — an authorisation recorded and valid on the date.
        $receiver = $pickup->external_provider_id === null ? null : ExternalPartner::query()->findOrFail($pickup->external_provider_id);
        $transporter = $pickup->external_transporter_id === null ? null : ExternalPartner::query()->findOrFail($pickup->external_transporter_id);
        if ($receiver !== null && ! $receiver->receives()) {
            throw new BusinessRuleViolation('PARTNER_ROLE_MISMATCH', "{$receiver->legal_name} is not recorded as a recycler or an eliminator.");
        }
        if ($transporter !== null && ! $transporter->is_transporter) {
            throw new BusinessRuleViolation('PARTNER_ROLE_MISMATCH', "{$transporter->legal_name} is not recorded as a transporter.");
        }
        if ($receiver !== null && ! $receiver->is_transporter && $transporter === null && $pickup->transporter_company_id === null) {
            throw new BusinessRuleViolation('TRANSPORTER_REQUIRED', "{$receiver->legal_name} does not transport waste: choose a transporter.");
        }
        foreach (array_filter([$receiver, $transporter]) as $partner) {
            if ($partner->linked_company_id !== null) {
                throw new BusinessRuleViolation('PARTNER_HAS_ACCOUNT', "{$partner->legal_name} now has an account on the platform: choose it among the providers of the platform.");
            }
            $expired = $partner->accreditation_expires_on !== null && $partner->accreditation_expires_on->lt($date);
            $missing = $hazardous && ($partner->accreditation_reference === null || ! $partner->covers_hazardous);
            if (! $partner->is_active || $expired || $missing) {
                throw new BusinessRuleViolation('PROVIDER_NOT_ELIGIBLE', "{$partner->legal_name} is not in use, or has no authorisation valid on the pickup date for this waste.");
            }
        }
    }

    /**
     * Hazardous waste leaves with its tracking slip, and a slip names the regulatory code of the waste: a hazardous lot
     * whose waste type has no code is not requested. The code is never guessed — it is completed on the waste type.
     */
    private function assertRegulatoryCodes(Pickup $pickup): void
    {
        $missing = DB::table('pickup_lots as pl')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->where('pl.pickup_id', $pickup->id)->where('pl.is_hazardous', true)->whereNull('pl.regulatory_waste_code_id')->orderBy('pl.id')
            ->get(['t.ulid', 't.code', 't.name', 'pl.lot_number_snapshot as lot_number'])->groupBy('ulid');
        if ($missing->isEmpty()) {
            return;
        }
        $types = $missing->map(fn (Collection $lots) => ['waste_type' => $lots->first()->ulid, 'code' => $lots->first()->code, 'name' => $lots->first()->name,
            'lots' => $lots->pluck('lot_number')->all()])->values()->all();
        throw new BusinessRuleViolation('REGULATORY_CODE_MISSING', implode('; ', array_map(fn (array $type) => "{$type['name']} ({$type['code']}) is hazardous and has no regulatory waste code — lots "
            .implode(', ', $type['lots']), $types)).'. Set the code on the waste type: its tracking slip cannot be issued without it.', ['waste_types' => $types]);
    }

    /**
     * @param  list<PickupStatus>  $from
     * @param  callable(Pickup): void  $apply
     */
    private function step(Pickup $pickup, array $from, PickupStatus $to, callable $apply): Pickup
    {
        return DB::transaction(function () use ($pickup, $from, $to, $apply) {
            $pickup = Pickup::query()->whereKey($pickup->id)->lockForUpdate()->firstOrFail();
            if (! in_array($pickup->status, $from, true)) {
                throw new BusinessRuleViolation('INVALID_PICKUP_TRANSITION', "Pickup cannot go from {$pickup->status->value} to {$to->value}.");
            }
            // Lot side effects belong to the owner company, also when a provider acts (party access).
            $this->context->runAs(Company::query()->findOrFail($pickup->company_id), fn () => $apply($pickup));
            $pickup->status = $to;
            $pickup->save();

            return $pickup;
        });
    }

    private function addLine(Pickup $pickup, WasteLot $lot, ?int $channelId): void
    {
        $code = $lot->regulatory_waste_code_id === null ? null : DB::table('regulatory_waste_codes')->where('id', $lot->regulatory_waste_code_id)->value('code');
        $type = DB::table('waste_types as t')->join('units as u', 'u.id', '=', 't.default_unit_id')->where('t.id', $lot->waste_type_id)
            ->first(['t.default_treatment_channel_id', 'u.code as unit', 'u.dimension']);
        $dimension = in_array($type->dimension, ['count', 'volume'], true) ? $type->dimension : 'mass';
        $lotUnit = $lot->unit_id === null ? null : DB::table('units')->where('id', $lot->unit_id)->value('code');
        // What the lot declares is a reference only in the dimension of its type: a count is never read as a weight.
        $declared = $dimension !== 'mass' && $lot->quantity !== null && UnitConversion::dimension($lotUnit) === $dimension;
        PickupLot::query()->create([
            'packaging_code' => $lot->packaging_type_id === null ? null : DB::table('packaging_types')->where('id', $lot->packaging_type_id)->value('code'),
            'package_count' => $lot->packaging_type_id === null ? null : ($lot->package_count ?? 1),
            'measure_dimension' => $dimension, 'unit_code' => $dimension === 'mass' ? 'kg' : ($declared ? $lotUnit : $type->unit),
            'declared_quantity' => $declared ? $lot->quantity : null,
            'planned_treatment_channel_id' => $channelId ?? $type->default_treatment_channel_id,
            'pickup_id' => $pickup->id, 'waste_lot_id' => $lot->id, 'provider_company_id' => $pickup->provider_company_id,
            'transporter_company_id' => $pickup->transporter_company_id, 'lot_number_snapshot' => $lot->lot_number,
            'qr_tag_value_snapshot' => DB::table('lot_tags')->where('waste_lot_id', $lot->id)->where('tag_type', 'qr')->where('is_active_flag', true)->value('tag_value'),
            'waste_type_label_snapshot' => DB::table('waste_types')->where('id', $lot->waste_type_id)->value('name'),
            'regulatory_waste_code_id' => $lot->regulatory_waste_code_id, 'regulatory_code_snapshot' => $code,
            'is_hazardous' => $lot->is_hazardous, 'declared_weight_kg' => $lot->net_weight_kg, 'weight_basis' => $lot->weight_basis ?? 'measured',
        ]);
    }

    private function releaseLots(Pickup $pickup, User $actor): void
    {
        foreach ($this->lines($pickup) as $line) {
            $lot = WasteLot::query()->findOrFail($line->waste_lot_id);
            if ($lot->status === LotStatus::AwaitingPickup) {
                $this->lots->handle($lot, LotStatus::Stored, $actor);
            }
            $line->forceFill(['is_active_flag' => null])->save();
        }
    }

    /**
     * Lines are read in the owner's context (provider contexts only see their own lines anyway).
     *
     * @return Collection<int, PickupLot>
     */
    private function lines(Pickup $pickup): Collection
    {
        return PickupLot::query()->where('pickup_id', $pickup->id)->orderBy('id')->get();
    }
}
