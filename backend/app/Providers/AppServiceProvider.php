<?php

namespace App\Providers;

use App\Modules\Common\Docs\DemoData;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Channels\LoggedMailChannel;
use App\Modules\Pickups\Support\PartyDirectory;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Knuckles\Scribe\Scribe;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reset for every request and every queued job.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ReferenceCodes::class);
        $this->app->scoped(PartyDirectory::class);

        // Every notification email leaves a trace (notification_logs): sent or failed, and through which transport.
        $this->app->bind(MailChannel::class, LoggedMailChannel::class);

        // API only: Fortify provides the TOTP engine (cahier §5), never its session/web routes.
        Fortify::ignoreRoutes();
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

        // Cahier §4: request limit per client. Authenticated calls are counted per user, the rest per IP.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('app.api_rate_limit', 240))
            ->by($request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip()));

        // Generated PDFs: only the glyphs used are embedded (a label drops from ~1 MB to a few kB), and a
        // document can never pull a remote resource or run PHP, whatever text ends up in it.
        config([
            'dompdf.options.enable_font_subsetting' => true,
            'dompdf.options.enable_remote' => false,
            'dompdf.options.enable_php' => false,
            'dompdf.options.enable_javascript' => false,
        ]);

        DB::prohibitDestructiveCommands($this->app->isProduction());

        // API documentation (development dependency): its example calls are made as accounts of the demo dataset.
        if ($this->app->runningInConsole() && class_exists(Scribe::class)) {
            Scribe::beforeResponseCall(DemoData::authenticate(...));
            Scribe::afterResponseCall(DemoData::summariseFile(...));
            // Document the URLs as they are routed ("lots/{lot}"), not renamed after the model key.
            Scribe::normalizeEndpointUrlUsing(fn (string $url) => $url);
        }
    }
}
