<?php

namespace App\Providers;

use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reset for every request and every queued job.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Lazy loading, silently discarded and missing attributes fail outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Stable aliases in polymorphic columns (Spatie model_has_roles, audit, tokens).
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        // Tenant permissions are site-aware: $user->can('lots.create', $site). Platform ones stay with Spatie.
        Gate::before(function (mixed $user, string $ability, array $arguments) {
            $access = app(AccessResolver::class);
            if (! $user instanceof User || ! $access->isTenantPermission($ability)) {
                return null;
            }

            $site = $arguments[0] ?? null;

            return $access->can($user, $ability, $site instanceof Site || is_int($site) ? $site : null);
        });

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
