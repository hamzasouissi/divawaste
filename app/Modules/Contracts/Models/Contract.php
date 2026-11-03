<?php

namespace App\Modules\Contracts\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What an industrial company agreed with a receiver or a transporter: prices per waste type, fees, how many pickups
 * and when, for how long. Owned by the company; a provider of the platform reads the contracts it is a party to.
 *
 * @property string $ulid
 * @property CarbonInterface $starts_on
 * @property CarbonInterface|null $ends_on
 */
class Contract extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    public const FREQUENCIES = ['on_demand', 'weekly', 'biweekly', 'monthly', 'quarterly'];

    protected $guarded = ['id', 'company_id'];

    protected $attributes = ['status' => 'draft', 'frequency' => 'on_demand', 'pickup_fee_direction' => 'cost', 'monthly_fee_direction' => 'cost'];

    /**
     * @return list<string>
     */
    public function partyColumns(): array
    {
        return ['provider_company_id'];
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'weekdays' => 'array', 'activated_at' => 'datetime', 'terminated_at' => 'datetime',
            'ending_notified_at' => 'datetime', 'pickups_per_period' => 'integer', 'planned_pickups' => 'integer', 'payment_terms_days' => 'integer'];
    }

    /**
     * @return HasMany<ContractPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ContractPrice::class);
    }

    /**
     * Where the contract stands today: a draft, active, not started yet, past its end, or terminated.
     *
     * @return 'draft'|'upcoming'|'active'|'expired'|'terminated'
     */
    public function state(?CarbonInterface $on = null): string
    {
        $on ??= Carbon::today();

        return match (true) {
            $this->status === 'draft' => 'draft',
            $this->status === 'terminated' => 'terminated',
            $this->starts_on->isAfter($on) => 'upcoming',
            $this->ends_on !== null && $this->ends_on->isBefore($on) => 'expired',
            default => 'active',
        };
    }
}
