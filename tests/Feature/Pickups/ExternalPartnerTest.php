<?php

namespace Tests\Feature\Pickups;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * A transporter or a receiver without an account on the platform: the company records it, fills in each stage of the
 * pickup on its behalf, and the tracking slip is signed by hand and closed with the scan of the signed copy.
 */
class ExternalPartnerTest extends TestCase
{
    use InteractsWithTenants;

    private const PASSWORD = 'a-strong-password-123';

    private Company $company;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        Notification::fake();

        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'EXT');
        $this->manager = $this->makeMember($this->company, ['client_admin' => null]);
        $this->manager->forceFill(['password' => self::PASSWORD])->save();
    }

    public function test_a_company_keeps_its_own_partners_and_nobody_else_sees_them(): void
    {
        $partner = $this->partner(['legal_name' => 'Recyclo SARL', 'roles' => ['recycler'], 'tax_id' => '1234567A', 'city' => 'Sousse']);
        $this->assertSame(['recycler'], $partner['roles']);

        $this->client()->postJson('/api/v1/external-partners', ['legal_name' => 'Sans rôle', 'roles' => []])->assertUnprocessable();
        $this->client()->putJson("/api/v1/external-partners/{$partner['ulid']}", ['legal_name' => 'Recyclo SARL', 'roles' => ['recycler', 'transporter'], 'is_active' => false])
            ->assertOk()->assertJsonPath('data.roles', ['transporter', 'recycler'])->assertJsonPath('data.is_active', false);
        $this->client()->getJson('/api/v1/external-partners')->assertOk()->assertJsonCount(0, 'data');
        $this->client()->getJson('/api/v1/external-partners?with_inactive=1')->assertOk()->assertJsonCount(1, 'data');

        // Another company neither lists it, nor changes it, nor uses it in a pickup.
        $other = $this->makeCompany();
        $stranger = $this->tokenFor($this->makeMember($other, ['client_admin' => null]), $other);
        $this->withToken($stranger)->getJson('/api/v1/external-partners?with_inactive=1')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($stranger)->putJson("/api/v1/external-partners/{$partner['ulid']}", ['legal_name' => 'X', 'roles' => ['recycler']])->assertNotFound();
        $lot = $this->tenant()->runAs($other, fn () => $this->makeSite($other, 'OTH'));
        $this->withToken($stranger)->postJson('/api/v1/pickups', ['site' => $lot->ulid, 'external_provider' => $partner['ulid'],
            'requested_date' => now()->toDateString(), 'lots' => ['01HZZZZZZZZZZZZZZZZZZZZZZZ']])->assertUnprocessable()->assertJsonStructure(['errors' => ['external_provider']]);

        // A read-only colleague cannot add one.
        $reader = $this->makeMember($this->company, ['management_viewer' => null]);
        $this->withToken($this->tokenFor($reader, $this->company))->postJson('/api/v1/external-partners', ['legal_name' => 'Y', 'roles' => ['recycler']])->assertForbidden();
    }

    public function test_a_pickup_names_exactly_one_receiver_with_the_right_roles(): void
    {
        $recycler = $this->partner(['legal_name' => 'Recyclo', 'roles' => ['recycler']]);
        $carrier = $this->partner(['legal_name' => 'Trans Express', 'roles' => ['transporter']]);
        $lot = $this->lot('50', hazardous: false);
        $base = ['site' => $this->site->ulid, 'requested_date' => now()->toDateString(), 'lots' => [$lot['ulid']]];

        $this->client()->postJson('/api/v1/pickups', $base)->assertUnprocessable()->assertJsonStructure(['errors' => ['provider']]);

        // A recycler that does not transport needs a transporter; a transporter is not a receiver.
        $alone = $this->client()->postJson('/api/v1/pickups', $base + ['external_provider' => $recycler['ulid']])->assertCreated()->json('data.ulid');
        $this->client()->postJson("/api/v1/pickups/{$alone}/submit")->assertConflict()->assertJsonPath('code', 'TRANSPORTER_REQUIRED');
        $this->client()->postJson("/api/v1/pickups/{$alone}/cancel", ['reason' => 'erreur'])->assertOk();
        $wrong = $this->client()->postJson('/api/v1/pickups', $base + ['external_provider' => $carrier['ulid']])->assertCreated()->json('data.ulid');
        $this->client()->postJson("/api/v1/pickups/{$wrong}/submit")->assertConflict()->assertJsonPath('code', 'PARTNER_ROLE_MISMATCH');
    }

    public function test_the_company_records_every_stage_and_the_slip_is_signed_on_paper(): void
    {
        $recycler = $this->partner(['legal_name' => 'Recyclo', 'roles' => ['recycler'], 'tax_id' => 'R-1']);
        $carrier = $this->partner(['legal_name' => 'Trans Express', 'roles' => ['transporter']]);
        $lot = $this->lot('50', hazardous: false);

        $pickup = $this->client()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $recycler['ulid'], 'external_transporter' => $carrier['ulid'],
            'requested_date' => now()->toDateString(), 'lots' => [$lot['ulid']]])->assertCreated()
            ->assertJsonPath('data.provider.legal_name', 'Recyclo')->assertJsonPath('data.provider.external', true)
            ->assertJsonPath('data.transporter.legal_name', 'Trans Express')->json('data.ulid');

        // The company fills in what the receiver would have done on the platform.
        $this->client()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->client()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->client()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => [['lot' => $lot['ulid']]]])->assertOk();

        // The slip was issued at loading, with the three parties.
        $slip = $this->client()->getJson("/api/v1/manifests?filter[pickup]={$pickup}")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['Recyclo', 'R-1'], [$slip['receiver']['legal_name'], $slip['receiver']['tax_id']]);
        $this->assertSame('Trans Express', $slip['transporter']['legal_name']);
        $this->assertSame([false, true, true], array_column($slip['signatures'], 'external'));
        $ulid = $slip['ulid'];

        // The producer signs electronically; the two partners have no account, so only a handwritten signature can be recorded.
        $this->client()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.status', 'pending_transporter_signature')->assertJsonPath('data.awaiting_external', true)
            ->assertJsonPath('data.awaiting_company', $this->company->ulid);
        $this->client()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD])->assertConflict()->assertJsonPath('code', 'PAPER_SIGNATURE_REQUIRED');
        $this->client()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD, 'method' => 'paper'])->assertUnprocessable();
        $paper = fn (string $name) => ['password' => self::PASSWORD, 'method' => 'paper', 'signer_name' => $name, 'signed_on' => now()->toDateString()];
        $this->client()->postJson("/api/v1/manifests/{$ulid}/sign", $paper('Ali Chauffeur'))->assertOk()->assertJsonPath('data.status', 'pending_receiver_signature')
            ->assertJsonPath('data.signatures.1.method', 'paper')->assertJsonPath('data.signatures.1.signed_by', 'Ali Chauffeur')
            ->assertJsonPath('data.signatures.1.recorded_by', $this->manager->fullName());

        $this->client()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $lot['lot_number'], 'received_weight_kg' => '49.5']]])->assertOk();
        $this->client()->postJson("/api/v1/pickups/{$pickup}/treat", ['lots' => [['lot_number' => $lot['lot_number'], 'channel' => 'recycling']]])->assertOk();

        // Every party has signed, two of them by hand: the slip waits for the scan of the signed copy.
        $this->client()->postJson("/api/v1/manifests/{$ulid}/sign", $paper('Mouna Réception'))->assertOk()->assertJsonPath('data.status', 'pending_signed_copy');
        $this->assertStringStartsWith('%PDF-', $this->client()->get("/api/v1/manifests/{$ulid}/pdf")->assertOk()->getContent());
        $this->client()->postJson("/api/v1/manifests/{$ulid}/signed-copy", ['file' => UploadedFile::fake()->create('note.txt', 4, 'text/plain')])->assertUnprocessable();
        $this->client()->postJson("/api/v1/manifests/{$ulid}/signed-copy", ['file' => UploadedFile::fake()->create('bsd-signe.pdf', 40, 'application/pdf')])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.received_weight_kg', '49.500');
        $this->client()->postJson("/api/v1/manifests/{$ulid}/signed-copy", ['file' => UploadedFile::fake()->create('bsd-signe.pdf', 40, 'application/pdf')])
            ->assertConflict()->assertJsonPath('code', 'MANIFEST_NOT_AWAITING_COPY');

        // The archived document is the scan; the pickup is closed by the company and appears in the register.
        $document = collect($this->client()->getJson('/api/v1/documents?filter[type]=hazardous_manifest')->assertOk()->json('data'))->firstWhere('reference_number', $slip['manifest_number']);
        $this->assertSame('issued', $document['status']);
        $this->client()->postJson("/api/v1/pickups/{$pickup}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->client()->getJson("/api/v1/lots/{$lot['ulid']}")->assertOk()->assertJsonPath('meta.pickup.provider', 'Recyclo');
    }

    public function test_hazardous_waste_needs_a_recorded_authorisation_and_a_receiver_with_an_account_keeps_its_own_stages(): void
    {
        $eliminator = $this->partner(['legal_name' => 'Elim', 'roles' => ['eliminator', 'transporter']]);
        $lot = $this->lot('20', hazardous: true);
        $pickup = $this->client()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $eliminator['ulid'],
            'requested_date' => now()->toDateString(), 'lots' => [$lot['ulid']]])->assertCreated()->json('data.ulid');

        $this->client()->postJson("/api/v1/pickups/{$pickup}/submit")->assertConflict()->assertJsonPath('code', 'PROVIDER_NOT_ELIGIBLE');
        $this->client()->putJson("/api/v1/external-partners/{$eliminator['ulid']}", ['legal_name' => 'Elim', 'roles' => ['eliminator', 'transporter'],
            'accreditation_reference' => 'AUT-77', 'accreditation_expires_on' => now()->addMonth()->toDateString(), 'covers_hazardous' => true])->assertOk();
        $this->client()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();

        // With a provider of the platform as receiver, the company cannot answer in its place.
        $provider = $this->makeCompany('provider');
        DB::table('pickups')->insert(['ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'company_id' => $this->company->id, 'pickup_number' => 'ENL-X', 'site_id' => $this->site->id,
            'provider_company_id' => $provider->id, 'status' => 'requested', 'requested_date' => now()->toDateString(), 'currency_code' => 'TND',
            'created_at' => now(), 'updated_at' => now()]);
        $this->client()->postJson('/api/v1/pickups/01HZZZZZZZZZZZZZZZZZZZZZZZ/confirm', ['confirmed_date' => now()->toDateString()])->assertForbidden();
    }

    public function test_a_partner_that_opens_an_account_is_tied_to_it(): void
    {
        $partner = $this->partner(['legal_name' => 'Recyclo SARL', 'roles' => ['recycler', 'transporter'], 'tax_id' => '1234 567a']);
        $this->assertNull($partner['suggested_company']);
        $lot = $this->lot('50', hazardous: false);
        $draft = $this->client()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $partner['ulid'],
            'requested_date' => now()->toDateString(), 'lots' => [$lot['ulid']]])->assertCreated()->json('data.ulid');

        // The partner registers on the platform with the same tax ID and publishes its profile: it is suggested.
        $provider = $this->makeCompany('provider');
        $provider->forceFill(['tax_id' => '1234567A'])->save();
        $unpublished = $this->makeCompany('provider');
        $this->client()->postJson("/api/v1/external-partners/{$partner['ulid']}/link", ['provider' => $provider->ulid])->assertUnprocessable();
        $this->tenant()->runAs($provider, fn () => ProviderProfile::query()->create(['is_collector' => true, 'is_recycler' => true, 'is_published' => true, 'published_at' => now()]));
        $this->client()->getJson('/api/v1/external-partners')->assertOk()->assertJsonPath('data.0.suggested_company.ulid', $provider->ulid);
        $this->client()->postJson("/api/v1/external-partners/{$partner['ulid']}/link", ['provider' => $unpublished->ulid])->assertUnprocessable();

        // Tying them makes the provider a partner of the platform; the record without an account is no longer usable.
        $this->client()->postJson("/api/v1/external-partners/{$partner['ulid']}/link", ['provider' => $provider->ulid])->assertOk()
            ->assertJsonPath('data.linked_company.legal_name', $provider->legal_name)->assertJsonPath('data.suggested_company', null);
        $this->assertSame('active', DB::table('provider_partnerships')->where('company_id', $this->company->id)->where('provider_company_id', $provider->id)->value('status'));
        $this->client()->postJson("/api/v1/external-partners/{$partner['ulid']}/link", ['provider' => $provider->ulid])->assertConflict()->assertJsonPath('code', 'PARTNER_ALREADY_LINKED');
        $this->client()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'external_provider' => $partner['ulid'],
            'requested_date' => now()->toDateString(), 'lots' => [$lot['ulid']]])->assertUnprocessable()->assertJsonStructure(['errors' => ['external_provider']]);
        $this->client()->postJson("/api/v1/pickups/{$draft}/submit")->assertConflict()->assertJsonPath('code', 'PARTNER_HAS_ACCOUNT');

        // History is untouched: the draft still names the partner as it was recorded.
        $this->client()->getJson("/api/v1/pickups/{$draft}")->assertOk()->assertJsonPath('data.provider.legal_name', 'Recyclo SARL')->assertJsonPath('data.provider.external', true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function partner(array $attributes): array
    {
        return $this->client()->postJson('/api/v1/external-partners', $attributes)->assertCreated()->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function lot(string $weight, bool $hazardous): array
    {
        $type = $this->tenant()->runAs($this->company, fn () => WasteType::query()->firstOrCreate(['code' => $hazardous ? 'TEINT' : 'CHUTES'], [
            'name' => $hazardous ? 'Résidus de teinture' : 'Chutes de coupe', 'is_hazardous' => $hazardous, 'activated_at' => now(),
            // Non-hazardous waste may have no regulatory code: its slip is then issued per waste type.
            'regulatory_waste_code_id' => $hazardous ? DB::table('regulatory_waste_codes')->where('code', '04 02 16')->value('id') : null,
            'waste_family_id' => DB::table('waste_families')->where('code', $hazardous ? 'chemical_sludge' : 'textile_product')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
        ]));
        $zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->firstOrFail());

        return $this->client()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $zone->ulid, 'waste_type' => $type->ulid, 'net_weight_kg' => $weight])
            ->assertCreated()->json('data');
    }

    private function client(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->company));
    }
}
