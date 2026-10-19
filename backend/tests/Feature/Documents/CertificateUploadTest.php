<?php

namespace Tests\Feature\Documents;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Models\StoredFile;
use App\Modules\Identity\Models\User;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Enums\LotStatus;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CertificateUploadTest extends TestCase
{
    use InteractsWithTenants;

    private Company $industrial;

    private Company $provider;

    private Site $site;

    private User $manager;

    private User $providerUser;

    private string $pickup;

    private string $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            ReferenceDataSeeder::class,
            AuthorizationSeeder::class,
        ]);

        Storage::fake('local');

        $this->industrial = $this->makeCompany();
        $this->provider = $this->makeCompany('provider');

        $this->site = $this->makeSite(
            $this->industrial,
            'CERT'
        );

        $this->manager = $this->makeMember(
            $this->industrial,
            ['environment_manager' => $this->site]
        );

        $this->providerUser = $this->makeMember(
            $this->provider,
            ['provider_admin' => null]
        );

        $this->tenant()->runAs(
            $this->provider,
            function (): void {
                ProviderProfile::query()->create([
                    'is_collector' => true,
                    'is_recycler' => true,
                    'is_published' => true,
                    'published_at' => now(),
                ]);

                $file = DB::table('stored_files')->insertGetId([
                    'ulid' => strtoupper((string) Str::ulid()),
                    'company_id' => $this->provider->id,
                    'disk' => 'local',
                    'path' => 'agreements/'.$this->provider->ulid.'/agreement.pdf',
                    'original_name' => 'agreement.pdf',
                    'mime_type' => 'application/pdf',
                    'extension' => 'pdf',
                    'size_bytes' => 1,
                    'checksum_sha256' => str_repeat('a', 64),
                    'purpose' => 'accreditation',
                    'scan_status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $countryId = DB::table('countries')->value('id');

                $type = DB::table('accreditation_types')
                    ->where('country_id', $countryId)
                    ->where('code', 'TN_CERT')
                    ->value('id');

                if ($type === null) {
                    $type = DB::table('accreditation_types')->insertGetId([
                        'country_id' => $countryId,
                        'code' => 'TN_CERT',
                        'name' => json_encode([
                            'fr' => 'Certificat',
                            'en' => 'Certificate',
                            'ar' => 'شهادة',
                        ], JSON_UNESCAPED_UNICODE),
                        'covers_hazardous' => true,
                        'is_active' => true,
                    ]);
                }

                ProviderAccreditation::query()->create([
                    'accreditation_type_id' => $type,
                    'reference_number' => 'CERT-1',
                    'valid_from' => now()->subYear(),
                    'expires_on' => now()->addYear(),
                    'stored_file_id' => $file,
                    'review_status' => 'approved',
                ]);
            }
        );

        $this->withToken(
            $this->tokenFor(
                $this->makeMember(
                    $this->industrial,
                    ['client_admin' => null]
                ),
                $this->industrial
            )
        )->postJson(
            '/api/v1/provider-partnerships',
            ['provider' => $this->provider->ulid]
        )->assertCreated();

        $this->createPickup();
    }

    public function test_provider_can_upload_certificate_for_its_pickup(): void
    {
        $this->withToken(
            $this->tokenFor(
                $this->providerUser,
                $this->provider
            )
        );

        $response = $this->post(
            "/api/v1/pickups/{$this->pickup}/certificates",
            [
                'file' => UploadedFile::fake()->createWithContent(
                    'certificate.pdf',
                    "%PDF-1.4\n%%EOF"
                ),
                'type' => 'recycling',
                'lots' => [$this->lot],
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.document_type',
                'recycling_certificate'
            )
            ->assertJsonPath(
                'data.status',
                'issued'
            );

        $documentId = $response->json('data.ulid');

        $document = DB::table('documents')
            ->where('ulid', $documentId)
            ->first();

        $this->assertNotNull($document);

        $this->assertSame(
            $this->industrial->id,
            $document->company_id
        );

        $this->assertSame(
            $this->provider->id,
            $document->shared_with_company_id
        );

        $this->assertSame(
            $this->provider->id,
            $document->uploaded_by_company_id
        );

        $this->assertNotNull($document->stored_file_id);

        $file = StoredFile::query()
            ->findOrFail($document->stored_file_id);

        Storage::disk('local')->assertExists($file->path);

        $this->assertStringStartsWith(
            "tenants/{$this->industrial->ulid}/certificates/",
            $file->path
        );
    }

    public function test_provider_cannot_upload_a_lot_from_another_pickup(): void
    {
        $otherLot = $this->createSecondLot();

        $response = $this->withToken(
            $this->tokenFor(
                $this->providerUser,
                $this->provider
            )
        )->post(
            "/api/v1/pickups/{$this->pickup}/certificates",
            [
                'file' => UploadedFile::fake()->createWithContent(
                    'certificate.pdf',
                    "%PDF-1.4\n%%EOF"
                ),
                'type' => 'recycling',
                'lots' => [$otherLot],
            ]
        );

        $response->assertStatus(409);
    }

    public function test_certificate_cannot_be_uploaded_before_collection(): void
    {
        $pickup = $this->createDraftPickup();

        $response = $this->withToken(
            $this->tokenFor(
                $this->providerUser,
                $this->provider
            )
        )->post(
            "/api/v1/pickups/{$pickup}/certificates",
            [
                'file' => UploadedFile::fake()->createWithContent(
                    'certificate.pdf',
                    "%PDF-1.4\n%%EOF"
                ),
                'type' => 'recycling',
                'lots' => [$this->lot],
            ]
        );

        $response->assertStatus(409);
    }

    private function createPickup(): void
    {
        $this->lot = $this->createLot('100');

        $response = $this->withToken(
            $this->tokenFor(
                $this->manager,
                $this->industrial
            )
        )->postJson(
            '/api/v1/pickups',
            [
                'site' => $this->site->ulid,
                'provider' => $this->provider->ulid,
                'requested_date' => now()->addDay()->toDateString(),
                'lots' => [$this->lot],
            ]
        )->assertCreated();

        $this->pickup = $response->json('data.ulid');

        $this->withToken(
            $this->tokenFor(
                $this->manager,
                $this->industrial
            )
        )->postJson(
            "/api/v1/pickups/{$this->pickup}/submit"
        )->assertOk();

        $this->withToken(
            $this->tokenFor(
                $this->providerUser,
                $this->provider
            )
        )->postJson(
            "/api/v1/pickups/{$this->pickup}/confirm",
            [
                'confirmed_date' => now()->addDay()->toDateString(),
            ]
        )->assertOk();

        $this->withToken(
            $this->tokenFor(
                $this->manager,
                $this->industrial
            )
        )->postJson(
            "/api/v1/pickups/{$this->pickup}/collect",
            [
                'lots' => [
                    [
                        'lot' => $this->lot,
                    ],
                ],
            ]
        )->assertOk();
    }

    private function createDraftPickup(): string
    {
        $lot = $this->createLot('50');

        return $this->withToken(
            $this->tokenFor(
                $this->manager,
                $this->industrial
            )
        )->postJson(
            '/api/v1/pickups',
            [
                'site' => $this->site->ulid,
                'provider' => $this->provider->ulid,
                'requested_date' => now()->addDay()->toDateString(),
                'lots' => [$lot],
            ]
        )->assertCreated()->json('data.ulid');
    }

    private function createSecondLot(): string
    {
        return $this->createLot('60');
    }

    private function createLot(string $kg): string
    {
        $type = $this->tenant()->runAs(
            $this->industrial,
            function (): WasteType {
                return WasteType::query()->firstOrCreate(
                    ['code' => 'CHUTES'],
                    [
                        'name' => 'Chutes',
                        'waste_family_id' => DB::table('waste_families')->value('id'),
                        'default_unit_id' => DB::table('units')->value('id'),
                        'activated_at' => now(),
                    ]
                );
            }
        );

        $zone = $this->tenant()->runAs(
            $this->industrial,
            function (): string {
                return (string) Zone::query()
                    ->where('site_id', $this->site->id)
                    ->value('ulid');
            }
        );

        return $this->withToken(
            $this->tokenFor(
                $this->manager,
                $this->industrial
            )
        )->postJson(
            '/api/v1/lots',
            [
                'site' => $this->site->ulid,
                'zone' => strtoupper($zone),
                'waste_type' => $type->ulid,
                'net_weight_kg' => $kg,
                'status' => LotStatus::Created->value,
            ]
        )->assertCreated()->json('data.ulid');
    }
}
