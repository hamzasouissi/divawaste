<?php

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Models\StoredFile;
use App\Modules\Common\Support\DocumentLanguage;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Documents\Actions\DeclarationWorkflow;
use App\Modules\Documents\Actions\ManifestWorkflow;
use App\Modules\Documents\Actions\UploadCertificate;
use App\Modules\Documents\Enums\AnnualDeclarationStatus;
use App\Modules\Documents\Enums\DocumentType;
use App\Modules\Documents\Enums\HazardousWasteManifestStatus;
use App\Modules\Documents\Models\AnnualDeclaration;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Documents\Models\WasteAuthorization;
use App\Modules\Documents\Requests\UploadCertificateRequest;
use App\Modules\Documents\Resources\AnnualDeclarationResource;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Resources\HazardousWasteManifestResource;
use App\Modules\Documents\Support\AuthorizationQuota;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Reporting\Support\ArrayExport;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $isProvider = fn () => app(TenantContext::class)->companyType() !== CompanyType::Industrial;

    /*
     * What the caller may read. Industrial: the sites where it holds documents.view. Provider: only what the
     * tenant scope shares with it (documents it uploaded or slips it is a party to), behind its own permission.
     *
     * @return list<int>|null site ids (null = no site restriction)
     */
    $readableSites = function (Request $request) use ($isProvider) {
        if ($isProvider()) {
            abort_unless($request->user()->can('provider.pickups.view'), 403);

            return null;
        }
        $siteIds = app(AccessResolver::class)->siteIdsFor($request->user(), 'documents.view');
        abort_if($siteIds === [], 403);

        return $siteIds;
    };
    $canRead = function (Request $request, ?int $siteId) use ($isProvider) {
        abort_unless($isProvider() ? $request->user()->can('provider.pickups.view') : $request->user()->can('documents.view', $siteId), 403);
    };
    $pdf = fn (string $content, string $name) => response($content, 200, [
        'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'.pdf"', 'Cache-Control' => 'private, no-store',
    ]);

    /*
     * M5-04: archived documents (certificates, signed slips, validated declarations).
     */
    Route::get('documents', function (Request $request) use ($readableSites) {
        $siteIds = $readableSites($request);
        $filter = $request->validate([
            'filter.type' => ['nullable', Rule::enum(DocumentType::class)],
            'filter.site' => ['nullable', 'string', 'ulid'],
            'filter.pickup' => ['nullable', 'string', 'ulid'],
            'filter.year' => ['nullable', 'integer', 'between:2020,2100'],
        ])['filter'] ?? [];

        return DocumentResource::collection(Document::query()->with('storedFile')
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->when($filter['type'] ?? null, fn ($query, $value) => $query->where('document_type', $value))
            ->when($filter['site'] ?? null, fn ($query, $value) => $query->whereIn('site_id', Site::query()->select('id')->where('ulid', strtoupper($value))))
            ->when($filter['pickup'] ?? null, fn ($query, $value) => $query->whereIn('pickup_id', Pickup::query()->select('id')->where('ulid', strtoupper($value))))
            ->when($filter['year'] ?? null, fn ($query, $value) => $query->whereYear('created_at', $value))
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('documents.index');

    Route::get('documents/{document}', function (Request $request, Document $document) use ($canRead) {
        $canRead($request, $document->site_id);

        return new DocumentResource($document->load(['storedFile', 'wasteLots:waste_lots.id,waste_lots.ulid,waste_lots.lot_number']));
    })->name('documents.show');

    Route::get('documents/{document}/download', function (Request $request, Document $document, FileStorage $files) use ($canRead) {
        $canRead($request, $document->site_id);
        abort_if($document->stored_file_id === null, 404);

        return $files->download(StoredFile::query()->where('company_id', $document->company_id)->findOrFail($document->stored_file_id), inline: true);
    })->name('documents.download');

    Route::post('pickups/{pickup}/certificates', function (UploadCertificateRequest $request, Pickup $pickup, UploadCertificate $action, NotifyUsers $notify, TenantContext $context) use ($isProvider) {
        abort_unless($isProvider() ? $request->user()->can('provider.certificates.upload') : $request->user()->can('documents.upload', $pickup->site_id), 403);
        $document = $action->handle($pickup, $request->toData(), $request->user()->id);

        if ($isProvider()) {
            $provider = Company::query()->findOrFail($context->companyId());
            $context->runAs(Company::query()->findOrFail($pickup->company_id), fn () => $notify->toSitePermission($pickup->site_id, 'documents.view',
                'document.certificate_uploaded', ['number' => $pickup->pickup_number, 'provider' => $provider->legal_name], '/pickups/'.$pickup->ulid));
        }

        return (new DocumentResource($document))->response()->setStatusCode(201);
    })->name('documents.certificates.store');

    /*
     * M5-02: hazardous waste tracking slips.
     */
    Route::get('manifests', function (Request $request) use ($readableSites) {
        $siteIds = $readableSites($request);
        $filter = $request->validate([
            'filter.status' => ['nullable', Rule::enum(HazardousWasteManifestStatus::class)],
            'filter.pickup' => ['nullable', 'string', 'ulid'],
        ])['filter'] ?? [];

        return HazardousWasteManifestResource::collection(HazardousWasteManifest::query()
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->when($filter['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filter['pickup'] ?? null, fn ($query, $value) => $query->whereIn('pickup_id', Pickup::query()->select('id')->where('ulid', strtoupper($value))))
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('manifests.index');

    Route::post('pickups/{pickup}/manifests', function (Request $request, Pickup $pickup, ManifestWorkflow $workflow) {
        abort_unless($request->user()->can('documents.generate', $pickup->site_id), 403);

        return HazardousWasteManifestResource::collection($workflow->generate($pickup, $request->user()))->response()->setStatusCode(201);
    })->name('manifests.store');

    Route::get('manifests/{manifest}', function (Request $request, HazardousWasteManifest $manifest) use ($canRead) {
        $canRead($request, $manifest->site_id);

        return new HazardousWasteManifestResource($manifest);
    })->name('manifests.show');

    Route::get('manifests/{manifest}/pdf', function (Request $request, HazardousWasteManifest $manifest, ManifestWorkflow $workflow) use ($canRead, $pdf) {
        $canRead($request, $manifest->site_id);

        return $pdf(DocumentLanguage::during(DocumentLanguage::of($request), fn () => $workflow->pdf($manifest)), $manifest->manifest_number);
    })->name('manifests.pdf');

    Route::post('manifests/{manifest}/sign', function (Request $request, HazardousWasteManifest $manifest, ManifestWorkflow $workflow) use ($isProvider) {
        abort_unless($isProvider() ? $request->user()->can('provider.manifests.sign') : $request->user()->can('documents.sign', $manifest->site_id), 403);
        $input = $request->validate([
            // 'paper': the party signed by hand on the printed slip; who signed and when is typed in.
            'method' => ['sometimes', Rule::in(['electronic', 'paper'])],
            'password' => ['required', 'string'],
            'signer_name' => ['required_if:method,paper', 'nullable', 'string', 'max:150'],
            'signed_on' => ['required_if:method,paper', 'nullable', 'date', 'before_or_equal:today'],
        ]);
        // Signing, or recording a signature, is a deliberate act: the user proves his identity again.
        if (! Hash::check((string) $input['password'], $request->user()->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => [__('auth.password')]]);
        }
        $paper = ($input['method'] ?? 'electronic') === 'paper' ? ['signer_name' => trim((string) $input['signer_name']), 'signed_on' => (string) $input['signed_on']] : null;

        return new HazardousWasteManifestResource($workflow->sign($manifest, $request->user(), $request, $paper));
    })->name('manifests.sign')->middleware('throttle:10,1');

    // The scan of the slip signed by hand: it closes a slip that carries at least one handwritten signature.
    Route::post('manifests/{manifest}/signed-copy', function (Request $request, HazardousWasteManifest $manifest, ManifestWorkflow $workflow) use ($isProvider) {
        abort_unless(! $isProvider() && $request->user()->can('documents.sign', $manifest->site_id), 403);
        $file = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']])['file'];

        return new HazardousWasteManifestResource($workflow->attachSignedCopy($manifest, $file, $request->user()));
    })->name('manifests.signed-copy');

    /*
     * M5-03: annual declarations.
     */
    Route::get('declarations', function (Request $request, AccessResolver $access) {
        $siteIds = $access->siteIdsFor($request->user(), 'declarations.prepare');
        abort_if($siteIds === [], 403);
        $filter = $request->validate([
            'filter.site' => ['nullable', 'string', 'ulid'],
            'filter.status' => ['nullable', Rule::enum(AnnualDeclarationStatus::class)],
            'filter.year' => ['nullable', 'integer', 'between:2020,2100'],
        ])['filter'] ?? [];

        return AnnualDeclarationResource::collection(AnnualDeclaration::query()
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->when($filter['site'] ?? null, fn ($query, $value) => $query->whereIn('site_id', Site::query()->select('id')->where('ulid', strtoupper($value))))
            ->when($filter['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filter['year'] ?? null, fn ($query, $value) => $query->where('reporting_year', $value))
            ->orderByDesc('reporting_year')->orderBy('site_id')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('declarations.index');

    // Creates the year's draft, or recomputes it while it is not validated.
    Route::post('sites/{site}/declarations', function (Request $request, Site $site, DeclarationWorkflow $workflow) {
        abort_unless($request->user()->can('declarations.prepare', $site->id), 403);
        $year = (int) $request->validate(['reporting_year' => ['required', 'integer', 'between:2020,'.now()->year]])['reporting_year'];
        $declaration = $workflow->prepare($site, $year, $request->user());

        return (new AnnualDeclarationResource($declaration))->response()->setStatusCode($declaration->wasRecentlyCreated ? 201 : 200);
    })->name('declarations.store');

    Route::get('declarations/{declaration}', function (Request $request, AnnualDeclaration $declaration) {
        abort_unless($request->user()->can('declarations.prepare', $declaration->site_id), 403);

        return new AnnualDeclarationResource($declaration);
    })->name('declarations.show');

    Route::post('declarations/{declaration}/validate', function (Request $request, AnnualDeclaration $declaration, DeclarationWorkflow $workflow) {
        abort_unless($request->user()->can('declarations.validate', $declaration->site_id), 403);

        return new AnnualDeclarationResource($workflow->validate($declaration, $request->user()));
    })->name('declarations.validate');

    Route::get('declarations/{declaration}/export', function (Request $request, AnnualDeclaration $declaration, DeclarationWorkflow $workflow) use ($pdf) {
        abort_unless($request->user()->can('declarations.prepare', $declaration->site_id), 403);
        $name = "declaration-{$declaration->reporting_year}";

        $language = DocumentLanguage::of($request);
        if ($request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx'])]])['format'] === 'pdf') {
            return $pdf(DocumentLanguage::during($language, fn () => $workflow->pdf($declaration)), $name);
        }
        // The spreadsheet is written in the same language as the PDF.
        app()->setLocale($language);
        $yesNo = fn (bool $value) => __($value ? 'documents.yes' : 'documents.no');

        $rows = [
            ...array_map(fn (array $row) => [__('documents.declaration.rubric_produced'), $row['regulatory_code'], $row['waste_type'], $yesNo((bool) $row['is_hazardous']), null, null, (float) $row['kg']], $declaration->data['produced'] ?? []),
            ...array_map(fn (array $row) => [__('documents.declaration.rubric_evacuated'), $row['regulatory_code'], $row['waste_type'], $yesNo((bool) $row['is_hazardous']), $row['provider'], $row['channel'] ? __('exports.channel.'.$row['channel']) : __('documents.declaration.channel_undeclared'), (float) $row['kg']], $declaration->data['evacuated'] ?? []),
        ];

        return response(Excel::raw(new ArrayExport(__('documents.declaration.sheet', ['year' => $declaration->reporting_year]),
            [__('documents.declaration.rubric'), __('documents.declaration.waste_code'), __('documents.declaration.designation'), __('documents.declaration.hazardous'),
                __('documents.declaration.receiver'), __('documents.declaration.channel'), __('documents.declaration.quantity')], $rows), ExcelFormat::XLSX), 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"', 'Cache-Control' => 'private, no-store',
                ]);
    })->name('declarations.export');
});

/*
 * Authorisations to have a waste type leave the site (declaration signed by customs): a period and an allowed quantity.
 * Only the waste types marked "requires an authorisation" are concerned; their pickups are refused without one that
 * still has room. An authorisation is never deleted: the pickups it covered keep pointing to it.
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $allow = fn (Request $request, string $permission) => abort_unless($request->user()->can($permission), 403);
    $data = function (WasteAuthorization $authorization) {
        $usage = app(AuthorizationQuota::class)->usage($authorization);
        $codes = app(ReferenceCodes::class);

        return [
            'ulid' => $authorization->ulid, 'reference' => $authorization->reference,
            'waste_type' => (array) DB::table('waste_types')->where('id', $authorization->waste_type_id)->first(['ulid', 'name']),
            'issued_on' => $authorization->issued_on?->toDateString(),
            'valid_from' => $authorization->valid_from->toDateString(), 'valid_until' => $authorization->valid_until->toDateString(),
            'max_kg' => $authorization->max_kg, 'max_lots' => $authorization->max_lots,
            'limits' => DB::table('waste_authorization_limits')->where('waste_authorization_id', $authorization->id)->orderBy('id')->get(['packaging_type_id', 'max_count'])
                ->map(fn ($row) => ['packaging_type' => $codes->code('packaging_types', (int) $row->packaging_type_id), 'max_count' => (int) $row->max_count]),
            'alert_pct' => $authorization->alert_pct, 'notes' => $authorization->notes, 'is_active' => $authorization->is_active,
            'has_file' => $authorization->stored_file_id !== null,
            // 'upcoming' / 'valid' / 'ended' on today's date; a withdrawn one covers nothing whatever its dates.
            'state' => ! $authorization->is_active ? 'withdrawn' : (now()->toDateString() < $authorization->valid_from->toDateString() ? 'upcoming'
                : (now()->toDateString() > $authorization->valid_until->toDateString() ? 'ended' : 'valid')),
            'in_use' => DB::table('pickup_lots')->where('waste_authorization_id', $authorization->id)->exists(),
            'usage' => $usage,
        ];
    };
    $rules = [
        'waste_type' => ['required', 'string', new TenantExists(WasteType::class, fn ($q) => $q->where('requires_authorization', true))],
        'reference' => ['required', 'string', 'max:100'],
        'issued_on' => ['nullable', 'date'],
        'valid_from' => ['required', 'date'],
        'valid_until' => ['required', 'date', 'after_or_equal:valid_from'],
        // The allowed quantity, as the document states it. At least one of the three.
        'max_kg' => ['nullable', 'decimal:0,3', 'gt:0', 'required_without_all:max_lots,limits'],
        'max_lots' => ['nullable', 'integer', 'between:1,1000000'],
        'limits' => ['nullable', 'array', 'max:30'],
        'limits.*.packaging_type' => ['required', 'string', 'distinct', Rule::exists('packaging_types', 'code')->where('is_active', true)],
        'limits.*.max_count' => ['required', 'integer', 'between:1,1000000'],
        'alert_pct' => ['nullable', 'integer', 'between:1,100'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'is_active' => ['sometimes', 'boolean'],
    ];
    $save = function (WasteAuthorization $authorization, array $input, ReferenceCodes $codes) {
        $authorization->forceFill([
            'waste_type_id' => WasteType::query()->where('ulid', strtoupper($input['waste_type']))->value('id'),
            'reference' => trim($input['reference']), 'issued_on' => $input['issued_on'] ?? null,
            'valid_from' => $input['valid_from'], 'valid_until' => $input['valid_until'],
            'max_kg' => $input['max_kg'] ?? null, 'max_lots' => $input['max_lots'] ?? null,
            'alert_pct' => $input['alert_pct'] ?? 80, 'notes' => $input['notes'] ?? null, 'is_active' => (bool) ($input['is_active'] ?? true),
        ])->save();
        DB::table('waste_authorization_limits')->where('waste_authorization_id', $authorization->id)->delete();
        DB::table('waste_authorization_limits')->insert(array_map(fn (array $line) => [
            'company_id' => $authorization->company_id, 'waste_authorization_id' => $authorization->id,
            'packaging_type_id' => $codes->id('packaging_types', $line['packaging_type']), 'max_count' => (int) $line['max_count'],
            'created_at' => now(), 'updated_at' => now(),
        ], $input['limits'] ?? []));
    };

    Route::get('waste-authorizations', function (Request $request) use ($allow, $data) {
        $allow($request, 'waste_types.view');

        return ['data' => WasteAuthorization::query()->orderByDesc('valid_until')->orderByDesc('id')->get()->map($data)];
    })->name('waste-authorizations.index');

    Route::post('waste-authorizations', function (Request $request, ReferenceCodes $codes) use ($allow, $data, $rules, $save) {
        $allow($request, 'waste_types.manage');
        $input = $request->validate($rules);
        $authorization = DB::transaction(function () use ($input, $save, $codes, $request) {
            $authorization = (new WasteAuthorization)->forceFill(['created_by_user_id' => $request->user()->id]);
            $save($authorization, $input, $codes);

            return $authorization;
        });

        return response()->json(['data' => $data($authorization)], 201);
    })->name('waste-authorizations.store');

    // Once pickups draw on it, what was authorised is history: the waste type, the period and the quantities are
    // frozen. A different authorisation is a new one. Reference, notes, alert level and withdrawal stay editable.
    Route::put('waste-authorizations/{authorization}', function (Request $request, WasteAuthorization $authorization, ReferenceCodes $codes) use ($allow, $data, $rules, $save) {
        $allow($request, 'waste_types.manage');
        $input = $request->validate($rules);

        if (DB::table('pickup_lots')->where('waste_authorization_id', $authorization->id)->exists()) {
            $current = $data($authorization);
            $same = strtoupper($input['waste_type']) === $current['waste_type']['ulid'] && $input['valid_from'] === $current['valid_from'] && $input['valid_until'] === $current['valid_until']
                && bccomp((string) ($input['max_kg'] ?? '0'), (string) ($current['max_kg'] ?? '0'), 3) === 0 && (int) ($input['max_lots'] ?? 0) === (int) ($current['max_lots'] ?? 0)
                && collect($input['limits'] ?? [])->map(fn ($l) => [$l['packaging_type'], (int) $l['max_count']])->sort()->values()->all()
                    === collect($current['limits'])->map(fn ($l) => [$l['packaging_type'], $l['max_count']])->sort()->values()->all();
            if (! $same) {
                throw new BusinessRuleViolation('AUTHORIZATION_IN_USE', 'Pickups already draw on this authorisation: its waste type, period and quantities can no longer change. Record a new authorisation.');
            }
        }
        DB::transaction(fn () => $save($authorization, $input, $codes));

        return response()->json(['data' => $data($authorization->refresh())]);
    })->name('waste-authorizations.update');

    // The scan of the document signed by customs.
    Route::post('waste-authorizations/{authorization}/file', function (Request $request, WasteAuthorization $authorization, FileStorage $files, TenantContext $context) use ($allow, $data) {
        $allow($request, 'waste_types.manage');
        $company = Company::query()->findOrFail($context->companyId());
        $file = $files->store($request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']])['file'],
            $company, 'authorizations', $request->user()->id, $company->id, now()->addYears(10));
        $authorization->forceFill(['stored_file_id' => $file->id])->save();

        return response()->json(['data' => $data($authorization)]);
    })->name('waste-authorizations.file.store');

    Route::get('waste-authorizations/{authorization}/file', function (Request $request, WasteAuthorization $authorization, FileStorage $files) use ($allow) {
        $allow($request, 'waste_types.view');
        abort_if($authorization->stored_file_id === null, 404);

        return $files->download(StoredFile::query()->findOrFail($authorization->stored_file_id), inline: true);
    })->name('waste-authorizations.file.show');

    // Before a pickup is requested: would these lots be covered on that date, and what would be left?
    Route::post('waste-authorizations/check', function (Request $request, AuthorizationQuota $quota) {
        abort_unless($request->user()->can('waste_types.view') || $request->user()->can('pickups.request'), 403);
        $input = $request->validate([
            'date' => ['required', 'date'],
            'lots' => ['required', 'array', 'min:1', 'max:500'],
            'lots.*' => ['required', 'string', new TenantExists(WasteLot::class)],
        ]);
        $lots = DB::table('waste_lots')->whereIn('ulid', array_map('strtoupper', $input['lots']))
            ->where('company_id', app(TenantContext::class)->companyId())->get(['waste_type_id', 'packaging_type_id', 'package_count as packages', 'net_weight_kg as kg', 'weight_basis', 'lot_number']);

        return ['data' => array_map(fn (array $item) => [
            'waste_type' => $item['waste_type'], 'reference' => $item['authorization']?->reference, 'valid_until' => $item['authorization']?->valid_until->toDateString(),
            // null = covered; 'missing', 'exceeded', 'packaging' or 'unverifiable' (a weight limit and lots nobody weighed, listed
            // in `unweighed`) = the pickup would be refused.
            'reason' => $item['reason'], 'unweighed' => $item['unweighed'], 'uncounted' => $item['uncounted'],
            // Every limit of the document, each with its own figures and answer, before this pickup and with it.
            'constraints' => $item['constraints'], 'before' => $item['before'],
        ], $quota->cover($lots, (string) $input['date']))];
    })->name('waste-authorizations.check');
});
