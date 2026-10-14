<?php

namespace Tests\Feature\Documents;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Documents\Actions\ManifestWorkflow;
use App\Modules\Documents\Models\HazardousWasteManifest;
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
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Scenario D — M5-02 (tracking slip signed by each party), M5-03 (annual declaration), M5-04 (archive).
 */
class ComplianceApiTest extends TestCase
{
    use InteractsWithTenants;

    private const PASSWORD = 'a-strong-password-123';

    private Company $company;

    private Company $provider;

    private Site $site;

    private User $manager;

    private User $providerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        Notification::fake();

        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'CMP');
        $this->manager = $this->makeMember($this->company, ['client_admin' => null]);
        $this->manager->forceFill(['password' => self::PASSWORD])->save();
        $this->provider = $this->makeCompany('provider');
        $this->providerUser = $this->makeMember($this->provider, ['provider_admin' => null]);
        $this->providerUser->forceFill(['password' => self::PASSWORD])->save();

        $this->tenant()->runAs($this->provider, function () {
            ProviderProfile::query()->create(['is_eliminator' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $this->provider->id, 'disk' => 'local',
                'path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 1,
                'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditations', 'created_at' => now(), 'updated_at' => now()]);
            ProviderAccreditation::query()->create(['accreditation_type_id' => DB::table('accreditation_types')->where('code', 'hazardous_treatment')->value('id'),
                'reference_number' => 'AG-H', 'valid_from' => now()->subYear(), 'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });
        $this->industrial()->postJson('/api/v1/provider-partnerships', ['provider' => $this->provider->ulid])->assertCreated();
    }

    public function test_tracking_slip_is_prefilled_from_the_pickup_signed_in_order_and_archived(): void
    {
        [$pickup, $hazardous] = $this->collectedPickup();

        // Pre-filled, nothing typed by the user: one slip per waste code — every waste leaves with one, hazardous or
        // not. They were issued when the lots were loaded.
        $slips = collect($this->industrial()->postJson("/api/v1/pickups/{$pickup}/manifests")->assertCreated()->assertJsonCount(2, 'data')->json('data'));
        $this->assertSame([true, false], $slips->pluck('is_hazardous')->all());
        $manifest = $slips->firstWhere('is_hazardous', true);
        $this->assertSame('04 02 16', $manifest['regulatory_code']);
        $this->assertSame('30.000', $manifest['declared_weight_kg']);
        $this->assertSame($this->company->legal_name, $manifest['producer']['legal_name']);
        $this->assertSame($this->provider->legal_name, $manifest['receiver']['legal_name']);
        $this->assertSame('pending_producer_signature', $manifest['status']);
        $this->assertSame(['producer', 'transporter', 'receiver'], array_column($manifest['signatures'], 'party'));
        Notification::assertSentTo($this->manager, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'document.signature_requested');

        // Asking again returns the same slip instead of creating a duplicate.
        $this->industrial()->postJson("/api/v1/pickups/{$pickup}/manifests")->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.0.ulid', $manifest['ulid']);
        $ulid = $manifest['ulid'];

        // Order is enforced, and a signature needs the signer's password.
        $this->asProvider()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD])->assertConflict()->assertJsonPath('code', 'NOT_YOUR_TURN');
        $this->industrial()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => 'wrong'])->assertUnprocessable();
        $this->industrial()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.status', 'pending_transporter_signature')->assertJsonPath('data.signatures.0.signed_by', $this->manager->fullName());
        Notification::assertSentTo($this->providerUser, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'document.signature_requested');
        $this->industrial()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD])->assertConflict();

        // The provider transports and receives: it signs each role.
        $this->asProvider()->getJson('/api/v1/manifests')->assertOk()->assertJsonCount(2, 'data');
        $this->asProvider()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD])->assertOk()->assertJsonPath('data.status', 'pending_receiver_signature');
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $hazardous['lot_number'], 'received_weight_kg' => '29.5']]])->assertOk();
        $this->asProvider()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.received_weight_kg', '29.500')->assertJsonPath('data.awaiting_company', null);
        $this->asProvider()->postJson("/api/v1/manifests/{$ulid}/sign", ['password' => self::PASSWORD])->assertConflict()->assertJsonPath('code', 'MANIFEST_NOT_SIGNABLE');

        // Each signature keeps who, when, where from and the hash of what was signed.
        $signatures = DB::table('document_signatures')->where('document_id', DB::table('hazardous_waste_manifests')->where('ulid', $ulid)->value('document_id'))->orderBy('sequence')->get();
        $this->assertSame([$this->manager->id, $this->providerUser->id, $this->providerUser->id], $signatures->pluck('signer_user_id')->all());
        $this->assertCount(3, $signatures->pluck('signed_payload_hash')->filter(fn ($hash) => strlen((string) $hash) === 64));
        $this->assertNotNull($signatures[0]->ip_address);

        // M5-04: the signed slip is archived as an issued PDF kept ten years, readable by both parties.
        $document = collect($this->industrial()->getJson('/api/v1/documents?filter[type]=hazardous_manifest')->assertOk()->json('data'))->firstWhere('reference_number', $manifest['manifest_number']);
        $this->assertSame('issued', $document['status']);
        $this->assertSame(now()->addYears(10)->toDateString(), $document['retention_until']);
        $this->assertStringStartsWith('%PDF-', $this->industrial()->get("/api/v1/documents/{$document['ulid']}/download")->assertOk()->streamedContent());
        $this->assertStringStartsWith('%PDF-', $this->asProvider()->get("/api/v1/manifests/{$ulid}/pdf")->assertOk()->getContent());
        $this->asProvider()->get("/api/v1/documents/{$document['ulid']}/download")->assertOk();

        // Nobody else: another provider, another industrial, a colleague without rights on the site.
        $otherProvider = $this->makeCompany('provider');
        $intruder = $this->tokenFor($this->makeMember($otherProvider, ['provider_admin' => null]), $otherProvider);
        $this->withToken($intruder)->getJson("/api/v1/manifests/{$ulid}")->assertNotFound();
        $this->withToken($intruder)->get("/api/v1/documents/{$document['ulid']}/download")->assertNotFound();
        $this->withToken($intruder)->getJson('/api/v1/documents')->assertOk()->assertJsonCount(0, 'data');
        $stranger = $this->makeCompany();
        $this->withToken($this->tokenFor($this->makeMember($stranger, ['client_admin' => null]), $stranger))->get("/api/v1/documents/{$document['ulid']}/download")->assertNotFound();
        $elsewhere = $this->makeMember($this->company, ['environment_manager' => $this->makeSite($this->company, 'TUN')]);
        $this->withToken($this->tokenFor($elsewhere, $this->company))->get("/api/v1/documents/{$document['ulid']}/download")->assertForbidden();

        // A regulatory document is never physically deleted.
        $this->assertSame(1, DB::table('documents')->where('ulid', $document['ulid'])->count());
    }

    public function test_non_hazardous_waste_leaves_with_a_slip_too(): void
    {
        [$pickup, $lot] = $this->collectedPickup(hazardous: false);

        $slip = $this->industrial()->getJson("/api/v1/manifests?filter[pickup]={$pickup}")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertFalse($slip['is_hazardous']);
        $this->assertSame('04 02 22', $slip['regulatory_code']);
        $this->assertSame('80.000', $slip['declared_weight_kg']);
        $this->assertSame($lot['lot_number'], DB::table('pickup_lots')->whereNotNull('hazardous_waste_manifest_id')->value('lot_number_snapshot'));
    }

    public function test_a_slip_names_every_waste_type_that_shares_its_regulatory_code(): void
    {
        $offcuts = $this->lot('80', hazardous: false);
        $yarn = $this->tenant()->runAs($this->company, fn () => WasteType::query()->create([
            'code' => 'FILS', 'name' => 'Déchets de fil', 'is_hazardous' => false, 'activated_at' => now(),
            'regulatory_waste_code_id' => DB::table('regulatory_waste_codes')->where('code', '04 02 22')->value('id'),
            'waste_family_id' => DB::table('waste_families')->where('code', 'textile_fibre')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
        ]));
        $zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->firstOrFail());
        $second = $this->industrial()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $zone->ulid, 'waste_type' => $yarn->ulid, 'net_weight_kg' => '20'])->assertCreated()->json('data');
        $lots = [$offcuts['ulid'], $second['ulid']];
        $pickup = $this->industrial()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->provider->ulid, 'requested_date' => now()->toDateString(), 'lots' => $lots])->assertCreated()->json('data.ulid');
        $this->industrial()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk();
        $this->industrial()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => array_map(fn ($ulid) => ['lot' => $ulid], $lots)])->assertOk();

        // One slip for the code, 100 kg; it does not present yarn waste as offcuts.
        $slip = $this->industrial()->getJson("/api/v1/manifests?filter[pickup]={$pickup}")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('100.000', $slip['declared_weight_kg']);
        $this->assertSame('Chutes de coupe, Déchets de fil', $slip['waste_type']);
        $manifest = HazardousWasteManifest::query()->withoutGlobalScopes()->where('ulid', $slip['ulid'])->firstOrFail();
        $this->assertSame('Chutes de coupe, Déchets de fil', $this->tenant()->runAs($this->company, fn () => app(ManifestWorkflow::class)->payload($manifest)['waste_type']));
    }

    public function test_annual_declaration_is_computed_from_the_year_then_validated_and_archived(): void
    {
        $this->collectedPickup();
        $year = now()->year;

        $declaration = $this->industrial()->postJson("/api/v1/sites/{$this->site->ulid}/declarations", ['reporting_year' => $year])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.format_code', 'TN_ANGED')->json('data');

        // 30 kg hazardous + 80 kg non-hazardous produced; both left with the provider; nothing remains on site.
        $this->assertSame('110.000', $declaration['figures']['totals']['produced_kg']);
        $this->assertSame('30.000', $declaration['figures']['totals']['hazardous_produced_kg']);
        $this->assertSame('110.000', $declaration['figures']['totals']['evacuated_kg']);
        $this->assertSame('0.000', $declaration['figures']['totals']['stock_at_year_end_kg']);
        $this->assertSame($this->provider->legal_name, $declaration['figures']['evacuated'][0]['provider']);

        // New activity before validation: the same draft is recomputed, not duplicated.
        $this->lot('20', hazardous: false);
        $this->industrial()->postJson("/api/v1/sites/{$this->site->ulid}/declarations", ['reporting_year' => $year])->assertOk()
            ->assertJsonPath('data.ulid', $declaration['ulid'])->assertJsonPath('data.figures.totals.produced_kg', '130.000')
            ->assertJsonPath('data.figures.totals.stock_at_year_end_kg', '20.000');

        $this->assertStringStartsWith('%PDF-', $this->industrial()->get("/api/v1/declarations/{$declaration['ulid']}/export?format=pdf")->assertOk()->getContent());
        $this->industrial()->get("/api/v1/declarations/{$declaration['ulid']}/export?format=xlsx")->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $validated = $this->industrial()->postJson("/api/v1/declarations/{$declaration['ulid']}/validate")->assertOk()->assertJsonPath('data.status', 'validated')->json('data');
        $this->assertSame("TN_ANGED-CMP-{$year}", $validated['submission_reference']);
        $this->industrial()->getJson("/api/v1/documents/{$validated['document']}")->assertOk()->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.document_type', 'annual_declaration');

        // Frozen once validated.
        $this->industrial()->postJson("/api/v1/sites/{$this->site->ulid}/declarations", ['reporting_year' => $year])->assertConflict()->assertJsonPath('code', 'DECLARATION_LOCKED');
        $this->industrial()->postJson("/api/v1/declarations/{$declaration['ulid']}/validate")->assertConflict();

        // The operator prepares nothing; another company reaches nothing.
        $operator = $this->tokenFor($this->makeMember($this->company, ['workshop_operator' => $this->site]), $this->company);
        $this->withToken($operator)->getJson('/api/v1/declarations')->assertForbidden();
        $stranger = $this->makeCompany();
        $strangerToken = $this->tokenFor($this->makeMember($stranger, ['client_admin' => null]), $stranger);
        $this->withToken($strangerToken)->getJson("/api/v1/declarations/{$declaration['ulid']}")->assertNotFound();
        $this->withToken($strangerToken)->postJson("/api/v1/sites/{$this->site->ulid}/declarations", ['reporting_year' => $year])->assertNotFound();
    }

    /**
     * A confirmed and loaded pickup with one hazardous and one non-hazardous lot.
     *
     * @return array{string, array<string, mixed>}
     */
    private function collectedPickup(bool $hazardous = true): array
    {
        $special = $hazardous ? $this->lot('30', hazardous: true) : null;
        $ordinary = $this->lot('80', hazardous: false);
        $lots = array_values(array_filter([$special['ulid'] ?? null, $ordinary['ulid']]));

        $pickup = $this->industrial()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->provider->ulid,
            'requested_date' => now()->toDateString(), 'lots' => $lots])->assertCreated()->json('data.ulid');
        $this->industrial()->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->toDateString()])->assertOk();
        $this->industrial()->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => array_map(fn ($ulid) => ['lot' => $ulid], $lots)])->assertOk();

        return [$pickup, $special ?? $ordinary];
    }

    /**
     * @return array<string, mixed>
     */
    private function lot(string $weight, bool $hazardous): array
    {
        $type = $this->tenant()->runAs($this->company, fn () => WasteType::query()->firstOrCreate(['code' => $hazardous ? 'TEINT' : 'CHUTES'], [
            'name' => $hazardous ? 'Résidus de teinture' : 'Chutes de coupe', 'is_hazardous' => $hazardous, 'activated_at' => now(),
            'regulatory_waste_code_id' => DB::table('regulatory_waste_codes')->where('code', $hazardous ? '04 02 16' : '04 02 22')->value('id'),
            'waste_family_id' => DB::table('waste_families')->where('code', $hazardous ? 'chemical_sludge' : 'textile_product')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
        ]));
        $zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->firstOrFail());

        return $this->industrial()->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $zone->ulid, 'waste_type' => $type->ulid, 'net_weight_kg' => $weight])
            ->assertCreated()->json('data');
    }

    private function industrial(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->company));
    }

    private function asProvider(): static
    {
        return $this->withToken($this->tokenFor($this->providerUser, $this->provider));
    }
}
