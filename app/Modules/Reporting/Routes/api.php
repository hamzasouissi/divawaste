<?php

use App\Modules\Common\Models\StoredFile;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Documents\Support\WasteRegister;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Reporting\Jobs\GenerateReport;
use App\Modules\Reporting\Models\GeneratedReport;
use App\Modules\Reporting\Models\ProductionVolume;
use App\Modules\Reporting\Resources\GeneratedReportResource;
use App\Modules\Reporting\Support\ReportBuilder;
use App\Modules\Reporting\Support\WasteStatistics;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    /*
     * Period and site filter shared by the dashboard, the register and the exports. The sites are always
     * intersected with the ones the user may see for the given permission.
     *
     * @return array{Carbon, Carbon, list<int>|null, ?string}
     */
    $scope = function (Request $request, string $permission) {
        $input = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'site' => ['nullable', 'string', 'ulid'],
        ]);
        $from = Carbon::parse($input['from'])->startOfDay();
        $to = Carbon::parse($input['to'])->startOfDay();
        abort_if($from->diffInDays($to) > 366 * 3, 422, 'The period cannot exceed three years.');

        $siteIds = app(AccessResolver::class)->siteIdsFor($request->user(), $permission);
        abort_if($siteIds === [], 403);
        if ($input['site'] ?? null) {
            $siteId = (int) Site::query()->where('ulid', strtoupper($input['site']))->valueOrFail('id');
            abort_unless($siteIds === null || in_array($siteId, $siteIds, true), 403);
            $siteIds = [$siteId];
        }

        return [$from, $to, $siteIds, $input['site'] ?? null];
    };

    // M6-01 / M6-02: every figure comes from the lots and pickups. Cached one minute per company, period and site set.
    Route::get('dashboard', function (Request $request, WasteStatistics $statistics, TenantContext $context) use ($scope) {
        [$from, $to, $siteIds] = $scope($request, 'dashboard.view');
        $key = 'dash:'.$context->companyId().':'.md5($from.$to.json_encode($siteIds));

        return response()->json(['data' => Cache::remember($key, 60, fn () => $statistics->dashboard($from, $to, $siteIds))]);
    })->name('dashboard.show');

    // M5-01: the register on screen (the exports go through /reports).
    Route::get('register', function (Request $request, WasteRegister $register) use ($scope) {
        [$from, $to, $siteIds] = $scope($request, 'documents.view');
        $rows = $register->query($from, $to, $siteIds, $request->has('hazardous') ? $request->boolean('hazardous') : null)
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        return response()->json([
            'data' => collect($rows->items())->map(fn (object $row) => WasteRegister::row($row)),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    })->name('register.index');

    /*
     * M6-02: pieces produced per site and month (manual entry in the MVP; M3 will feed it from production orders).
     */
    Route::get('production-volumes', function (Request $request, AccessResolver $access) {
        $siteIds = $access->siteIdsFor($request->user(), 'dashboard.view');
        abort_if($siteIds === [], 403);
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2020,2100']])['year'];

        return response()->json(['data' => ProductionVolume::query()->with('site:id,ulid,name')
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->whereYear('period_month', $year)->orderBy('period_month')->get()
            ->map(fn (ProductionVolume $volume) => [
                'site' => $volume->site->ulid, 'site_name' => $volume->site->name,
                'month' => $volume->period_month->format('Y-m'), 'pieces_produced' => $volume->pieces_produced, 'notes' => $volume->notes,
            ])]);
    })->name('production-volumes.index');

    Route::put('production-volumes', function (Request $request) {
        $input = $request->validate([
            'site' => ['required', 'string', 'ulid'],
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'pieces_produced' => ['required', 'integer', 'min:0', 'max:4000000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $site = Site::query()->where('ulid', strtoupper($input['site']))->firstOrFail();
        abort_unless($request->user()->can('production_volumes.manage', $site->id), 403);

        ProductionVolume::query()->updateOrCreate(
            ['site_id' => $site->id, 'period_month' => $input['month'].'-01'],
            ['pieces_produced' => $input['pieces_produced'], 'notes' => $input['notes'] ?? null, 'source' => 'manual', 'entered_by_user_id' => $request->user()->id],
        );

        return response()->noContent();
    })->name('production-volumes.update');

    /*
     * M5-01 / M6-04 / M4-05 exports: requested here, generated on the queue, downloaded when ready.
     */
    Route::get('reports', function (Request $request) {
        abort_unless($request->user()->can('reports.view'), 403);

        return GeneratedReportResource::collection(GeneratedReport::query()
            // Exports are personal: they were built with the requester's site rights.
            ->where('requested_by_user_id', $request->user()->id)
            ->orderByDesc('id')->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('reports.index');

    Route::post('reports', function (Request $request) use ($scope) {
        $input = $request->validate([
            'report_type' => ['required', Rule::in(ReportBuilder::TYPES)],
            'format' => ['required', Rule::in(ReportBuilder::FORMATS)],
        ]);
        [$from, $to, $siteIds, $site] = $scope($request, $input['report_type'] === 'waste_register' ? 'documents.view' : 'reports.export');
        abort_unless($request->user()->can('reports.export'), 403);

        $report = GeneratedReport::query()->create([
            'report_type' => $input['report_type'], 'format' => $input['format'], 'status' => 'queued',
            'parameters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'site' => $site, 'site_ids' => $siteIds],
            'requested_by_user_id' => $request->user()->id, 'expires_at' => now()->addDays(30),
        ]);
        GenerateReport::dispatch($report->company_id, $report->id);

        return (new GeneratedReportResource($report->refresh()))->response()->setStatusCode(202);
    })->name('reports.store');

    $own = fn (Request $request, GeneratedReport $report) => abort_unless($report->requested_by_user_id === $request->user()->id, 404);

    Route::get('reports/{report}', function (Request $request, GeneratedReport $report) use ($own) {
        $own($request, $report);

        return new GeneratedReportResource($report);
    })->name('reports.show');

    Route::get('reports/{report}/download', function (Request $request, GeneratedReport $report, FileStorage $files) use ($own) {
        $own($request, $report);
        abort_unless($report->status === 'completed' && $report->stored_file_id !== null, 409, 'The report is not ready.');
        abort_if($report->expires_at !== null && $report->expires_at->isPast(), 410);

        return $files->download(StoredFile::query()->where('company_id', $report->company_id)->findOrFail($report->stored_file_id));
    })->name('reports.download');
});
