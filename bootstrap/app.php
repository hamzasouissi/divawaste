<?php

use App\Modules\Common\Http\Middleware\AssignRequestId;
use App\Modules\Common\Http\ProblemDetails;
use App\Modules\Tenancy\Http\Middleware\EstablishTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias(['tenant' => EstablishTenantContext::class]);
        // Tenant context must exist before route-model binding queries scoped models.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EstablishTenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ProblemDetails::render($e, $request);
            }

            return null;
        });
    })->create();
