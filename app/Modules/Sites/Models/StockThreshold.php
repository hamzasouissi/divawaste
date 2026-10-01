<?php

namespace App\Modules\Sites\Models;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Sites\Enums\AlertLevel;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Storage limit for a site, optionally narrowed to a zone and/or a waste type (M2-07).
 * alert_level/alerted_at hold the current alert state (merged stock_alerts).
 */
class StockThreshold extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $attributes = ['warning_pct' => 90, 'is_active' => true];

    protected $fillable = ['ulid', 'site_id', 'zone_id', 'waste_type_id', 'max_quantity_kg', 'warning_pct', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'max_quantity_kg' => 'decimal:3',
            'warning_pct' => 'integer',
            'alert_level' => AlertLevel::class,
            'alerted_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
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
}
