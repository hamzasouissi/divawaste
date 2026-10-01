<?php

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Resources\CompanyResource;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->get('company', fn (TenantContext $context) => new CompanyResource(
    Company::query()
        ->select(['id', 'ulid', 'company_type', 'status', 'legal_name', 'trade_name', 'tax_id', 'currency_code', 'timezone'])
        ->findOrFail($context->companyId())
))->name('company.show');
