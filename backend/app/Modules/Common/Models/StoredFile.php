<?php

namespace App\Modules\Common\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $ulid
 */
class StoredFile extends Model
{
    use HasPublicUlid;
    use SoftDeletes;

    protected $table = 'stored_files';

    protected $fillable = [
        'ulid',
        'company_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum_sha256',
        'purpose',
        'uploaded_by_user_id',
        'uploaded_by_company_id',
        'scan_status',
        'scanned_at',
        'retention_until',
        'legal_hold',
        'deleted_by_user_id',
        'deletion_reason',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'scan_status' => 'string',
        'scanned_at' => 'datetime',
        'retention_until' => 'date',
        'legal_hold' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class,
            'company_id'
        );
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by_user_id'
        );
    }

    public function uploadedByCompany(): BelongsTo
    {
        return $this->belongsTo(
            Company::class,
            'uploaded_by_company_id'
        );
    }

    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'deleted_by_user_id'
        );
    }
}
