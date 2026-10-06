<?php

namespace App\Modules\Catalog\DTOs;

/**
 * Reference data is addressed by code; null = take the catalog default (or none for custom types).
 */
final readonly class CreateWasteTypeData
{
    /**
     * @param  list<array{material: string, pct: string}>|null  $composition
     * @param  array<string, mixed>|null  $extraAttributes
     */
    public function __construct(
        public string $code,
        public ?string $catalogItem = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $wasteFamily = null,
        public ?string $regulatoryCode = null,
        public ?bool $isHazardous = null,
        public ?string $unit = null,
        public ?string $packagingType = null,
        public ?string $colorFamily = null,
        public ?string $grammageGsm = null,
        public ?string $treatmentChannel = null,
        public ?array $composition = null,
        public ?string $densityKgM3 = null,
        public ?string $unitWeightKg = null,
        public ?array $extraAttributes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key) => isset($data[$key]) ? (string) $data[$key] : null;

        return new self(
            code: $data['code'],
            catalogItem: $string('catalog_item'),
            name: $string('name'),
            description: $string('description'),
            wasteFamily: $string('waste_family'),
            regulatoryCode: $string('regulatory_code'),
            isHazardous: isset($data['is_hazardous']) ? (bool) $data['is_hazardous'] : null,
            unit: $string('unit'),
            packagingType: $string('packaging_type'),
            colorFamily: $string('color_family'),
            grammageGsm: $string('default_grammage_gsm'),
            treatmentChannel: $string('treatment_channel'),
            composition: isset($data['default_composition']) ? array_map(
                fn (array $line) => ['material' => (string) $line['material'], 'pct' => bcadd((string) $line['pct'], '0', 2)],
                $data['default_composition'],
            ) : null,
            densityKgM3: $string('density_kg_m3'),
            unitWeightKg: $string('unit_weight_kg'),
            extraAttributes: $data['extra_attributes'] ?? null,
        );
    }
}
