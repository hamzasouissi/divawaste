<?php

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Models\StoredFile;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Providers\Models\ProviderAcceptedWaste;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderPartnership;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Support\ProviderDirectory;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

$accreditationData = fn (object $row) => [
    'ulid' => $row->ulid, 'type' => $row->type, 'name' => json_decode((string) $row->name, true),
    'covers_hazardous' => (bool) $row->covers_hazardous, 'reference_number' => $row->reference_number,
    'issuing_authority' => $row->issuing_authority, 'valid_from' => $row->valid_from, 'expires_on' => $row->expires_on,
    'review_status' => $row->review_status, 'rejection_reason' => $row->rejection_reason,
    'expired' => $row->expires_on < now()->toDateString(),
];
$accreditationColumns = ['a.ulid', 't.code as type', 't.name', 't.covers_hazardous', 'a.reference_number', 'a.issuing_authority',
    'a.valid_from', 'a.expires_on', 'a.review_status', 'a.rejection_reason'];
$streamFile = fn (StoredFile $file) => Storage::disk($file->disk)->response($file->path, $file->original_name, [
    'Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
]);

Route::middleware(['auth:sanctum', 'tenant'])->group(function () use ($accreditationData, $accreditationColumns, $streamFile) {
    $allow = fn (Request $request, string $permission) => abort_unless($request->user()->can($permission), 403);

    /*
     * Industrial side: directory (M4-01, M4-02) and partnerships.
     */
    Route::get('providers/directory', function (Request $request, ProviderDirectory $directory, TenantContext $context) use ($allow) {
        $allow($request, 'providers.view');
        $input = $request->validate([
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.channel' => ['nullable', 'string', Rule::exists('treatment_channels', 'code')],
            'filter.partners_only' => ['nullable', 'boolean'],
            'filter.hazardous' => ['nullable', 'boolean'],
        ])['filter'] ?? [];

        return ['data' => $directory->list($context->companyId(), [
            'search' => $input['search'] ?? null, 'channel' => $input['channel'] ?? null,
            'partners_only' => (bool) ($input['partners_only'] ?? false), 'hazardous' => (bool) ($input['hazardous'] ?? false),
        ])];
    })->name('providers.directory');

    // The accreditation document itself, for an industrial checking a published provider.
    Route::get('providers/accreditations/{accreditation}/file', function (Request $request, string $accreditation) use ($allow, $streamFile) {
        $allow($request, 'providers.view');
        $row = DB::table('provider_accreditations as a')->join('provider_profiles as p', 'p.company_id', '=', 'a.company_id')
            ->where('a.ulid', strtoupper($accreditation))->where('a.review_status', 'approved')->whereNull('a.deleted_at')->where('p.is_published', true)
            ->first(['a.stored_file_id', 'a.company_id']);
        abort_if($row === null, 404);

        return $streamFile(StoredFile::query()->where('company_id', $row->company_id)->findOrFail($row->stored_file_id));
    })->name('providers.accreditations.file');

    Route::post('provider-partnerships', function (Request $request) use ($allow) {
        $allow($request, 'providers.partnerships.manage');
        $ulid = strtoupper((string) $request->validate([
            'provider' => ['required', 'string', Rule::exists('companies', 'ulid')->where('company_type', CompanyType::Provider->value)->where('status', 'active')],
        ])['provider']);

        $partnership = ProviderPartnership::query()->firstOrCreate(
            ['provider_company_id' => Company::query()->where('ulid', $ulid)->value('id')],
            ['status' => 'active', 'started_at' => now(), 'created_by_user_id' => $request->user()->id],
        );
        if ($partnership->status !== 'active') {
            $partnership->forceFill(['status' => 'active', 'ended_at' => null])->save();
        }

        return response()->json(['data' => ['ulid' => $partnership->ulid, 'provider' => $ulid, 'status' => $partnership->status]], 201);
    })->name('provider-partnerships.store');

    Route::put('provider-partnerships/{partnership}', function (Request $request, ProviderPartnership $partnership) use ($allow) {
        $allow($request, 'providers.partnerships.manage');
        $input = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'ended'])],
            'internal_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $partnership->forceFill($input + ['ended_at' => $input['status'] === 'ended' ? now() : null])->save();

        return response()->json(['data' => ['ulid' => $partnership->ulid, 'status' => $partnership->status]]);
    })->name('provider-partnerships.update');

    /*
     * Provider side: its own profile, accepted waste and accreditations.
     */
    $profileData = function (TenantContext $context, ReferenceCodes $codes) {
        $profile = ProviderProfile::query()->firstOrFail();

        return [
            'roles' => array_keys(array_filter(['collector' => $profile->is_collector, 'transporter' => $profile->is_transporter,
                'recycler' => $profile->is_recycler, 'eliminator' => $profile->is_eliminator])),
            'description' => $profile->description,
            'service_area' => $profile->service_area,
            'public_contact_name' => $profile->public_contact_name,
            'public_contact_email' => $profile->public_contact_email,
            'public_contact_phone' => $profile->public_contact_phone,
            'is_published' => (bool) $profile->is_published,
            'accepted_wastes' => ProviderAcceptedWaste::query()->where('is_active', true)->orderBy('id')->get()->map(fn ($row) => [
                'channel' => $codes->code('treatment_channels', $row->treatment_channel_id),
                'family' => $codes->code('waste_families', $row->waste_family_id),
                'catalog_item' => $codes->code('waste_catalog_items', $row->waste_catalog_item_id),
                'min_quantity_kg' => $row->min_quantity_kg, 'notes' => $row->notes,
            ]),
        ];
    };

    Route::get('provider/profile', function (Request $request, TenantContext $context, ReferenceCodes $codes) use ($allow, $profileData) {
        $allow($request, 'provider.profile.manage');

        return ['data' => $profileData($context, $codes)];
    })->name('provider.profile.show');

    Route::put('provider/profile', function (Request $request, TenantContext $context, ReferenceCodes $codes) use ($allow, $profileData) {
        $allow($request, 'provider.profile.manage');
        $input = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(['collector', 'transporter', 'recycler', 'eliminator'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'service_area' => ['nullable', 'string', 'max:500'],
            'public_contact_name' => ['nullable', 'string', 'max:150'],
            'public_contact_email' => ['nullable', 'email', 'max:191'],
            'public_contact_phone' => ['nullable', 'string', 'max:30'],
            'accepted_wastes' => ['present', 'array', 'max:200'],
            'accepted_wastes.*.channel' => ['required', 'string', Rule::exists('treatment_channels', 'code')->where('is_active', true)],
            'accepted_wastes.*.family' => ['nullable', 'required_without:accepted_wastes.*.catalog_item', 'string', Rule::exists('waste_families', 'code')],
            'accepted_wastes.*.catalog_item' => ['nullable', 'string', Rule::exists('waste_catalog_items', 'code')],
            'accepted_wastes.*.min_quantity_kg' => ['nullable', 'decimal:0,3', 'gte:0'],
            'accepted_wastes.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($input, $codes) {
            ProviderProfile::query()->firstOrFail()->forceFill([
                'is_collector' => in_array('collector', $input['roles'], true), 'is_transporter' => in_array('transporter', $input['roles'], true),
                'is_recycler' => in_array('recycler', $input['roles'], true), 'is_eliminator' => in_array('eliminator', $input['roles'], true),
            ] + array_intersect_key($input, array_flip(['description', 'service_area', 'public_contact_name', 'public_contact_email', 'public_contact_phone'])))->save();

            ProviderAcceptedWaste::query()->delete();
            foreach ($input['accepted_wastes'] as $waste) {
                ProviderAcceptedWaste::query()->create([
                    'treatment_channel_id' => $codes->id('treatment_channels', $waste['channel']),
                    'waste_family_id' => $codes->id('waste_families', $waste['family'] ?? null),
                    'waste_catalog_item_id' => $codes->id('waste_catalog_items', $waste['catalog_item'] ?? null),
                    'min_quantity_kg' => $waste['min_quantity_kg'] ?? null, 'notes' => $waste['notes'] ?? null,
                ]);
            }
        });

        return ['data' => $profileData($context, $codes)];
    })->name('provider.profile.update');

    Route::get('provider/accreditations', function (Request $request, TenantContext $context) use ($allow, $accreditationData, $accreditationColumns) {
        $allow($request, 'provider.accreditations.manage');

        return ['data' => DB::table('provider_accreditations as a')->join('accreditation_types as t', 't.id', '=', 'a.accreditation_type_id')
            ->where('a.company_id', $context->companyId())->whereNull('a.deleted_at')->orderByDesc('a.expires_on')
            ->get($accreditationColumns)->map($accreditationData)];
    })->name('provider.accreditations.index');

    // A renewal is a new accreditation: the previous document stays in the history.
    Route::post('provider/accreditations', function (Request $request, TenantContext $context, FileStorage $files) use ($allow) {
        $allow($request, 'provider.accreditations.manage');
        $company = Company::query()->findOrFail($context->companyId());
        $input = $request->validate([
            'type' => ['required', 'string', Rule::exists('accreditation_types', 'code')->where('country_id', $company->country_id)->where('is_active', true)],
            'reference_number' => ['required', 'string', 'max:100'],
            'issuing_authority' => ['nullable', 'string', 'max:191'],
            'valid_from' => ['required', 'date'],
            'expires_on' => ['required', 'date', 'after:today', 'after_or_equal:valid_from'],
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $accreditation = DB::transaction(function () use ($input, $company, $files, $request) {
            $file = $files->store($request->file('file'), $company, 'accreditations', $request->user()->id, $company->id);

            return ProviderAccreditation::query()->create([
                'accreditation_type_id' => DB::table('accreditation_types')->where('country_id', $company->country_id)->where('code', $input['type'])->value('id'),
                'reference_number' => $input['reference_number'], 'issuing_authority' => $input['issuing_authority'] ?? null,
                'valid_from' => $input['valid_from'], 'expires_on' => $input['expires_on'], 'stored_file_id' => $file->id,
            ]);
        });

        return response()->json(['data' => ['ulid' => $accreditation->ulid, 'review_status' => 'pending']], 201);
    })->name('provider.accreditations.store');

    Route::get('provider/accreditations/{accreditation}/file', function (Request $request, ProviderAccreditation $accreditation) use ($allow, $streamFile) {
        $allow($request, 'provider.accreditations.manage');

        return $streamFile(StoredFile::query()->where('company_id', $accreditation->company_id)->findOrFail($accreditation->stored_file_id));
    })->name('provider.accreditations.file');

    // Regulatory document: withdrawn logically, never erased.
    Route::delete('provider/accreditations/{accreditation}', function (Request $request, ProviderAccreditation $accreditation) use ($allow) {
        $allow($request, 'provider.accreditations.manage');
        $accreditation->forceFill(['deletion_reason' => $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null])->save();
        $accreditation->delete();

        return response()->noContent();
    })->name('provider.accreditations.destroy');
});

