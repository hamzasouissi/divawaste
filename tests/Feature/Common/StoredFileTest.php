<?php

namespace Tests\Feature\Common;

use App\Modules\Common\Models\StoredFile;
use App\Modules\Common\Support\FileStorage;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StoredFileTest extends TestCase
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

    public function test_it_stores_a_tenant_file_and_registers_metadata(): void
    {
        Storage::fake('local');

        $company = $this->makeCompany('industrial');
        $user = $this->makeMember($company);

        $pdfContent = "%PDF-1.4\n"
            ."1 0 obj\n"
            ."<< /Type /Catalog /Pages 2 0 R >>\n"
            ."endobj\n"
            ."2 0 obj\n"
            ."<< /Type /Pages /Count 0 >>\n"
            ."endobj\n"
            ."trailer\n"
            ."<< /Root 1 0 R >>\n"
            ."%%EOF\n";

        $file = UploadedFile::fake()->createWithContent(
            'certificate.pdf',
            $pdfContent
        );

        $stored = app(FileStorage::class)->store(
            $file,
            $company,
            'certificates',
            $user->id,
            $company->id,
        );

        $this->assertInstanceOf(
            StoredFile::class,
            $stored
        );

        $this->assertSame(
            $company->id,
            $stored->company_id
        );

        $this->assertSame(
            $user->id,
            $stored->uploaded_by_user_id
        );

        $this->assertSame(
            $company->id,
            $stored->uploaded_by_company_id
        );

        $this->assertSame(
            'certificates',
            $stored->purpose
        );

        $this->assertSame(
            'pending',
            $stored->scan_status
        );

        $this->assertSame(
            'application/pdf',
            $stored->mime_type
        );

        $this->assertSame(
            'pdf',
            $stored->extension
        );

        $this->assertSame(
            hash('sha256', $pdfContent),
            $stored->checksum_sha256
        );

        $this->assertStringStartsWith(
            "tenants/{$company->ulid}/certificates/",
            $stored->path
        );

        Storage::disk('local')->assertExists(
            $stored->path
        );
    }
}
