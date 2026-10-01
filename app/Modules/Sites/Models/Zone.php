<?php

namespace App\Modules\Sites\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Zone extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;
    use SoftDeletes;

    protected $attributes = [
        'capacity_alert_pct' => 90,
        'is_waste_storage' => false,
        'is_active' => true,
    ];

    protected $fillable = [
        'ulid', 'site_id', 'zone_type_id', 'code', 'name', 'capacity_kg', 'capacity_m3', 'capacity_alert_pct', 'is_waste_storage',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'decimal:3',
            'capacity_m3' => 'decimal:3',
            'capacity_alert_pct' => 'integer',
            'is_waste_storage' => 'boolean',
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
}
