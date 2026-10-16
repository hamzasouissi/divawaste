<?php

namespace App\Modules\Sites\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Sites\Enums\SiteKind;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property string $ulid
 */
class Site extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;
    use SoftDeletes;

    protected $attributes = [
        'site_kind' => 'production',
        'is_active' => true,
    ];

    protected $fillable = [
        'ulid', 'code', 'name', 'site_kind', 'address_line1', 'address_line2', 'city', 'postal_code', 'region',
        'country_id', 'latitude', 'longitude', 'regulatory_identifier', 'timezone',
        'activated_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'site_kind' => SiteKind::class,
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

            // Every site starts with its waste storage area so lots can be created at once (M1-01, M2-01).
            $type = DB::table('zone_types')->where('code', 'waste_storage')->first(['id', 'name']);

            $site->zones()->create([
                'ulid' => strtoupper((string) Str::ulid()),
                'zone_type_id' => (int) $type->id,
                'code' => 'STOCK',
                'name' => (string) (json_decode((string) $type->name, true)['fr'] ?? 'Aire de stockage déchets'),
                'is_waste_storage' => true,
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
