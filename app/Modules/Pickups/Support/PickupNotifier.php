<?php

namespace App\Modules\Pickups\Support;

use App\Modules\Common\Support\Constraint;
use App\Modules\Common\Support\QuantityText;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * M4-03 / M7-01: each step of a pickup tells the other party (email + in-app space).
 */
final class PickupNotifier
{
    public function __construct(private readonly NotifyUsers $notify, private readonly TenantContext $context) {}

    public function requested(Pickup $pickup): void
    {

        $this->toProvider($pickup, 'provider.pickups.respond', 'pickup.requested', [
            'date' => $pickup->requested_date->format('d/m/Y'),
            'site' => DB::table('sites')->where('id', $pickup->site_id)->value('name'),
            'lots' => DB::table('pickup_lots')->where('pickup_id', $pickup->id)->count(),
        ] + $this->measure($pickup, 'declared', 'quantity'));
    }

    public function confirmed(Pickup $pickup): void
    {
        $this->toOwner($pickup, 'pickup.confirmed', ['date' => $pickup->confirmed_date->format('d/m/Y')]);
    }

    public function refused(Pickup $pickup): void
    {
        $this->toOwner($pickup, 'pickup.refused', ['reason' => $pickup->refusal_reason]);
        $this->toTransporter($pickup, 'pickup.refused', ['reason' => $pickup->refusal_reason]);
    }

    /**
     * The receiver set the date: the transporter is asked to confirm it, and the company is told that the request waits
     * for it (unless the company answered itself for a receiver without an account).
     */
    public function awaitingTransporter(Pickup $pickup, bool $onBehalf): void
    {
        $params = ['date' => $pickup->confirmed_date->format('d/m/Y')];
        $this->toTransporter($pickup, 'pickup.transport_requested', $params);
        $onBehalf || $this->toOwner($pickup, 'pickup.awaiting_transporter', $params);
    }

    public function transportConfirmed(Pickup $pickup): void
    {
        $params = ['date' => $pickup->confirmed_date->format('d/m/Y')];
        $this->toOwner($pickup, 'pickup.transport_confirmed', $params);
        $this->toReceiver($pickup, 'pickup.transport_confirmed', $params);
    }

    public function transportRefused(Pickup $pickup): void
    {
        $params = ['reason' => $pickup->refusal_reason];
        $this->toOwner($pickup, 'pickup.transport_refused', $params);
        $this->toReceiver($pickup, 'pickup.transport_refused', $params);
    }

    public function cancelled(Pickup $pickup): void
    {
        $this->toProvider($pickup, 'provider.pickups.view', 'pickup.cancelled', ['reason' => $pickup->cancellation_reason]);
    }

    /**
     * What left. When the receiver asks something of a collection and it is no longer met — or can no longer be
     * verified — on what left, the receiver is told that instead, with the requirements and their figures; the people
     * who may send a pickup against a blocking requirement are asked to explain the exception. One loading, one message.
     */
    public function collected(Pickup $pickup): void
    {
        $quantity = $this->measure($pickup, 'departure', 'quantity');
        $loading = $pickup->loading_requirements;
        $unmet = array_values(array_filter($loading['constraints'] ?? [], fn (array $constraint) => in_array($constraint['key'], $loading['unmet'] ?? [], true)));
        if ($unmet === []) {
            $this->toProvider($pickup, 'provider.pickups.receive', 'pickup.collected', $quantity);

            return;
        }
        $params = $this->names($pickup) + $quantity + $this->requirements($unmet) + ['left' => count($loading['left_behind'] ?? [])];
        if ($pickup->provider_company_id !== null) {
            $this->notify->toCompanyPermission((int) $pickup->provider_company_id, 'provider.pickups.receive', 'pickup.requirements_unmet_at_loading', $params, '/provider/pickups/'.$pickup->ulid);
        }
        if ($pickup->transporter_company_id !== null && $pickup->transporter_company_id !== $pickup->provider_company_id) {
            $this->notify->toCompanyPermission((int) $pickup->transporter_company_id, 'provider.pickups.receive', 'pickup.collected', $this->names($pickup) + $quantity, '/provider/pickups/'.$pickup->ulid);
        }
        if (($loading['exception'] ?? null) !== null) {
            $this->context->runAs(Company::query()->findOrFail($pickup->company_id), fn () => $this->notify
                ->toSitePermission($pickup->site_id, 'pickups.override_provider_requirements', 'pickup.requirement_exception', $params, '/pickups/'.$pickup->ulid));
        }
    }

