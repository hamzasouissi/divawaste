<?php

use App\Modules\CustomViews\Support\DivaBiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Custom views (DivaBI): the screens ask for a short-lived token, then talk to DivaBI with it. The token carries the
 * user's own permissions, so DivaBI shows only what the user may see here. Building views needs
 * `custom_views.manage`; opening them, `custom_views.view`.
 */
Route::middleware(['auth:sanctum', 'tenant', 'throttle:60,1'])->prefix('custom-views')->group(function () {
    Route::get('session', function (Request $request, DivaBiToken $tokens) {
        $user = $request->user();
        abort_unless($user->can('custom_views.view') || $user->can('custom_views.manage'), 403);
        abort_unless(DivaBiToken::configured(), 503, 'Custom views are off: DIVABI_KEY is empty or shorter than 32 characters in the environment file this API reads (.env, or .env.demo for the demo). Set it, then php artisan config:clear.');
        $issued = $tokens->issue($user);

        return ['data' => [
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'url' => rtrim((string) config('divabi.url'), '/'),
            'can_build' => $user->can('custom_views.manage'),
        ]];
    })->name('custom-views.session');
});
