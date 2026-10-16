<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Providers\Actions\AlertExpiringAccreditations;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M1-02 to M1-05 (catalogue, attributes, units) and the M4-02 expiry alert.
 */
class CatalogAndAlertsTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
    }

    public function test_client_activates_catalogue_types_edits_them_and_reads_the_vocabularies(): void
    {
        $company = $this->makeCompany();
        $token = $this->tokenFor($this->makeMember($company, ['client_admin' => null]), $company);

        $reference = $this->withToken($token)->getJson('/api/v1/reference')->assertOk()->json('data');
        $this->assertEquals(['fr' => 'Coton', 'en' => 'Cotton'], collect($reference['materials'])->firstWhere('code', 'CO')['name']);
        $this->assertContains('landfill', array_column($reference['treatment_channels'], 'code'));
        $this->assertTrue(collect($reference['regulatory_waste_codes'])->firstWhere('code', '04 02 16')['is_hazardous']);

        // M1-03: the 14 default types are offered, none activated yet.
        $catalogue = $this->withToken($token)->getJson('/api/v1/waste-catalog')->assertOk()->json('data');
        $this->assertCount(14, $catalogue);
        $this->assertNull($catalogue[0]['waste_type']);

        $type = $this->withToken($token)->postJson('/api/v1/waste-types', ['catalog_item' => 'dye_residues', 'code' => 'TEINT'])
            ->assertCreated()->assertJsonPath('data.is_hazardous', true)->assertJsonPath('data.regulatory_code', '04 02 16')
            ->assertJsonPath('data.treatment_channel', 'other_treatment')->assertJsonPath('data.unit', 'kg')->json('data.ulid');
        $this->assertSame($type, collect($this->withToken($token)->getJson('/api/v1/waste-catalog')->json('data'))->firstWhere('code', 'dye_residues')['waste_type']);

        // M1-04: composition with percentages, colour, grammage.
        $this->withToken($token)->putJson("/api/v1/waste-types/{$type}", ['name' => 'Résidus de teinture', 'unit' => 'kg',
            'default_composition' => [['material' => 'CO', 'pct' => '60'], ['material' => 'PES', 'pct' => '30']]])
            ->assertUnprocessable()->assertJsonValidationErrors('default_composition');
        $this->withToken($token)->putJson("/api/v1/waste-types/{$type}", ['name' => 'Résidus de teinture', 'unit' => 'm3', 'density_kg_m3' => '1250',
            'color_family' => 'navy', 'default_grammage_gsm' => '180',
            'default_composition' => [['material' => 'CO', 'pct' => '65'], ['material' => 'PES', 'pct' => '35']]])
            ->assertOk()->assertJsonPath('data.unit', 'm3')->assertJsonPath('data.color_family', 'navy')
            // Classification is frozen: still hazardous, same code.
            ->assertJsonPath('data.is_hazardous', true)->assertJsonPath('data.regulatory_code', '04 02 16');

        $this->withToken($token)->postJson("/api/v1/waste-types/{$type}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->withToken($token)->getJson('/api/v1/waste-types')->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/waste-types?active_only=0')->assertJsonCount(1, 'data');

        // Another company cannot read or edit it.
        $other = $this->makeCompany();
        $otherToken = $this->tokenFor($this->makeMember($other, ['client_admin' => null]), $other);
        $this->withToken($otherToken)->getJson("/api/v1/waste-types/{$type}")->assertNotFound();
        $this->withToken($otherToken)->putJson("/api/v1/waste-types/{$type}", ['name' => 'x', 'unit' => 'kg'])->assertNotFound();
    }

    public function test_unit_conversion_follows_the_waste_type(): void
    {
        $this->assertSame('1.250', UnitConversion::fromKg('1250', 't'));
        $this->assertSame('2.000', UnitConversion::fromKg('2500', 'm3', '1250'));
        $this->assertSame('40', UnitConversion::fromKg('10', 'piece', null, '0.250'));
        $this->assertNull(UnitConversion::fromKg('10', 'm3'));
        $this->assertSame('10.000', UnitConversion::fromKg('10', 'kg'));
    }

    public function test_platform_maintains_the_reference_catalogue(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('super_admin');
        $token = $staff->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/platform/waste-catalog', [
            'code' => 'labels', 'name_fr' => 'Étiquettes', 'waste_family' => 'packaging', 'is_hazardous' => false, 'treatment_channel' => 'recycling',
        ])->assertCreated();
        $this->withToken($token)->putJson('/api/v1/platform/waste-catalog/labels', [
            'name_fr' => 'Étiquettes et griffes', 'waste_family' => 'packaging', 'is_hazardous' => false, 'is_active' => true,
        ])->assertNoContent();
        $this->assertSame('Étiquettes et griffes', collect($this->withToken($token)->getJson('/api/v1/platform/waste-catalog')->json('data'))->firstWhere('code', 'labels')['name']['fr']);

        $company = $this->makeCompany();
        $client = $this->tokenFor($this->makeMember($company, ['client_admin' => null]), $company);
        $this->withToken($client)->postJson('/api/v1/platform/waste-catalog', ['code' => 'x', 'name_fr' => 'X', 'waste_family' => 'packaging', 'is_hazardous' => false])->assertForbidden();
        $this->assertCount(15, $this->withToken($client)->getJson('/api/v1/waste-catalog')->json('data'));
    }

    public function test_accreditation_alert_is_sent_once_30_days_before_expiry_and_once_at_expiry(): void
    {
        Notification::fake();
        $industrial = $this->makeCompany();
        $buyer = $this->makeMember($industrial, ['client_admin' => null]);
        $outsider = $this->makeMember($this->makeCompany(), ['client_admin' => null]);
        $provider = $this->makeCompany('provider');
        $owner = $this->makeMember($provider, ['provider_admin' => null]);
        $this->accreditation($provider, now()->addDays(45));
        $this->withToken($this->tokenFor($buyer, $industrial))->postJson('/api/v1/provider-partnerships', ['provider' => $provider->ulid])->assertCreated();
        $action = app(AlertExpiringAccreditations::class);

        $this->assertSame(['expiring' => 0, 'expired' => 0], $action->handle());

        $this->travel(16)->days();
        $this->assertSame(['expiring' => 1, 'expired' => 0], $action->handle());
        Notification::assertSentTo($owner, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'accreditation.expiring_soon' && $n->params['days'] === 29);
        Notification::assertSentTo($buyer, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->companyId === $industrial->id);
        Notification::assertNotSentTo($outsider, BusinessEventNotification::class);

        // Not repeated every day.
        Notification::fake();
        $this->travel(1)->days();
        $this->assertSame(['expiring' => 0, 'expired' => 0], $action->handle());
        Notification::assertNothingSent();

        $this->travel(30)->days();
        $this->assertSame(['expiring' => 0, 'expired' => 1], $action->handle());
        Notification::assertSentTo($buyer, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'accreditation.expired');
        $this->artisan('accreditations:alert')->expectsOutputToContain('0 expiring, 0 expired')->assertSuccessful();
    }

    private function accreditation(Company $provider, mixed $expiresOn): void
    {
        $this->tenant()->runAs($provider, function () use ($provider, $expiresOn) {
            ProviderProfile::query()->create(['is_collector' => true, 'is_recycler' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $provider->id, 'disk' => 'local',
                'path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 1,
                'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditations', 'created_at' => now(), 'updated_at' => now()]);
            ProviderAccreditation::query()->create([
                'accreditation_type_id' => DB::table('accreditation_types')->where('code', 'recycling_recovery')->value('id'),
                'reference_number' => 'AG-42', 'valid_from' => now()->subYear(), 'expires_on' => $expiresOn, 'stored_file_id' => $file, 'review_status' => 'approved',
            ]);
        });
    }
}
