<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per plan period; the rows are the subscription history. is_current = 1 on exactly one row.
 */
class CompanySubscription extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = [
        'ulid', 'subscription_plan_id', 'status', 'billing_interval', 'payment_method', 'currency_code', 'sites_quantity',
        'trial_starts_at', 'trial_ends_at', 'starts_at', 'is_current', 'price_snapshot', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'cancel_at_period_end' => 'boolean',
            'is_current' => 'boolean',
            'price_snapshot' => 'array',
        ];
    }
}
