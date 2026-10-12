<?php

namespace App\Modules\Sites\Requests;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Common\Support\Measure;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Sites\DTOs\CreateStockThresholdData;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Rules\TenantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        $site = $this->site();

        // Unknown site: let validation answer 422.
        return $site === null || (bool) $this->user()?->can('stock.thresholds.manage', $site);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $siteId = $this->site()->id ?? 0;

        return [
            'site' => ['required', 'string', new TenantExists(Site::class)],
            'zone' => ['nullable', 'string', new TenantExists(Zone::class, fn ($query) => $query->where('site_id', $siteId))],
            'waste_type' => ['nullable', 'string', new TenantExists(WasteType::class)],
            // The unit the restriction is stated in. Kilograms when nothing is said, as before.
            'unit' => ['sometimes', 'string', Rule::in(Measure::UNITS), function (string $attribute, mixed $value, \Closure $fail): void {
                if (in_array(Measure::dimension((string) $value), ['count', 'volume'], true) && ! $this->typeCountsIn((string) $value)) {
                    $fail('Pieces and volumes need a waste type counted that way, or one that states the weight of a piece or its density.');
                }
            }],
            // A limit in packages says which packaging it counts: bags are not cartons.
            'packaging_type' => ['nullable', 'required_if:unit,package', 'prohibited_unless:unit,package', 'string', Rule::exists('packaging_types', 'code')->where('is_active', true)],
            'max_quantity' => ['required_without:max_quantity_kg', 'decimal:0,3', 'gt:0', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->input('unit') === Measure::PACKAGE && ! ctype_digit((string) $value)) {
                    $fail('A number of packages is a whole number.');
                }
            }],
            // The former name of a limit in kilograms.
            'max_quantity_kg' => ['sometimes', 'prohibits:max_quantity', 'decimal:0,3', 'gt:0'],
            'warning_pct' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    private function typeCountsIn(string $unit): bool
    {
        $ulid = $this->input('waste_type');
        $type = is_string($ulid) ? WasteType::query()->where('ulid', strtoupper($ulid))->first() : null;

        return $type !== null && Measure::suits($unit, app(ReferenceCodes::class)->code('units', $type->default_unit_id),
            $type->density_kg_m3 === null ? null : (string) $type->density_kg_m3, $type->unit_weight_kg === null ? null : (string) $type->unit_weight_kg);
    }

    public function toData(): CreateStockThresholdData
    {
        return new CreateStockThresholdData(
            siteUlid: strtoupper((string) $this->validated('site')),
            maxQuantity: (string) ($this->validated('max_quantity') ?? $this->validated('max_quantity_kg')),
            warningPct: (int) $this->validated('warning_pct', 90),
            zoneUlid: $this->filled('zone') ? strtoupper((string) $this->validated('zone')) : null,
            wasteTypeUlid: $this->filled('waste_type') ? strtoupper((string) $this->validated('waste_type')) : null,
            unit: (string) $this->validated('unit', 'kg'),
            packagingType: $this->filled('packaging_type') ? (string) $this->validated('packaging_type') : null,
        );
    }

    private function site(): ?Site
    {
        $ulid = $this->input('site');

        return is_string($ulid) ? Site::query()->select(['id', 'ulid', 'company_id'])->where('ulid', strtoupper($ulid))->first() : null;
    }
}
