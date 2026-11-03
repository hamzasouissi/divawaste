<?php

namespace Tests\Feature\Providers;

use App\Modules\Identity\Models\User;
use App\Modules\Providers\Support\OperatorRegistry;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The ANGed lists: loaded from the extractor's file, searched by a company and by the platform.
 */
class OperatorRegistryTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
    }

    public function test_the_file_is_loaded_once_and_a_new_edition_replaces_it(): void
    {
        $path = $this->file([$this->entry('a', 'Recyclage du Cap', 'textile', 'Nabeul'), $this->entry('b', 'Batteries du Nord', 'batteries', 'Bizerte', true)]);
        $first = app(OperatorRegistry::class)->import($path);
        $this->assertSame(['inserted' => 2, 'updated' => 0, 'removed' => 0], array_intersect_key($first, ['inserted' => 0, 'updated' => 0, 'removed' => 0]));
        $this->assertSame(2, app(OperatorRegistry::class)->import($path)['unchanged']);

        // The next edition: one entry changed its phone, the other is no longer listed, a third appears.
        $next = $this->file([array_merge($this->entry('a', 'Recyclage du Cap', 'textile', 'Nabeul'), ['phone' => '72000000']), $this->entry('c', 'Verre Sahel', 'verre', 'Sousse')]);
        $summary = app(OperatorRegistry::class)->import($next);
        $this->assertSame([1, 1, 1], [$summary['inserted'], $summary['updated'], $summary['removed']]);
        $this->assertEqualsCanonicalizing(['Recyclage du Cap', 'Verre Sahel'], DB::table('regulatory_operators')->pluck('legal_name')->all());
        $this->assertSame('72000000', DB::table('regulatory_operators')->where('legal_name', 'Recyclage du Cap')->value('phone'));
    }

    public function test_a_company_searches_the_list_and_the_platform_reads_it_too(): void
    {
        app(OperatorRegistry::class)->import($this->file([
            $this->entry('a', 'Recyclage du Cap', 'textile', 'Nabeul'),
            $this->entry('b', 'Batteries du Nord', 'batteries', 'Bizerte', true) + ['valid_until' => now()->subDay()->toDateString(), 'authorization_number' => '02-2024'],
            array_merge($this->entry('c', 'Textile Collecte Sousse', 'textile', 'Sousse'), ['is_recycler' => false]),
        ]));
        $company = $this->makeCompany();
        $token = $this->tokenFor($this->makeMember($company, ['client_admin' => null]), $company);

        $found = $this->withToken($token)->getJson('/api/v1/registry/operators?filter[search]=textile&filter[governorate]=Nabeul')->assertOk();
        $this->assertSame(['Recyclage du Cap'], array_column($found->json('data'), 'legal_name'));
        $this->assertSame(['collector', 'transporter', 'recycler'], $found->json('data.0.roles'));

        $this->withToken($token)->getJson('/api/v1/registry/operators?filter[role]=recycler&filter[list]=textile')->assertOk()->assertJsonCount(1, 'data');
        $expired = $this->withToken($token)->getJson('/api/v1/registry/operators?filter[hazardous]=1')->assertOk();
        $this->assertTrue($expired->json('data.0.expired'));
        $this->withToken($token)->getJson('/api/v1/registry/operators?filter[hazardous]=1&filter[valid]=1')->assertOk()->assertJsonCount(0, 'data');
        $lists = $this->withToken($token)->getJson('/api/v1/registry/operators/lists')->assertOk();
        $this->assertSame(3, $lists->json('data.total'));
        $this->assertSame('batteries', $lists->json('data.lists.0.code'));

        // A workshop operator does not look for partners; the platform does.
        $operator = $this->makeMember($company, ['workshop_operator' => $this->makeSite($company, 'S1')]);
        app('auth')->forgetGuards();
        $this->withToken($this->tokenFor($operator, $company))->getJson('/api/v1/registry/operators')->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        app('auth')->forgetGuards();
        $this->withToken($admin->createToken('t')->plainTextToken)->getJson('/api/v1/platform/registry/operators?filter[search]=batteries')
            ->assertOk()->assertJsonPath('data.0.authorization_number', '02-2024');
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $key, string $name, string $list, string $governorate, bool $hazardous = false): array
    {
        return [
            'fingerprint' => sha1($key), 'list' => $list, 'list_code' => $list, 'list_title' => 'Déchets '.ucfirst($list), 'hazardous' => $hazardous,
            'legal_name' => $name, 'phone' => '71000000', 'head_office' => "Zone industrielle {$governorate}", 'governorate' => $governorate,
            'activity' => 'Collecte-Transport et Recyclage-Valorisation', 'waste' => 'Déchets '.ucfirst($list),
            'is_collector' => true, 'is_transporter' => true, 'is_recycler' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $operators
     */
    private function file(array $operators): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'anged');
        file_put_contents($path, json_encode(['registry' => 'anged', 'extracted_on' => '2026-11-03', 'operators' => $operators]));

        return $path;
    }
}
