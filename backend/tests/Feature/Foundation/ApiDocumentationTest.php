<?php

namespace Tests\Feature\Foundation;

use App\Modules\Common\Docs\ApiRoutes;
use App\Modules\Common\Docs\RouteSource;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Cahier §5: the OpenAPI documentation is generated from the code. This keeps the committed file honest: a route
 * added, renamed or removed without regenerating it (`php artisan api:docs`) fails here.
 */
class ApiDocumentationTest extends TestCase
{
    private const FILE = 'docs/api/openapi.yaml';

    public function test_every_api_route_is_documented_and_nothing_else(): void
    {
        $documented = ApiRoutes::documented(base_path(self::FILE));

        $this->assertSame([], array_values(array_diff(ApiRoutes::exposed(), $documented)), 'Routes missing from docs/api/openapi.yaml: run "php artisan api:docs".');
        $this->assertSame([], array_values(array_diff($documented, ApiRoutes::exposed())), 'docs/api/openapi.yaml documents routes that no longer exist: run "php artisan api:docs".');
    }

    public function test_the_documentation_states_authentication_parameters_and_errors(): void
    {
        $spec = Yaml::parseFile(base_path(self::FILE));

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['default']['scheme']);
        // Public routes say so; every other one inherits the bearer requirement.
        $this->assertSame([], $spec['paths']['/api/v1/auth/login']['post']['security']);
        $this->assertArrayNotHasKey('security', $spec['paths']['/api/v1/lots']['get']);

        $activate = $spec['paths']['/api/v1/platform/companies/{company}/subscription']['post'];
        $this->assertSame('Billing', $activate['tags'][0]);
        $this->assertStringContainsString('platform.invoices.manage', $activate['description']);
        $body = $activate['requestBody']['content']['application/json']['schema'];
        $this->assertEqualsCanonicalizing(['plan', 'billing_interval', 'sites_quantity', 'unit_amount'], $body['required']);
        $this->assertSame(['monthly', 'yearly'], $body['properties']['billing_interval']['enum']);
        $this->assertEqualsCanonicalizing([401, 403, 404, 409, 422], array_map('intval', array_keys($activate['responses'])));
        $this->assertStringContainsString('COMPANY_NOT_ACTIVE', $activate['responses'][409]['description']);
    }

    public function test_what_is_documented_is_read_from_the_route_itself(): void
    {
        $source = RouteSource::of(Route::getRoutes()->getByName('platform.subscriptions.activate'));

        $this->assertNotNull($source);
        $this->assertSame('Billing', $source->module());
        $this->assertSame(['platform.invoices.manage'], $source->permissions());
        $this->assertSame(['COMPANY_NOT_ACTIVE'], $source->businessErrors());
        $this->assertSame(['required', 'in:monthly,yearly'], $source->validationRules()['billing_interval']);
        $this->assertSame(['required', 'integer', 'between:1,500'], $source->validationRules()['sites_quantity']);
    }
}
