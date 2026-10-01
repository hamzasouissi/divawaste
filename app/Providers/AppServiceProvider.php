<?php

namespace App\Providers;

use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
