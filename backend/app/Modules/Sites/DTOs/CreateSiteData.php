<?php

namespace App\Modules\Sites\DTOs;

use App\Modules\Sites\Enums\SiteKind;

final readonly class CreateSiteData
{
    public function __construct(
        public string $code,
        public string $name,
        public SiteKind $siteKind,
        public string $countryIso2,
        public ?string $addressLine1 = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?string $region = null,
        public ?string $timezone = null,
        public ?string $regulatoryIdentifier = null,
        public ?string $capacityKg = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: $data['code'],
            name: $data['name'],
            siteKind: SiteKind::from($data['site_kind'] ?? SiteKind::Production->value),
            countryIso2: $data['country'],
            addressLine1: $data['address_line1'] ?? null,
            city: $data['city'] ?? null,
            postalCode: $data['postal_code'] ?? null,
            region: $data['region'] ?? null,
            timezone: $data['timezone'] ?? null,
            regulatoryIdentifier: $data['regulatory_identifier'] ?? null,
            capacityKg: isset($data['capacity_kg']) ? (string) $data['capacity_kg'] : null,
        );
    }
}
