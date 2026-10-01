<?php

namespace App\Modules\Providers\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Industrial ↔ provider relationship (company_id = industrial). Pickups only go to active partners.
 */
class ProviderPartnership extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'provider_company_id', 'status', 'internal_reference', 'notes', 'started_at', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
