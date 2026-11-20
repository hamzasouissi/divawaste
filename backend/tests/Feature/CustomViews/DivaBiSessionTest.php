<?php

namespace Tests\Feature\CustomViews;

use App\Modules\CustomViews\Support\DivaBiPackage;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Identity\Models\UserRoleAssignment;
use App\Modules\Tenancy\Support\CompanyModules;
use App\Modules\Tenancy\TableClassification;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Custom views (DivaBI): the token that vouches for the user carries their own permissions and sites, nothing more,
 * and the application package opens only the tables listed in config/divabi.php, under the company's rules.
 */
class DivaBiSessionTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private const KEY = 'divabi-test-key-0123456789-abcdefghijklmnop';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        config(['divabi.key' => self::KEY]);
    }

    /** @return array<string, mixed> */
    private function claims(string $token): array
    {
        [$header, $payload, $signature] = explode('.', $token);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", self::KEY, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $signature, 'the token is signed with the shared key');

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    }

    public function test_an_administrator_gets_a_token_with_company_wide_permissions(): void
    {
        $company = $this->makeCompany();
        $this->makeSite($company, 'NAB');
        $admin = $this->makeMember($company, ['client_admin' => null]);

        $response = $this->withToken($this->tokenFor($admin, $company))->getJson('/api/v1/custom-views/session')->assertOk();

        $this->assertTrue($response->json('data.can_build'));
        $this->assertSame('/divabi', $response->json('data.url'));
        $claims = $this->claims($response->json('data.token'));
        $this->assertSame($company->ulid, $claims['tenant']);
        $this->assertSame($admin->ulid, $claims['sub']);
        $this->assertSame(['divawaste', 'divabi'], [$claims['iss'], $claims['aud']]);
        $this->assertArrayHasKey('custom_views.manage', $claims['perms']);
        $this->assertNull($claims['perms']['lots.view']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertStringNotContainsString('"id"', json_encode($claims), 'no internal id leaves the API');
    }

    public function test_site_permissions_carry_the_public_ids_of_the_sites(): void
    {
        $company = $this->makeCompany();
        $nabeul = $this->makeSite($company, 'NAB');
        $this->makeSite($company, 'KOR');
        $manager = $this->makeMember($company, ['environment_manager' => $nabeul]);

        // Custom views are a company permission: a role given on one site only does not open them.
        $this->withToken($this->tokenFor($manager, $company))->getJson('/api/v1/custom-views/session')->assertForbidden();

        // With the company-wide right to open views, the token keeps the manager's site limits.
        $role = DB::table('roles')->insertGetId(['company_id' => $company->id, 'name' => 'views_reader', 'guard_name' => 'web',
            'label' => '{"fr":"Lecteur de vues"}', 'audience' => 'industrial', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_has_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('name', 'custom_views.view')->value('id')]);
        $this->tenant()->runAs($company, fn () => UserRoleAssignment::query()->create(['user_id' => $manager->id, 'role_id' => $role, 'site_id' => null]));
        app(AccessResolver::class)->flushAll();

        $response = $this->withToken($this->tokenFor($manager, $company))->getJson('/api/v1/custom-views/session')->assertOk();

        $this->assertFalse($response->json('data.can_build'));
        $perms = $this->claims($response->json('data.token'))['perms'];
        $this->assertSame([$nabeul->ulid], $perms['lots.view']);
        $this->assertNull($perms['custom_views.view']);
        $this->assertArrayNotHasKey('custom_views.manage', $perms);
    }

    public function test_switched_off_modules_are_left_out_of_the_token(): void
    {
        $company = $this->makeCompany();
        $this->makeSite($company, 'NAB');
        $admin = $this->makeMember($company, ['client_admin' => null]);
        CompanyModules::set($company, ['production' => false, 'marketplace' => false]);

        $perms = $this->claims($this->withToken($this->tokenFor($admin, $company))->getJson('/api/v1/custom-views/session')->assertOk()->json('data.token'))['perms'];

        $this->assertEmpty(array_filter(array_keys($perms), fn (string $name) => str_starts_with($name, 'production.') || str_starts_with($name, 'marketplace.')));
        $this->assertArrayHasKey('lots.view', $perms);
    }

    public function test_users_without_custom_views_are_refused_and_the_feature_is_off_without_a_key(): void
    {
        $company = $this->makeCompany();
        $site = $this->makeSite($company, 'NAB');
        $operator = $this->makeMember($company, ['workshop_operator' => $site]);
        $this->withToken($this->tokenFor($operator, $company))->getJson('/api/v1/custom-views/session')->assertForbidden();

        $viewer = $this->makeMember($company, ['management_viewer' => null]);
        $response = $this->withToken($this->tokenFor($viewer, $company))->getJson('/api/v1/custom-views/session')->assertOk();
        $this->assertFalse($response->json('data.can_build'));

        config(['divabi.key' => null]);
        $this->withToken($this->tokenFor($viewer, $company))->getJson('/api/v1/custom-views/session')->assertStatus(503);
    }

    public function test_the_package_opens_only_listed_tables_under_the_company_rules(): void
    {
        $translations = fn (string $locale) => json_decode((string) file_get_contents(base_path("../frontend/public/i18n/{$locale}.json")), true);
        $package = DivaBiPackage::build(config('divabi'), fn (string $table) => Schema::getColumnListing($table), $translations);

        $lots = $package['entities']['waste_lots'];
        $this->assertSame('allow', $lots['access']);
        $this->assertSame(['lots.view'], $lots['requires']);
        $this->assertContains(['type' => 'tenant', 'column' => 'company_id'], $lots['rows']);
        $this->assertContains(['type' => 'scopes', 'column' => 'site_id', 'permission' => 'lots.view'], $lots['rows']);
        $this->assertContains(['type' => 'is_null', 'column' => 'deleted_at'], $lots['rows']);
        $this->assertContains(['type' => 'tenant_wide', 'column' => 'company_id', 'permission' => 'pickups.view'], $package['entities']['pickup_lots']['rows']);
        $this->assertSame(['pickups.price'], $package['entities']['pickup_lots']['column_requires']['unit_price']);
        foreach (['users', 'personal_access_tokens', 'user_invitations', 'audit_logs', 'permissions'] as $closed) {
            $this->assertSame(['access' => 'deny'], $package['entities'][$closed], $closed);
        }
        // Stored values carry the application's own labels, in both languages.
        $this->assertSame(['fr' => 'Stocké', 'en' => 'In stock'], $lots['value_labels']['status']['stored']);
        foreach (config('divabi.value_labels') as $table => $columns) {
            foreach ($columns as $column => $group) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column}");
                $this->assertNotEmpty($package['entities'][$table]['value_labels'][$column] ?? [], "{$table}.{$column} <- {$group}");
            }
        }
        // Every table the configuration opens exists and is classified.
        foreach (array_keys(config('divabi.entities')) as $table) {
            $this->assertContains($table, TableClassification::all(), $table);
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }
}
