<?php

namespace Tests\Feature\Documents;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Documents\Actions\IssueDocument;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\DocumentType;
use App\Modules\Documents\Enums\SignatureStatus;
use App\Modules\Documents\Enums\SignerParty;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentSignature;
use App\Modules\Tenancy\TenantContext;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DocumentModelTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            ReferenceDataSeeder::class,
            AuthorizationSeeder::class,
        ]);
    }

    public function test_document_defaults_to_draft(): void
    {
        $company = $this->makeCompany('industrial');

        $document = app(TenantContext::class)->runAs(
            $company,
            fn () => Document::query()->create([
                'document_type' => DocumentType::RecyclingCertificate,
                'title' => 'Test certificate',
                'is_regulatory' => true,
            ])
        );

        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertTrue($document->is_regulatory);
        $this->assertSame($company->id, $document->company_id);
    }

    public function test_issued_document_cannot_modify_frozen_fields(): void
    {
        $company = $this->makeCompany('industrial');

        app(TenantContext::class)->runAs(
            $company,
            function () {
                $document = Document::query()->create([
                    'document_type' => DocumentType::RecyclingCertificate,
                    'title' => 'Original title',
                    'is_regulatory' => true,
                ]);

                $document->status = DocumentStatus::Issued;
                $document->issued_at = now();
                $document->save();

                $document->title = 'Changed title';

                $this->expectException(BusinessRuleViolation::class);
                $document->save();
            }
        );
    }

    public function test_signed_signature_cannot_be_modified(): void
    {
        $company = $this->makeCompany('industrial');

        app(TenantContext::class)->runAs(
            $company,
            function () use ($company) {
                $document = Document::query()->create([
                    'document_type' => DocumentType::HazardousManifest,
                    'title' => 'Manifest',
                ]);

                $signature = DocumentSignature::query()->create([
                    'document_id' => $document->id,
                    'signer_party' => SignerParty::Producer,
                    'sequence' => 1,
                    'signer_company_id' => $company->id,
                    'status' => SignatureStatus::Signed,
                    'signed_at' => now(),
                ]);

                $signature->signed_payload_hash = str_repeat('a', 64);

                $this->expectException(BusinessRuleViolation::class);
                $signature->save();
            }
        );
    }

    public function test_issue_document_sets_issued_state_and_retention(): void
    {
        Storage::fake('local');

        $company = $this->makeCompany('industrial');

        app(TenantContext::class)->runAs(
            $company,
            function () use ($company): void {
                $file = UploadedFile::fake()->createWithContent(
                    'certificate.pdf',
                    "%PDF-1.4\n%%EOF"
                );

                $stored = app(FileStorage::class)->store(
                    $file,
                    $company,
                    'certificates',
                );

                $document = Document::query()->create([
                    'document_type' => DocumentType::RecyclingCertificate,
                    'title' => 'Recycling certificate',
                    'stored_file_id' => $stored->id,
                ]);

                $issued = app(IssueDocument::class)->handle($document);

                $this->assertSame(
                    DocumentStatus::Issued,
                    $issued->status
                );

                $this->assertNotNull($issued->issued_at);
                $this->assertNotNull($issued->retention_until);
                $this->assertSame(
                    $stored->id,
                    $issued->stored_file_id
                );

                $this->assertTrue(
                    $issued->retention_until->isSameDay(
                        now()->addYears(10)
                    )
                );
            }
        );
    }
}
