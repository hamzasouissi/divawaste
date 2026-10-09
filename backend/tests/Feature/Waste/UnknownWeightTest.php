<?php

namespace Tests\Feature\Waste;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Documents\Actions\ManifestWorkflow;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Documents\Support\WasteRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * A weight nobody measured is unknown: it is never read as 0 kg, never passes a limit in kilograms, and is counted
 * apart in every total. A lot may still be registered, stored and collected before it is weighed.
 */
class UnknownWeightTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private User $admin;

    private string $zone;

    private string $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'NAB');
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
        $this->zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id,
            'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire', 'capacity_kg' => '1000'])->ulid);
        // A receiver without an account: the company records every stage itself.
        $this->partner = $this->api()->postJson('/api/v1/external-partners', ['legal_name' => 'Recyclo', 'roles' => ['recycler', 'transporter']])->assertCreated()->json('data.ulid');
    }

    public function test_a_lot_is_registered_before_it_is_weighed_and_says_so(): void
    {
        $type = $this->type('CHUTES');
        $lot = $this->lot($type, null)->assertJsonPath('data.net_weight_kg', null)->assertJsonPath('data.weight_basis', 'unknown')->json('data');
        $guess = $this->lot($type, '40', ['weight_estimated' => true])->assertJsonPath('data.net_weight_kg', '40.000')->assertJsonPath('data.weight_basis', 'estimated')->json('data');
        $this->lot($type, '25')->assertJsonPath('data.weight_basis', 'measured');
        // A typed zero stays what it was: a weight of 0 kg entered by someone, not an unknown.
        $this->lot($type, '0')->assertJsonPath('data.net_weight_kg', '0.000')->assertJsonPath('data.weight_basis', 'measured');

        $this->api()->getJson('/api/v1/lots?filter[unweighed]=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ulid', $lot['ulid']);
        // Storing and labelling do not need the weight; sharing out a weight does.
        $this->api()->postJson("/api/v1/lots/{$lot['ulid']}/store", ['zone' => $this->zone])->assertOk()->assertJsonPath('data.status', 'stored');
        $this->api()->get("/api/v1/lots/{$lot['ulid']}/label")->assertOk();
        $this->api()->postJson("/api/v1/lots/{$lot['ulid']}/split", ['outputs' => [['net_weight_kg' => '1'], ['net_weight_kg' => '1']]])
            ->assertStatus(409)->assertJsonPath('code', 'LOT_WEIGHT_UNKNOWN');
        $this->api()->postJson('/api/v1/lots/group', ['lots' => [$lot['ulid'], $guess['ulid']], 'zone' => $this->zone])->assertStatus(409)->assertJsonPath('code', 'LOT_WEIGHT_UNKNOWN');

        // Weighing gives it a weight; the trail keeps that there was none before.
        $this->api()->postJson("/api/v1/lots/{$lot['ulid']}/weigh", ['net_weight_kg' => '31.5'])->assertOk()->assertJsonPath('data.net_weight_kg', '31.500')->assertJsonPath('data.weight_basis', 'measured');
        $event = DB::table('waste_lot_events')->where('event_type', 'weighed')->first();
        $this->assertEquals(['previous_weight_kg' => null, 'weight_basis' => 'measured'], json_decode((string) $event->payload, true));
        $this->api()->postJson("/api/v1/lots/{$guess['ulid']}/weigh", ['net_weight_kg' => '42', 'estimated' => true])->assertOk()->assertJsonPath('data.weight_basis', 'estimated');
        $this->api()->getJson('/api/v1/lots?filter[unweighed]=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_totals_give_the_known_weight_and_count_the_lots_that_have_none(): void
    {
        $type = $this->type('CHUTES');
        $this->lot($type, '120');
        $this->lot($type, null);
        $this->lot($type, null);
        $limit = $this->api()->postJson('/api/v1/stock-thresholds', ['site' => $this->site->ulid, 'waste_type' => $type, 'max_quantity_kg' => '500', 'warning_pct' => 80])->assertCreated()->json('data.ulid');

        $stock = $this->api()->getJson('/api/v1/stock')->assertOk();
        $stock->assertJsonPath('data.0.quantity_kg', '120.000')->assertJsonPath('data.0.lots', 3)->assertJsonPath('data.0.unweighed_lots', 2)
            ->assertJsonPath('meta.total_kg', '120.000')->assertJsonPath('meta.unweighed_lots', 2)
            ->assertJsonPath('data.0.zone_fill.quantity_kg', '120.000')->assertJsonPath('data.0.zone_fill.unweighed_lots', 2);
        // The limit is below its warning on what is known — and says that two lots could not be counted.
        $this->assertSame([['ulid' => $limit, 'quantity_kg' => '120.000', 'unweighed_lots' => 2, 'max_quantity_kg' => '500.000', 'alert_level' => null]], $stock->json('thresholds'));

        $range = '?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString();
        $this->api()->getJson('/api/v1/dashboard'.$range)->assertOk()->assertJsonPath('data.totals.generated_kg', '120.000')
            ->assertJsonPath('data.totals.generated_lots', 3)->assertJsonPath('data.totals.unweighed_lots', 2)
            ->assertJsonPath('data.by_waste_type.0.kg', '120.000')->assertJsonPath('data.by_waste_type.0.unweighed_lots', 2)->assertJsonPath('data.by_site.0.unweighed_lots', 2);

        $register = $this->api()->getJson('/api/v1/register'.$range)->assertOk();
        $this->assertSame(['120.000', null, null], array_column($register->json('data'), 'net_weight_kg'));
        $this->assertSame(['measured', 'unknown', 'unknown'], array_column($register->json('data'), 'weight_basis'));
        // In the exported register an unweighed lot has an empty weight cell, not a zero, and the total does not count it.
        $lines = $this->tenant()->runAs($this->company, fn () => app(WasteRegister::class)->query(Carbon::now()->subDay(), Carbon::now()->addDay(), null)->get()
            ->map(fn ($row) => WasteRegister::exportLine($row, 'UTC'))->all());
        $this->assertSame([120.0, null, null], array_column($lines, 7));
        $this->assertSame(count(WasteRegister::headings()), count($lines[0]));
    }

    public function test_a_weight_limited_authorisation_is_never_passed_by_a_weight_nobody_measured(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $free = $this->type('CHUTES');
        $this->api()->postJson('/api/v1/waste-authorizations', ['waste_type' => $type, 'reference' => 'D-2026-001', 'max_kg' => '500',
            'valid_from' => now()->subMonth()->toDateString(), 'valid_until' => now()->addMonths(5)->toDateString()])->assertCreated();
        $unweighed = $this->lot($type, null)->json('data');
        $guessed = $this->lot($type, '40', ['weight_estimated' => true])->json('data');
        $weighed = $this->lot($type, '60')->json('data');
        $other = $this->lot($free, null)->json('data');

        // Before the request: the check says which lots stop it, instead of reading them as 0 kg of a 500 kg allowance.
        $this->api()->postJson('/api/v1/waste-authorizations/check', ['date' => now()->toDateString(), 'lots' => [$unweighed['ulid'], $guessed['ulid'], $weighed['ulid']]])
            ->assertOk()->assertJsonPath('data.0.reason', 'unverifiable')->assertJsonPath('data.0.unweighed', [$unweighed['lot_number'], $guessed['lot_number']]);
        $pickup = $this->pickup([$unweighed['ulid'], $weighed['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertStatus(409)->assertJsonPath('code', 'AUTHORIZATION_UNVERIFIABLE');
        $this->assertStringContainsString($unweighed['lot_number'], (string) $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->json('detail'));
        $this->api()->postJson("/api/v1/pickups/{$pickup}/cancel", ['reason' => 'x'])->assertOk();
        // An estimate is not a measure either.
        $guess = $this->pickup([$guessed['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$guess}/submit")->assertStatus(409)->assertJsonPath('code', 'AUTHORIZATION_UNVERIFIABLE');
        $this->api()->postJson("/api/v1/pickups/{$guess}/cancel", ['reason' => 'x'])->assertOk();

        // Once weighed, the same lot is covered; a waste that needs no authorisation is requested unweighed.
        $this->api()->postJson("/api/v1/lots/{$unweighed['ulid']}/weigh", ['net_weight_kg' => '80'])->assertOk();
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$unweighed['ulid'], $weighed['ulid']]).'/submit')->assertOk()->assertJsonPath('data.status', 'requested');
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$other['ulid']]).'/submit')->assertOk();
    }

    public function test_an_authorisation_stated_in_lots_only_does_not_need_the_weight(): void
    {
        $type = $this->type('REBUTS', authorised: true);
        $this->api()->postJson('/api/v1/waste-authorizations', ['waste_type' => $type, 'reference' => 'D-2026-002', 'max_lots' => 3,
            'valid_from' => now()->subMonth()->toDateString(), 'valid_until' => now()->addMonths(5)->toDateString()])->assertCreated();
        $lot = $this->lot($type, null)->json('data');

        $this->api()->postJson('/api/v1/waste-authorizations/check', ['date' => now()->toDateString(), 'lots' => [$lot['ulid']]])->assertOk()->assertJsonPath('data.0.reason', null);
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup([$lot['ulid']]).'/submit')->assertOk();
    }

    public function test_a_lot_leaves_unweighed_is_priced_flat_and_its_slip_says_it_was_not_weighed(): void
    {
        $type = $this->type('CHUTES');
        [$a, $b] = [$this->lot($type, null)->json('data'), $this->lot($type, '50')->json('data')];
        $pickup = $this->pickup([$a['ulid'], $b['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $a['ulid']], ['lot' => $b['ulid']]]])->assertOk()
            // The total that left is the weight that is known, with the unweighed lot counted beside it.
            ->assertJsonPath('data.departure_weight_kg', '50.000')->assertJsonPath('data.unweighed_lots', 1)
            ->assertJsonPath('data.lots.0.departure_weight_kg', null)->assertJsonPath('data.lots.0.weight_basis', 'unknown')->assertJsonPath('data.lots.1.weight_basis', 'measured');

        // A price per kilogram needs a weight; a flat amount does not.
        $price = fn (string $mode, string $amount) => ['lots' => [['lot_number' => $a['lot_number'], 'direction' => 'cost', 'pricing_mode' => $mode, 'unit_price' => $amount]]];
        $this->api()->putJson("/api/v1/pickups/{$pickup}/pricing", $price('per_kg', '0.25'))->assertStatus(409)->assertJsonPath('code', 'PRICE_NEEDS_WEIGHT');
        $this->assertNull(DB::table('pickup_lots')->where('lot_number_snapshot', $a['lot_number'])->value('net_amount'));
        $this->api()->putJson("/api/v1/pickups/{$pickup}/pricing", $price('flat', '35'))->assertOk()->assertJsonPath('data.lots.0.net_amount', '-35.000')->assertJsonPath('data.lots.0.price_basis', null);

        // The slip prints no weight for that lot, and the weight of the slip is the one that is known.
        $slip = $this->tenant()->runAs($this->company, function () {
            $manifest = HazardousWasteManifest::query()->firstOrFail();

            return [app(ManifestWorkflow::class)->payload($manifest), strlen(app(ManifestWorkflow::class)->pdf($manifest))];
        });
        $this->assertSame('50.000', $slip[0]['declared_weight_kg']);
        $this->assertSame([null, '50.000'], array_column($slip[0]['lots'], 'weight_kg'));
        $this->assertGreaterThan(1000, $slip[1]);

        // At reception the receiver weighs it: there was nothing to compare with, which is said, not computed.
        $this->api()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $a['lot_number'], 'received_weight_kg' => '28'], ['lot_number' => $b['lot_number'], 'received_weight_kg' => '50']]])
            ->assertOk()->assertJsonPath('data.lots.0.tolerance_status', 'no_reference')->assertJsonPath('data.lots.0.tolerance.pct', null)
            ->assertJsonPath('data.lots.1.tolerance_status', 'within')->assertJsonPath('data.received_weight_kg', '78.000')
            ->assertJsonPath('data.weight_variance_pct', null)->assertJsonPath('data.unweighed_lots', 0);
        // With a received weight, the price per kilogram can be computed.
        $this->api()->putJson("/api/v1/pickups/{$pickup}/pricing", $price('per_kg', '0.25'))->assertOk()->assertJsonPath('data.lots.0.net_amount', '-7.000');
    }

    public function test_a_slip_of_unweighed_lots_only_carries_no_weight(): void
    {
        $lot = $this->lot($this->type('CHUTES'), null)->json('data');
        $pickup = $this->pickup([$lot['ulid']]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk();
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot['ulid']]]])->assertOk()->assertJsonPath('data.unweighed_lots', 1);

        $this->assertNull(DB::table('hazardous_waste_manifests')->value('declared_weight_kg'));
        $this->api()->getJson('/api/v1/manifests')->assertOk()->assertJsonPath('data.0.declared_weight_kg', null);
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
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $type, ?string $kg, array $extra = []): TestResponse
    {
        return $this->api()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone, 'waste_type' => $type, 'net_weight_kg' => $kg] + $extra)->assertCreated();
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
