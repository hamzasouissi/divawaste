<?php

namespace App\Modules\Sites\DTOs;

final readonly class CreateStockThresholdData
{
    public function __construct(
        public string $siteUlid,
        public string $maxQuantityKg,
        public int $warningPct,
        public ?string $zoneUlid = null,
        public ?string $wasteTypeUlid = null,
    ) {}
}
