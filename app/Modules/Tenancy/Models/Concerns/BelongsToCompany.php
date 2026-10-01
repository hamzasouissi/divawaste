<?php

namespace App\Modules\Tenancy\Models\Concerns;

use App\Modules\Tenancy\Exceptions\CrossTenantWriteException;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every TENANT table (company_id NOT NULL): global scope, company_id stamping, immutable tenant.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if ($context->isSystem()) {
                $model->getAttribute('company_id') ?? throw MissingTenantContextException::for($model::class);

                return;
            }

            $companyId = $context->companyId();

            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', $companyId);
            } elseif ((int) $model->getAttribute('company_id') !== $companyId) {
                throw CrossTenantWriteException::for($model::class);
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('company_id')) {
                throw CrossTenantWriteException::for($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