    /**
     * The requirements not met on what left, in words and in their units, for each language:
     * "minimum 2 t : 1,66 t chargée(s), il manque 0,34 t" / "minimum 2 t: 1.66 t loaded, 0.34 t short".
     *
     * @param  list<array<string, mixed>>  $unmet
     * @return array<string, string|null>
     */
    private function requirements(array $unmet): array
    {
        return QuantityText::localized('requirements', function (string $locale) use ($unmet): string {
            $fr = $locale === 'fr';

            return implode('; ', array_map(function (array $constraint) use ($fr, $locale): string {
                if ($constraint['kind'] === 'packaging') {
                    $packaging = QuantityText::packaging((string) $constraint['packaging_type'], $locale);

                    return $fr ? "{$packaging} demandé : {$constraint['outside_count']} lot(s) dans un autre conditionnement"
                        : "{$packaging} asked for: {$constraint['outside_count']} lot(s) in another packaging";
                }
                $packaging = $constraint['packaging_type'] ?? null;
                $minimum = 'minimum '.QuantityText::amount($constraint['min'], (string) $constraint['unit'], $packaging, $locale);
                $loaded = QuantityText::amount($constraint['used'], (string) $constraint['unit'], $packaging, $locale);
                if ($constraint['outcome'] === Constraint::UNVERIFIABLE) {
                    return $fr ? "{$minimum} : {$loaded} chargé(s), {$constraint['missing_count']} lot(s) sans quantité mesurée"
                        : "{$minimum}: {$loaded} loaded, {$constraint['missing_count']} lot(s) with no measured quantity";
                }
                $short = QuantityText::amount($constraint['shortfall'], (string) $constraint['unit'], $packaging, $locale);

                return $fr ? "{$minimum} : {$loaded} chargé(s), il manque {$short}" : "{$minimum}: {$loaded} loaded, {$short} short";
            }, $unmet));
        });
    }

    public function received(Pickup $pickup): void
    {
        $this->toOwner($pickup, 'pickup.received', $this->measure($pickup, 'received', 'quantity'));

        if ($pickup->variance_flagged) {
            // Each lot was judged in its own unit: the message says how many are outside their tolerance, not one
            // percentage for a pickup that may mix kilograms, pieces and litres.
            $this->toOwner($pickup, 'pickup.variance_flagged', [
                'flagged' => DB::table('pickup_lots')->where('pickup_id', $pickup->id)->where('variance_flagged', true)->count(),
            ] + $this->measure($pickup, 'received', 'received') + $this->measure($pickup, 'departure', 'departure'));
        }
    }

