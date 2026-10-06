<?php

namespace App\Modules\Documents\Models;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Common\Models\StoredFile;
use App\Modules\Documents\Enums\SignatureStatus;
use App\Modules\Documents\Enums\SignerParty;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $ulid
 */
class DocumentSignature extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $table = 'document_signatures';

    protected $guarded = [
        'id',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'signer_party' => SignerParty::class,
            'status' => SignatureStatus::class,
            'signed_at' => 'datetime',
        ];
    }

    /**
     * Companies allowed to see this signature.
     *
     * @return list<string>
     */
    public function partyColumns(): array
    {
        return ['signer_company_id'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $signature): void {
            if (
                $signature->getOriginal('status') === SignatureStatus::Signed &&
                $signature->isDirty()
            ) {
                throw new BusinessRuleViolation(
                    'SIGNATURE_IMMUTABLE',
                    'A signed signature cannot be modified.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function signerCompany(): BelongsTo
    {
        return $this->belongsTo(
            Company::class,
            'signer_company_id'
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signerUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'signer_user_id'
        );
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function signatureImage(): BelongsTo
    {
        return $this->belongsTo(
            StoredFile::class,
            'signature_image_stored_file_id'
        );
    }
}
