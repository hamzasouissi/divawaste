<?php

namespace App\Modules\Providers\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Provider-owned (company_id = provider), published to industrials through the directory.
 */
class ProviderProfile extends Model
{
    use BelongsToCompany;

    protected $table = 'provider_profiles';

    protected $guarded = ['id', 'company_id'];
}
