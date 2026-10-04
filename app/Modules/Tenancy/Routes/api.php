<?php

use App\Modules\Common\Models\StoredFile;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Identity\Support\LegalAcceptance;
use App\Modules\Tenancy\Actions\RegisterCompany;
use App\Modules\Tenancy\Actions\ReviewCompany;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Requests\RegisterCompanyRequest;
use App\Modules\Tenancy\Resources\CompanyResource;
use App\Modules\Tenancy\Support\Onboarding;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/*
 * Public registration (M0-01, M0-03, M0-05, M0-10).
 */
Route::get('registration/options', function () {
    $json = fn ($rows) => $rows->map(fn ($row) => ['name' => json_decode((string) $row->name, true)] + (array) $row)->values();

    return response()->json(['data' => [
        'countries' => DB::table('countries')->where('is_signup_enabled', true)->where('is_active', true)->orderBy('iso2')
            ->get(['iso2', 'name', 'currency_code', 'phone_prefix', 'tax_id_label'])
            ->map(fn ($row) => ['name' => json_decode((string) $row->name, true), 'tax_id_label' => json_decode((string) $row->tax_id_label, true)] + (array) $row),
        'activities' => $json(DB::table('textile_activities')->where('is_active', true)->orderBy('sort_order')->get(['code', 'name'])),
        'plans' => DB::table('subscription_plans')->where('is_public', true)->where('is_active', true)->orderBy('sort_order')
            ->get(['code', 'name', 'billing_model', 'is_trial', 'trial_days'])
            ->map(fn ($row) => ['name' => json_decode((string) $row->name, true), 'is_trial' => (bool) $row->is_trial] + (array) $row),
        'accreditation_types' => DB::table('accreditation_types as t')->join('countries as c', 'c.id', '=', 't.country_id')
            ->where('t.is_active', true)->orderBy('t.id')->get(['c.iso2 as country', 't.code', 't.name', 't.covers_hazardous'])
            ->map(fn ($row) => ['name' => json_decode((string) $row->name, true), 'covers_hazardous' => (bool) $row->covers_hazardous] + (array) $row),
        'legal_documents' => LegalAcceptance::current(['terms', 'privacy', 'provider_terms'])
            ->map(fn ($doc) => ['type' => $doc->document_type, 'version' => $doc->version, 'title' => $doc->title, 'content_html' => $doc->content_html]),
    ]]);
})->name('registration.options');

Route::post('auth/register', fn (RegisterCompanyRequest $request, RegisterCompany $action) => (new CompanyResource(
    $action->handle($request->validated(), $request)
))->response()->setStatusCode(201))->name('auth.register')->middleware('throttle:5,1');

/*
 * Current company.
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $current = fn () => Company::query()->findOrFail(app(TenantContext::class)->companyId());

    Route::get('company', fn () => new CompanyResource($current()))->name('company.show');

    Route::put('company', function (Request $request) use ($current) {
        abort_unless($request->user()->can('company.update'), 403);
        $input = $request->validate([
            'trade_name' => ['nullable', 'string', 'max:191'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'region' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'billing_email' => ['nullable', 'email', 'max:191'],
            'weight_variance_threshold_pct' => ['nullable', 'decimal:0,2', 'gt:0', 'lte:100'],
        ]);

        // Legal name, tax ID and country identify the contracting party: they change through the platform only.
        $company = $current();
        $threshold = $input['weight_variance_threshold_pct'] ?? null;
        unset($input['weight_variance_threshold_pct']);
        $company->fill($input);
        if ($threshold !== null) {
            $company->forceFill(['settings' => ['weight_variance_threshold_pct' => $threshold] + ($company->settings ?? [])]);
        }
        $company->save();

        return new CompanyResource($company);
    })->name('company.update');

    // M6-04: logo printed on exported reports.
    Route::post('company/logo', function (Request $request, FileStorage $files) use ($current) {
        abort_unless($request->user()->can('company.update'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:png,jpg,jpeg', 'mimetypes:image/png,image/jpeg', 'max:2048']]);

        $company = $current();
        $file = $files->store($request->file('file'), $company, 'logos', $request->user()->id, $company->id);
        $company->forceFill(['logo_stored_file_id' => $file->id])->save();

        return new CompanyResource($company);
    })->name('company.logo.store');

    Route::get('company/logo', function () use ($current) {
        $file = StoredFile::query()->where('company_id', $current()->id)->findOrFail($current()->logo_stored_file_id);

        return Storage::disk($file->disk)->response($file->path, null, ['Content-Type' => $file->mime_type, 'Cache-Control' => 'private, max-age=300']);
    })->name('company.logo.show');

    // M0-07.
    Route::get('onboarding', fn () => response()->json(['data' => Onboarding::state($current())]))->name('onboarding.show');

    Route::put('onboarding/steps/{step}', function (Request $request, string $step) use ($current) {
        abort_unless($request->user()->can('company.update'), 403);
        abort_unless(in_array($step, Onboarding::STEPS, true), 404);
        $company = $current();
        Onboarding::skip($company, $step, (bool) $request->validate(['skipped' => ['required', 'boolean']])['skipped']);

        return response()->json(['data' => Onboarding::state($company)]);
    })->name('onboarding.steps.update');

    Route::post('onboarding/dismiss', function (Request $request) use ($current) {
        abort_unless($request->user()->can('company.update'), 403);
        $company = $current();
        Onboarding::dismiss($company);

        return response()->json(['data' => Onboarding::state($company)]);
    })->name('onboarding.dismiss');
});

/*
 * Platform back-office (M0-06). No tenant context: guarded by platform permissions held by Diva Software staff.
 */
