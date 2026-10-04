<?php

namespace App\Modules\Documents\DTOs;

final readonly class CreateHazardousWasteManifestData
{
    /**
     * @param  array<string, mixed>  $producerSnapshot
     * @param  array<string, mixed>  $transporterSnapshot
     * @param  array<string, mixed>  $receiverSnapshot
     */
    public function __construct(
        public int $regulatoryWasteCodeId,
        public string|int|null $wasteType = null,
        public ?int $packageCount = null,
        public ?string $declaredWeightKg = null,
        public array $producerSnapshot = [],
        public array $transporterSnapshot = [],
        public array $receiverSnapshot = [],
        public ?int $transporterCompanyId = null,
        public ?int $receiverCompanyId = null,
        public ?string $status = 'draft',
    ) {}
}
