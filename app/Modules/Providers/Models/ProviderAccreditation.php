<?php

namespace App\Modules\Providers\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * "Expired" is computed from dates, never stored (blueprint §8.1).
 */
class ProviderAccreditation extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;
    use SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'expires_on' => 'date', 'issued_on' => 'date', 'reviewed_at' => 'datetime'];
    }
}