/*
 * Platform: review of provider accreditations (M0-05, M0-06).
 */
Route::middleware('auth:sanctum')->prefix('platform')->group(function () use ($accreditationData, $accreditationColumns, $streamFile) {
    $allow = fn (Request $request) => abort_unless($request->user()->hasPermissionTo('platform.providers.review'), 403);

    Route::get('accreditations', function (Request $request) use ($allow, $accreditationData, $accreditationColumns) {
        $allow($request);
        $status = $request->validate(['filter.review_status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'revoked'])]])['filter']['review_status'] ?? 'pending';

        return ['data' => DB::table('provider_accreditations as a')->join('accreditation_types as t', 't.id', '=', 'a.accreditation_type_id')
            ->join('companies as c', 'c.id', '=', 'a.company_id')
            ->where('a.review_status', $status)->whereNull('a.deleted_at')->orderBy('a.created_at')->limit(200)
            ->get([...$accreditationColumns, 'c.ulid as company', 'c.legal_name'])
            ->map(fn (object $row) => $accreditationData($row) + ['company' => $row->company, 'legal_name' => $row->legal_name])];
    })->name('platform.accreditations.index');

    Route::get('accreditations/{accreditation}/file', function (Request $request, string $accreditation) use ($allow, $streamFile) {
        $allow($request);
        $row = DB::table('provider_accreditations')->where('ulid', strtoupper($accreditation))->first(['stored_file_id', 'company_id']);
        abort_if($row === null, 404);

        return $streamFile(StoredFile::query()->where('company_id', $row->company_id)->findOrFail($row->stored_file_id));
    })->name('platform.accreditations.file');

    Route::post('accreditations/{accreditation}/review', function (Request $request, string $accreditation, NotifyUsers $notify) use ($allow) {
        $allow($request);
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'revoked'])],
            'reason' => ['nullable', 'required_unless:decision,approved', 'string', 'max:1000'],
        ]);

        $row = DB::transaction(function () use ($accreditation, $input, $request) {
            $row = DB::table('provider_accreditations')->where('ulid', strtoupper($accreditation))->whereNull('deleted_at')->lockForUpdate()->first();
            abort_if($row === null, 404);
            if ($row->review_status === $input['decision']) {
                throw new BusinessRuleViolation('ACCREDITATION_ALREADY_REVIEWED', 'This accreditation already has that status.');
            }
            DB::table('provider_accreditations')->where('id', $row->id)->update([
                'review_status' => $input['decision'], 'reviewed_at' => now(), 'reviewed_by_user_id' => $request->user()->id,
                'rejection_reason' => $input['decision'] === 'approved' ? null : $input['reason'], 'updated_at' => now(),
            ]);
            AuditLogger::record([
                'company_id' => $row->company_id, 'event' => 'accreditation_'.$input['decision'], 'auditable_type' => 'provider_accreditations',
                'auditable_id' => $row->id, 'auditable_ulid' => $row->ulid, 'old_values' => ['review_status' => $row->review_status],
                'new_values' => array_filter(['review_status' => $input['decision'], 'reason' => $input['reason'] ?? null]), 'tags' => ['platform_review'],
            ]);

            return $row;
        });

        $notify->toCompanyPermission((int) $row->company_id, 'provider.accreditations.manage', 'accreditation.reviewed',
            ['reference' => $row->reference_number, 'status' => $input['decision']], '/provider/accreditations');

        return response()->noContent();
    })->name('platform.accreditations.review');
});
