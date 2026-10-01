<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\DTOs\CreateWasteTypeData;
use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Activates a catalog item (copying its defaults, keeping the reference) or creates a custom type.
 * A hazardous regulatory code always makes the type hazardous (blueprint R-20).
 */
final class CreateWasteType
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(CreateWasteTypeData $data, User $actor): WasteType
    {
        $catalog = $data->catalogItem === null ? null : DB::table('waste_catalog_items')->where('code', $data->catalogItem)->first([
            'id', 'name', 'waste_family_id', 'default_regulatory_waste_code_id', 'default_is_hazardous', 'default_unit_id',
            'default_packaging_type_id', 'default_treatment_channel_id', 'default_density_kg_m3', 'default_unit_weight_kg',
        ]);

        $regulatoryCodeId = $data->regulatoryCode !== null
            ? $this->regulatoryCodeId($data->regulatoryCode)
            : $this->compatibleCatalogCode($catalog?->default_regulatory_waste_code_id);
        $codeIsHazardous = $regulatoryCodeId !== null
            && (bool) DB::table('regulatory_waste_codes')->where('id', $regulatoryCodeId)->value('is_hazardous');

        return DB::transaction(fn () => WasteType::query()->create([
            'waste_catalog_item_id' => $catalog?->id,
            'code' => $data->code,
            'name' => $data->name ?? $this->catalogName($catalog?->name),
            'description' => $data->description,
            'waste_family_id' => $data->wasteFamily !== null ? $this->id('waste_families', $data->wasteFamily) : $catalog?->waste_family_id,
            'regulatory_waste_code_id' => $regulatoryCodeId,
            'is_hazardous' => $codeIsHazardous || ($data->isHazardous ?? (bool) ($catalog?->default_is_hazardous ?? false)),
            'default_unit_id' => $this->id('units', $data->unit) ?? $catalog?->default_unit_id ?? $this->id('units', 'kg'),
            'default_packaging_type_id' => $this->id('packaging_types', $data->packagingType) ?? $catalog?->default_packaging_type_id,
            'default_color_family_id' => $this->id('color_families', $data->colorFamily),
            'default_grammage_gsm' => $data->grammageGsm,
            'default_treatment_channel_id' => $this->id('treatment_channels', $data->treatmentChannel) ?? $catalog?->default_treatment_channel_id,
            'default_composition' => $data->composition,
            'density_kg_m3' => $data->densityKgM3 ?? $catalog?->default_density_kg_m3,
            'unit_weight_kg' => $data->unitWeightKg ?? $catalog?->default_unit_weight_kg,
            'extra_attributes' => $data->extraAttributes,
            'activated_at' => now(),
            'created_by_user_id' => $actor->id,
        ]));
    }

    private function id(string $table, ?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        $id = DB::table($table)->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function regulatoryCodeId(string $code): ?int
    {
        $id = DB::table('regulatory_waste_codes')->where('code_system', $this->codeSystem())->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The catalog default is kept only if it belongs to the company country's nomenclature.
     */
    private function compatibleCatalogCode(?int $codeId): ?int
    {
        if ($codeId === null) {
            return null;
        }

        return DB::table('regulatory_waste_codes')->where('id', $codeId)->value('code_system') === $this->codeSystem() ? $codeId : null;
    }

    private function codeSystem(): ?string
    {
        $system = DB::table('companies as c')->join('countries as k', 'k.id', '=', 'c.country_id')
            ->where('c.id', $this->context->companyId())->value('k.waste_code_system');

        return is_string($system) ? $system : null;
    }

    private function catalogName(?string $json): string
    {
        $names = json_decode((string) $json, true);

        return is_array($names) ? (string) ($names['fr'] ?? reset($names)) : '';
    }
}
