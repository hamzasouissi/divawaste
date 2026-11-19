<?php

namespace App\Modules\CustomViews\Support;

use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Authorization\PermissionCatalog;
use App\Modules\Identity\Enums\PermissionScope;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The token that lets the browser talk to DivaBI on the user's behalf (custom views).
 *
 * DivaBI has no account and no permission of its own: it reads what this token says, signed with the secret both
 * sides share. The token carries the user's own tenant permissions exactly as AccessResolver computes them — each
 * with the public ids (ULIDs) of the sites where it applies, or null for "every site of the company" — so a custom
 * view never shows a row the user's screens would hide. Internal ids never leave this API.
 */
final class DivaBiToken
{
    public function __construct(private readonly AccessResolver $access, private readonly TenantContext $context) {}

    public static function configured(): bool
    {
        return strlen((string) config('divabi.key')) >= 32;
    }

    /**
     * @return array{token: string, expires_at: string, claims: array<string, mixed>}
     */
    public function issue(User $user): array
    {
        $companyUlid = (string) DB::table('companies')->where('id', $this->context->companyId())->value('ulid');
        $now = now();
        $expires = $now->copy()->addMinutes((int) config('divabi.token_minutes', 15));
        $claims = [
            'iss' => (string) config('divabi.issuer', 'divawaste'),
            'aud' => (string) config('divabi.audience', 'divabi'),
            'sub' => (string) $user->ulid,
            'name' => $user->fullName(),
            'tenant' => $companyUlid,
            'locale' => app()->getLocale(),
            'perms' => $this->permissions($user),
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $expires->getTimestamp(),
            'jti' => (string) Str::ulid(),
        ];

        return ['token' => Jwt::encode($claims, (string) config('divabi.key')), 'expires_at' => $expires->toIso8601ZuluString(), 'claims' => $claims];
    }

    /**
     * Tenant permissions the user holds: name => null (company-wide) or the ULIDs of the sites where it applies.
     *
     * @return array<string, list<string>|null>
     */
    public function permissions(User $user): array
    {
        $siteUlids = DB::table('sites')->where('company_id', $this->context->companyId())->pluck('ulid', 'id');
        $granted = [];
        foreach (PermissionCatalog::permissions() as $name => [$scope]) {
            if ($scope === PermissionScope::Platform) {
                continue;
            }
            if ($scope === PermissionScope::Company) {
                if ($this->access->can($user, $name)) {
                    $granted[$name] = null;
                }

                continue;
            }
            $sites = $this->access->siteIdsFor($user, $name);
            if ($sites === null) {
                $granted[$name] = null;
            } elseif ($sites !== []) {
                $granted[$name] = array_values(array_filter(array_map(fn (int $id) => $siteUlids[$id] ?? null, $sites)));
            }
        }

        return $granted;
    }
}
