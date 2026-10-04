<?php

use App\Modules\Documents\Actions\CreateAnnualDeclaration;
use App\Modules\Documents\Actions\CreateHazardousWasteManifest;
use App\Modules\Documents\Actions\IssueDocument;
use App\Modules\Documents\Actions\UploadCertificate;
use App\Modules\Documents\Models\AnnualDeclaration;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Documents\Requests\StoreAnnualDeclarationRequest;
use App\Modules\Documents\Requests\StoreHazardousWasteManifestRequest;
use App\Modules\Documents\Requests\UploadCertificateRequest;
use App\Modules\Documents\Resources\AnnualDeclarationResource;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Resources\HazardousWasteManifestResource;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Sites\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('documents', function (Request $request) {
        abort_unless($request->user()->can('documents.view'), 403);

        return DocumentResource::collection(
            Document::query()
                ->with(['storedFile', 'site'])
                ->when($request->filled('filter.type'), fn ($query, $value) => $query->where('document_type', $value))
                ->when($request->filled('filter.pickup'), fn ($query, $value) => $query->where('pickup_id', $value))
                ->when($request->filled('filter.site'), fn ($query, $value) => $query->where('site_id', $value))
                ->when($request->filled('filter.period'), fn ($query, $value) => $query->whereDate('period_start', '<=', $value)->whereDate('period_end', '>=', $value))
                ->orderByDesc('created_at')
                ->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    })->name('documents.index');

    Route::get('documents/{document}', function (
        Request $request,
        Document $document
    ) {
        abort_unless(
            $request->user()->can(
                'documents.view',
                $document->site_id
            ),
            403
        );

        return new DocumentResource(
            $document->load(['storedFile', 'signatures'])
        );
    })->name('documents.show');

    Route::post('documents/{document}/issue', function (
        Document $document,
        IssueDocument $action,
        Request $request
    ) {
        abort_unless($request->user()->can('documents.generate', $document->site_id), 403);

        return new DocumentResource(
            $action->handle($document)
        );
    })->name('documents.issue');

    Route::get('manifests', function (Request $request) {
        abort_unless($request->user()->can('documents.view'), 403);

        return HazardousWasteManifestResource::collection(
            HazardousWasteManifest::query()
                ->with(['pickup', 'site', 'wasteType'])
                ->when($request->filled('filter.site'), fn ($query, $value) => $query->where('site_id', $value))
                ->when($request->filled('filter.pickup'), fn ($query, $value) => $query->where('pickup_id', $value))
                ->when($request->filled('filter.status'), fn ($query, $value) => $query->where('status', $value))
                ->orderByDesc('created_at')
                ->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    })->name('manifests.index');

    Route::post(
        'pickups/{pickup}/manifests',
        function (
            Pickup $pickup,
            StoreHazardousWasteManifestRequest $request,
            CreateHazardousWasteManifest $action
        ) {
            abort_unless($request->user()->can('documents.generate', $pickup->site_id), 403);

            return (
                new HazardousWasteManifestResource(
                    $action->handle($pickup, $request->toData(), $request->user())
                )
            )->response()->setStatusCode(201);
        }
    )->name('manifests.store');

    Route::get('declarations', function (Request $request) {
        abort_unless($request->user()->can('declarations.prepare'), 403);

        return AnnualDeclarationResource::collection(
            AnnualDeclaration::query()
                ->with(['site', 'document'])
                ->when($request->filled('filter.site'), fn ($query, $value) => $query->where('site_id', $value))
                ->when($request->filled('filter.status'), fn ($query, $value) => $query->where('status', $value))
                ->when($request->filled('filter.year'), fn ($query, $value) => $query->where('reporting_year', $value))
                ->orderByDesc('reporting_year')
                ->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    })->name('declarations.index');

    Route::post(
        'sites/{site}/declarations',
        function (
            Site $site,
            StoreAnnualDeclarationRequest $request,
            CreateAnnualDeclaration $action
        ) {
            abort_unless($request->user()->can('declarations.prepare', $site->id), 403);

            return (
                new AnnualDeclarationResource(
                    $action->handle($site, $request->toData(), $request->user())
                )
            )->response()->setStatusCode(201);
        }
    )->name('declarations.store');

    Route::post(
        'pickups/{pickup}/certificates',
        function (
            UploadCertificateRequest $request,
            Pickup $pickup,
            UploadCertificate $action
        ) {
            return (
                new DocumentResource(
                    $action->handle(
                        $pickup,
                        $request->toData(),
                        $request->user()->id,
                    )
                )
            )->response()->setStatusCode(201);
        }
    )->name('documents.certificates.store');
});