    /**
     * What the pickup carries at a stage, in the units of its lots and for each language: "1 918 kg",
     * "1,2 m³ + 150 l", "298 pièces", "320 kg + 1 lot(s) sans quantité mesurée". A lot nobody measured is counted
     * beside the figures, never inside them as a zero.
     *
     * @param  'declared'|'departure'|'received'  $stage
     * @return array<string, string|null>
     */
    private function measure(Pickup $pickup, string $stage, string $param): array
    {
        $lines = DB::table('pickup_lots')->where('pickup_id', $pickup->id)
            ->when($stage === 'departure', fn ($query) => $query->whereNotNull('loaded_at'))
            ->when($stage === 'received', fn ($query) => $query->whereNotNull('received_at'))
            ->get(['measure_dimension', 'unit_code', "{$stage}_weight_kg as kg", "{$stage}_quantity as quantity"]);
        $sums = [];
        $missing = 0;
        foreach ($lines as $line) {
            $mass = ($line->measure_dimension ?? 'mass') === 'mass';
            $value = $mass ? $line->kg : $line->quantity;
            if ($value === null) {
                $missing++;

                continue;
            }
            $unit = $mass ? 'kg' : (string) $line->unit_code;
            $sums[$unit] = bcadd($sums[$unit] ?? '0', (string) $value, 3);
        }

        return QuantityText::localized($param, function (string $locale) use ($sums, $missing): string {
            $parts = array_map(fn (string $unit) => QuantityText::amount($sums[$unit], $unit, null, $locale), array_keys($sums));
            if ($missing > 0) {
                $parts[] = $locale === 'fr' ? "{$missing} lot(s) sans quantité mesurée" : "{$missing} lot(s) with no measured quantity";
            }

            return implode(' + ', $parts);
        });
    }

    public function completed(Pickup $pickup): void
    {
        $this->toProvider($pickup, 'provider.pickups.view', 'pickup.completed', []);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function toProvider(Pickup $pickup, string $permission, string $type, array $params): void
    {
        foreach (array_unique(array_filter([$pickup->provider_company_id, $pickup->transporter_company_id])) as $companyId) {
            $this->notify->toCompanyPermission((int) $companyId, $permission, $type, $this->names($pickup) + $params, '/provider/pickups/'.$pickup->ulid);
        }
    }

    /**
     * A transporter of the platform other than the receiver; nobody when the receiver carries the waste itself.
     *
     * @param  array<string, scalar|null>  $params
     */
    private function toTransporter(Pickup $pickup, string $type, array $params): void
    {
        if ($pickup->transporterConfirms()) {
            $this->notify->toCompanyPermission((int) $pickup->transporter_company_id, 'provider.pickups.respond', $type, $this->names($pickup) + $params, '/provider/pickups/'.$pickup->ulid);
        }
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function toReceiver(Pickup $pickup, string $type, array $params): void
    {
        if ($pickup->provider_company_id !== null) {
            $this->notify->toCompanyPermission((int) $pickup->provider_company_id, 'provider.pickups.view', $type, $this->names($pickup) + $params, '/provider/pickups/'.$pickup->ulid);
        }
    }

    /**
     * People of the industrial who follow pickups on the pickup's site.
     *
     * @param  array<string, scalar|null>  $params
     */
    private function toOwner(Pickup $pickup, string $type, array $params): void
    {
        $this->context->runAs(Company::query()->findOrFail($pickup->company_id), fn () => $this->notify
            ->toSitePermission($pickup->site_id, 'pickups.view', $type, $this->names($pickup) + $params, '/pickups/'.$pickup->ulid));
    }

    /**
     * @return array<string, string>
     */
    private function names(Pickup $pickup): array
    {
        $names = DB::table('companies')->whereIn('id', array_filter([$pickup->company_id, $pickup->provider_company_id, $pickup->transporter_company_id]))->pluck('legal_name', 'id');
        $provider = $pickup->provider_company_id !== null ? $names[$pickup->provider_company_id]
            : DB::table('external_partners')->where('id', $pickup->external_provider_id)->value('legal_name');
        $transporter = match (true) {
            $pickup->transporter_company_id !== null => $names[$pickup->transporter_company_id],
            $pickup->external_transporter_id !== null => DB::table('external_partners')->where('id', $pickup->external_transporter_id)->value('legal_name'),
            default => $provider,
        };

        return ['number' => $pickup->pickup_number, 'company' => (string) $names[$pickup->company_id], 'provider' => (string) $provider, 'transporter' => (string) $transporter];
    }
}
