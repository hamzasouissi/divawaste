<?php

namespace Tests\Feature\Waste;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * A lot is not a package: one lot may be twelve bags. Zones and authorisations count the packages; a lot without
 * packaging has none; a count nobody entered is a default and says so; a count corrected after departure stands beside
 * the figure the decisions were taken with.
 */
class PackageCountTest extends TestCase
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

    public function test_a_lot_counts_its_packages_one_by_default_and_none_when_loose(): void
    {
        $type = $this->type('CHUTES');
        $this->lot($type, '10', ['packaging_type' => 'bag'])->assertJsonPath('data.package_count', 1)->assertJsonPath('data.package_count_confirmed', false);
        $twelve = $this->lot($type, '84', ['packaging_type' => 'bag', 'package_count' => 12, 'quantity' => '240', 'unit' => 'piece'])
            ->assertJsonPath('data.package_count', 12)->assertJsonPath('data.package_count_confirmed', true)->json('data');
        // One lot, twelve bags, 240 pieces, 84 kg: four figures, none of them replacing another.
        $this->assertSame([12, '240.000', '84.000'], [$twelve['package_count'], $twelve['quantity'], $twelve['net_weight_kg']]);
        $this->assertSame(2, DB::table('waste_lots')->count());

        // Loose waste has no packages: none is invented, and one cannot be typed.
        $loose = $this->lot($type, '30')->assertJsonPath('data.packaging_type', null)->assertJsonPath('data.package_count', null)->json('data');
        $this->api()->postJson('/api/v1/lots', $this->payload($type, '30') + ['package_count' => 3])->assertStatus(422)->assertJsonValidationErrors('package_count');
        $this->api()->putJson("/api/v1/lots/{$loose['ulid']}/packages", ['package_count' => 2])->assertStatus(409)->assertJsonPath('code', 'LOT_NOT_PACKAGED');
        // The packaging the waste type plans is a packaging too.
        $baled = $this->type('CARTON', packaging: 'bale');
        $this->lot($baled, '50')->assertJsonPath('data.packaging_type', 'bale')->assertJsonPath('data.package_count', 1)->assertJsonPath('data.package_count_confirmed', false);
    }

    public function test_a_zone_fills_with_packages_whatever_the_number_of_lots_and_mixed_packagings_share_one_room(): void
    {
        $this->api()->putJson("/api/v1/zones/{$this->zone->ulid}", ['code' => 'AIRE', 'name' => 'Aire',
            'capacities' => [['packaging_type' => 'bag', 'max_count' => 100], ['packaging_type' => 'box', 'max_count' => 50]]])->assertOk();
        $type = $this->type('CHUTES');
        // What a zone holds is read once per request; each call here is a new request.
        $fill = function () {
            $this->app->forgetScopedInstances();

            $fill = $this->api()->getJson("/api/v1/sites/{$this->site->ulid}")->assertOk()->json('data.zones.0.fill');

            return $fill + ['space' => collect($fill['constraints'])->firstWhere('key', 'space')];
        };

        // One lot of one bag: 1 %. One lot of twelve bags: twelve more, not one more.
        $this->lot($type, '5', ['packaging_type' => 'bag']);
        $this->assertSame([1.0, ['bag' => 1], 1], [(float) $fill()['space']['pct'], $fill()['packages'], $fill()['unconfirmed_lots']]);
        $this->lot($type, '60', ['packaging_type' => 'bag', 'package_count' => 12]);
        $this->assertSame([13.0, ['bag' => 13], 2], [(float) $fill()['space']['pct'], $fill()['packages'], $fill()['lots']]);

        // Several lots add up: 13 + 37 = 50 bags, half the room; 10 + 15 = 25 boxes, the other half.
        $this->lot($type, '200', ['packaging_type' => 'bag', 'package_count' => 37]);
        $this->assertSame(50.0, (float) $fill()['space']['pct']);
        $this->assertSame(['bag' => 50, 'box' => 25], array_column($fill()['space']['lines'], 'remaining', 'packaging_type'));
        $this->lot($type, '40', ['packaging_type' => 'box', 'package_count' => 10]);
        $this->lot($type, '60', ['packaging_type' => 'box', 'package_count' => 15]);
        $full = $fill();
        $this->assertSame([100.0, ['bag' => 50, 'box' => 25], ['bag' => 0, 'box' => 0], 5], [(float) $full['space']['pct'], $full['packages'], array_column($full['space']['lines'], 'remaining', 'packaging_type'), $full['lots']]);
        $this->assertSame(['warning', 'warning'], [$full['space']['outcome'], $full['alert_level']]);

        // A loose lot and a packaging the zone does not plan for are reported apart, not turned into bags.
        $this->lot($type, '20');
        $this->lot($type, '20', ['packaging_type' => 'drum', 'package_count' => 3]);
        $more = $fill();
        // The room is then no longer said to be verified: the two lots it cannot count are named.
        $this->assertSame([100.0, 2, 'unverifiable', ['bag' => 50, 'box' => 25, '' => 1, 'drum' => 3]], [(float) $more['space']['pct'], $more['space']['missing_count'], $more['space']['outcome'], $more['packages']]);
    }

    public function test_an_authorisation_in_packages_counts_packages_and_cannot_count_what_has_none(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $authorization = $this->authorize($type, ['limits' => [['packaging_type' => 'bag', 'max_count' => 30]]]);
        $twelve = $this->lot($type, null, ['packaging_type' => 'bag', 'package_count' => 12])->json('data');
        $twenty = $this->lot($type, '90', ['packaging_type' => 'bag', 'package_count' => 20])->json('data');
        $loose = $this->lot($type, '15')->json('data');
        $drums = $this->lot($type, '15', ['packaging_type' => 'drum', 'package_count' => 2])->json('data');

        // Twelve bags of thirty — no weight is needed for a document stated in bags.
        $this->check([$twelve['ulid']])->assertJsonPath('data.0.reason', null)->assertJsonPath('data.0.constraints.0.pct', 40)->assertJsonPath('data.0.constraints.0.lines.0.used', 12);
        $first = $this->pickup([$twelve['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$first}/submit")->assertOk();
        $usage = $this->usage($authorization);
        $this->assertSame([12, 1, 18], [$usage['used']['counts']['bag'], $usage['used']['lots'], $usage['constraints'][0]['lines'][0]['remaining']]);
        // Twenty more would make thirty-two.
        $this->check([$twenty['ulid']])->assertJsonPath('data.0.reason', 'exceeded');
        // Loose waste and drums have no bags to count: the document cannot be checked for them, and they are named.
        $this->check([$loose['ulid'], $drums['ulid']])->assertJsonPath('data.0.reason', 'packaging')->assertJsonPath('data.0.uncounted', [$loose['lot_number'], $drums['lot_number']]);
        $refused = $this->pickup([$loose['ulid']]);
        $response = $this->api()->postJson("/api/v1/pickups/{$refused}/submit")->assertStatus(409)->assertJsonPath('code', 'AUTHORIZATION_PACKAGING');
        $this->assertStringContainsString($loose['lot_number'], (string) $response->json('detail'));

        // What was asked and answered is kept with the line.
        $decision = json_decode((string) DB::table('pickup_lots')->where('lot_number_snapshot', $twelve['lot_number'])->value('authorization_decision'), true);
        $this->assertSame(['bag', 12, ['bag' => 30], 'packages', 12, 30, 'respected', 0], [$decision['packaging'], $decision['package_count'], $decision['limits']['packages'], $decision['constraints_after'][0]['key'],
            $decision['constraints_after'][0]['lines'][0]['used'], $decision['constraints_after'][0]['lines'][0]['max'], $decision['constraints_after'][0]['outcome'], $decision['constraints_before'][0]['lines'][0]['used']]);
    }

    public function test_an_authorisation_in_weight_only_takes_any_packaging_and_one_in_both_checks_both(): void
    {
        $weight = $this->type('REBUTS', authorised: true);
        $this->authorize($weight, ['max_kg' => '500']);
        // Drums, bags or loose: a document stated in kilograms does not care how the waste is packed.
        $drums = $this->lot($weight, '120', ['packaging_type' => 'drum', 'package_count' => 4])->json('data');
        $loose = $this->lot($weight, '80')->json('data');
        $this->check([$drums['ulid'], $loose['ulid']])->assertJsonPath('data.0.reason', null)->assertJsonPath('data.0.constraints.0.key', 'weight')->assertJsonPath('data.0.constraints.0.pct', 40);
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$drums['ulid'], $loose['ulid']]).'/submit')->assertOk();

        $both = $this->type('TEINT', authorised: true);
        $this->authorize($both, ['max_kg' => '500', 'limits' => [['packaging_type' => 'bag', 'max_count' => 30]]], 'D-2026-002');
        $fine = $this->lot($both, '84', ['packaging_type' => 'bag', 'package_count' => 12])->json('data');
        $many = $this->lot($both, '50', ['packaging_type' => 'bag', 'package_count' => 40])->json('data');
        $heavy = $this->lot($both, '600', ['packaging_type' => 'bag', 'package_count' => 2])->json('data');
        $unweighed = $this->lot($both, null, ['packaging_type' => 'bag', 'package_count' => 5])->json('data');
        $this->check([$fine['ulid']])->assertJsonPath('data.0.reason', null);
        // Within the weight, over the bags; within the bags, over the weight: either is enough to refuse.
        $this->check([$many['ulid']])->assertJsonPath('data.0.reason', 'exceeded');
        $this->check([$heavy['ulid']])->assertJsonPath('data.0.reason', 'exceeded');
        // The bags are within their limit, but the weight limit cannot be checked: it is not passed on the bags alone.
        $this->check([$unweighed['ulid']])->assertJsonPath('data.0.reason', 'unverifiable')->assertJsonPath('data.0.unweighed', [$unweighed['lot_number']]);
    }

    public function test_a_count_is_edited_on_site_and_corrected_with_a_reason_after_departure_without_rewriting_what_was_decided(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $authorization = $this->authorize($type, ['limits' => [['packaging_type' => 'bag', 'max_count' => 30]]]);
        $lot = $this->lot($type, '60', ['packaging_type' => 'bag'])->json('data');

        // On site: the default becomes a confirmed count; the pickup being prepared follows.
        $this->api()->putJson("/api/v1/lots/{$lot['ulid']}/packages", ['package_count' => 10])->assertOk()->assertJsonPath('data.package_count', 10)->assertJsonPath('data.package_count_confirmed', true);
        $pickup = $this->pickup([$lot['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk()->assertJsonPath('data.lots.0.package_count', 10)->assertJsonPath('data.lots.0.packaging_type', 'bag');
        $this->api()->putJson("/api/v1/lots/{$lot['ulid']}/packages", ['package_count' => 12])->assertOk();
        $this->assertSame(12, (int) DB::table('pickup_lots')->value('package_count'));
        // A count that the authorisation cannot hold is refused while the lot is still here, and nothing changes.
        $this->api()->putJson("/api/v1/lots/{$lot['ulid']}/packages", ['package_count' => 31])->assertStatus(409)->assertJsonPath('code', 'AUTHORIZATION_EXCEEDED');
        $this->assertSame([12, 12], [(int) DB::table('waste_lots')->value('package_count'), (int) DB::table('pickup_lots')->value('package_count')]);

        $this->api()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot['ulid']]]])->assertOk();
        $slip = DB::table('hazardous_waste_manifests')->first(['id', 'package_count']);
        $this->assertSame(12, (int) $slip->package_count);
        $decided = DB::table('pickup_lots')->value('authorization_decision');

        // Gone: a correction needs its reason, by someone allowed to change lots.
        $this->api()->putJson("/api/v1/lots/{$lot['ulid']}/packages", ['package_count' => 14])->assertStatus(422)->assertJsonValidationErrors('reason');
        $operator = $this->makeMember($this->company, ['auditor' => null]);
        $this->withToken($this->tokenFor($operator, $this->company))->putJson("/api/v1/lots/{$lot['ulid']}/packages", ['package_count' => 14, 'reason' => 'x'])->assertForbidden();
        $other = $this->makeCompany();
        $this->withToken($this->tokenFor($this->makeMember($other, ['client_admin' => null]), $other))->putJson("/api/v1/lots/{$lot['ulid']}/packages", ['package_count' => 14, 'reason' => 'x'])->assertNotFound();
        $this->api()->putJson("/api/v1/lots/{$lot['ulid']}/packages", ['package_count' => 14, 'reason' => 'Deux sacs comptés en plus au quai.'])->assertOk()->assertJsonPath('data.package_count', 14);

        // The line keeps the figure it left with and shows the corrected one beside it; the slip and the decision are as issued.
        $this->api()->getJson("/api/v1/pickups/{$pickup}")->assertJsonPath('data.lots.0.package_count', 12)->assertJsonPath('data.lots.0.corrected_package_count', 14);
        $this->assertSame(12, (int) DB::table('hazardous_waste_manifests')->where('id', $slip->id)->value('package_count'));
        $this->assertSame($decided, DB::table('pickup_lots')->value('authorization_decision'));
        // The allowance is drawn by what really left.
        $this->assertSame([14, 16], [$this->usage($authorization)['used']['counts']['bag'], $this->usage($authorization)['constraints'][0]['lines'][0]['remaining']]);

        // The trail: previous and new count, who, when, why — for each change.
        $events = DB::table('waste_lot_events')->where('event_type', 'packages_corrected')->orderBy('id')->get(['payload', 'user_id', 'occurred_at']);
        $this->assertSame([[1, 10, false], [10, 12, false], [12, 14, true]], $events->map(fn ($event) => [json_decode((string) $event->payload, true)['previous_package_count'],
            json_decode((string) $event->payload, true)['package_count'], json_decode((string) $event->payload, true)['after_departure']])->all());
        $last = json_decode((string) $events->last()->payload, true);
        $this->assertSame(['Deux sacs comptés en plus au quai.', false], [$last['reason'], json_decode((string) $events->first()->payload, true)['previous_confirmed']]);
        $this->assertSame($this->admin->id, (int) $events->last()->user_id);
        $this->assertNotNull($events->last()->occurred_at);
        $audit = DB::table('audit_logs')->where('auditable_type', 'waste_lots')->where('event', 'updated')->where('new_values', 'like', '%package_count%')->orderByDesc('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame([['package_count' => 12], ['package_count' => 14]], [json_decode((string) $audit->old_values, true), json_decode((string) $audit->new_values, true)]);
        $this->assertSame($this->admin->id, (int) $audit->actor_user_id);
    }

    public function test_split_and_group_take_the_packages_the_operator_counts_never_those_of_the_lots_they_come_from(): void
    {
        $type = $this->type('CHUTES');
        $lot = $this->lot($type, '120', ['packaging_type' => 'bag', 'package_count' => 12])->json('data');

        $children = $this->api()->postJson("/api/v1/lots/{$lot['ulid']}/split", ['outputs' => [['net_weight_kg' => '70', 'package_count' => 7], ['net_weight_kg' => '50']]])->assertCreated()->json('data');
        // Seven as counted; the other one was not counted: a default of one, not the twelve of its parent.
        $this->assertSame([[7, true], [1, false]], array_map(fn (array $child) => [$child['package_count'], $child['package_count_confirmed']], $children));

        $this->api()->postJson('/api/v1/lots/group', ['lots' => array_column($children, 'ulid'), 'zone' => $this->zone->ulid, 'package_count' => 2])->assertCreated()
            ->assertJsonPath('data.package_count', 2)->assertJsonPath('data.package_count_confirmed', true)->assertJsonPath('data.net_weight_kg', '120.000');

        // Loose lots stay loose: no count appears on the way.
        $loose = $this->lot($type, '40')->json('data');
        $parts = $this->api()->postJson("/api/v1/lots/{$loose['ulid']}/split", ['outputs' => [['net_weight_kg' => '20'], ['net_weight_kg' => '20']]])->assertCreated()->json('data');
        $this->assertSame([null, null], array_column($parts, 'package_count'));
    }

    public function test_an_estimated_departure_weight_shows_an_indicative_gap_and_does_not_meet_the_tolerance(): void
    {
        $type = $this->type('CHUTES');
        [$guess, $weighed] = [$this->lot($type, '100', ['weight_estimated' => true])->json('data'), $this->lot($type, '100')->json('data')];
        $pickup = $this->pickup([$guess['ulid'], $weighed['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $guess['ulid']], ['lot' => $weighed['ulid']]]])->assertOk();

        // Both arrive 1 % lighter. Against a weighing that is within the 5 %; against a guess it proves nothing.
        $this->api()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $guess['lot_number'], 'received_weight_kg' => '99'], ['lot_number' => $weighed['lot_number'], 'received_weight_kg' => '99']]])
            ->assertOk()->assertJsonPath('data.lots.0.tolerance_status', 'no_reference')->assertJsonPath('data.lots.0.tolerance.reference_basis', 'estimated')
            // The estimate is kept, and the gap against it is shown for what it is.
            ->assertJsonPath('data.lots.0.tolerance.reference_quantity', '100.000')->assertJsonPath('data.lots.0.tolerance.pct', '-1.00')->assertJsonPath('data.lots.0.variance_flagged', true)
            ->assertJsonPath('data.lots.1.tolerance_status', 'within')->assertJsonPath('data.lots.1.tolerance.reference_basis', 'measured')
            ->assertJsonPath('data.tolerance_status', 'no_reference')->assertJsonPath('data.variance_flagged', true);
        $this->assertSame(['100.000', 'estimated'], [DB::table('waste_lots')->where('ulid', $guess['ulid'])->value('net_weight_kg'), DB::table('waste_lots')->where('ulid', $guess['ulid'])->value('weight_basis')]);

        // Weighed at loading, the same lot is judged like any other.
        $again = $this->lot($type, '100', ['weight_estimated' => true])->json('data');
        $second = $this->pickup([$again['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$second}/submit")->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$second}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$second}/collect", ['lots' => [['lot' => $again['ulid'], 'departure_weight_kg' => '98']]])->assertOk()->assertJsonPath('data.lots.0.weight_basis', 'measured');
        $this->api()->postJson("/api/v1/pickups/{$second}/receive", ['lots' => [['lot_number' => $again['lot_number'], 'received_weight_kg' => '97.5']]])
            ->assertOk()->assertJsonPath('data.lots.0.tolerance_status', 'within')->assertJsonPath('data.variance_flagged', false);
    }

    private function type(string $code, bool $authorised = false, ?string $packaging = null): string
    {
        return $this->tenant()->runAs($this->company, function () use ($code, $authorised, $packaging) {
            $type = WasteType::query()->create(['code' => $code, 'name' => $code, 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
                'default_packaging_type_id' => $packaging === null ? null : DB::table('packaging_types')->where('code', $packaging)->value('id'), 'activated_at' => now()]);
            $type->forceFill(['requires_authorization' => $authorised])->save();

            return $type->ulid;
        });
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function authorize(string $type, array $limits, string $reference = 'D-2026-001'): string
    {
        return $this->api()->postJson('/api/v1/waste-authorizations', ['waste_type' => $type, 'reference' => $reference,
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
     * @return array<string, mixed>
     */
    private function payload(string $type, ?string $kg): array
    {
        return ['site' => $this->site->ulid, 'zone' => $this->zone->ulid, 'waste_type' => $type, 'net_weight_kg' => $kg];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $type, ?string $kg, array $extra = []): TestResponse
    {
        return $this->api()->postJson('/api/v1/lots', $this->payload($type, $kg) + $extra)->assertCreated();
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
