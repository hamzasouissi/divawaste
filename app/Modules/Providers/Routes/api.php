<?php

use App\Modules\Providers\Models\ProviderPartnership;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    // Published directory (cross-tenant read of public provider data only).
    Route::get('providers/directory', function (Request $request) {
        abort_unless($request->user()->can('providers.view'), 403);

        return ['data' => DB::table('provider_profiles as p')->join('companies as c', 'c.id', '=', 'p.company_id')
            ->where('p.is_published', true)->where('c.status', 'active')->orderBy('c.legal_name')
            ->get(['c.ulid', 'c.legal_name', 'p.is_collector', 'p.is_transporter', 'p.is_recycler', 'p.is_eliminator', 'p.service_area'])];
    })->name('providers.directory');

    Route::post('provider-partnerships', function (Request $request) {
        abort_unless($request->user()->can('providers.partnerships.manage'), 403);
        $ulid = strtoupper((string) $request->validate([
            'provider' => ['required', 'string', Rule::exists('companies', 'ulid')->where('company_type', CompanyType::Provider->value)->where('status', 'active')],
        ])['provider']);

        $partnership = ProviderPartnership::query()->firstOrCreate(
            ['provider_company_id' => Company::query()->where('ulid', $ulid)->value('id')],
            ['status' => 'active', 'started_at' => now(), 'created_by_user_id' => $request->user()->id],
        );

        return response()->json(['data' => ['ulid' => $partnership->ulid, 'provider' => $ulid, 'status' => $partnership->status]], 201);
    })->name('provider-partnerships.store');
});
