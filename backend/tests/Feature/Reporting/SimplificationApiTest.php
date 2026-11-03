<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Reporting\Support\RecurringWork;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Actions\CreateWasteLot;
use App\Modules\Waste\DTOs\CreateWasteLotData;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * What the simplified interface leans on: what waits for someone (attention), one search for every number, reports
 * received every month, and last year's declarations drafted by themselves.
 */
class SimplificationApiTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Company $other;

    private Site $site;

    private User $manager;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->industrial = $this->makeCompany();
        $this->other = $this->makeCompany();
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['client_admin' => null]);
        $this->type = $this->tenant()->runAs($this->industrial, fn () => WasteType::query()->create(['code' => 'DANGER', 'name' => 'Résidus dangereux', 'is_hazardous' => true,
            'waste_family_id' => DB::table('waste_families')->value('id'), 'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()])->ulid);
    }

    public function test_attention_lists_what_waits_for_the_company(): void
    {
        // A hazardous type without its regulatory code: no slip can carry its lots.
        $items = collect($this->asManager()->getJson('/api/v1/attention')->assertOk()->json('data'));
        $missing = $items->firstWhere('key', 'missing_codes');
        $this->assertSame(1, $missing['count']);
        $this->assertSame('compliance', $missing['area']);
        $this->assertSame('/waste-types', $missing['link']);
        $this->assertSame('danger', $items->first()['tone'], 'the most urgent first');
    }

    public function test_a_provider_sees_what_waits_for_it_too(): void
    {
        // Found while writing the provider's guide (2026-11-02): its page said "nothing waits" over a request to answer.
        $provider = $this->makeCompany('provider');
        $admin = $this->makeMember($provider, ['provider_admin' => null]);
        foreach (['requested', 'requested', 'collected', 'received', 'completed'] as $i => $status) {
            $this->tenant()->runAs($this->industrial, fn () => Pickup::query()->create(['pickup_number' => "ENL-2026-00{$i}", 'site_id' => $this->site->id,
                'provider_company_id' => $provider->id, 'requested_date' => now()->addDay()->toDateString(), 'currency_code' => 'TND'])->forceFill(['status' => $status])->save());
        }

        $items = collect($this->withToken($this->tokenFor($admin, $provider))->getJson('/api/v1/attention')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(2, $items['provider_requests']['count']);
        $this->assertSame('/provider/pickups', $items['provider_requests']['link']);
        $this->assertSame(1, $items['provider_to_receive']['count']);
        $this->assertSame(1, $items['provider_to_treat']['count']);
        $this->assertArrayNotHasKey('missing_codes', $items->all(), 'nothing of the industrial\'s own');
    }

    public function test_lots_that_keep_figures_unknown_wait_with_their_numbers(): void
    {
        // A lot created ten days ago, never weighed, never put in its zone.
        $lot = $this->lot('5');
        DB::table('waste_lots')->where('ulid', $lot)->update(['net_weight_kg' => null, 'status' => 'created', 'generated_at' => now()->subDays(10)]);
        $number = DB::table('waste_lots')->where('ulid', $lot)->value('lot_number');

        $items = collect($this->asManager()->getJson('/api/v1/attention')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame([$number], $items['lots_unweighed_long']['params']['lots']);
        $this->assertSame(['unweighed' => '1'], $items['lots_unweighed_long']['query']);
        $this->assertSame([$number], $items['lots_not_stored']['params']['lots']);
        // The administrator also sees what the company lacks: no stock limit yet.
        $this->assertSame('/stock', $items['setup_no_stock_limit']['link']);
    }

    public function test_one_search_finds_lots_and_types_of_the_company_only(): void
    {
        $lot = $this->lot('120');
        $number = DB::table('waste_lots')->where('ulid', $lot)->value('lot_number');
        $this->tenant()->runAs($this->other, fn () => WasteType::query()->create(['code' => 'AUTRE', 'name' => 'Résidus d\'ailleurs',
            'waste_family_id' => DB::table('waste_families')->value('id'), 'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()]));

        $found = collect($this->asManager()->getJson('/api/v1/search?q='.urlencode(substr($number, 0, 8)))->assertOk()->json('data'));
        $this->assertSame("/lots/{$lot}", $found->firstWhere('kind', 'lot')['link']);

        $types = collect($this->asManager()->getJson('/api/v1/search?q=sidus')->assertOk()->json('data'))->where('kind', 'waste_type');
        $this->assertSame(['Résidus dangereux'], $types->pluck('label')->all(), 'another company\'s types never show');
        $this->asManager()->getJson('/api/v1/search?q=a')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_scheduled_report_is_generated_on_the_month_before(): void
    {
        Queue::fake();
        $schedule = $this->asManager()->postJson('/api/v1/report-schedules', ['report_type' => 'waste_register', 'format' => 'xlsx'])->assertCreated()->json('data.ulid');
        $this->asManager()->getJson('/api/v1/report-schedules')->assertOk()->assertJsonPath('data.0.ulid', $schedule);

        $this->assertSame(1, app(RecurringWork::class)->scheduledReports(Carbon::parse('2026-11-01')));
        $report = DB::table('generated_reports')->where('company_id', $this->industrial->id)->first();
        $this->assertSame(['2026-10-01', '2026-10-31'], [json_decode($report->parameters, true)['from'], json_decode($report->parameters, true)['to']]);
        // Once a month, whatever the number of runs.
        $this->assertSame(0, app(RecurringWork::class)->scheduledReports(Carbon::parse('2026-11-01')));

        $this->asManager()->deleteJson("/api/v1/report-schedules/{$schedule}")->assertNoContent();
        $this->assertSame(0, app(RecurringWork::class)->scheduledReports(Carbon::parse('2026-12-01')));
    }

    public function test_last_years_declaration_is_drafted_for_each_producing_site(): void
    {
        $lot = $this->lot('80');
        DB::table('waste_lots')->where('ulid', $lot)->update(['generated_at' => '2025-06-10 10:00:00']);

        $drafted = app(RecurringWork::class)->draftDeclarations(Carbon::parse('2026-01-02'));
        $exists = DB::table('annual_declarations')->where('site_id', $this->site->id)->where('reporting_year', 2025)->exists();
        // A country without a declaration format drafts nothing; one with it drafts the site's declaration.
        $this->assertSame($exists ? 1 : 0, $drafted);
    }

    private function lot(string $kg): string
    {
        return $this->tenant()->runAs($this->industrial, function () use ($kg) {
            $zone = Zone::query()->firstOrCreate(['code' => 'AIRE'], ['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']);

            return app(CreateWasteLot::class)->handle(new CreateWasteLotData($this->site->ulid, $zone->ulid, $this->type, $kg), $this->manager)->ulid;
        });
    }

    private function asManager(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->industrial));
    }
}
