<?php

namespace App\Modules\Documents\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorisation to have a waste type leave the site (declaration signed by customs): a period and an allowed quantity.
 * Pickups of that waste type draw on it; nothing may leave without one that still has room.
 *
 * @property string $ulid
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $valid_until
 * @property CarbonImmutable|null $issued_on
 */
class WasteAuthorization extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = [
        'ulid', 'waste_type_id', 'reference', 'issued_on', 'valid_from', 'valid_until', 'max_kg', 'max_lots', 'alert_pct',
        'stored_file_id', 'notes', 'is_active', 'created_by_user_id',
    ];

    protected $attributes = ['alert_pct' => 80, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date', 'valid_from' => 'date', 'valid_until' => 'date', 'max_kg' => 'decimal:3', 'max_lots' => 'integer',
            'alert_pct' => 'integer', 'is_active' => 'boolean',
        ];
    }
}
