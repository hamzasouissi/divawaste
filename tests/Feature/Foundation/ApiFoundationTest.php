<?php

namespace Tests\Feature\Foundation;

use App\Modules\Common\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_health_endpoint_is_versioned_and_reaches_the_database(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertHeader(AssignRequestId::HEADER);
    }

    public function test_unknown_api_route_returns_problem_json_with_request_id(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist', [AssignRequestId::HEADER => 'test-request-0001']);

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['status' => 404, 'code' => 'NOT_FOUND', 'request_id' => 'test-request-0001']);
    }

    public function test_validation_errors_use_problem_json(): void
    {
        Route::middleware('api')->post('/api/v1/_test/validate', function () {
            request()->validate(['name' => 'required']);
        });

        $this->postJson('/api/v1/_test/validate')
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_malformed_request_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/health', [AssignRequestId::HEADER => 'bad id with spaces']);

        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', (string) $response->headers->get(AssignRequestId::HEADER));
    }
}
