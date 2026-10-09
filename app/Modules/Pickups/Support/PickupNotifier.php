<?php

namespace App\Modules\Pickups\Support;

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
        $lines = DB::table('pickup_lots')->where('pickup_id', $pickup->id)->selectRaw('COUNT(*) as lots, COALESCE(SUM(declared_weight_kg), 0) as kg')->first();

        $this->toProvider($pickup, 'provider.pickups.respond', 'pickup.requested', [
            'date' => $pickup->requested_date->format('d/m/Y'),
            'site' => DB::table('sites')->where('id', $pickup->site_id)->value('name'),
            'lots' => (int) $lines->lots,
            'weight_kg' => (string) $lines->kg,
        ]);
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
        $this->toProvider($pickup, 'provider.pickups.receive', 'pickup.collected', ['weight_kg' => (string) $pickup->departure_weight_kg]);
    }

    public function received(Pickup $pickup): void
    {
        $this->toOwner($pickup, 'pickup.received', ['weight_kg' => (string) $pickup->received_weight_kg]);

        if ($pickup->variance_flagged) {
            $this->toOwner($pickup, 'pickup.variance_flagged', [
                'received_kg' => (string) $pickup->received_weight_kg,
                'departure_kg' => (string) $pickup->departure_weight_kg,
                'variance_pct' => (string) $pickup->weight_variance_pct,
            ]);
        }
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
