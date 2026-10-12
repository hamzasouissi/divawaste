<?php

namespace Tests\Feature\Sites;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Actions\CreateWasteLot;
use App\Modules\Waste\DTOs\CreateWasteLotData;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * A stock limit is stated in the unit of the restriction: a weight, pieces, a volume, or packages of one packaging.
 * Each limit counts the lots of its scope in its own unit, names those it cannot count, and is answered on its own.
 */
class StockLimitUnitsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Zone $zone;

    private User $manager;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'NAB');
        $this->token = $this->tokenFor($this->makeMember($this->company, ['client_admin' => null]), $this->company);
        $this->manager = $this->makeMember($this->company, ['environment_manager' => $this->site]);
        $this->zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id,
            'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']));
    }

    public function test_a_limit_in_tonnes_and_one_under_its_former_name_in_kilograms(): void
    {
        $type = $this->type('CHUTES');
        $tonnes = $this->limit(['waste_type' => $type, 'max_quantity' => '0.9', 'unit' => 't', 'warning_pct' => 80])->assertCreated()
            ->assertJsonPath('data.unit', 't')->assertJsonPath('data.max_quantity', '0.900')->assertJsonPath('data.max_quantity_kg', '900.000')->json('data.ulid');
        // Without a unit a limit is in kilograms, as it always was — on another scope, since this one already has a weight.
        $kilograms = $this->limit(['zone' => $this->zone->ulid, 'max_quantity_kg' => '2000'])->assertCreated()
            ->assertJsonPath('data.unit', 'kg')->assertJsonPath('data.max_quantity', '2000.000')->assertJsonPath('data.packaging_type', null)->json('data.ulid');

        $this->lot($type, '700');
        $this->assertSame(['t', '0.700', '0.900', '700.000', null], $this->values($tonnes, ['unit', 'quantity', 'max_quantity', 'quantity_kg', 'alert_level']));
        $this->lot($type, '150');
        $state = $this->state($tonnes);
        $this->assertSame(['0.850', 'warning', 94.4, '0.050'], [$state['quantity'], $state['alert_level'], $state['constraint']['pct'], $state['constraint']['remaining']]);
        $this->assertSame(['kg', '850.000', '2000.000', null], $this->values($kilograms, ['unit', 'quantity', 'max_quantity', 'alert_level']));

        // The unit is what the limit is about: the figure changes, the unit does not.
        $this->api()->putJson("/api/v1/stock-thresholds/{$tonnes}", ['max_quantity' => '0.8', 'warning_pct' => 80, 'is_active' => true])->assertOk()->assertJsonPath('data.unit', 't');
        $this->assertSame(['exceeded', '0.050'], [$this->state($tonnes)['alert_level'], $this->state($tonnes)['constraint']['excess']]);
        $this->api()->putJson("/api/v1/stock-thresholds/{$tonnes}", ['max_quantity_kg' => '800', 'warning_pct' => 80, 'is_active' => true])->assertStatus(422);
    }

    public function test_pieces_and_volumes_are_offered_only_for_waste_that_can_be_counted_that_way(): void
    {
        $weighed = $this->type('CHUTES');
        $pieces = $this->type('CONES', 'piece');
        $liquid = $this->type('BAINS', 'kg', ['density_kg_m3' => '800']);

        // Waste that is only weighed has no pieces and no volume; nor has "all the waste of the site".
        $this->limit(['waste_type' => $weighed, 'max_quantity' => '100', 'unit' => 'piece'])->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->limit(['waste_type' => $weighed, 'max_quantity' => '500', 'unit' => 'l'])->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->limit(['max_quantity' => '100', 'unit' => 'piece'])->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->limit(['waste_type' => $weighed, 'max_quantity' => '100', 'unit' => 'pallets'])->assertStatus(422);

        $count = $this->limit(['waste_type' => $pieces, 'max_quantity' => '100', 'unit' => 'piece'])->assertCreated()->json('data.ulid');
        $volume = $this->limit(['waste_type' => $liquid, 'max_quantity' => '500', 'unit' => 'l'])->assertCreated()->json('data.ulid');

        // 60 pieces counted: no weight is needed to answer a limit in pieces.
        $this->lot($pieces, null, ['quantity' => '60', 'unit' => 'piece']);
        $this->assertSame(['piece', '60.000', '100.000', null, null, 0], $this->values($count, ['unit', 'quantity', 'max_quantity', 'quantity_kg', 'alert_level', 'unweighed_lots']));

        // Waste that is weighed but says what a piece weighs can be limited in pieces: 5 kg at 0.5 kg a piece is 10 pieces.
        $rejects = $this->type('REBUTS', 'kg', ['unit_weight_kg' => '0.5']);
        $converted = $this->limit(['waste_type' => $rejects, 'max_quantity' => '100', 'unit' => 'piece'])->assertCreated()->json('data.ulid');
        $this->lot($rejects, '5');
        $this->assertSame(['10.000', null], $this->values($converted, ['quantity', 'alert_level']));
        // A lot of it nobody weighed has no known number of pieces. It is named, not read as zero.
        $unweighed = $this->lot($rejects, null)->json('data');
        $state = $this->state($converted);
        $this->assertSame(['10.000', 'unverifiable', [$unweighed['lot_number']]], [$state['quantity'], $state['alert_level'], $state['constraint']['missing_lots']]);
        $this->api()->postJson("/api/v1/lots/{$unweighed['ulid']}/weigh", ['net_weight_kg' => '10'])->assertOk();
        $this->assertSame(['30.000', null, 0], $this->values($converted, ['quantity', 'alert_level', 'unweighed_lots']));
        // A volume through the density of the type: 200 kg at 800 kg/m³ is 250 litres; 300 litres measured are 300 litres.
        $this->lot($liquid, '200');
        $this->lot($liquid, null, ['quantity' => '300', 'unit' => 'l']);
        $this->assertSame(['l', '550.000', 'exceeded', '50.000'], [...$this->values($volume, ['unit', 'quantity', 'alert_level']), $this->state($volume)['constraint']['excess']]);
    }

    public function test_a_limit_in_bags_counts_bags_not_lots_and_several_limits_on_one_scope_are_answered_apart(): void
    {
        Notification::fake();
        $type = $this->type('CHUTES');
        $this->limit(['waste_type' => $type, 'max_quantity' => '100', 'unit' => 'package'])->assertStatus(422)->assertJsonValidationErrors('packaging_type');
        $this->limit(['waste_type' => $type, 'max_quantity' => '100.5', 'unit' => 'package', 'packaging_type' => 'bag'])->assertStatus(422)->assertJsonValidationErrors('max_quantity');
        $this->limit(['waste_type' => $type, 'max_quantity' => '100', 'unit' => 'kg', 'packaging_type' => 'bag'])->assertStatus(422)->assertJsonValidationErrors('packaging_type');

        $bags = $this->limit(['waste_type' => $type, 'max_quantity' => '100', 'unit' => 'package', 'packaging_type' => 'bag', 'warning_pct' => 90])->assertCreated()
            ->assertJsonPath('data.packaging_type', 'bag')->assertJsonPath('data.max_quantity_kg', null)->json('data.ulid');
        // The same stock under a weight and under a number of boxes: three limits, three answers.
        $weight = $this->limit(['waste_type' => $type, 'max_quantity' => '5000', 'unit' => 'kg'])->assertCreated()->json('data.ulid');
        $boxes = $this->limit(['waste_type' => $type, 'max_quantity' => '40', 'unit' => 'package', 'packaging_type' => 'box', 'warning_pct' => 70])->assertCreated()->json('data.ulid');
        // A second weight on the same scope is the same limit twice, whatever its unit.
        $this->limit(['waste_type' => $type, 'max_quantity' => '5', 'unit' => 't'])->assertStatus(409)->assertJsonPath('code', 'THRESHOLD_EXISTS');
        $this->limit(['waste_type' => $type, 'max_quantity' => '50', 'unit' => 'package', 'packaging_type' => 'bag'])->assertStatus(409);

        // One lot of 60 bags is 60 bags; a lot of 30 boxes and a loose one are not bags.
        $this->lot($type, '600', ['packaging_type' => 'bag', 'package_count' => 60]);
        $box = $this->lot($type, '450', ['packaging_type' => 'box', 'package_count' => 30])->json('data.lot_number');
        $loose = $this->lot($type, '80')->json('data.lot_number');
        $state = $this->state($bags);
        $this->assertSame(['package', 'bag', '60', '100', null, null], [$state['unit'], $state['packaging_type'], $state['quantity'], $state['max_quantity'], $state['quantity_kg'], $state['alert_level']]);
        $this->assertSame([60, 100, 40, 2, [$box, $loose]], [$state['constraint']['used'], $state['constraint']['max'], $state['constraint']['remaining'], $state['constraint']['outside_count'], $state['constraint']['outside_lots']]);
        $this->assertSame(['30', 'warning'], $this->values($boxes, ['quantity', 'alert_level']));
        $this->assertSame(['1130.000', null], $this->values($weight, ['quantity', 'alert_level']));

        // 50 more bags: the bags are over by 10, the weight and the boxes are not touched by it.
        Notification::fake();
        $this->lot($type, '500', ['packaging_type' => 'bag', 'package_count' => 50]);
        $state = $this->state($bags);
        $this->assertSame(['110', 'exceeded', 10, 110], [$state['quantity'], $state['alert_level'], $state['constraint']['excess'], $state['constraint']['pct']]);
        $this->assertSame(['1630.000', null], $this->values($weight, ['quantity', 'alert_level']));
        $this->assertSame(['30', 'warning'], $this->values($boxes, ['quantity', 'alert_level']));

        // The message carries the figures with their unit, written for each language, and names the right limit.
        $exceeded = $this->told('exceeded');
        $this->assertCount(1, $exceeded);
        $this->assertSame(['110 Sac', '100 Sac', '10 Sac', '110 Bag', '100 Bag', '10 Bag', $this->site->name.' · CHUTES'], array_map(fn (string $key) => $exceeded[0]->params[$key],
            ['quantity', 'max_quantity', 'excess', 'quantity_en', 'max_quantity_en', 'excess_en', 'scope']));
        $this->assertCount(0, $this->told('warning'));
        app()->setLocale('en');
        $mail = $exceeded[0]->toMail($this->manager);
        $this->assertStringContainsString('Stock is at 110 Bag, above the limit of 100 Bag by 10 Bag', implode(' ', $mail->introLines));
        app()->setLocale('fr');
        $this->assertStringContainsString('Le stock atteint 110 Sac et dépasse de 10 Sac la limite de 100 Sac', implode(' ', $exceeded[0]->toMail($this->manager)->introLines));

        // The same state after another movement: nobody is told again.
        Notification::fake();
        $unknown = $this->lot($type, '10', ['packaging_type' => 'bag', 'package_count' => 5])->json('data');
        Notification::assertNothingSent();

        // A lot of bags whose number of packages nobody knows is not one bag: it is named beside the exceedance.
        DB::table('waste_lots')->where('ulid', $unknown['ulid'])->update(['package_count' => null]);
        $state = $this->state($bags);
        $this->assertSame(['110', 'exceeded', 1, [$unknown['lot_number']]], [$state['quantity'], $state['alert_level'], $state['unweighed_lots'], $state['constraint']['missing_lots']]);
    }

    public function test_a_limit_in_packages_below_its_maximum_with_an_uncounted_lot_is_announced_once_as_unverifiable(): void
    {
        $type = $this->type('CHUTES');
        $limit = $this->limit(['zone' => $this->zone->ulid, 'max_quantity' => '50', 'unit' => 'package', 'packaging_type' => 'bale'])->assertCreated()->json('data.ulid');
        $first = $this->lot($type, '300', ['packaging_type' => 'bale', 'package_count' => 12])->json('data');
        DB::table('waste_lots')->where('ulid', $first['ulid'])->update(['package_count' => null]);

        Notification::fake();
        $this->lot($type, '200', ['packaging_type' => 'bale', 'package_count' => 8]);
        $sent = $this->told('unverifiable');
        $this->assertCount(1, $sent);
        $this->assertSame(['8 Balle', '50 Balle', '8 Bale', '50 Bale', 1, $first['lot_number'], null], array_map(fn (string $key) => $sent[0]->params[$key],
            ['quantity', 'max_quantity', 'quantity_en', 'max_quantity_en', 'unweighed_lots', 'lots', 'excess']));
        $this->assertSame(['unverifiable'], DB::table('stock_thresholds')->where('ulid', $limit)->pluck('alert_level')->all());
        $this->assertCount(0, $this->told('exceeded'));

        Notification::fake();
        $this->lot($type, '100', ['packaging_type' => 'bale', 'package_count' => 4]);
        Notification::assertNothingSent();
    }

    public function test_the_states_nobody_was_told_about_are_announced_once_by_the_reconciliation_and_never_again(): void
    {
        $type = $this->type('CHUTES');
        DB::table('sites')->where('id', $this->site->id)->update(['capacity_kg' => '1000', 'capacity_alert_pct' => 90]);
        $room = $this->api()->postJson("/api/v1/sites/{$this->site->ulid}/zones", ['code' => 'B', 'name' => 'Hall B', 'capacity_kg' => '5000',
            'capacities' => [['packaging_type' => 'bag', 'max_count' => 100]]])->assertCreated()->json('data.ulid');
        $this->limit(['waste_type' => $type, 'max_quantity' => '100', 'unit' => 'package', 'packaging_type' => 'bag'])->assertCreated();
        $this->api()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $room, 'waste_type' => $type, 'net_weight_kg' => '1200', 'packaging_type' => 'bag', 'package_count' => 110])->assertCreated();

        // Another company with its own site over its capacity: its people, and only they, hear about it.
        $other = $this->makeCompany();
        $otherSite = $this->makeSite($other, 'TUN');
        $otherManager = $this->makeMember($other, ['environment_manager' => $otherSite]);
        DB::table('sites')->where('id', $otherSite->id)->update(['capacity_kg' => '100']);
        $this->tenant()->runAs($other, function () use ($otherSite, $otherManager) {
            $zone = Zone::query()->create(['code' => 'Z', 'site_id' => $otherSite->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Z']);
            $type = WasteType::query()->create(['code' => 'X', 'name' => 'X', 'waste_family_id' => DB::table('waste_families')->value('id'), 'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()]);
            app(CreateWasteLot::class)->handle(new CreateWasteLotData($otherSite->ulid, $zone->ulid, $type->ulid, '250'), $otherManager);
        });

        // As after a deployment: the limits exist, the stock is over them, and nothing was ever evaluated.
        DB::table('capacity_alerts')->delete();
        DB::table('stock_thresholds')->update(['alert_level' => null, 'alerted_at' => null, 'last_evaluated_at' => null]);
        $this->app->forgetScopedInstances();
        Notification::fake();
        $this->artisan('limits:evaluate')->expectsOutputToContain('2 site(s) evaluated')->assertSuccessful();

        $types = fn (User $user) => Notification::sent($user, BusinessEventNotification::class)->map(fn (BusinessEventNotification $n) => $n->type)->sort()->values()->all();
        $this->assertSame(['capacity.site_exceeded', 'capacity.space_exceeded', 'stock.threshold_exceeded'], $types($this->manager));
        $this->assertSame(['capacity.site_exceeded'], $types($otherManager));
        // English readers get the figures in English too.
        $space = Notification::sent($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'capacity.space_exceeded')->first();
        $this->assertSame(['110 sur 100 Sac', '110 of 100 Bag'], [$space->params['detail'], $space->params['detail_en']]);
        $site = Notification::sent($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'capacity.site_exceeded')->first();
        $this->assertSame(['1 200 kg sur 1 000 kg', '1,200 kg of 1,000 kg', '200 kg', '200 kg'], [$site->params['detail'], $site->params['detail_en'], $site->params['excess'], $site->params['excess_en']]);
        $this->assertSame(3, DB::table('capacity_alerts')->where('company_id', $this->company->id)->whereNotNull('alert_level')->count() + DB::table('stock_thresholds')->where('alert_level', 'exceeded')->count());

        // Run again, and again the next day: the same states are not announced twice.
        Notification::fake();
        $this->app->forgetScopedInstances();
        $this->artisan('limits:evaluate')->assertSuccessful();
        $this->artisan('limits:evaluate')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_limits_are_set_by_those_entitled_to_and_stay_inside_the_company(): void
    {
        $type = $this->type('CHUTES');
        $limit = $this->limit(['waste_type' => $type, 'max_quantity' => '100', 'unit' => 'package', 'packaging_type' => 'bag'])->assertCreated()->json('data.ulid');
        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company);
        $this->fresh($operator)->postJson('/api/v1/stock-thresholds', ['site' => $this->site->ulid, 'max_quantity' => '10', 'unit' => 't'])->assertForbidden();
        $this->fresh($operator)->putJson("/api/v1/stock-thresholds/{$limit}", ['max_quantity' => '10', 'warning_pct' => 90, 'is_active' => true])->assertForbidden();

        $other = $this->makeCompany();
        $otherSite = $this->makeSite($other, 'TUN');
        $outsider = $this->tokenFor($this->makeMember($other, ['client_admin' => null]), $other);
        $this->fresh($outsider)->getJson('/api/v1/stock-thresholds')->assertOk()->assertJsonCount(0, 'data');
        $this->fresh($outsider)->putJson("/api/v1/stock-thresholds/{$limit}", ['max_quantity' => '10', 'warning_pct' => 90, 'is_active' => true])->assertNotFound();
        // A waste type of another company is not a waste type here.
        $this->fresh($outsider)->postJson('/api/v1/stock-thresholds', ['site' => $otherSite->ulid, 'waste_type' => $type, 'max_quantity' => '10', 'unit' => 'kg'])->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function type(string $code, string $unit = 'kg', array $extra = []): string
    {
        return $this->tenant()->runAs($this->company, function () use ($code, $unit, $extra) {
            $type = WasteType::query()->create(['code' => $code, 'name' => $code, 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', $unit)->value('id'), 'activated_at' => now()]);
            $type->forceFill($extra)->save();

            return $type->ulid;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function limit(array $input): TestResponse
    {
        return $this->api()->postJson('/api/v1/stock-thresholds', ['site' => $this->site->ulid] + $input);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $limit): array
    {
        return collect($this->fresh()->getJson('/api/v1/stock')->assertOk()->json('thresholds'))->firstWhere('ulid', $limit);
    }

    /**
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private function values(string $limit, array $keys): array
    {
        $state = $this->state($limit);

        return array_map(fn (string $key) => $state[$key], $keys);
    }

    /**
     * @return Collection<int, BusinessEventNotification>
     */
    private function told(string $kind): Collection
    {
        return Notification::sent($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'stock.threshold_'.$kind)->values();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $type, ?string $kg, array $extra = []): TestResponse
    {
        return $this->fresh()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone->ulid, 'waste_type' => $type, 'net_weight_kg' => $kg] + $extra)->assertCreated();
    }

    private function api(): static
    {
        return $this->fresh();
    }

    private function fresh(?string $token = null): static
    {
        $this->app->forgetScopedInstances();
        // The auth guard caches the user between requests of one test: switching tokens must reset it.
        app('auth')->forgetGuards();

        return $this->withToken($token ?? $this->token);
    }
}
