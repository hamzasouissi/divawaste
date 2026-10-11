<?php

namespace Tests\Feature\Documents;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Some waste only leaves the site under an authorisation (declaration signed by customs): a quota over a period.
 * Pickups draw on it from the moment they are requested, and are refused when nothing covers them.
 */
class WasteAuthorizationTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private Site $site;

    private User $manager;

    private WasteType $controlled;

    private string $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        Storage::fake('local');
        Notification::fake();

        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'AUT');
        $this->manager = $this->makeMember($this->company, ['client_admin' => null]);
        $this->controlled = $this->wasteType('CHUTES', requiresAuthorization: true);
        // A receiver without an account keeps the scenario short: the company records every stage itself.
        $this->partner = $this->client()->postJson('/api/v1/external-partners', ['legal_name' => 'Recyclo', 'roles' => ['recycler', 'transporter']])->assertCreated()->json('data.ulid');
    }

    public function test_an_authorisation_states_a_quantity_for_a_waste_type_that_requires_one(): void
    {
        $base = ['waste_type' => $this->controlled->ulid, 'reference' => 'D-2026-001', 'valid_from' => now()->subMonth()->toDateString(), 'valid_until' => now()->addMonths(5)->toDateString()];

        // A quantity is required, in at least one of the three forms; the waste type must be one that needs an authorisation.
        $this->client()->postJson('/api/v1/waste-authorizations', $base)->assertUnprocessable()->assertJsonStructure(['errors' => ['max_kg']]);
        $free = $this->wasteType('CARTON', requiresAuthorization: false);
        $this->client()->postJson('/api/v1/waste-authorizations', ['waste_type' => $free->ulid, 'max_kg' => '100'] + $base)->assertUnprocessable()->assertJsonStructure(['errors' => ['waste_type']]);

        $created = $this->client()->postJson('/api/v1/waste-authorizations', $base + ['max_kg' => '1000', 'shared_packaging_allowance' => true, 'limits' => [['packaging_type' => 'bale', 'max_count' => 4], ['packaging_type' => 'bag', 'max_count' => 10]]])
            ->assertCreated()->assertJsonPath('data.state', 'valid')->assertJsonPath('data.usage.outcome', 'respected')->assertJsonPath('data.usage.constraints.0.pct', 0)->assertJsonPath('data.usage.constraints.1.key', 'packages')->assertJsonPath('data.in_use', false)
            ->assertJsonPath('data.limits.0', ['packaging_type' => 'bale', 'max_count' => 4])->json('data');

        // The scan of the document is kept with it.
        $this->client()->postJson("/api/v1/waste-authorizations/{$created['ulid']}/file", ['file' => UploadedFile::fake()->create('autorisation.pdf', 30, 'application/pdf')])
            ->assertOk()->assertJsonPath('data.has_file', true);
        $this->client()->get("/api/v1/waste-authorizations/{$created['ulid']}/file")->assertOk();

        // Another company sees nothing of it; a read-only colleague cannot record one.
        $other = $this->makeCompany();
        $stranger = $this->tokenFor($this->makeMember($other, ['client_admin' => null]), $other);
        $this->withToken($stranger)->getJson('/api/v1/waste-authorizations')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($stranger)->get("/api/v1/waste-authorizations/{$created['ulid']}/file")->assertNotFound();
        $reader = $this->makeMember($this->company, ['management_viewer' => null]);
        $this->withToken($this->tokenFor($reader, $this->company))->postJson('/api/v1/waste-authorizations', $base + ['reference' => 'X', 'max_kg' => '5'])->assertForbidden();
    }

    public function test_pickups_draw_on_the_authorisation_and_are_refused_when_nothing_covers_them(): void
    {
        // Without any authorisation, the waste cannot be requested for pickup; waste that needs none is not concerned.
        $first = $this->pickup([$this->lot($this->controlled, '100', 'bale')]);
        $this->client()->postJson("/api/v1/pickups/{$first}/submit")->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_MISSING');
        $carton = $this->wasteType('CARTON', requiresAuthorization: false);
        $this->client()->postJson('/api/v1/pickups/'.$this->pickup([$this->lot($carton, '30', 'bale')]).'/submit')->assertOk();

        // The document states one quantity its packagings share — 4 bales or 10 bags — and at most 1000 kg.
        $authorization = $this->client()->postJson('/api/v1/waste-authorizations', ['waste_type' => $this->controlled->ulid, 'reference' => 'D-2026-001',
            'valid_from' => now()->toDateString(), 'valid_until' => now()->addMonths(3)->toDateString(), 'max_kg' => '1000', 'alert_pct' => 60,
            'shared_packaging_allowance' => true, 'limits' => [['packaging_type' => 'bale', 'max_count' => 4], ['packaging_type' => 'bag', 'max_count' => 10]]])->assertCreated()->json('data.ulid');
        $usage = fn () => collect($this->client()->getJson('/api/v1/waste-authorizations')->assertOk()->json('data'))->firstWhere('ulid', $authorization)['usage'];
        // Each limit of the document is answered on its own: the room in packages, and the weight.
        $limit = fn (string $key) => collect($usage()['constraints'])->firstWhere('key', $key);

        // The request holds its share at once: 1 bale of 4 = 25 %.
        $this->client()->postJson("/api/v1/pickups/{$first}/submit")->assertOk();
        $this->assertEquals([25, 'respected'], [$limit('packages')['pct'], $limit('packages')['outcome']]);
        $this->assertSame(['bale' => 3, 'bag' => 7], array_column($limit('packages')['lines'], 'remaining', 'packaging_type'));
        $this->assertEquals(['100.000', '1000.000', 10, 'respected'], [$limit('weight')['used'], $limit('weight')['max'], $limit('weight')['pct'], $limit('weight')['outcome']]);
        Notification::assertNothingSentTo($this->manager);

        // Before asking, the form shows what a selection would leave: 2 more bales and 3 bags = 25 + 50 + 30 = 105 %.
        $more = [$this->lot($this->controlled, '100', 'bale'), $this->lot($this->controlled, '100', 'bale'), $this->lot($this->controlled, '20', 'bag'), $this->lot($this->controlled, '20', 'bag'), $this->lot($this->controlled, '20', 'bag')];
        $this->client()->postJson('/api/v1/waste-authorizations/check', ['date' => now()->toDateString(), 'lots' => $more])->assertOk()
            ->assertJsonPath('data.0.reference', 'D-2026-001')->assertJsonPath('data.0.reason', 'exceeded')
            // The weight is fine (360 of 1000 kg) and says so; the packages are not, and say so. Neither figure replaces the other.
            ->assertJsonPath('data.0.before.0.key', 'weight')->assertJsonPath('data.0.before.1.pct', 25)
            ->assertJsonPath('data.0.constraints.0.outcome', 'respected')->assertJsonPath('data.0.constraints.0.used', '360.000')
            ->assertJsonPath('data.0.constraints.1.key', 'packages')->assertJsonPath('data.0.constraints.1.pct', 105)->assertJsonPath('data.0.constraints.1.outcome', 'exceeded');
        $tooMuch = $this->pickup($more);
        $this->client()->postJson("/api/v1/pickups/{$tooMuch}/submit")->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_EXCEEDED');
        $this->assertSame(0, DB::table('waste_lots')->where('status', 'awaiting_pickup')->whereIn('ulid', $more)->count());
        $this->client()->postJson("/api/v1/pickups/{$tooMuch}/cancel", ['reason' => 'trop'])->assertOk();

        // Within the room left it passes, and crossing the alert level tells the people who request pickups, once.
        $second = $this->pickup(array_slice($more, 0, 2));
        $this->client()->postJson("/api/v1/pickups/{$second}/submit")->assertOk();
        $this->assertEquals([75, 'warning'], [$limit('packages')['pct'], $limit('packages')['outcome']]);
        $this->assertEquals([30, 'respected'], [$limit('weight')['pct'], $limit('weight')['outcome']]);
        $this->assertSame(['warning', false], [$usage()['outcome'], $usage()['blocked']]);
        Notification::assertSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'authorization.usage_high');

        // A packaging the authorisation says nothing about is still covered by its weight; a cancelled pickup gives its share back.
        $drum = $this->pickup([$this->lot($this->controlled, '50', 'drum')]);
        $this->client()->postJson("/api/v1/pickups/{$drum}/submit")->assertOk();
        $this->client()->postJson("/api/v1/pickups/{$second}/cancel", ['reason' => 'reporté'])->assertOk();
        $this->assertEquals(25, $limit('packages')['pct']);
        $this->assertSame(['150.000', 1, 'respected'], [$limit('weight')['used'], $limit('unlisted')['outside_count'], $limit('unlisted')['outcome']]);

        // Once pickups draw on it, the quantities are history; the reference and a withdrawal are not.
        $payload = ['waste_type' => $this->controlled->ulid, 'reference' => 'D-2026-001-BIS', 'valid_from' => now()->toDateString(), 'valid_until' => now()->addMonths(3)->toDateString(),
            'max_kg' => '1000', 'shared_packaging_allowance' => true, 'limits' => [['packaging_type' => 'bale', 'max_count' => 4], ['packaging_type' => 'bag', 'max_count' => 10]]];
        $this->client()->putJson("/api/v1/waste-authorizations/{$authorization}", ['max_kg' => '5000'] + $payload)->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_IN_USE');
        $this->client()->putJson("/api/v1/waste-authorizations/{$authorization}", $payload + ['is_active' => false])->assertOk()
            ->assertJsonPath('data.reference', 'D-2026-001-BIS')->assertJsonPath('data.state', 'withdrawn')->assertJsonPath('data.in_use', true);
        $this->client()->postJson('/api/v1/pickups/'.$this->pickup([$this->lot($this->controlled, '10', 'bale')]).'/submit')->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_MISSING');
    }

    public function test_the_period_counts_on_the_date_of_the_pickup_and_the_end_is_announced(): void
    {
        $this->client()->postJson('/api/v1/waste-authorizations', ['waste_type' => $this->controlled->ulid, 'reference' => 'D-COURT', 'max_lots' => 5,
            'valid_from' => now()->toDateString(), 'valid_until' => now()->addDays(5)->toDateString()])->assertCreated();

        // Requested for a day after the end: refused. Requested inside, then confirmed for a day outside: refused too.
        $late = $this->client()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $this->partner,
            'requested_date' => now()->addDays(9)->toDateString(), 'lots' => [$this->lot($this->controlled, '10', 'bale')]])->assertCreated()->json('data.ulid');
        $this->client()->postJson("/api/v1/pickups/{$late}/submit")->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_MISSING');
        $inside = $this->pickup([$this->lot($this->controlled, '10', 'bale')]);
        $this->client()->postJson("/api/v1/pickups/{$inside}/submit")->assertOk();
        $this->client()->postJson("/api/v1/pickups/{$inside}/confirm", ['confirmed_date' => now()->addDays(9)->toDateString()])->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_MISSING');
        $this->client()->postJson("/api/v1/pickups/{$inside}/confirm", ['confirmed_date' => now()->addDays(2)->toDateString()])->assertOk();

        // The daily task announces the end once per threshold, and stays silent when a later authorisation takes over.
        $this->artisan('authorizations:alert')->assertSuccessful();
        Notification::assertSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'authorization.expiring_soon');
        $this->assertNotNull(DB::table('waste_authorizations')->where('reference', 'D-COURT')->value('notified_7d_at'));
        $this->assertNotNull(DB::table('waste_authorizations')->where('reference', 'D-COURT')->value('notified_30d_at'));
    }

    private function wasteType(string $code, bool $requiresAuthorization): WasteType
    {
        return $this->tenant()->runAs($this->company, function () use ($code, $requiresAuthorization) {
            $type = WasteType::query()->create([
                'code' => $code, 'name' => ucfirst(strtolower($code)), 'waste_family_id' => DB::table('waste_families')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now(),
            ]);
            $type->forceFill(['requires_authorization' => $requiresAuthorization])->save();

            return $type;
        });
    }

    private function lot(WasteType $type, string $kg, string $packaging): string
    {
        $zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->firstOrFail());

        return $this->client()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $zone->ulid, 'waste_type' => $type->ulid, 'net_weight_kg' => $kg, 'packaging_type' => $packaging])
            ->assertCreated()->json('data.ulid');
    }

    /**
     * @param  list<string>  $lots
     */
    private function pickup(array $lots): string
    {
        return $this->client()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $this->partner, 'requested_date' => now()->toDateString(), 'lots' => $lots])
            ->assertCreated()->json('data.ulid');
    }

    private function client(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->company));
    }
}
