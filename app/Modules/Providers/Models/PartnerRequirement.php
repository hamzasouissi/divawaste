<?php

namespace App\Modules\Providers\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a partner without an account asks of a collection, written down by the company that works with it. It is the
 * company's own record — never confirmed by the partner — and every creation, change and removal is in the audit trail.
 *
 * @property string $ulid
 * @property CarbonImmutable|null $reviewed_at
 */
class PartnerRequirement extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = [
        'ulid', 'external_partner_id', 'waste_family_id', 'treatment_channel_id', 'min_quantity', 'min_unit_code', 'min_packaging_type_id',
        'required_packaging_type_id', 'is_blocking', 'contract_reference', 'notes', 'reviewed_at', 'reviewed_by_user_id', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected $attributes = ['is_blocking' => false];

    protected function casts(): array
    {
        return ['min_quantity' => 'decimal:3', 'is_blocking' => 'boolean', 'reviewed_at' => 'datetime'];
    }
}
