<?php

namespace App\Modules\Pickups\Support;

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
    }

    public function cancelled(Pickup $pickup): void
    {
        $this->toProvider($pickup, 'provider.pickups.view', 'pickup.cancelled', ['reason' => $pickup->cancellation_reason]);
    }

    public function collected(Pickup $pickup): void
    {
        $this->toProvider($pickup, 'provider.pickups.receive', 'pickup.collected', $this->measure($pickup, 'departure', 'quantity'));
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
        $names = DB::table('companies')->whereIn('id', array_filter([$pickup->company_id, $pickup->provider_company_id]))->pluck('legal_name', 'id');
        $provider = $pickup->provider_company_id !== null ? $names[$pickup->provider_company_id]
            : DB::table('external_partners')->where('id', $pickup->external_provider_id)->value('legal_name');

        return ['number' => $pickup->pickup_number, 'company' => (string) $names[$pickup->company_id], 'provider' => (string) $provider];
    }
}
