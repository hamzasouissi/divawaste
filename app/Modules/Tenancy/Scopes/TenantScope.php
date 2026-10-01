<?php

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters tenant-owned models by the current company. Fails closed without context.
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isSystem()) {
            return;
        }

        if (! $context->hasTenant()) {
            throw MissingTenantContextException::for($model::class);
        }

        // Party-shared tables (pickups…): a provider/transporter sees the rows addressed to it (blueprint §3.5).
        if ($context->companyType() !== CompanyType::Industrial && method_exists($model, 'partyColumns')) {
            $builder->where(function (Builder $query) use ($model, $context) {
                foreach ($model->partyColumns() as $column) {
                    $query->orWhere($model->qualifyColumn($column), $context->companyId());
                }
            });

            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $context->companyId());
    }
}
