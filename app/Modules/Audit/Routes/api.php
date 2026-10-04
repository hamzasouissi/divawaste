<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Resources\AuditLogResource;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    // M7-02: the client administrator reads the trail of his own company only.
    Route::get('audit-logs', function (Request $request, TenantContext $context) {
        abort_unless($request->user()->can('audit.view'), 403);
        $filter = $request->validate([
            'filter.entity' => ['nullable', 'string', 'max:50'],
            'filter.event' => ['nullable', 'string', 'max:40'],
            'filter.user' => ['nullable', 'string', 'ulid'],
            'filter.object' => ['nullable', 'string', 'ulid'],
            'filter.from' => ['nullable', 'date'],
            'filter.to' => ['nullable', 'date', 'after_or_equal:filter.from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])['filter'] ?? [];

        $logs = AuditLog::query()
            ->where('company_id', $context->companyId())
            ->when($filter['entity'] ?? null, fn ($query, $value) => $query->where('auditable_type', $value))
            ->when($filter['event'] ?? null, fn ($query, $value) => $query->where('event', $value))
            ->when($filter['object'] ?? null, fn ($query, $value) => $query->where('auditable_ulid', strtoupper($value)))
            ->when($filter['user'] ?? null, fn ($query, $value) => $query->where('actor_user_id', User::query()->where('ulid', strtoupper($value))->value('id') ?? 0))
            ->when($filter['from'] ?? null, fn ($query, $value) => $query->where('created_at', '>=', $value))
            ->when($filter['to'] ?? null, fn ($query, $value) => $query->where('created_at', '<', now()->parse($value)->addDay()->startOfDay()))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        $actors = DB::table('users')->whereIn('id', $logs->getCollection()->pluck('actor_user_id')->filter()->unique())
            ->get(['id', 'ulid', 'first_name', 'last_name'])->keyBy('id');
        $logs->getCollection()->each(fn (AuditLog $log) => $log->setRelation('actor', $actors->get($log->actor_user_id)));

        return AuditLogResource::collection($logs);
    })->name('audit-logs.index');
});
