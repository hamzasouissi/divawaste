<?php

namespace App\Modules\Documents\DTOs;

final readonly class CreateAnnualDeclarationData
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public string $formatCode,
        public int $reportingYear,
        public ?array $data = null,
        public ?string $status = 'draft',
    ) {}
}
