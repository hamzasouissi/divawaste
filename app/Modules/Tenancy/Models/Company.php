<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Enums\CompanyStatus;
use App\Modules\Tenancy\Enums\CompanyType;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant root. Not tenant-scoped itself: access is resolved through memberships.
 */
#[UseFactory(CompanyFactory::class)]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasPublicUlid;

    protected $attributes = [
        'status' => 'pending_verification',
        'default_locale' => 'fr',
    ];

    protected $fillable = [
        'ulid', 'company_type', 'legal_name', 'trade_name', 'tax_id', 'vat_number', 'trade_register_number',
        'country_id', 'currency_code', 'default_locale', 'timezone', 'address_line1', 'address_line2', 'city',
        'postal_code', 'region', 'phone', 'email', 'billing_email', 'website',
    ];

    protected function casts(): array
    {
        return [
            'company_type' => CompanyType::class,
            'status' => CompanyStatus::class,
            'settings' => 'array',
            'onboarding_state' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'suspended_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CompanyUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }
}
