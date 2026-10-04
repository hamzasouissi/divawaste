<?php

namespace App\Modules\Documents\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Common\Models\StoredFile;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\DocumentType;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;
    use SoftDeletes;

    public const FROZEN_WHEN_ISSUED = [
        'document_type',
        'title',
        'reference_number',
        'is_regulatory',
        'site_id',
        'pickup_id',
        'period_start',
        'period_end',
        'stored_file_id',
        'checksum_sha256',
        'source_snapshot',
        'issued_at',
    ];

    protected $attributes = [
        'status' => 'draft',
        'is_regulatory' => false,
        'legal_hold' => false,
    ];

    protected $guarded = [
        'id',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'is_regulatory' => 'boolean',
            'period_start' => 'date',
            'period_end' => 'date',
            'source_snapshot' => 'array',
            'issued_at' => 'datetime',
            'retention_until' => 'datetime',
            'legal_hold' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Companies that may see this document through party scope.
     *
     * @return list<string>
     */
    public function partyColumns(): array
    {
        return ['shared_with_company_id'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            // A document still collecting signatures is not issued yet: its file is attached when the last party signs.
            if (
                ! in_array($document->getOriginal('status'), [DocumentStatus::Draft, DocumentStatus::PendingSignatures], true) &&
                $document->isDirty(self::FROZEN_WHEN_ISSUED)
            ) {
                throw new BusinessRuleViolation(
                    'DOCUMENT_IMMUTABLE',
                    'An issued document cannot be modified.'
                );
            }
        });

        static::forceDeleting(function (): void {
            throw new BusinessRuleViolation(
                'DOCUMENT_NOT_DELETABLE',
                'Documents cannot be physically deleted.'
            );
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function sharedWithCompany(): BelongsTo
    {
        return $this->belongsTo(
            Company::class,
            'shared_with_company_id'
        );
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function uploadedByCompany(): BelongsTo
    {
        return $this->belongsTo(
            Company::class,
            'uploaded_by_company_id'
        );
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Pickup, $this>
     */
    public function pickup(): BelongsTo
    {
        return $this->belongsTo(Pickup::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(
            StoredFile::class,
            'stored_file_id'
        );
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'superseded_by_document_id'
        );
    }

    /**
     * @return HasMany<DocumentSignature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class);
    }

    /**
     * @return HasMany<HazardousWasteManifest, $this>
     */
    public function hazardousWasteManifests(): HasMany
    {
        return $this->hasMany(HazardousWasteManifest::class, 'document_id');
    }

    /**
     * @return HasMany<AnnualDeclaration, $this>
     */
    public function annualDeclarations(): HasMany
    {
        return $this->hasMany(AnnualDeclaration::class, 'document_id');
    }

    /**
     * @return BelongsToMany<WasteLot>
     */
    public function wasteLots(): BelongsToMany
    {
        return $this->belongsToMany(
            WasteLot::class,
            'document_waste_lots',
            'document_id',
            'waste_lot_id'
        )->withPivot('company_id');
    }
}
