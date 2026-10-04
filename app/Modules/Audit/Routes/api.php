<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Requests\IndexAuditLogsRequest;
use App\Modules\Audit\Resources\AuditLogResource;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('audit-logs', function (IndexAuditLogsRequest $request) {
        $tenantId = app(TenantContext::class)->companyId();

        $query = AuditLog::query()->where('company_id', $tenantId)
            ->when($request->input('filter.entity'), fn ($query, $value) => $query->where('auditable_type', $value))
            ->when($request->input('filter.user'), fn ($query, $value) => $query->where('actor_user_id', $value));

        $between = $request->input('filter.between');
        if (is_array($between) && count($between) === 2) {
            $first = $between[0];
            $second = $between[1];

            if ($first !== null && $second !== null) {
                $query->whereBetween('created_at', [$first, $second]);
            }
        }

        return AuditLogResource::collection($query
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100)));
    })->name('audit-logs.index');
});
