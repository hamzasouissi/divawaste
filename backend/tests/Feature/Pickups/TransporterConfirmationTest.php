<?php

namespace Tests\Feature\Pickups;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The transporter and the receiver are two companies: a company that only transports carries the waste to one that
 * recycles it. Each does what its profile says, the transporter of the platform confirms the date the receiver set,
 * and each signs the slip for its own part only.
 */
class TransporterConfirmationTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Site $site;

    private Zone $zone;

    private User $manager;

    private string $token;

    private Company $recycler;

    private User $recyclerUser;

    private string $recyclerToken;

    private Company $carrier;

    private User $carrierUser;

    private string $carrierToken;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->industrial = $this->makeCompany();
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['environment_manager' => $this->site]);
        $this->token = $this->tokenFor($this->manager, $this->industrial);
        $this->zone = $this->tenant()->runAs($this->industrial, fn () => Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id,
            'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']));
        $this->type = $this->tenant()->runAs($this->industrial, fn () => WasteType::query()->create(['code' => 'FILS', 'name' => 'Déchets de fil',
            'waste_family_id' => DB::table('waste_families')->where('code', 'textile_fibre')->value('id'), 'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
            'default_treatment_channel_id' => DB::table('treatment_channels')->where('code', 'recycling')->value('id'), 'activated_at' => now()])->ulid);

        // A recycler that does not come and fetch the waste, and a company that only transports it.
        [$this->recycler, $this->recyclerUser] = $this->provider(['is_recycler' => true]);
        [$this->carrier, $this->carrierUser] = $this->provider(['is_transporter' => true]);
        $this->recyclerToken = $this->tokenFor($this->recyclerUser, $this->recycler);
        $this->carrierToken = $this->tokenFor($this->carrierUser, $this->carrier);
        $admin = $this->tokenFor($this->makeMember($this->industrial, ['client_admin' => null]), $this->industrial);
        foreach ([$this->recycler, $this->carrier] as $provider) {
            $this->as($admin)->postJson('/api/v1/provider-partnerships', ['provider' => $provider->ulid])->assertCreated();
        }
    }

    public function test_each_company_does_what_its_profile_says(): void
    {
        // A recycler that neither collects nor transports needs a transporter.
        $alone = $this->pickup(['provider' => $this->recycler->ulid]);
        $this->api()->postJson("/api/v1/pickups/{$alone}/submit")->assertConflict()->assertJsonPath('code', 'TRANSPORTER_REQUIRED');
        // A company that only transports is never the receiver.
        $wrongWay = $this->pickup(['provider' => $this->carrier->ulid, 'transporter' => $this->recycler->ulid]);
        $this->api()->postJson("/api/v1/pickups/{$wrongWay}/submit")->assertConflict()->assertJsonPath('code', 'PARTNER_ROLE_MISMATCH');
        // And the transporter chosen must transport: here, the recycler carrying for another recycler.
        [$other] = $this->provider(['is_recycler' => true]);
        $this->as($this->tokenFor($this->makeMember($this->industrial, ['client_admin' => null]), $this->industrial))
            ->postJson('/api/v1/provider-partnerships', ['provider' => $other->ulid])->assertCreated();
        $notCarrying = $this->pickup(['provider' => $other->ulid, 'transporter' => $this->recycler->ulid]);
        $this->api()->postJson("/api/v1/pickups/{$notCarrying}/submit")->assertConflict()->assertJsonPath('code', 'PARTNER_ROLE_MISMATCH');
        // With its transporter, the recycler's request goes.
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup(['provider' => $this->recycler->ulid, 'transporter' => $this->carrier->ulid]).'/submit')
            ->assertOk()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.awaiting', 'receiver')->assertJsonPath('data.transporter_confirms', true);
    }

    public function test_the_receiver_sets_the_date_then_the_transporter_confirms_and_each_signs_its_own_part(): void
    {
        $lot = $this->lot();
        $pickup = $this->pickup(['provider' => $this->recycler->ulid, 'transporter' => $this->carrier->ulid], [$lot]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $date = now()->addDays(2)->toDateString();

        // Both see the request, each in its own part; the transporter cannot answer before the receiver has set the date.
        $this->as($this->recyclerToken)->getJson("/api/v1/pickups/{$pickup}")->assertOk()->assertJsonPath('data.viewer_roles', ['receiver']);
        $this->as($this->carrierToken)->getJson("/api/v1/pickups/{$pickup}")->assertOk()->assertJsonPath('data.viewer_roles', ['transporter'])->assertJsonPath('data.awaiting', 'receiver');
        $this->as($this->carrierToken)->postJson("/api/v1/pickups/{$pickup}/transport/confirm")->assertConflict()->assertJsonPath('code', 'AWAITING_RECEIVER');
        // The receiver's stages are not the transporter's, nor the transporter's the receiver's.
        $this->as($this->carrierToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => $date])->assertForbidden();
        $this->as($this->carrierToken)->postJson("/api/v1/pickups/{$pickup}/refuse", ['reason' => 'x'])->assertForbidden();
        $this->as($this->recyclerToken)->postJson("/api/v1/pickups/{$pickup}/transport/confirm")->assertForbidden();

        Notification::fake();
        $this->as($this->recyclerToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => $date])->assertOk()
            ->assertJsonPath('data.status', 'requested')->assertJsonPath('data.awaiting', 'transporter')->assertJsonPath('data.confirmed_date', $date);
        $this->assertSame(['pickup.transport_requested'], $this->types($this->carrierUser));
        $this->assertSame(['pickup.awaiting_transporter'], $this->types($this->manager));
        // Answered once: the receiver does not confirm or refuse again, the truck is not loaded before the transporter says yes.
        $this->as($this->recyclerToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => $date])->assertConflict()->assertJsonPath('code', 'ALREADY_ANSWERED');
        $this->as($this->recyclerToken)->postJson("/api/v1/pickups/{$pickup}/refuse", ['reason' => 'x'])->assertConflict()->assertJsonPath('code', 'ALREADY_ANSWERED');
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot]]])->assertConflict();

        $this->as($this->carrierToken)->postJson("/api/v1/pickups/{$pickup}/transport/confirm")->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.awaiting', null);
        $this->assertNotNull(DB::table('pickups')->where('ulid', $pickup)->value('transporter_confirmed_by_user_id'));
        $this->assertContains('pickup.transport_confirmed', $this->types($this->manager));
        $this->assertSame(['pickup.transport_confirmed'], $this->types($this->recyclerUser));
        Notification::assertSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n, array $channels) => $n->type === 'pickup.transport_confirmed' && in_array('mail', $channels, true));

        // Loaded: the slip names both companies; each signs its own part, in turn.
        $this->api()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot]]])->assertOk()->assertJsonPath('data.status', 'collected');
        $slip = $this->api()->getJson('/api/v1/manifests?filter[pickup]='.$pickup)->assertOk()->json('data.0');
        $slip = $this->api()->getJson("/api/v1/manifests/{$slip['ulid']}")->assertOk()->json('data');
        $this->assertSame([$this->carrier->legal_name, $this->recycler->legal_name], [$slip['transporter']['legal_name'], $slip['receiver']['legal_name']]);
        $sign = fn (string $token) => $this->as($token)->postJson("/api/v1/manifests/{$slip['ulid']}/sign", ['password' => 'correct-horse-battery']);
        $sign($this->token)->assertOk()->assertJsonPath('data.status', 'pending_transporter_signature');
        $sign($this->recyclerToken)->assertConflict()->assertJsonPath('code', 'NOT_YOUR_TURN');
        $sign($this->carrierToken)->assertOk()->assertJsonPath('data.status', 'pending_receiver_signature');
        $sign($this->carrierToken)->assertConflict()->assertJsonPath('code', 'NOT_YOUR_TURN');
        $sign($this->recyclerToken)->assertOk()->assertJsonPath('data.status', 'completed');

        // Reception, treatment and the certificate are the receiver's: the transporter reads the pickup, does not do them.
        $number = DB::table('pickup_lots')->where('pickup_id', DB::table('pickups')->where('ulid', $pickup)->value('id'))->value('lot_number_snapshot');
        $this->as($this->carrierToken)->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $number, 'received_weight_kg' => '300']]])->assertForbidden();
        $this->as($this->carrierToken)->post("/api/v1/pickups/{$pickup}/certificates", ['file' => UploadedFile::fake()->create('certificat.pdf', 10, 'application/pdf'),
            'type' => 'recycling', 'lot_numbers' => [$number]], ['Accept' => 'application/json'])->assertForbidden();
        $this->as($this->recyclerToken)->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $number, 'received_weight_kg' => '300']]])->assertOk()
            ->assertJsonPath('data.status', 'received');
    }

    public function test_the_transporter_may_refuse_and_the_lots_go_back_to_stock(): void
    {
        $lot = $this->lot();
        $pickup = $this->pickup(['provider' => $this->recycler->ulid, 'transporter' => $this->carrier->ulid], [$lot]);
        $this->api()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->as($this->recyclerToken)->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();

        Notification::fake();
        $this->as($this->carrierToken)->postJson("/api/v1/pickups/{$pickup}/transport/refuse", ['reason' => 'Aucun camion ce jour-là'])->assertOk()
            ->assertJsonPath('data.status', 'refused')->assertJsonPath('data.refused_by', 'transporter')->assertJsonPath('data.refusal_reason', 'Aucun camion ce jour-là');
        $this->assertSame(['pickup.transport_refused'], $this->types($this->manager));
        $this->assertSame(['pickup.transport_refused'], $this->types($this->recyclerUser));
        $this->assertSame('stored', DB::table('waste_lots')->where('ulid', $lot)->value('status'));
        // The lot can be requested again, with another transporter.
        $this->api()->postJson('/api/v1/pickups/'.$this->pickup(['provider' => $this->recycler->ulid, 'transporter' => $this->carrier->ulid], [$lot]).'/submit')->assertOk();

        // A receiver's refusal says it is the receiver's, and the transporter is told.
        $next = $this->api()->getJson('/api/v1/pickups?filter[status][]=requested')->json('data.0.ulid');
        $this->as($this->recyclerToken)->postJson("/api/v1/pickups/{$next}/refuse", ['reason' => 'Plein'])->assertOk()->assertJsonPath('data.refused_by', 'receiver');
        $this->assertContains('pickup.refused', $this->types($this->carrierUser));
        // Nobody refuses the transport of a pickup whose receiver carries the waste itself.
        $this->as($this->carrierToken)->postJson("/api/v1/pickups/{$next}/transport/refuse", ['reason' => 'x'])->assertConflict();
    }

    /**
     * @param  array<string, bool>  $roles
     * @return array{Company, User}
     */
    private function provider(array $roles): array
    {
        $company = $this->makeCompany('provider');
        $user = $this->makeMember($company, ['provider_admin' => null]);
        $this->tenant()->runAs($company, function () use ($company, $roles) {
            ProviderProfile::query()->create($roles + ['is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $company->id, 'disk' => 's3',
                'path' => "tenants/{$company->id}/agrement.pdf", 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditation', 'created_at' => now(), 'updated_at' => now()]);
            $type = DB::table('accreditation_types')->where('code', 'TN_COLLECT')->value('id')
                ?? DB::table('accreditation_types')->insertGetId(['country_id' => DB::table('countries')->value('id'), 'code' => 'TN_COLLECT', 'name' => '{}']);
            ProviderAccreditation::query()->create(['accreditation_type_id' => $type, 'reference_number' => 'AG-'.$company->id, 'valid_from' => now()->subYear(),
                'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });

        return [$company, $user];
    }

    private function lot(): string
    {
        return $this->api()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone->ulid, 'waste_type' => $this->type, 'net_weight_kg' => '300'])
            ->assertCreated()->json('data.ulid');
    }

    /**
     * @param  array<string, string>  $parties
     * @param  list<string>|null  $lots
     */
    private function pickup(array $parties, ?array $lots = null): string
    {
        return $this->api()->postJson('/api/v1/pickups', $parties + ['site' => $this->site->ulid, 'requested_date' => now()->addDay()->toDateString(),
            'lots' => $lots ?? [$this->lot()]])->assertCreated()->json('data.ulid');
    }

    /**
     * @return list<string>
     */
    private function types(User $user): array
    {
        return Notification::sent($user, BusinessEventNotification::class)->map(fn (BusinessEventNotification $n) => $n->type)->values()->all();
    }

    private function api(): static
    {
        return $this->as($this->token);
    }

    private function as(string $token): static
    {
        $this->app->forgetScopedInstances();
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }
}