Route::middleware('auth:sanctum')->prefix('platform')->group(function () {
    $allow = fn (Request $request, string $permission) => abort_unless($request->user()->hasPermissionTo($permission), 403);

    Route::get('companies', function (Request $request) use ($allow) {
        $allow($request, 'platform.companies.review');
        $filter = $request->validate([
            'filter.status' => ['nullable', Rule::in(['pending_verification', 'pending_approval', 'active', 'rejected', 'suspended', 'closed'])],
            'filter.type' => ['nullable', Rule::in(['industrial', 'provider'])],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])['filter'] ?? [];

        return CompanyResource::collection(Company::query()
            ->when($filter['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filter['type'] ?? null, fn ($query, $value) => $query->where('company_type', $value))
            ->when($filter['search'] ?? null, fn ($query, $value) => $query->where(fn ($q) => $q
                ->where('legal_name', 'like', '%'.addcslashes($value, '%_\\').'%')->orWhere('tax_id', 'like', addcslashes($value, '%_\\').'%')))
            ->orderByRaw("status = 'pending_approval' desc")->orderByDesc('submitted_at')->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('platform.companies.index');

    Route::get('companies/{company}', function (Request $request, Company $company, TenantContext $context) use ($allow) {
        $allow($request, 'platform.companies.review');
        $contact = DB::table('users')->where('id', $company->primary_contact_user_id)->first(['first_name', 'last_name', 'email', 'phone', 'email_verified_at']);

        return response()->json(['data' => (new CompanyResource($company))->resolve($request) + [
            'rejection_reason' => $company->rejection_reason,
            'suspension_reason' => $company->suspension_reason,
            'requested_plan' => $company->settings['requested_plan'] ?? null,
            'requested_sites' => $company->settings['requested_sites'] ?? null,
            'subscription' => ($subscription = DB::table('company_subscriptions as s')->join('subscription_plans as p', 'p.id', '=', 's.subscription_plan_id')
                ->where('s.company_id', $company->id)->where('s.is_current', true)
                ->first(['p.code', 'p.name', 's.status', 's.billing_interval', 's.sites_quantity', 's.currency_code', 's.price_snapshot', 's.trial_ends_at', 's.current_period_end', 's.cancel_at_period_end'])) === null ? null : [
                    'plan' => $subscription->code, 'name' => json_decode((string) $subscription->name, true), 'status' => $subscription->status,
                    'billing_interval' => $subscription->billing_interval, 'sites_quantity' => $subscription->sites_quantity, 'currency_code' => $subscription->currency_code,
                    'unit_amount' => json_decode((string) $subscription->price_snapshot, true)['unit_amount'] ?? null,
                    'trial_ends_at' => $subscription->trial_ends_at, 'current_period_end' => $subscription->current_period_end, 'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
                ],
            'contact' => $contact === null ? null : [
                'name' => trim("{$contact->first_name} {$contact->last_name}"), 'email' => $contact->email, 'phone' => $contact->phone,
                'email_verified' => $contact->email_verified_at !== null,
            ],
            'counts' => [
                'sites' => DB::table('sites')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
                'users' => DB::table('company_users')->where('company_id', $company->id)->where('status', 'active')->count(),
                'lots' => DB::table('waste_lots')->where('company_id', $company->id)->count(),
            ],
            'accreditations' => DB::table('provider_accreditations as a')->join('accreditation_types as t', 't.id', '=', 'a.accreditation_type_id')
                ->where('a.company_id', $company->id)->whereNull('a.deleted_at')->orderBy('a.expires_on')
                ->get(['a.ulid', 't.code as type', 't.name', 'a.reference_number', 'a.valid_from', 'a.expires_on', 'a.review_status', 'a.rejection_reason'])
                ->map(fn ($row) => ['name' => json_decode((string) $row->name, true)] + (array) $row),
        ]]);
    })->name('platform.companies.show');

    Route::post('companies/{company}/approve', function (Request $request, Company $company, ReviewCompany $review) use ($allow) {
        $allow($request, 'platform.companies.review');

        return new CompanyResource($review->approve($company, $request->user()));
    })->name('platform.companies.approve');

    Route::post('companies/{company}/reject', function (Request $request, Company $company, ReviewCompany $review) use ($allow) {
        $allow($request, 'platform.companies.review');

        return new CompanyResource($review->reject($company, (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $request->user()));
    })->name('platform.companies.reject');

    Route::post('companies/{company}/suspend', function (Request $request, Company $company, ReviewCompany $review) use ($allow) {
        $allow($request, 'platform.companies.suspend');

        return new CompanyResource($review->suspend($company, (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $request->user()));
    })->name('platform.companies.suspend');

    Route::post('companies/{company}/reactivate', function (Request $request, Company $company, ReviewCompany $review) use ($allow) {
        $allow($request, 'platform.companies.suspend');

        return new CompanyResource($review->reactivate($company, $request->user()));
    })->name('platform.companies.reactivate');
});
