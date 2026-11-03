<?php

namespace Tests\Feature\Waste;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Models\WasteLot;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Waste lots brought in from a spreadsheet (an ERP export, a register kept in Excel): each line is a lot created as the
 * lot form creates it, on the sites where the person may create lots, and the same file read twice creates nothing more.
 */
class LotImportTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Zone $storage;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'SFX');
        $other = $this->makeSite($this->company, 'KOR');
        $this->storage = $this->tenant()->runAs($this->company, function () use ($other) {
            $zoneType = DB::table('zone_types')->value('id');
            Zone::query()->create(['site_id' => $this->site->id, 'zone_type_id' => $zoneType, 'code' => 'COUPE', 'name' => 'Coupe']);
            Zone::query()->create(['site_id' => $other->id, 'zone_type_id' => $zoneType, 'code' => 'COUPE', 'name' => 'Coupe']);
            WasteType::query()->create(['code' => 'CHUTES', 'name' => 'Chutes de coupe', 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now(), 'is_hazardous' => false]);

            // Every site has its storage area, made with it.
            return Zone::query()->where('site_id', $this->site->id)->where('is_waste_storage', true)->firstOrFail();
        });
        // Creates lots on SFX only.
        $this->operator = $this->makeMember($this->company, ['workshop_operator' => $this->site]);
    }

    public function test_a_register_of_lots_is_imported_on_the_sites_of_the_person(): void
    {
        $day = now()->subDays(3)->format('d/m/Y');
        $csv = implode("\n", [
            'Usine,Type de déchet,Poids (kg),Estimé,Date,Réf,Remarque',
            "SFX,chutes de coupe,\"125,5\",oui,{$day},ERP-1,Atelier 2",
            'Site SFX,CHUTES,80,non,,ERP-2,',
            'KOR,CHUTES,10,,,ERP-3,',
            'SFX,Inconnu,5,,,ERP-4,',
            'SFX,CHUTES,abc,,,ERP-5,',
        ]);
        $file = fn () => UploadedFile::fake()->createWithContent('registre.csv', $csv);
        $client = fn () => $this->withToken($this->tokenFor($this->operator, $this->company));

        // An operator sees the lots import only, and the headings of the file are matched to what a lot needs.
        $client()->getJson('/api/v1/production/imports/targets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.target', 'waste_lots');
        $mapping = array_filter($client()->post('/api/v1/production/imports/preview', ['target' => 'waste_lots', 'file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.lines', 5)->json('data.mapping'), fn ($column) => $column !== null);
        $this->assertEquals(['site' => 0, 'waste_type' => 1, 'net_weight_kg' => 2, 'weight_estimated' => 3, 'generated_at' => 4, 'external_reference' => 5, 'notes' => 6], $mapping);
        $client()->post('/api/v1/production/imports/preview', ['target' => 'materials', 'file' => $file()], ['Accept' => 'application/json'])->assertForbidden();

        // The check writes nothing and says why each bad line would fail.
        $check = $client()->post('/api/v1/production/imports/run', ['target' => 'waste_lots', 'file' => $file(), 'mapping' => $mapping, 'dry_run' => '1'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created', 2)->json('data');
        $this->assertSame([4, 5, 6], array_column($check['errors'], 'line'));
        $this->assertStringContainsString('Site KOR', $check['errors'][0]['message']);
        $this->assertStringContainsString('Inconnu', $check['errors'][1]['message']);
        $this->assertStringContainsString('abc', $check['errors'][2]['message']);
        $this->assertSame(0, DB::table('waste_lots')->count());

        // The import creates the good lines; without a zone, a lot goes to the storage area of its site.
        $client()->post('/api/v1/production/imports/run', ['target' => 'waste_lots', 'file' => $file(), 'mapping' => $mapping], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created', 2)->assertJsonCount(3, 'data.errors');
        $lots = $this->tenant()->runAs($this->company, fn () => WasteLot::query()->orderBy('id')->get());
        $this->assertSame([['125.500', 'estimated', $this->storage->id, 'Atelier 2'], ['80.000', 'measured', $this->storage->id, null]],
            $lots->map(fn (WasteLot $lot) => [$lot->net_weight_kg, $lot->weight_basis, $lot->zone_id, $lot->notes])->all());
        $this->assertSame(now()->subDays(3)->toDateString(), $lots[0]->generated_at->toDateString());
        $this->assertSame(['created', 'created'], DB::table('waste_lot_events')->orderBy('id')->pluck('event_type')->all());

        // The same file again: the lots already imported are left as they are.
        $client()->post('/api/v1/production/imports/run', ['target' => 'waste_lots', 'file' => $file(), 'mapping' => $mapping], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.unchanged', 2);
        $this->assertSame(2, DB::table('waste_lots')->count());
    }

    public function test_who_creates_no_lot_imports_none(): void
    {
        $viewer = $this->makeMember($this->company, ['management_viewer' => null]);
        $file = UploadedFile::fake()->createWithContent('registre.csv', "Site,Déchet\nSFX,CHUTES");
        $this->withToken($this->tokenFor($viewer, $this->company))->post('/api/v1/production/imports/preview', ['target' => 'waste_lots', 'file' => $file], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
