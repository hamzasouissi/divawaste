<?php

namespace App\Providers;

use App\Modules\Common\Database\BlueprintMacros;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Loads each module's API routes under /api/v1.
 * Migrations stay in database/migrations to keep one global dependency order.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        BlueprintMacros::register();
    }

    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        foreach (glob(app_path('Modules/*/Routes/api.php')) ?: [] as $routes) {
            Route::middleware('api')->prefix('api/v1')->group($routes);
        }
    }
}
