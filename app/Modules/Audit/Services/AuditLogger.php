<?php

namespace App\Modules\Audit\Services;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function record(array $attributes): void
    {
        $request = app('request');
        $tenant = app(TenantContext::class);

        $payload = [
            'created_at' => now(),
            'company_id' => $attributes['company_id'] ?? ($tenant->hasTenant() ? $tenant->companyId() : null),
            'actor_type' => $attributes['actor_type'] ?? 'system',
            'actor_user_id' => $attributes['actor_user_id'] ?? null,
            'actor_company_id' => $attributes['actor_company_id'] ?? null,
            'impersonator_user_id' => $attributes['impersonator_user_id'] ?? null,
            'event' => $attributes['event'] ?? 'updated',
            'auditable_type' => $attributes['auditable_type'] ?? 'unknown',
            'auditable_id' => $attributes['auditable_id'] ?? null,
            'auditable_ulid' => $attributes['auditable_ulid'] ?? null,
            'parent_type' => $attributes['parent_type'] ?? null,
            'parent_id' => $attributes['parent_id'] ?? null,
            'old_values' => array_key_exists('old_values', $attributes) && $attributes['old_values'] !== null ? json_encode($attributes['old_values']) : null,
            'new_values' => array_key_exists('new_values', $attributes) && $attributes['new_values'] !== null ? json_encode($attributes['new_values']) : null,
            'request_id' => $attributes['request_id'] ?? ($request ? $request->header('X-Request-Id') : null) ?? (string) Str::ulid(),
            'source' => $attributes['source'] ?? ($request ? 'api' : 'system'),
            'device_id' => $attributes['device_id'] ?? null,
            'ip_address' => $attributes['ip_address'] ?? ($request ? $request->ip() : null),
            'user_agent' => $attributes['user_agent'] ?? ($request ? $request->userAgent() : null),
            'url' => $attributes['url'] ?? ($request ? $request->fullUrl() : null),
            'http_method' => $attributes['http_method'] ?? ($request ? $request->method() : null),
            'tags' => array_key_exists('tags', $attributes) && $attributes['tags'] !== null ? json_encode($attributes['tags']) : null,
        ];

        DB::table('audit_logs')->insert($payload);
    }

    /**
     * @param  Model  $model
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public static function recordModel(Model $model, string $event, array $oldValues = [], array $newValues = []): void
    {
        $tenant = app(TenantContext::class);
        $companyId = $model->getAttribute('company_id') ?? ($tenant->hasTenant() ? $tenant->companyId() : null);
        $auditableUlid = $model->getAttribute('ulid') ?? $model->getAttribute('public_ulid') ?? null;

        static::record([
            'event' => $event,
            'company_id' => $companyId,
            'auditable_type' => $model->getTable(),
            'auditable_id' => $model->getKey(),
            'auditable_ulid' => $auditableUlid,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
