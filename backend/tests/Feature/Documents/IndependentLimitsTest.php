<?php

namespace Tests\Feature\Documents;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Each limit is answered on its own, in its own unit: the weight and the packages of an authorisation, the room and the
 * load of a zone, a stock limit. No answer is replaced by the percentage of another, and a quantity nobody knows is
 * neither zero nor a met limit.
 */
class IndependentLimitsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private User $admin;

    private Zone $zone;

    private string $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'NAB');
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
        $this->zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id,
            'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']));
        $this->partner = $this->api()->postJson('/api/v1/external-partners', ['legal_name' => 'Recyclo', 'roles' => ['recycler', 'transporter']])->assertCreated()->json('data.ulid');
    }

    public function test_an_authorisation_in_weight_and_bags_gives_two_answers_and_fails_on_the_one_that_is_exceeded(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $authorization = $this->authorize($type, ['max_kg' => '500', 'alert_pct' => 95, 'limits' => [['packaging_type' => 'bag', 'max_count' => 300]]]);
        $lot = $this->lot($type, '450', ['packaging_type' => 'bag', 'package_count' => 310])->json('data');

        $check = $this->check([$lot['ulid']])->assertJsonPath('data.0.reason', 'exceeded')->json('data.0.constraints');
        // Weight: 450 of 500 kg, 90 %, respected. Bags: 310 of 300, exceeded by 10. Two lines, two units, two answers.
        $this->assertSame(['weight', 'kg', '450.000', '500.000', '50.000', 'respected', true], [$check[0]['key'], $check[0]['unit'], $check[0]['used'], $check[0]['max'], $check[0]['remaining'], $check[0]['outcome'], $check[0]['blocking']]);
        $this->assertEquals(90, $check[0]['pct']);
        $this->assertSame(['packages:bag', 'package', 'exceeded', 'bag', 310, 300, 10], [$check[1]['key'], $check[1]['unit'], $check[1]['outcome'], $check[1]['packaging_type'], $check[1]['used'], $check[1]['max'], $check[1]['excess']]);

        // The refusal says both: what is respected and what is not.
        $refused = $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$lot['ulid']]).'/submit')->assertStatus(409)->assertJsonPath('code', 'AUTHORIZATION_EXCEEDED')
            ->assertJsonPath('context.reference', 'D-2026-001')->assertJsonPath('context.constraints.0.outcome', 'respected')->assertJsonPath('context.constraints.1.outcome', 'exceeded');
        $this->assertStringContainsString('weight 450.000/500.000 kg: respected', (string) $refused->json('detail'));
        $this->assertStringContainsString('bag 310/300: exceeded by 10', (string) $refused->json('detail'));
        // Nothing was drawn.
        $this->assertSame(['0.000', 'respected'], [$this->usage($authorization)['constraints'][0]['used'], $this->usage($authorization)['outcome']]);
    }

    public function test_an_exceeded_limit_and_an_unverifiable_one_are_both_reported_for_the_same_request(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $this->authorize($type, ['max_kg' => '500', 'limits' => [['packaging_type' => 'bag', 'max_count' => 300]]]);
        $weighed = $this->lot($type, '100', ['packaging_type' => 'bag', 'package_count' => 200])->json('data');
        $unweighed = $this->lot($type, null, ['packaging_type' => 'bag', 'package_count' => 110])->json('data');

        $check = $this->check([$weighed['ulid'], $unweighed['ulid']])->assertJsonPath('data.0.reason', 'exceeded')->json('data.0');
        // The weight is not "20 % used": one lot has none, and it is named.
        $this->assertSame(['unverifiable', '100.000', 1, [$unweighed['lot_number']]], [$check['constraints'][0]['outcome'], $check['constraints'][0]['used'], $check['constraints'][0]['missing_count'], $check['constraints'][0]['missing_lots']]);
        $this->assertSame(['exceeded', 10], [$check['constraints'][1]['outcome'], $check['constraints'][1]['excess']]);
        $this->assertSame([$unweighed['lot_number']], $check['unweighed']);

        $refused = $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$weighed['ulid'], $unweighed['ulid']]).'/submit')->assertStatus(409)->json();
        $this->assertStringContainsString('cannot be verified, 1 lot(s) have no measured weight ('.$unweighed['lot_number'].')', (string) $refused['detail']);
        $this->assertStringContainsString('exceeded by 10', (string) $refused['detail']);
        $this->assertSame(['unverifiable', 'exceeded'], array_column($refused['context']['constraints'], 'outcome'));

        // Without the bags in excess, the unknown weight alone still refuses — as a different answer.
        $this->check([$unweighed['ulid']])->assertJsonPath('data.0.reason', 'unverifiable')->assertJsonPath('data.0.constraints.1.outcome', 'respected');
    }

    public function test_a_document_that_states_one_shared_quantity_keeps_it_as_one_limit_beside_weight_and_lots(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        // "100 bags or 50 boxes": said by the document, recorded as such. Never assumed.
        $authorization = $this->authorize($type, ['max_kg' => '1000', 'max_lots' => 2, 'shared_packaging_allowance' => true, 'limits' => [['packaging_type' => 'bag', 'max_count' => 100], ['packaging_type' => 'box', 'max_count' => 50]]]);
        $bags = $this->lot($type, '100', ['packaging_type' => 'bag', 'package_count' => 50])->json('data');
        $boxes = $this->lot($type, '100', ['packaging_type' => 'box', 'package_count' => 20])->json('data');
        $loose = $this->lot($type, '50')->json('data');

        $this->check([$bags['ulid'], $boxes['ulid']])->assertJsonPath('data.0.reason', null);
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$bags['ulid'], $boxes['ulid']]).'/submit')->assertOk();
        $usage = array_column($this->usage($authorization)['constraints'], null, 'key');
        $this->assertSame(['weight', 'packages', 'lots'], array_keys($usage));
        $this->assertEquals([20, 'respected'], [$usage['weight']['pct'], $usage['weight']['outcome']]);
        // 50 bags of 100 and 20 boxes of 50 share the allowance: 90 %, its own alert reached; 5 more boxes still fit.
        $this->assertEquals([90, 'warning', 5], [$usage['packages']['pct'], $usage['packages']['outcome'], $usage['packages']['lines'][1]['remaining']]);
        $this->assertSame([2, 2, 0, 'warning'], [$usage['lots']['used'], $usage['lots']['max'], $usage['lots']['remaining'], $usage['lots']['outcome']]);

        // A third lot, loose: the weight covers it and the packages do not concern it — the number of lots refuses it.
        $third = $this->check([$loose['ulid']])->assertJsonPath('data.0.reason', 'exceeded')->json('data.0.constraints');
        $this->assertSame(['weight' => 'respected', 'packages' => 'warning', 'unlisted' => 'respected', 'lots' => 'exceeded'], array_column($third, 'outcome', 'key'));
        $this->assertSame([1, [$loose['lot_number']], 1], [$third[2]['outside_count'], $third[2]['outside_lots'], $third[3]['excess']]);

        // The decision kept with the line holds every limit as it was answered, before and with the pickup.
        $decision = json_decode((string) DB::table('pickup_lots')->where('lot_number_snapshot', $bags['lot_number'])->value('authorization_decision'), true);
        $this->assertSame([['weight', 'packages', 'lots'], true], [array_column($decision['constraints_after'], 'key'), $decision['limits']['shared_packaging_allowance']]);
        $this->assertSame(['0.000', '200.000', 'warning'], [$decision['constraints_before'][0]['used'], $decision['constraints_after'][0]['used'], $decision['constraints_after'][1]['outcome']]);
    }

    public function test_bags_and_bales_on_one_document_are_two_limits_that_do_not_draw_on_each_other(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $authorization = $this->authorize($type, ['alert_pct' => 100, 'limits' => [['packaging_type' => 'bag', 'max_count' => 300], ['packaging_type' => 'bale', 'max_count' => 40]]]);
        $this->assertFalse($this->document($authorization)['shared_packaging_allowance']);
        $bags = $this->lot($type, '100', ['packaging_type' => 'bag', 'package_count' => 310])->json('data');
        $bales = $this->lot($type, '100', ['packaging_type' => 'bale', 'package_count' => 10])->json('data');

        // 310 bags of 300: exceeded by 10. 10 bales of 40: a quarter, respected. One does not weigh on the other.
        $check = $this->check([$bags['ulid'], $bales['ulid']])->assertJsonPath('data.0.reason', 'exceeded')->json('data.0.constraints');
        $this->assertSame(['packages:bag', 'packages:bale'], array_column($check, 'key'));
        $this->assertEquals(['exceeded', 310, 300, 10, 'bag'], [$check[0]['outcome'], $check[0]['used'], $check[0]['max'], $check[0]['excess'], $check[0]['packaging_type']]);
        $this->assertEquals(['respected', 10, 40, 30, 25], [$check[1]['outcome'], $check[1]['used'], $check[1]['max'], $check[1]['remaining'], $check[1]['pct']]);
        $refused = $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$bags['ulid'], $bales['ulid']]).'/submit')->assertStatus(409)->assertJsonPath('code', 'AUTHORIZATION_EXCEEDED')->json();
        $this->assertStringContainsString('bag 310/300: exceeded by 10; bale 10/40: respected', (string) $refused['detail']);

        // Both limits can be used in full: 300 bags and 40 bales together are covered. Shared, this would be twice the room.
        $full = [$this->lot($type, '90', ['packaging_type' => 'bag', 'package_count' => 300])->json('data.ulid'), $this->lot($type, '90', ['packaging_type' => 'bale', 'package_count' => 40])->json('data.ulid')];
        $this->check($full)->assertJsonPath('data.0.reason', null)->assertJsonPath('data.0.constraints.0.pct', 100)->assertJsonPath('data.0.constraints.1.pct', 100);
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup($full).'/submit')->assertOk();
        // One more bale is refused by the bales alone.
        $more = $this->lot($type, '5', ['packaging_type' => 'bale'])->json('data');
        $this->check([$more['ulid']])->assertJsonPath('data.0.reason', 'exceeded')->assertJsonPath('data.0.constraints.0.outcome', 'warning')
            ->assertJsonPath('data.0.constraints.1.outcome', 'exceeded')->assertJsonPath('data.0.constraints.1.excess', 1);
        $this->assertSame(['packages:bale'], $this->usageWith($more['ulid'])['failing']);

        // A document with a single packaging has nothing to share, whatever is sent.
        $single = $this->authorize($this->type('TEINT', authorised: true), ['shared_packaging_allowance' => true, 'limits' => [['packaging_type' => 'bag', 'max_count' => 5]]]);
        $this->assertFalse($this->document($single)['shared_packaging_allowance']);
    }

    public function test_packaging_limits_beside_a_weight_and_a_lot_whose_packages_nobody_knows(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $this->authorize($type, ['max_kg' => '500', 'limits' => [['packaging_type' => 'bag', 'max_count' => 300], ['packaging_type' => 'bale', 'max_count' => 40]]]);

        // Bales within their limit, but not weighed: the weight cannot be verified, the bales can.
        $unweighed = $this->lot($type, null, ['packaging_type' => 'bale', 'package_count' => 5])->json('data');
        $check = array_column($this->check([$unweighed['ulid']])->assertJsonPath('data.0.reason', 'unverifiable')->json('data.0.constraints'), null, 'key');
        $this->assertSame(['weight' => 'unverifiable', 'packages:bag' => 'respected', 'packages:bale' => 'respected'], array_map(fn (array $limit) => $limit['outcome'], $check));
        $this->assertSame([[$unweighed['lot_number']], 5, 0], [$check['weight']['missing_lots'], $check['packages:bale']['used'], $check['packages:bag']['used']]);

        // A bagged lot that does not say how many bags it is: the bags cannot be verified — it is not counted as one,
        // nor as none — and the bales and the weight are answered as usual.
        $bags = $this->lot($type, '50', ['packaging_type' => 'bag', 'package_count' => 20])->json('data');
        DB::table('waste_lots')->where('ulid', $bags['ulid'])->update(['package_count' => null]);
        $check = array_column($this->check([$bags['ulid']])->assertJsonPath('data.0.reason', 'unverifiable')->json('data.0.constraints'), null, 'key');
        $this->assertSame(['weight' => 'respected', 'packages:bag' => 'unverifiable', 'packages:bale' => 'respected'], array_map(fn (array $limit) => $limit['outcome'], $check));
        $this->assertSame([[$bags['lot_number']], 0], [$check['packages:bag']['missing_lots'], $check['packages:bag']['used']]);

        // Weighed drums: no packaging limit names them, the weight covers them, and they are listed as such.
        $drums = $this->lot($type, '60', ['packaging_type' => 'drum', 'package_count' => 3])->json('data');
        $check = array_column($this->check([$drums['ulid']])->assertJsonPath('data.0.reason', null)->json('data.0.constraints'), null, 'key');
        $this->assertSame(['respected', 1, [$drums['lot_number']]], [$check['unlisted']['outcome'], $check['unlisted']['outside_count'], $check['unlisted']['outside_lots']]);
    }

    public function test_a_line_already_drawn_without_a_weight_is_named_not_counted_as_zero(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $authorization = $this->authorize($type, ['limits' => [['packaging_type' => 'bag', 'max_count' => 100]]]);
        $unweighed = $this->lot($type, null, ['packaging_type' => 'bag', 'package_count' => 10])->json('data');
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$unweighed['ulid']]).'/submit')->assertOk();
        // The document is later read as limited in weight too (direct write: the API refuses to change one in use).
        DB::table('waste_authorizations')->where('ulid', $authorization)->update(['max_kg' => '500']);

        $usage = $this->usage($authorization);
        $this->assertSame(['unverifiable', true, ['weight']], [$usage['outcome'], $usage['blocked'], $usage['failing']]);
        $this->assertSame(['0.000', [$unweighed['lot_number']]], [$usage['constraints'][0]['used'], $usage['constraints'][0]['missing_lots']]);
        // A weighed lot cannot be said to fit while that weight is unknown.
        $weighed = $this->lot($type, '50', ['packaging_type' => 'bag'])->json('data');
        $this->check([$weighed['ulid']])->assertJsonPath('data.0.reason', 'unverifiable')->assertJsonPath('data.0.unweighed', [$unweighed['lot_number']]);
    }

    public function test_the_room_and_the_load_of_a_zone_are_answered_separately(): void
    {
        $this->api()->putJson("/api/v1/zones/{$this->zone->ulid}", ['code' => 'AIRE', 'name' => 'Aire', 'capacity_kg' => '2000', 'capacity_alert_pct' => 80,
            'capacities' => [['packaging_type' => 'bag', 'max_count' => 100]]])->assertOk();
        $type = $this->type('CHUTES');
        $fill = function () {
            $this->app->forgetScopedInstances();
            $fill = $this->api()->getJson("/api/v1/sites/{$this->site->ulid}")->assertOk()->json('data.zones.0.fill');

            return $fill + array_column($fill['constraints'], null, 'key');
        };

        // 85 bags of 100, 1,500 kg of 2,000: the room is at its alert, the load is not. No single percentage.
        $this->lot($type, '1500', ['packaging_type' => 'bag', 'package_count' => 85]);
        $first = $fill();
        $this->assertEquals(['warning', 85, 'respected', 75, '500.000'], [$first['space']['outcome'], $first['space']['pct'], $first['load']['outcome'], $first['load']['pct'], $first['load']['remaining']]);
        $this->assertSame(['warning', 'warning', false], [$first['outcome'], $first['alert_level'], array_key_exists('pct', $first)]);

        // A lot nobody weighed: the load is no longer verified, and the lot is named. The room counts its bags as before.
        $unweighed = $this->lot($type, null, ['packaging_type' => 'bag', 'package_count' => 10])->json('data');
        $second = $fill();
        $this->assertEquals(['warning', 95, 'unverifiable', '1500.000', [$unweighed['lot_number']]], [$second['space']['outcome'], $second['space']['pct'], $second['load']['outcome'], $second['load']['used'], $second['load']['missing_lots']]);
        $this->assertSame(['unverifiable', null], [$second['outcome'], $second['alert_level']]);

        // 600 kg loose: the load is exceeded by 100 kg on what is known (the unweighed lot still named); the room cannot
        // count a loose lot and says so. Placing it was not refused: a zone alerts.
        $loose = $this->lot($type, '600')->json('data');
        $third = $fill();
        $this->assertSame(['exceeded', '100.000', 1, 'unverifiable', [$loose['lot_number']]], [$third['load']['outcome'], $third['load']['excess'], $third['load']['missing_count'], $third['space']['outcome'], $third['space']['missing_lots']]);
        $this->assertEquals(95, $third['space']['pct']);
        $this->assertSame(['exceeded', 'exceeded'], [$third['outcome'], $third['alert_level']]);
    }

    public function test_a_stock_limit_with_lots_nobody_weighed_is_not_said_to_be_respected(): void
    {
        $type = $this->type('CHUTES');
        $this->api()->postJson('/api/v1/stock-thresholds', ['site' => $this->site->ulid, 'waste_type' => $type, 'max_quantity_kg' => '500', 'warning_pct' => 80])->assertCreated();
        $limit = fn () => $this->api()->getJson('/api/v1/stock')->assertOk()->json('thresholds.0.constraint');

        $this->lot($type, '120');
        $this->assertEquals(['respected', 24, 'kg', false], [$limit()['outcome'], $limit()['pct'], $limit()['unit'], $limit()['blocking']]);

        // Known total below the limit, one lot unknown: not verifiable.
        $unweighed = $this->lot($type, null)->json('data');
        $this->assertSame(['unverifiable', '120.000', 1, [$unweighed['lot_number']]], [$limit()['outcome'], $limit()['used'], $limit()['missing_count'], $limit()['missing_lots']]);

        // Known total above it: exceeded, and the unknown lot is still disclosed. The lot was created all the same.
        $this->lot($type, '400');
        $this->assertSame(['exceeded', '520.000', '20.000', 1], [$limit()['outcome'], $limit()['used'], $limit()['excess'], $limit()['missing_count']]);
    }

    public function test_people_are_told_once_when_a_stock_limit_can_no_longer_be_verified(): void
    {
        Notification::fake();
        $manager = $this->makeMember($this->company, ['environment_manager' => $this->site]);
        $type = $this->type('CHUTES');
        $limit = $this->api()->postJson('/api/v1/stock-thresholds', ['site' => $this->site->ulid, 'waste_type' => $type, 'max_quantity_kg' => '900', 'warning_pct' => 80])->assertCreated()->json('data.ulid');
        $told = fn (string $kind) => Notification::sent($manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'stock.threshold_'.$kind);

        // 700 kg of 900: below the warning, nothing to say.
        $this->lot($type, '700');
        Notification::assertNothingSent();
        $this->assertSame([null], DB::table('stock_thresholds')->where('ulid', $limit)->pluck('alert_level')->all());

        // A lot nobody weighed: placed all the same, and the limit can no longer be called respected. People are told
        // what is known, what is not, and where.
        $first = $this->lot($type, null)->json('data');
        $this->assertSame(['unverifiable'], DB::table('stock_thresholds')->where('ulid', $limit)->pluck('alert_level')->all());
        $sent = $told('unverifiable');
        $this->assertCount(1, $sent);
        $this->assertSame(['700 kg', '900 kg', 1, $first['lot_number'], $this->site->name.' · CHUTES', null, 'CHUTES'], [$sent[0]->params['quantity'], $sent[0]->params['max_quantity'],
            $sent[0]->params['unweighed_lots'], $sent[0]->params['lots'], $sent[0]->params['scope'], $sent[0]->params['zone'], $sent[0]->params['waste_type']]);
        $this->assertCount(0, $told('exceeded'));
        // Like a known exceedance, it also goes out by email: someone has to weigh.
        $this->assertSame([true, true, false], array_map(NotificationCatalog::sendsMail(...), ['stock.threshold_unverifiable', 'stock.threshold_exceeded', 'stock.threshold_warning']));

        // The same state, recalculated: a second unweighed lot, another movement — nobody is told again.
        Notification::fake();
        $second = $this->lot($type, null)->json('data');
        $this->lot($type, '1');
        Notification::assertNothingSent();
        $this->assertSame(['unverifiable'], DB::table('stock_thresholds')->where('ulid', $limit)->pluck('alert_level')->all());

        // Weighed, the limit is verified again (711 kg): the state clears, silently.
        $this->api()->postJson("/api/v1/lots/{$first['ulid']}/weigh", ['net_weight_kg' => '5'])->assertOk();
        $this->assertTrue(DB::table('stock_thresholds')->where('ulid', $limit)->where('alert_level', 'unverifiable')->exists());
        $this->api()->postJson("/api/v1/lots/{$second['ulid']}/weigh", ['net_weight_kg' => '5'])->assertOk();
        $this->assertSame([null], DB::table('stock_thresholds')->where('ulid', $limit)->pluck('alert_level')->all());
        Notification::assertNothingSent();

        // It happens again later: that is news again.
        $third = $this->lot($type, null)->json('data');
        $this->assertCount(1, $told('unverifiable'));

        // The known weight goes above the limit: a known exceedance, a different message, and the unweighed lot is still named.
        $this->lot($type, '300');
        $this->assertSame(['exceeded'], DB::table('stock_thresholds')->where('ulid', $limit)->pluck('alert_level')->all());
        $exceeded = $told('exceeded');
        $this->assertSame(['1 011 kg', 1, $third['lot_number']], [$exceeded[0]->params['quantity'], $exceeded[0]->params['unweighed_lots'], $exceeded[0]->params['lots']]);
        $this->assertCount(1, $told('unverifiable'));
    }

    public function test_an_unverifiable_stock_limit_that_turns_out_to_be_at_its_warning_says_so(): void
    {
        Notification::fake();
        $manager = $this->makeMember($this->company, ['environment_manager' => $this->site]);
        $type = $this->type('CHUTES');
        $this->api()->postJson('/api/v1/stock-thresholds', ['site' => $this->site->ulid, 'zone' => $this->zone->ulid, 'max_quantity_kg' => '900', 'warning_pct' => 80])->assertCreated();
        $told = fn (string $kind) => Notification::sent($manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'stock.threshold_'.$kind);

        $this->lot($type, '750');
        $this->assertCount(1, $told('warning'));
        // Warning, then a lot without weight: the limit says less than before, and people are told, with the zone.
        $unweighed = $this->lot($type, null)->json('data');
        $this->assertCount(1, $told('unverifiable'));
        $this->assertSame($this->site->name.' · Aire', $told('unverifiable')[0]->params['scope']);
        // Weighed: 755 kg, at the warning for certain this time.
        $this->api()->postJson("/api/v1/lots/{$unweighed['ulid']}/weigh", ['net_weight_kg' => '5'])->assertOk();
        $this->assertCount(2, $told('warning'));
        $this->assertCount(0, $told('exceeded'));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $authorization): array
    {
        return collect($this->api()->getJson('/api/v1/waste-authorizations')->assertOk()->json('data'))->firstWhere('ulid', $authorization);
    }

    /**
     * The limits that would refuse these lots.
     *
     * @return array{failing: list<string>}
     */
    private function usageWith(string $lot): array
    {
        $constraints = $this->check([$lot])->json('data.0.constraints');

        return ['failing' => array_values(array_map(fn (array $limit) => $limit['key'], array_filter($constraints, fn (array $limit) => in_array($limit['outcome'], ['exceeded', 'unverifiable'], true))))];
    }

    private function type(string $code, bool $authorised = false): string
    {
        return $this->tenant()->runAs($this->company, function () use ($code, $authorised) {
            $type = WasteType::query()->create(['code' => $code, 'name' => $code, 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()]);
            $type->forceFill(['requires_authorization' => $authorised])->save();

            return $type->ulid;
        });
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function authorize(string $type, array $limits): string
    {
        return $this->api()->postJson('/api/v1/waste-authorizations', ['waste_type' => $type, 'reference' => 'D-2026-001',
            'valid_from' => now()->subMonth()->toDateString(), 'valid_until' => now()->addMonths(5)->toDateString()] + $limits)->assertCreated()->json('data.ulid');
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(string $authorization): array
    {
        return collect($this->api()->getJson('/api/v1/waste-authorizations')->assertOk()->json('data'))->firstWhere('ulid', $authorization)['usage'];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $type, ?string $kg, array $extra = []): TestResponse
    {
        return $this->api()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone->ulid, 'waste_type' => $type, 'net_weight_kg' => $kg] + $extra)->assertCreated();
    }

    /**
     * @param  list<string>  $lots
     */
    private function check(array $lots): TestResponse
    {
        return $this->api()->postJson('/api/v1/waste-authorizations/check', ['date' => now()->toDateString(), 'lots' => $lots])->assertOk();
    }

    /**
     * @param  list<string>  $lots
     */
    private function pickup(array $lots): string
    {
        return $this->api()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $this->partner,
            'requested_date' => now()->toDateString(), 'lots' => $lots])->assertCreated()->json('data.ulid');
    }

    private function api(): static
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company));
    }
}
