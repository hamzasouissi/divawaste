<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Support\EmailVerification;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Notifications\CompanyStatusNotification;
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
 * M0-01, M0-02, M0-03, M0-05, M0-06, M0-07, M0-10 end to end.
 */
class RegistrationAndReviewTest extends TestCase
{
    use InteractsWithTenants;

    private const PASSWORD = 'a-strong-password-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        Storage::fake('local');
    }

    public function test_registration_options_expose_what_the_form_needs(): void
    {
        $data = $this->getJson('/api/v1/registration/options')->assertOk()->json('data');

        $this->assertSame(['TN'], array_column($data['countries'], 'iso2'));
        $this->assertContains('garment_making', array_column($data['activities'], 'code'));
        $this->assertEqualsCanonicalizing(['trial', 'per_site', 'per_volume'], array_column($data['plans'], 'code'));
        $this->assertEqualsCanonicalizing(['terms', 'privacy', 'provider_terms'], array_column($data['legal_documents'], 'type'));
    }

    public function test_industrial_registers_confirms_email_is_approved_and_starts_a_trial(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->industrialPayload())->assertCreated()
            ->assertJsonPath('data.status', 'pending_verification')
            ->assertJsonPath('data.activities', ['weaving', 'garment_making']);

        $company = Company::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
        $user = User::query()->where('email', 'amira@textile.example')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        // M0-10: both documents, with their version, time and IP.
        $acceptances = DB::table('legal_acceptances as a')->join('legal_documents as d', 'd.id', '=', 'a.legal_document_id')
            ->where('a.user_id', $user->id)->get(['d.document_type', 'd.version', 'a.context', 'a.ip_address', 'a.accepted_at']);
        $this->assertEqualsCanonicalizing(['terms', 'privacy'], $acceptances->pluck('document_type')->all());
        $this->assertSame([ProvisionalReferenceSeeder::LEGAL_VERSION], $acceptances->pluck('version')->unique()->values()->all());
        $this->assertSame(['registration'], $acceptances->pluck('context')->unique()->values()->all());

        // Before approval the account exists but has no tenant access.
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->assertNull($login->json('data.company'));
        $this->assertSame('pending_verification', $login->json('data.user.memberships.0.status'));
        $this->withToken($login->json('data.access_token'))->getJson('/api/v1/sites')->assertForbidden();

        $this->postJson('/api/v1/auth/email/verify', EmailVerification::parameters($user))->assertOk();
        $this->assertSame('pending_approval', $company->fresh()->status->value);

        $admin = $this->platformAdmin();
        app('auth')->forgetGuards();
        $this->withToken($admin)->getJson('/api/v1/platform/companies?filter[status]=pending_approval')->assertOk()
            ->assertJsonPath('data.0.ulid', $company->ulid);
        $this->withToken($admin)->getJson("/api/v1/platform/companies/{$company->ulid}")->assertOk()
            ->assertJsonPath('data.requested_plan', 'per_site')->assertJsonPath('data.contact.email_verified', true);
        $this->withToken($admin)->postJson("/api/v1/platform/companies/{$company->ulid}/approve")->assertOk()
            ->assertJsonPath('data.status', 'active');

        Notification::assertSentTo($user, CompanyStatusNotification::class, fn (CompanyStatusNotification $n) => $n->event === 'company_approved');
        $this->assertDatabaseHas('company_subscriptions', ['company_id' => $company->id, 'status' => 'trialing', 'is_current' => true]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'company_approved', 'auditable_type' => 'companies']);

        // The registrant is the client administrator and now reaches the tenant API.
        app('auth')->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.company.ulid', $company->ulid);
        $access = $this->withToken($login->json('data.access_token'))->getJson('/api/v1/me/access')->assertOk();
        $this->assertContains('users.manage', $access->json('data.company_permissions'));
        $this->assertSame('client_admin', $access->json('data.roles.0.name'));

        // M0-07: nothing configured yet, a step can be skipped then resumed.
        $onboarding = $this->withToken($login->json('data.access_token'))->getJson('/api/v1/onboarding')->assertOk();
        $this->assertFalse($onboarding->json('data.completed'));
        $this->assertSame(['sites', 'waste_types', 'users', 'providers'], array_column($onboarding->json('data.steps'), 'key'));
        $this->withToken($login->json('data.access_token'))->putJson('/api/v1/onboarding/steps/providers', ['skipped' => true])
            ->assertOk()->assertJsonPath('data.steps.3.skipped', true);
        $this->withToken($login->json('data.access_token'))->putJson('/api/v1/onboarding/steps/providers', ['skipped' => false])
            ->assertOk()->assertJsonPath('data.steps.3.skipped', false);
    }

    public function test_registration_is_validated(): void
    {
        $this->postJson('/api/v1/auth/register', $this->industrialPayload())->assertCreated();

        $payload = $this->industrialPayload();
        $payload['contact']['password'] = 'short';
        $payload['accept_terms'] = false;
        $payload['company']['activities'] = [];

        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors([
            'company.tax_id', 'contact.email', 'contact.password', 'accept_terms', 'company.activities',
        ]);
        $this->assertSame(1, Company::query()->count());
    }

    public function test_provider_registers_with_accreditation_pdf_and_can_be_rejected_with_a_reason(): void
    {
        $payload = [
            'company_type' => 'provider',
            'company' => ['legal_name' => 'Recyclage du Sahel', 'tax_id' => '7654321B', 'country' => 'TN'],
            'contact' => ['first_name' => 'Karim', 'last_name' => 'Ben Salah', 'email' => 'karim@recyclage.example', 'phone' => '+21620000002', 'password' => self::PASSWORD],
            'accept_terms' => '1', 'accept_privacy' => '1',
            'provider' => [
                'roles' => ['collector', 'recycler'],
                'service_area' => 'Sousse, Monastir',
                'accreditations' => [[
                    'type' => 'recycling_recovery', 'reference_number' => 'ANGED-2026-118',
                    'valid_from' => now()->subYear()->toDateString(), 'expires_on' => now()->addYear()->toDateString(),
                    'file' => $this->realUpload("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"),
                ]],
            ],
        ];

        $company = Company::query()->where('ulid', $this->post('/api/v1/auth/register', $payload, ['Accept' => 'application/json'])
            ->assertCreated()->json('data.ulid'))->firstOrFail();

        $this->assertDatabaseHas('provider_profiles', ['company_id' => $company->id, 'is_collector' => true, 'is_recycler' => true, 'is_published' => false]);
        $this->assertDatabaseHas('provider_accreditations', ['company_id' => $company->id, 'reference_number' => 'ANGED-2026-118', 'review_status' => 'pending']);
        $file = DB::table('stored_files')->where('company_id', $company->id)->first();
        Storage::disk('local')->assertExists($file->path);

        // A text file renamed .pdf is refused.
        $payload['company']['tax_id'] = '0000001C';
        $payload['contact']['email'] = 'other@recyclage.example';
        $payload['provider']['accreditations'][0]['file'] = $this->realUpload('plain text, not a PDF');
        $this->post('/api/v1/auth/register', $payload, ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonValidationErrors('provider.accreditations.0.file');

        $user = User::query()->where('email', 'karim@recyclage.example')->firstOrFail();
        $this->postJson('/api/v1/auth/email/verify', EmailVerification::parameters($user))->assertOk();

        $admin = $this->platformAdmin();
        $this->withToken($admin)->postJson("/api/v1/platform/companies/{$company->ulid}/reject", [])->assertUnprocessable();
        $this->withToken($admin)->postJson("/api/v1/platform/companies/{$company->ulid}/reject", ['reason' => 'Agrément illisible'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        Notification::assertSentTo($user, CompanyStatusNotification::class, fn (CompanyStatusNotification $n) => $n->event === 'company_rejected');

        // A decided registration cannot be decided again.
        $this->withToken($admin)->postJson("/api/v1/platform/companies/{$company->ulid}/approve")->assertConflict();
    }

    public function test_platform_endpoints_are_closed_to_client_users_and_suspension_blocks_the_tenant(): void
    {
        $company = $this->makeCompany();
        $clientAdmin = $this->makeMember($company, ['client_admin' => null]);
        $clientToken = $this->tokenFor($clientAdmin, $company);

        $this->withToken($clientToken)->getJson('/api/v1/platform/companies')->assertForbidden();
        $this->withToken($clientToken)->postJson("/api/v1/platform/companies/{$company->ulid}/suspend", ['reason' => 'x'])->assertForbidden();
        $this->withToken($clientToken)->getJson('/api/v1/company')->assertOk();

        $admin = $this->platformAdmin();
        $this->withToken($admin)->postJson("/api/v1/platform/companies/{$company->ulid}/suspend", ['reason' => 'Impayé'])->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        app('auth')->forgetGuards();
        $this->withToken($clientToken)->getJson('/api/v1/company')->assertForbidden();

        app('auth')->forgetGuards();
        $this->withToken($admin)->postJson("/api/v1/platform/companies/{$company->ulid}/reactivate")->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($clientToken)->getJson('/api/v1/company')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function industrialPayload(): array
    {
        return [
            'company_type' => 'industrial',
            'company' => [
                'legal_name' => 'Textile du Cap Bon', 'tax_id' => '1234567A', 'country' => 'TN',
                'activities' => ['weaving', 'garment_making'], 'city' => 'Nabeul',
            ],
            'contact' => ['first_name' => 'Amira', 'last_name' => 'Trabelsi', 'email' => 'Amira@Textile.example', 'phone' => '+21620000001', 'password' => self::PASSWORD],
            'plan' => 'per_site',
            'accept_terms' => true,
            'accept_privacy' => true,
        ];
    }

    /**
     * A real file on disk: fake uploads report their type from the name, which would hide content sniffing.
     */
    private function realUpload(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dw');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'agrement.pdf', null, null, true);
    }

    private function platformAdmin(): string
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        app('auth')->forgetGuards();

        return $admin->createToken('test')->plainTextToken;
    }
}
