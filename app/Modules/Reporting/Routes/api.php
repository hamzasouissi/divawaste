<?php

use App\Modules\Reporting\Actions\CreateGeneratedReport;
use App\Modules\Reporting\Models\GeneratedReport;
use App\Modules\Reporting\Requests\StoreGeneratedReportRequest;
use App\Modules\Reporting\Resources\GeneratedReportResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('reports', function (Request $request) {
        abort_unless($request->user()?->can('reports.view'), 403);

        return GeneratedReportResource::collection(GeneratedReport::query()
            ->where('company_id', app(\App\Modules\Tenancy\TenantContext::class)->companyId())
            ->when($request->filled('filter.type'), fn ($query, $type) => $query->where('report_type', $type))
            ->when($request->filled('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('reports.index');

    Route::post('reports', fn (StoreGeneratedReportRequest $request, CreateGeneratedReport $action) => (new GeneratedReportResource(
        $action->handle($request->toData(), $request->user())
    ))->response()->setStatusCode(202))->name('reports.store');

    Route::get('reports/{report}', function (Request $request, GeneratedReport $report) {
        abort_unless($request->user()?->can('reports.view'), 403);

        return new GeneratedReportResource($report);
    })->name('reports.show');
});
