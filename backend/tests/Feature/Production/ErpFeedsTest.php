<?php

namespace Tests\Feature\Production;

use App\Modules\Identity\Models\User;
use App\Modules\Production\Models\ProductionClient;
use App\Modules\Production\Support\ErpFeeds;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * G-03 / PRD-05: an export an ERP publishes at an address is read every night with the columns of the last import;
 * the platform only fetches public HTTPS servers, and a night that failed waits on the dashboard.
 */
class ErpFeedsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->manager = $this->makeMember($this->company, ['client_admin' => null]);
        ErpFeeds::$resolver = fn (string $host) => ['93.184.216.34'];
    }

    protected function tearDown(): void
    {
        ErpFeeds::$resolver = null;
        parent::tearDown();
    }

    public function test_an_export_is_read_at_night_with_the_columns_of_the_last_import(): void
    {
        Http::fake(['erp.example.com/*' => Http::response("Ref;Raison sociale;Pays\nC1;Levant Denim;TN\nC2;Atlas Mode;MA\n", 200, ['Content-Type' => 'text/csv'])]);
        // The columns chosen by hand the last time: "Raison sociale" is the name.
        $this->asManager()->putJson('/api/v1/company/import-mappings/clients', ['mapping' => ['name' => 'Raison sociale', 'code' => 'Ref']])->assertOk();

        $this->asManager()->putJson('/api/v1/production/erp-feeds/clients', ['url' => 'https://erp.example.com/export/clients.csv?key=secret', 'enabled' => true])
            ->assertOk()->assertJsonPath('data.host', 'erp.example.com');
        // The address (and its key) never comes back.
        $listed = $this->asManager()->getJson('/api/v1/production/erp-feeds')->assertOk()->json('data');
        $this->assertStringNotContainsString('secret', json_encode($listed));

        $this->assertSame(1, app(ErpFeeds::class)->pullAll());
        $names = $this->tenant()->runAs($this->company, fn () => ProductionClient::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Atlas Mode', 'Levant Denim'], $names);
        $feed = $this->asManager()->getJson('/api/v1/production/erp-feeds')->json('data.0');
        $this->assertNull($feed['last_error']);
        $this->assertSame(2, $feed['last_result']['created']);

        // Switched off: the night leaves it alone.
        $this->asManager()->putJson('/api/v1/production/erp-feeds/clients', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(0, app(ErpFeeds::class)->pullAll());
    }

    public function test_only_public_https_servers_are_fetched(): void
    {
        $this->asManager()->putJson('/api/v1/production/erp-feeds/clients', ['url' => 'http://erp.example.com/clients.csv', 'enabled' => true])->assertJsonValidationErrors('url');
        ErpFeeds::$resolver = fn (string $host) => ['10.0.0.5'];
        $this->asManager()->putJson('/api/v1/production/erp-feeds/clients', ['url' => 'https://intranet.example.com/clients.csv', 'enabled' => true])->assertJsonValidationErrors('url');
        $this->asManager()->putJson('/api/v1/production/erp-feeds/clients', ['url' => 'https://169.254.169.254/latest', 'enabled' => true])->assertJsonValidationErrors('url');
        $this->asManager()->getJson('/api/v1/production/erp-feeds')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_night_that_failed_waits_on_the_dashboard(): void
    {
        Http::fake(['erp.example.com/*' => Http::response('Service unavailable', 503)]);
        $this->asManager()->putJson('/api/v1/production/erp-feeds/clients', ['url' => 'https://erp.example.com/clients.csv', 'enabled' => true])->assertOk();

        $this->asManager()->postJson('/api/v1/production/erp-feeds/clients/run')->assertOk()->assertJsonPath('data.last_error', 'download');
        $item = collect($this->asManager()->getJson('/api/v1/attention')->assertOk()->json('data'))->firstWhere('key', 'erp_feeds_failed');
        $this->assertSame(1, $item['count']);
        $this->assertSame('/production/import', $item['link']);
    }

    private function asManager(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->company));
    }
}
