<?php

namespace App\Modules\Waste\Models;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use App\Modules\Waste\Enums\LotStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Current state of a lot. History lives in waste_lot_events / lineage. Never physically deleted (cahier §4).
 */
class WasteLot extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;
    use SoftDeletes;

    protected $attributes = ['origin_type' => 'created', 'status' => 'created', 'version' => 1];

    protected $fillable = [
        'ulid', 'lot_number', 'site_id', 'zone_id', 'waste_type_id', 'packaging_type_id', 'origin_type', 'is_hazardous',
        'regulatory_waste_code_id', 'gross_weight_kg', 'tare_weight_kg', 'net_weight_kg', 'quantity', 'unit_id',
        'color_family_id', 'color_label', 'grammage_gsm', 'composition_label', 'extra_attributes', 'notes', 'generated_at',
        'created_by_user_id', 'created_device_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => LotStatus::class,
            'is_hazardous' => 'boolean',
            'gross_weight_kg' => 'decimal:3',
            'tare_weight_kg' => 'decimal:3',
            'net_weight_kg' => 'decimal:3',
            'quantity' => 'decimal:3',
            'grammage_gsm' => 'decimal:2',
            'extra_attributes' => 'array',
            'generated_at' => 'datetime',
            'stored_at' => 'datetime',
            'awaiting_pickup_at' => 'datetime',
            'collected_at' => 'datetime',
            'treated_at' => 'datetime',
            'closed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Optimistic-lock / sync base version.
        static::updating(fn (self $lot) => $lot->version = $lot->version + 1);
        static::forceDeleting(fn () => throw new BusinessRuleViolation('LOT_NOT_DELETABLE', 'Lots are never physically deleted.'));
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * @return BelongsTo<WasteType, $this>
     */
    public function wasteType(): BelongsTo
    {
        return $this->belongsTo(WasteType::class);
    }

    /**
     * @return HasMany<WasteLotComposition, $this>
     */
    public function compositions(): HasMany
    {
        return $this->hasMany(WasteLotComposition::class);
    }

    /**
     * @return HasMany<LotTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(LotTag::class);
    }

    /**
     * @return HasMany<WasteLotEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(WasteLotEvent::class);
    }
}
