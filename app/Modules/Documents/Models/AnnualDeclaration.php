<?php

namespace App\Modules\Documents\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Documents\Enums\AnnualDeclarationStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnualDeclaration extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $table = 'annual_declarations';

    protected $fillable = [
        'company_id',
        'site_id',
        'format_code',
        'reporting_year',
        'status',
        'data',
        'computed_at',
        'validated_by_user_id',
        'validated_at',
        'submitted_at',
        'submission_reference',
        'document_id',
        'ulid',
    ];

    protected function casts(): array
    {
        return [
            'status' => AnnualDeclarationStatus::class,
            'reporting_year' => 'integer',
            'data' => 'array',
            'computed_at' => 'datetime',
            'validated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }
}
