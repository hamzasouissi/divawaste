<?php

namespace App\Modules\Waste\DTOs;

final readonly class CreateWasteLotData
{
    /**
     * @param  list<array{material: string, pct: string}>|null  $composition
     * @param  array<string, mixed>|null  $extraAttributes
     */
    public function __construct(
        public string $siteUlid,
        public string $zoneUlid,
        public string $wasteTypeUlid,
        public string $netWeightKg,
        public ?string $ulid = null,
        public ?string $tagValue = null,
        public ?string $grossWeightKg = null,
        public ?string $tareWeightKg = null,
        public ?string $packagingType = null,
        public ?string $quantity = null,
        public ?string $unit = null,
        public ?string $colorFamily = null,
        public ?string $colorLabel = null,
        public ?string $grammageGsm = null,
        public ?array $composition = null,
        public ?array $extraAttributes = null,
        public ?string $notes = null,
        public ?string $generatedAt = null,
        public ?int $deviceId = null,
        public string $source = 'web',
    ) {}
}
