<?php

namespace App\Modules\Documents\DTOs;

use Illuminate\Http\UploadedFile;

final readonly class UploadCertificateData
{
    /**
     * @param  array<int, string>  $lotNumbers
     */
    public function __construct(
        public UploadedFile $file,
        public array $lotNumbers,
        public string $type,
        public ?string $title,
    ) {}
}
