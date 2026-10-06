<?php

namespace App\Modules\Audit\Models;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'actor_user_id' => 'integer',
            'actor_company_id' => 'integer',
            'impersonator_user_id' => 'integer',
            'auditable_id' => 'integer',
            'parent_id' => 'integer',
            'device_id' => 'integer',
            'old_values' => 'array',
            'new_values' => 'array',
            'tags' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
