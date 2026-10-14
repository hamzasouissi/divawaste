<?php

namespace App\Modules\Notifications\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Sends a business event (M7-01) to the people entitled to see it. Recipients are always resolved from
 * the permission matrix, never from role names, so the matrix stays the single place that decides who is told.
 *
 * Who is told and who gets an email are two questions. Everyone entitled sees the event in the application. For a
 * stock or capacity alert, the email goes by default only to those who manage the limits or the zones of the site
 * (NotificationCatalog::STOCK_MANAGERS) — a workshop operator who sees the stock reads the alert in the application,
 * and gets the email only if they ask for it. Each person's own choice, either way, always wins.
 */
final class NotifyUsers
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Members of the current company holding the permission on the site (or on all sites).
     *
     * @param  array<string, scalar|null>  $params
     */
    public function toSitePermission(int $siteId, string $permission, string $type, array $params, ?string $link = null): void
    {
        $companyId = $this->context->companyId();
        $onSite = fn (Builder $query) => $query->whereNull('ura.site_id')->orWhere('ura.site_id', $siteId);
        $managers = NotificationCatalog::mailDefaultPermissions($type);
        $this->send($this->holders($companyId, $permission)->where($onSite), $companyId, $type, $params, $link,
            $managers === null ? null : $this->holders($companyId, $managers)->where($onSite));
    }

    /**
     * Members of a company (the current one or the other party of a pickup) holding the permission.
     *
     * @param  array<string, scalar|null>  $params
     */
    public function toCompanyPermission(int $companyId, string $permission, string $type, array $params, ?string $link = null): void
    {
        $managers = NotificationCatalog::mailDefaultPermissions($type);
        $this->send($this->holders($companyId, $permission), $companyId, $type, $params, $link, $managers === null ? null : $this->holders($companyId, $managers));
    }

    /**
     * Platform staff holding a platform permission (no company).
     *
     * @param  array<string, scalar|null>  $params
     */
    public function toPlatformPermission(string $permission, string $type, array $params, ?string $link = null): void
    {
        $query = DB::table('model_has_roles as mhr')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'mhr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('mhr.model_type', 'user')->where('p.name', $permission)
            ->select('mhr.model_id as user_id');

        $this->send($query, null, $type, $params, $link);
    }

    /**
     * @param  string|list<string>  $permission  one permission, or any of several
     */
    private function holders(int $companyId, string|array $permission): Builder
    {
        return DB::table('user_role_assignments as ura')
            ->join('company_users as cu', fn ($join) => $join->on('cu.company_id', '=', 'ura.company_id')->on('cu.user_id', '=', 'ura.user_id'))
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'ura.role_id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('ura.company_id', $companyId)->where('cu.status', 'active')->whereIn('p.name', (array) $permission)
            ->select('ura.user_id');
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @param  Builder|null  $mailByDefault  among the recipients, those who get the email unless they switched it off; null = all of them
     */
    private function send(Builder $userIds, ?int $companyId, string $type, array $params, ?string $link, ?Builder $mailByDefault = null): void
    {
        if (! NotificationCatalog::isValidType($type)) {
            throw new InvalidArgumentException("Unknown notification type: {$type}");
        }

        $users = User::query()->whereIn('id', $userIds->distinct()->pluck('user_id'))->where('status', 'active')->get();
        if ($users->isNotEmpty()) {
            $defaults = $mailByDefault?->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
            Notification::send($users, new BusinessEventNotification($type, $params, $link, $companyId, $defaults));
        }
    }
}
