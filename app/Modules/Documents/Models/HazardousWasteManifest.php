<?php

namespace App\Modules\Documents\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Documents\Enums\HazardousWasteManifestStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $ulid
 * @property CarbonImmutable|null $created_at
 */
class HazardousWasteManifest extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $table = 'hazardous_waste_manifests';

    protected $fillable = [
        'company_id',
        'manifest_number',
        'pickup_id',
        'site_id',
        'document_id',
        'status',
        'regulatory_waste_code_id',
        'is_hazardous',
        'waste_type_id',
        'packaging_type_id',
        'treatment_channel_id',
        'package_count',
        'declared_weight_kg',
        'received_weight_kg',
        'producer_snapshot',
        'transporter_snapshot',
        'receiver_snapshot',
        'transporter_company_id',
        'receiver_company_id',
        'collected_at',
        'received_at',
        'ulid',
    ];

    protected function casts(): array
    {
        return [
            'status' => HazardousWasteManifestStatus::class,
            'declared_weight_kg' => 'decimal:3',
            'received_weight_kg' => 'decimal:3',
            'package_count' => 'integer',
            'is_hazardous' => 'boolean',
            'producer_snapshot' => 'array',
            'transporter_snapshot' => 'array',
            'receiver_snapshot' => 'array',
            'collected_at' => 'datetime',
            'received_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function partyColumns(): array
    {
        return ['transporter_company_id', 'receiver_company_id'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Pickup, $this>
     */
    public function pickup(): BelongsTo
    {
        return $this->belongsTo(Pickup::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<WasteType, $this>
     */
    public function wasteType(): BelongsTo
    {
        return $this->belongsTo(WasteType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
