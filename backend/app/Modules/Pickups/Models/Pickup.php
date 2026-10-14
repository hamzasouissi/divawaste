<?php

namespace App\Modules\Pickups\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Enlèvement owned by the industrial; visible to its provider/transporter through partyColumns().
 *
 * @property string $ulid
 * @property string|null $submitted_at
 * @property string|null $confirmed_at
 * @property string|null $refused_at
 * @property CarbonImmutable|null $collected_at
 * @property CarbonImmutable|null $received_at
 * @property string|null $completed_at
 * @property string|null $cancelled_at
 */
class Pickup extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $guarded = ['id', 'company_id'];

    protected $attributes = ['status' => 'draft', 'version' => 1, 'variance_flagged' => false];

    /**
     * @return list<string>
     */
    public function partyColumns(): array
    {
        return ['provider_company_id', 'transporter_company_id'];
    }

    protected function casts(): array
    {
        return [
            'status' => PickupStatus::class, 'requested_date' => 'date', 'confirmed_date' => 'date', 'variance_flagged' => 'boolean',
            'departure_weight_kg' => 'decimal:3', 'received_weight_kg' => 'decimal:3', 'weight_variance_kg' => 'decimal:3',
            'weight_variance_pct' => 'decimal:2', 'collected_at' => 'datetime', 'received_at' => 'datetime', 'provider_requirements' => 'array',
            'loading_requirements' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $pickup) => $pickup->version = $pickup->version + 1);
    }

    /**
     * @return HasMany<PickupLot, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PickupLot::class);
    }
}
