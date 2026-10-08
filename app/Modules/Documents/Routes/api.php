<?php

use App\Modules\Common\Models\StoredFile;
use App\Modules\Common\Support\DocumentLanguage;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Documents\Actions\DeclarationWorkflow;
use App\Modules\Documents\Actions\ManifestWorkflow;
use App\Modules\Documents\Actions\UploadCertificate;
use App\Modules\Documents\Enums\AnnualDeclarationStatus;
use App\Modules\Documents\Enums\DocumentType;
use App\Modules\Documents\Enums\HazardousWasteManifestStatus;
use App\Modules\Documents\Models\AnnualDeclaration;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Documents\Requests\UploadCertificateRequest;
use App\Modules\Documents\Resources\AnnualDeclarationResource;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Resources\HazardousWasteManifestResource;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Reporting\Support\ArrayExport;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
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
