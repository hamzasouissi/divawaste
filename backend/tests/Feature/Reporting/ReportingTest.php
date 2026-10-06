<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Scenarios D and E — M5-01, M6-01, M6-02, M6-04, M4-05: figures checked against a dataset whose totals are known.
 *
 * Dataset (site SFX): chutes 100 + 60 + 40 kg, cartons 50 kg. One pickup takes chutes 100 (received 95, recycled,
 * sold 0.4/kg = +38) and cartons 50 (received 50, landfill, cost 10 flat). 1 000 pieces produced.
 */
class ReportingTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private Site $site;

    private User $adminUser;

    private string $admin;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        Storage::fake('local');

        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
        $this->adminUser = $this->makeMember($this->company, ['client_admin' => null]);
        $this->admin = $this->tokenFor($this->adminUser, $this->company);
        $this->period = 'from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString();
        $this->buildDataset();
        $this->admin = $this->tokenFor($this->adminUser, $this->company);
    }

    public function test_dashboard_figures_match_the_known_dataset(): void
    {
        $data = $this->withToken($this->admin)->getJson("/api/v1/dashboard?{$this->period}")->assertOk()->json('data');

        $this->assertSame('250.000', $data['totals']['generated_kg']);
        $this->assertSame(4, $data['totals']['generated_lots']);
        $this->assertSame('145.000', $data['totals']['evacuated_kg']);
        $this->assertSame('95.000', $data['totals']['recovered_kg']);
        $this->assertSame('50.000', $data['totals']['landfill_kg']);
        // 95 / 145 and 50 / 145.
        $this->assertSame('65.5', $data['totals']['recovery_rate_pct']);
        $this->assertSame('34.4', $data['totals']['landfill_share_pct']);
        // 250 kg / 1 000 pieces.
        $this->assertSame('0.2500', $data['totals']['kg_per_piece']);
        $this->assertSame('38.000', $data['totals']['revenue']);
        $this->assertSame('10.000', $data['totals']['cost']);
        $this->assertSame('28.000', $data['totals']['net_balance']);

        $this->assertSame(['Chutes de coupe' => '200.000', 'Cartons' => '50.000'], array_column($data['by_waste_type'], 'kg', 'name'));
        $this->assertSame(['recycling' => '95.000', 'landfill' => '50.000'], array_column($data['by_channel'], 'kg', 'channel'));
        $this->assertSame('250.000', $data['by_site'][0]['kg']);
        $this->assertSame([now()->format('Y-m')], array_column($data['by_month'], 'month'));
        $this->assertSame('28.000', $data['by_month'][0]['net_balance']);

        // A split does not create waste: the indicator stays at 250 kg.
        $lot = DB::table('waste_lots')->where('company_id', $this->company->id)->where('net_weight_kg', 40)->value('ulid');
        $this->withToken($this->admin)->postJson("/api/v1/lots/{$lot}/split", ['outputs' => [['net_weight_kg' => '20'], ['net_weight_kg' => '20']]])->assertCreated();
        $this->travel(2)->minutes();
        $this->assertSame('250.000', $this->withToken($this->admin)->getJson("/api/v1/dashboard?{$this->period}")->json('data.totals.generated_kg'));
    }

    public function test_dashboard_and_register_are_limited_to_the_sites_and_company_of_the_user(): void
    {
        $other = $this->makeSite($this->company, 'TUN');
        $viewer = $this->tokenFor($this->makeMember($this->company, ['management_viewer' => $other]), $this->company);
        $this->assertSame('0.000', $this->withToken($viewer)->getJson("/api/v1/dashboard?{$this->period}")->assertOk()->json('data.totals.generated_kg'));
        $this->withToken($viewer)->getJson("/api/v1/dashboard?{$this->period}&site={$this->site->ulid}")->assertForbidden();
        $this->withToken($viewer)->getJson("/api/v1/register?{$this->period}")->assertOk()->assertJsonPath('meta.total', 0);

        $stranger = $this->makeCompany();
        $strangerToken = $this->tokenFor($this->makeMember($stranger, ['client_admin' => null]), $stranger);
        $this->assertSame('0.000', $this->withToken($strangerToken)->getJson("/api/v1/dashboard?{$this->period}")->json('data.totals.generated_kg'));
        $this->withToken($strangerToken)->getJson("/api/v1/dashboard?{$this->period}&site={$this->site->ulid}")->assertNotFound();

        $this->withToken($this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company))
            ->getJson("/api/v1/dashboard?{$this->period}")->assertForbidden();
    }

    public function test_register_lists_every_lot_with_its_exit_and_is_exported_to_excel_and_pdf_on_the_queue(): void
    {
        Notification::fake();
        $register = $this->withToken($this->admin)->getJson("/api/v1/register?{$this->period}")->assertOk();
        $register->assertJsonPath('meta.total', 4);
        $first = $register->json('data.0');
        $this->assertSame('100.000', $first['net_weight_kg']);
        $this->assertSame('95.000', $first['received_weight_kg']);
        $this->assertSame('recycling', $first['channel']);
        $this->assertNotNull($first['pickup_number']);
        $this->assertNull($register->json('data.1.pickup_number'));

        // Excel.
        $xlsx = $this->withToken($this->admin)->postJson("/api/v1/reports?{$this->period}", ['report_type' => 'waste_register', 'format' => 'xlsx'])
            ->assertStatus(202)->json('data');
        $ready = $this->withToken($this->admin)->getJson("/api/v1/reports/{$xlsx['ulid']}")->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.downloadable', true)->json('data');
        $this->assertArrayNotHasKey('site_ids', $ready['parameters']);
        Notification::assertSentTo($this->adminUser, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'report.ready');

        $path = tempnam(sys_get_temp_dir(), 'dw').'.xlsx';
        file_put_contents($path, $this->withToken($this->admin)->get("/api/v1/reports/{$xlsx['ulid']}/download")->assertOk()->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        $this->assertSame('N° de lot', $sheet[0][1]);
        $this->assertCount(5, $sheet);
        $this->assertEqualsWithDelta(250.0, array_sum(array_column(array_slice($sheet, 1), 7)), 0.001);

        // PDF, with the client's identity.
        $pdf = $this->withToken($this->admin)->postJson("/api/v1/reports?{$this->period}", ['report_type' => 'rse_summary', 'format' => 'pdf'])
            ->assertStatus(202)->json('data.ulid');
        $content = $this->withToken($this->admin)->get("/api/v1/reports/{$pdf}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf')->streamedContent();
        $this->assertStringStartsWith('%PDF-', $content);

        // An export belongs to the person who asked for it, and to nobody in another company.
        $colleague = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
        $this->withToken($colleague)->get("/api/v1/reports/{$pdf}/download")->assertNotFound();
        $stranger = $this->makeCompany();
        $this->withToken($this->tokenFor($this->makeMember($stranger, ['client_admin' => null]), $stranger))->get("/api/v1/reports/{$pdf}/download")->assertNotFound();

        $this->withToken($this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company))
            ->postJson("/api/v1/reports?{$this->period}", ['report_type' => 'payroll', 'format' => 'pdf'])->assertUnprocessable();
    }

    private function buildDataset(): void
    {
        $provider = $this->makeCompany('provider');
        $providerUser = $this->makeMember($provider, ['provider_admin' => null]);
        $this->tenant()->runAs($provider, function () use ($provider) {
            ProviderProfile::query()->create(['is_recycler' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $provider->id, 'disk' => 'local',
                'path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 1,
                'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditations', 'created_at' => now(), 'updated_at' => now()]);
            ProviderAccreditation::query()->create(['accreditation_type_id' => DB::table('accreditation_types')->where('code', 'recycling_recovery')->value('id'),
                'reference_number' => 'AG-1', 'valid_from' => now()->subYear(), 'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });

        $zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->firstOrFail());
        $type = fn (string $code, string $name) => $this->tenant()->runAs($this->company, fn () => WasteType::query()->create([
            'code' => $code, 'name' => $name, 'activated_at' => now(),
            'waste_family_id' => DB::table('waste_families')->where('code', 'textile_product')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
        ]));
        $chutes = $type('CHUTES', 'Chutes de coupe');
        $cartons = $type('CARTON', 'Cartons');
        $lot = fn (WasteType $t, string $kg) => $this->withToken($this->admin)->postJson('/api/v1/lots', [
            'site' => $this->site->ulid, 'zone' => $zone->ulid, 'waste_type' => $t->ulid, 'net_weight_kg' => $kg,
        ])->assertCreated()->json('data');

        $a = $lot($chutes, '100');
        $lot($chutes, '60');
        $lot($chutes, '40');
        $b = $lot($cartons, '50');

        $this->withToken($this->admin)->postJson('/api/v1/provider-partnerships', ['provider' => $provider->ulid])->assertCreated();
        $this->withToken($this->admin)->putJson('/api/v1/production-volumes', ['site' => $this->site->ulid, 'month' => now()->format('Y-m'), 'pieces_produced' => 1000])->assertNoContent();
        $pickup = $this->withToken($this->admin)->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $provider->ulid,
            'requested_date' => now()->toDateString(), 'lots' => [$a['ulid'], $b['ulid']]])->assertCreated()->json('data.ulid');
        $this->withToken($this->admin)->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();

        $providerToken = $this->tokenFor($providerUser, $provider);
        $this->withToken($providerToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk();
        $this->withToken($this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company))
            ->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $a['ulid']], ['lot' => $b['ulid']]]])->assertOk();
        $this->withToken($this->tokenFor($providerUser, $provider))->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [
            ['lot_number' => $a['lot_number'], 'received_weight_kg' => '95'], ['lot_number' => $b['lot_number'], 'received_weight_kg' => '50'],
        ]])->assertOk();
        $this->withToken($providerToken)->postJson("/api/v1/pickups/{$pickup}/treat", ['lots' => [
            ['lot_number' => $a['lot_number'], 'channel' => 'recycling'], ['lot_number' => $b['lot_number'], 'channel' => 'landfill'],
        ]])->assertOk();
        $this->withToken($this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company))
            ->putJson("/api/v1/pickups/{$pickup}/pricing", ['lots' => [
                ['lot_number' => $a['lot_number'], 'direction' => 'sale', 'pricing_mode' => 'per_kg', 'unit_price' => '0.4'],
                ['lot_number' => $b['lot_number'], 'direction' => 'cost', 'pricing_mode' => 'flat', 'unit_price' => '10'],
            ]])->assertOk();
    }
}
