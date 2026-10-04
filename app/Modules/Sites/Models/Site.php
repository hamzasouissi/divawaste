<?php

namespace App\Modules\Sites\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Sites\Enums\SiteKind;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Site extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;
    use SoftDeletes;

    protected $attributes = [
        'site_kind' => 'production',
        'is_active' => true,
    ];

    protected $fillable = [
        'ulid', 'code', 'name', 'site_kind', 'address_line1', 'address_line2', 'city', 'postal_code', 'region',
        'country_id', 'latitude', 'longitude', 'regulatory_identifier', 'timezone', 'capacity_kg',
        'activated_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'site_kind' => SiteKind::class,
            'capacity_kg' => 'decimal:3',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $site): void {
            if ($site->zones()->exists()) {
                return;
            }

            $zoneTypeId = (int) DB::table('zone_types')->where('code', 'waste_storage')->value('id');

            $site->zones()->create([
                'ulid' => strtoupper((string) Str::ulid()),
                'zone_type_id' => $zoneTypeId,
                'code' => 'DEFAULT',
                'name' => 'Default Zone',
                'capacity_kg' => 0,
                'capacity_m3' => 0,
                'capacity_alert_pct' => 90,
                'is_waste_storage' => true,
                'is_active' => true,
            ]);
        });
    }

    /**
     * @return HasMany<Zone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }
}
