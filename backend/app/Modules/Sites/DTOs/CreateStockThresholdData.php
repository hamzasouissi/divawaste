<?php

namespace App\Modules\Sites\DTOs;

final readonly class CreateStockThresholdData
{
    public function __construct(
        public string $siteUlid,
        public string $maxQuantity,
        public int $warningPct,
        public ?string $zoneUlid = null,
        public ?string $wasteTypeUlid = null,
        public string $unit = 'kg',
        public ?string $packagingType = null,
    ) {}
}
