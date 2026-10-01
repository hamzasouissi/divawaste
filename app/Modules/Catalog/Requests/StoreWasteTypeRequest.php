<?php

namespace App\Modules\Catalog\Requests;

use App\Modules\Catalog\DTOs\CreateWasteTypeData;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWasteTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('waste_types.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(TenantContext::class)->companyId();
        $codeSystem = DB::table('companies as c')->join('countries as k', 'k.id', '=', 'c.country_id')
            ->where('c.id', $companyId)->value('k.waste_code_system');
        $reference = fn (string $table) => Rule::exists($table, 'code')->where('is_active', true);

        return [
            'catalog_item' => ['nullable', 'string', $reference('waste_catalog_items')],
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('waste_types', 'code')->where('company_id', $companyId)],
            'name' => ['required_without:catalog_item', 'nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'waste_family' => ['required_without:catalog_item', 'nullable', 'string', $reference('waste_families')],
            // Code must belong to the company country's nomenclature (TN, MA, EU_LOW).
            'regulatory_code' => ['nullable', 'string', Rule::exists('regulatory_waste_codes', 'code')->where('code_system', $codeSystem)->where('is_selectable', true)],
            'is_hazardous' => ['sometimes', 'boolean'],
            'unit' => ['nullable', 'string', Rule::exists('units', 'code')],
            'packaging_type' => ['nullable', 'string', $reference('packaging_types')],
            'color_family' => ['nullable', 'string', $reference('color_families')],
            'default_grammage_gsm' => ['nullable', 'decimal:0,2', 'gt:0'],
            'treatment_channel' => ['nullable', 'string', $reference('treatment_channels')],
            'default_composition' => ['nullable', 'array', 'min:1'],
            'default_composition.*.material' => ['required', 'string', 'distinct', $reference('materials')],
            'default_composition.*.pct' => ['required', 'decimal:0,2', 'gt:0', 'lte:100'],
            'density_kg_m3' => ['nullable', 'decimal:0,3', 'gt:0'],
            'unit_weight_kg' => ['nullable', 'decimal:0,3', 'gt:0'],
            'extra_attributes' => ['nullable', 'array'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $lines = $this->input('default_composition');
            if ($validator->errors()->isNotEmpty() || ! is_array($lines)) {
                return;
            }

            $sum = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, (string) $line['pct'], 2), '0');
            if (bccomp($sum, '100', 2) !== 0) {
                $validator->errors()->add('default_composition', 'Composition percentages must total 100.');
            }
        }];
    }

    public function toData(): CreateWasteTypeData
    {
        return CreateWasteTypeData::fromArray($this->validated());
    }
}
