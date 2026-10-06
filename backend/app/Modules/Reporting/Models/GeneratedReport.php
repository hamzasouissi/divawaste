<?php

namespace App\Modules\Reporting\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Common\Models\StoredFile;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $ulid
 * @property CarbonImmutable|null $created_at
 */
class GeneratedReport extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $table = 'generated_reports';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => 'string',
            'format' => 'string',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
