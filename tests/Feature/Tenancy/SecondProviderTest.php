<?php

namespace Tests\Feature\Tenancy;

use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M0-05: several providers can register one after the other, each with its own accreditation, and a refused
 * registration says which field is wrong.
 */
class SecondProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
    }

    /**
     * @return array<string, mixed>
     */
    private function provider(string $name, string $taxId, string $email): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        return [
            'company_type' => 'provider',
            'company' => ['legal_name' => $name, 'tax_id' => $taxId, 'country' => 'TN', 'city' => 'Sousse', 'phone' => '+21673000001'],
            'contact' => ['first_name' => 'Karim', 'last_name' => 'Ben Salah', 'email' => $email, 'phone' => '+21620000002', 'password' => 'a-strong-password-123'],
            // Exactly what the browser sends in a multipart form.
            'accept_terms' => '1', 'accept_privacy' => '1',
            'provider' => [
                'roles' => ['collector'], 'service_area' => '',
                'accreditations' => [[
                    'type' => 'recycling_recovery', 'reference_number' => 'ANGED-2026-118',
                    'valid_from' => now()->subYear()->toDateString(), 'expires_on' => now()->addYear()->toDateString(),
                    'file' => new UploadedFile($path, 'agrement.pdf', 'application/pdf', null, true),
                ]],
            ],
        ];
    }

    public function test_a_second_provider_registers_like_the_first(): void
    {
        $headers = ['Accept' => 'application/json'];

        $this->post('/api/v1/auth/register', $this->provider('Recyclage du Sahel', '7654321B', 'karim@sahel.example'), $headers)->assertCreated();
        // Same accreditation number, same phone, same file name: nothing but the tax ID and the email must be unique.
        $this->post('/api/v1/auth/register', $this->provider('Collecte du Nord', '7654322C', 'karim@nord.example'), $headers)->assertCreated();

        // Reusing the tax ID or the email of the first one is refused, and the answer names the field.
        $this->post('/api/v1/auth/register', $this->provider('Autre', '7654321B', 'autre@nord.example'), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['company.tax_id']);
        $this->post('/api/v1/auth/register', $this->provider('Autre', '7654323D', 'karim@sahel.example'), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['contact.email']);
    }
}
