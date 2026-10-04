<?php

namespace App\Modules\Common\Support;

use App\Modules\Common\Models\StoredFile;
use App\Modules\Tenancy\Models\Company;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FileStorage
{
    public function store(
        UploadedFile $file,
        Company $company,
        string $purpose,
        ?int $uploadedByUserId = null,
        ?int $uploadedByCompanyId = null,
        ?DateTimeInterface $retentionUntil = null,
    ): StoredFile {
        $disk = (string) config('filesystems.default', 'local');

        $ulid = Str::upper((string) Str::ulid());

        $mimeType = $this->detectMimeType($file);

        $extension = strtolower(
            (string) ($file->guessExtension() ?: 'bin')
        );

        $purpose = trim($purpose, '/');

        if ($purpose === '') {
            throw new RuntimeException('File purpose cannot be empty.');
        }

        $purpose = Str::slug($purpose, '_');

        $companyUlid = (string) $company->ulid;

        $directory = sprintf(
            'tenants/%s/%s/%s/%s',
            $companyUlid,
            $purpose,
            now()->format('Y'),
            now()->format('m'),
        );

        $filename = "{$ulid}.{$extension}";

        $path = "{$directory}/{$filename}";

        $checksum = $this->sha256($file);

        $size = $file->getSize();

        if ($size === false) {
            throw new RuntimeException(
                'Unable to determine uploaded file size.'
            );
        }

        $stored = false;

        try {
            $stored = Storage::disk($disk)->putFileAs(
                $directory,
                $file,
                $filename
            );

            if ($stored === false) {
                throw new RuntimeException(
                    'Unable to store uploaded file.'
                );
            }

            return StoredFile::query()->create([
                'ulid' => $ulid,
                'company_id' => $company->getKey(),
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'purpose' => $purpose,
                'uploaded_by_user_id' => $uploadedByUserId,
                'uploaded_by_company_id' => $uploadedByCompanyId,
                'scan_status' => 'pending',
                'scanned_at' => null,
                'retention_until' => $retentionUntil,
                'legal_hold' => false,
            ]);
        } catch (\Throwable $exception) {
            if ($stored !== false) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
    }

    public function temporaryUrl(
        StoredFile $file,
        int $minutes = 5,
    ): string {
        return Storage::disk($file->disk)->temporaryUrl(
            $file->path,
            now()->addMinutes($minutes)
        );
    }

    private function detectMimeType(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException(
                'Unable to access uploaded file.'
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        if (! is_string($mimeType) || $mimeType === '') {
            throw new RuntimeException(
                'Unable to determine uploaded file MIME type.'
            );
        }

        return $mimeType;
    }

    private function sha256(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException(
                'Unable to access uploaded file.'
            );
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to read uploaded file.'
            );
        }

        try {
            $context = hash_init('sha256');

            while (! feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException(
                        'Unable to read uploaded file.'
                    );
                }

                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }
            }

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }
}
