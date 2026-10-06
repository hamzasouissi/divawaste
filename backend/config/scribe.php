<?php

use App\Modules\Common\Docs\ClosureBodyParameters;
use App\Modules\Common\Docs\ClosureQueryParameters;
use App\Modules\Common\Docs\RouteMetadata;
use App\Modules\Common\Docs\RouteUrlParameters;
use App\Modules\Common\Docs\StandardErrors;
use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\configureStrategy;

/*
 * API documentation (cahier §5: OpenAPI generated automatically). `php artisan api:docs` — docs/DEVELOPER_RUNBOOK.md.
 *
 * Scribe is a development dependency: without it (production install) this file configures nothing.
 */
if (! class_exists(Defaults::class)) {
    return [];
}

return [
    'title' => 'DivaWaste Textile — API',
    'description' => 'REST API of DivaWaste Textile: waste traceability for textile manufacturers and their waste providers.',
    'intro_text' => <<<'INTRO'
        This documentation is generated from the code of the API (`php artisan api:docs`): the routes, the permission
        each one checks, the parameters it validates and the errors it can answer are read from the route definitions.
        The example responses are real answers of the API on the demonstration dataset.

        **Base path** `/api/v1`. **Format** JSON; a successful answer wraps its payload in `data`, lists add `meta`
        (pagination). Identifiers are public ULIDs, never database ids. Dates are ISO 8601 in UTC; weights are decimal
        strings in kilograms; amounts are decimal strings with 3 decimals.

        **Errors** use RFC 7807 (`application/problem+json`): `status`, a stable `code` (`UNAUTHENTICATED`,
        `FORBIDDEN`, `NOT_FOUND`, `VALIDATION_FAILED` with `errors` per field, or the code of a business rule with
        status 409), and `request_id` to quote to support. Requests are limited per user (HTTP 429 beyond the limit).

        **Tenant isolation**: every route answers for the company of the session or token only. A record of another
        company answers 404, exactly like an unknown one.
        INTRO,
    'base_url' => config('app.url'),
    'routes' => [
        ['match' => ['prefixes' => ['api/*'], 'domains' => ['*']], 'include' => [], 'exclude' => []],
    ],
    // Served by the application at /docs (see `laravel` below); the OpenAPI file is also copied to docs/api/.
    'type' => 'laravel',
    'theme' => 'default',
    'static' => ['output_path' => 'public/docs'],
    'laravel' => [
        // Internal API (no public API in the MVP): the documentation is not published by a production server.
        'add_routes' => (bool) env('API_DOCS_ENABLED', env('APP_ENV', 'production') !== 'production'),
        'docs_url' => '/docs',
        'assets_directory' => null,
        'middleware' => [],
    ],
    'external' => ['html_attributes' => []],
    'try_it_out' => ['enabled' => true, 'base_url' => null, 'use_csrf' => false, 'csrf_url' => '/sanctum/csrf-cookie'],
    'auth' => [
        'enabled' => true,
        // Each route says whether it needs authentication: read from its middleware.
        'default' => false,
        'in' => AuthIn::BEARER->value,
        'name' => 'Authorization',
        'use_value' => null,
        'placeholder' => '{ACCESS_TOKEN}',
        'extra_info' => <<<'AUTH'
            Three ways to authenticate, all through Laravel Sanctum:

            - **Web application** — cookie session: `GET /sanctum/csrf-cookie`, then `POST /api/v1/auth/login` with `"mode": "session"`. Every write then carries the `X-XSRF-TOKEN` header.
            - **Scripts and tests** — bearer token: `POST /api/v1/auth/login` with `"mode": "token"` returns `access_token` (12 hours). Send it as `Authorization: Bearer {ACCESS_TOKEN}`.
            - **Workshop devices** — device token: `POST /api/v1/mobile/devices` (signed in) returns a 30-day token that is only accepted by `POST /api/v1/sync/push`.

            Sign-in enforces email verification, lockout after 5 failures, and the second factor (TOTP) when the account enabled it. A user who belongs to several companies chooses one: the session or token then answers for that company only.
            AUTH,
    ],
    'example_languages' => ['bash', 'javascript'],
    'postman' => ['enabled' => true, 'overrides' => []],
    'openapi' => ['enabled' => true, 'version' => '3.0.3', 'overrides' => [], 'generators' => []],
    'groups' => [
        'default' => 'Application',
        'order' => ['Identity', 'Tenancy', 'Billing', 'Platform', 'Sites', 'Catalog', 'Waste', 'Sync', 'Providers', 'Pickups', 'Documents', 'Reporting', 'Notifications', 'Audit'],
    ],
    'logo' => false,
    'last_updated' => 'Generated on {date:j F Y}',
    'examples' => ['faker_seed' => 1234, 'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst']],
    'strategies' => [
        'metadata' => [...Defaults::METADATA_STRATEGIES, RouteMetadata::class],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: ['Content-Type' => 'application/json', 'Accept' => 'application/json']),
        ],
        'urlParameters' => [...Defaults::URL_PARAMETERS_STRATEGIES, RouteUrlParameters::class],
        'queryParameters' => [...Defaults::QUERY_PARAMETERS_STRATEGIES, ClosureQueryParameters::class],
        'bodyParameters' => [...Defaults::BODY_PARAMETERS_STRATEGIES, ClosureBodyParameters::class],
        'responses' => [
            ...configureStrategy(
                Defaults::RESPONSES_STRATEGIES,
                // Only reads are called, each inside a transaction that is rolled back, and only on the demo dataset
                // (elsewhere there is nobody to sign in as). Routes that answer a file are described, not called.
                Strategies\Responses\ResponseCalls::withSettings(
                    only: env('APP_ENV') === 'demo' ? [
                        'GET *',
                    ] : ['GET api/v1/registration/options'],
                    config: ['app.debug' => false],
                    // Three rows are enough to show the shape of a list.
                    queryParams: ['per_page' => 3],
                )
            ),
            StandardErrors::class,
        ],
        'responseFields' => [...Defaults::RESPONSE_FIELDS_STRATEGIES],
    ],
    'database_connections_to_transact' => [config('database.default')],
    'fractal' => ['serializer' => null],
];
