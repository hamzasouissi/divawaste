<?php

namespace App\Modules\Audit\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Append-only audit trail (M7-02): who did what to which object, with old and new values, time and IP.
 * `company_id` is the company that owns the object; `actor_company_id` the one the actor was working for
 * (they differ when a provider acts on an industrial's pickup).
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function record(array $attributes): void
    {
        $tenant = app(TenantContext::class);
        $console = app()->runningInConsole() && ! app()->runningUnitTests();
        $request = $console ? null : request();
        $actor = Auth::user();
        $actorId = $attributes['actor_user_id'] ?? ($actor instanceof User ? $actor->id : null);
        $currentCompany = $tenant->hasTenant() ? $tenant->companyId() : null;

        DB::table('audit_logs')->insert([
            'created_at' => now(),
            'company_id' => $attributes['company_id'] ?? $currentCompany,
            'actor_type' => $attributes['actor_type'] ?? ($actorId !== null ? 'user' : ($console ? 'scheduler' : 'system')),
            'actor_user_id' => $actorId,
            'actor_company_id' => $attributes['actor_company_id'] ?? $currentCompany,
            'impersonator_user_id' => $attributes['impersonator_user_id'] ?? null,
            'event' => $attributes['event'] ?? 'updated',
            'auditable_type' => $attributes['auditable_type'] ?? 'unknown',
            'auditable_id' => $attributes['auditable_id'] ?? null,
            'auditable_ulid' => $attributes['auditable_ulid'] ?? null,
            'parent_type' => $attributes['parent_type'] ?? null,
            'parent_id' => $attributes['parent_id'] ?? null,
            'old_values' => self::json($attributes['old_values'] ?? null),
            'new_values' => self::json($attributes['new_values'] ?? null),
            'request_id' => $attributes['request_id'] ?? Context::get('request_id'),
            'source' => $attributes['source'] ?? self::source($actor, $console),
            'device_id' => $attributes['device_id'] ?? null,
            'ip_address' => $attributes['ip_address'] ?? $request?->ip(),
            'user_agent' => $attributes['user_agent'] ?? ($request === null ? null : mb_substr((string) $request->userAgent(), 0, 512)),
            'url' => $attributes['url'] ?? ($request === null ? null : mb_substr($request->fullUrl(), 0, 500)),
            'http_method' => $attributes['http_method'] ?? $request?->method(),
            'tags' => self::json($attributes['tags'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public static function recordModel(Model $model, string $event, array $oldValues = [], array $newValues = []): void
    {
        // Raw attributes: strict models throw on columns they do not have (ulid, company_id).
        $attributes = $model->getAttributes();

        static::record([
            'event' => $event,
            'company_id' => $model instanceof Company ? $model->getKey() : ($attributes['company_id'] ?? null),
            'auditable_type' => $model->getTable(),
            'auditable_id' => $model->getKey(),
            'auditable_ulid' => $attributes['ulid'] ?? null,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    private static function source(mixed $actor, bool $console): string
    {
        if ($console) {
            return 'console';
        }

        $token = $actor instanceof User ? $actor->currentAccessToken() : null;

        return match (true) {
            $token instanceof PersonalAccessToken && $token->device_id !== null => 'mobile',
            $token instanceof PersonalAccessToken => 'api',
            $actor instanceof User => 'web',
            default => 'system',
        };
    }

    /**
     * @param  array<string, mixed>|null  $values
     */
    private static function json(?array $values): ?string
    {
        return $values === null || $values === [] ? null : (string) json_encode($values, JSON_UNESCAPED_UNICODE);
    }
}
