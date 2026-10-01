<?php

namespace App\Modules\Waste\Requests;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Waste\DTOs\CreateWasteLotData;
use App\Modules\Waste\Rules\ValidComposition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class StoreWasteLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $site = is_string($this->input('site')) ? Site::query()->where('ulid', strtoupper($this->input('site')))->first(['id']) : null;

        return $site === null || (bool) $this->user()?->can('lots.create', $site);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $site = is_string($this->input('site')) ? strtoupper($this->input('site')) : '';
        $ref = fn (string $table) => Rule::exists($table, 'code')->where('is_active', true);

        return [
            'ulid' => ['nullable', 'ulid', Rule::unique('waste_lots', 'ulid')],
            'tag' => ['nullable', 'string', 'max:64', Rule::unique('lot_tags', 'tag_value')->where('tag_type', 'qr')],
            'site' => ['required', 'string', new TenantExists(Site::class)],
            'zone' => ['required', 'string', new TenantExists(Zone::class, fn ($q) => $q->whereIn('site_id', Site::query()->select('id')->where('ulid', $site)))],
            'waste_type' => ['required', 'string', new TenantExists(WasteType::class, fn ($q) => $q->where('is_active', true))],
            'net_weight_kg' => ['required', 'decimal:0,3', 'gte:0'],
            'gross_weight_kg' => ['nullable', 'decimal:0,3', 'gte:0'],
            'tare_weight_kg' => ['nullable', 'decimal:0,3', 'gte:0'],
            'packaging_type' => ['nullable', 'string', $ref('packaging_types')],
            'quantity' => ['nullable', 'decimal:0,3', 'gt:0', 'required_with:unit'],
            'unit' => ['nullable', 'string', Rule::exists('units', 'code')],
            'color_family' => ['nullable', 'string', $ref('color_families')],
            'color_label' => ['nullable', 'string', 'max:100'],
            'grammage_gsm' => ['nullable', 'decimal:0,2', 'gt:0'],
            'composition' => ['nullable', 'array', new ValidComposition],
            'composition.*.material' => ['required', 'string', $ref('materials')],
            'composition.*.pct' => ['required', 'decimal:0,2'],
            'extra_attributes' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'generated_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    public function toData(): CreateWasteLotData
    {
        $v = fn (string $key) => $this->validated($key) === null ? null : (string) $this->validated($key);
        $token = $this->user()?->currentAccessToken();

        return new CreateWasteLotData(
            siteUlid: strtoupper((string) $v('site')),
            zoneUlid: strtoupper((string) $v('zone')),
            wasteTypeUlid: strtoupper((string) $v('waste_type')),
            netWeightKg: (string) $v('net_weight_kg'),
            ulid: $v('ulid') === null ? null : strtoupper((string) $v('ulid')),
            tagValue: $v('tag'),
            grossWeightKg: $v('gross_weight_kg'),
            tareWeightKg: $v('tare_weight_kg'),
            packagingType: $v('packaging_type'),
            quantity: $v('quantity'),
            unit: $v('unit'),
            colorFamily: $v('color_family'),
            colorLabel: $v('color_label'),
            grammageGsm: $v('grammage_gsm'),
            composition: $this->validated('composition'),
            extraAttributes: $this->validated('extra_attributes'),
            notes: $v('notes'),
            generatedAt: $v('generated_at'),
            deviceId: $token instanceof PersonalAccessToken && $token->device_id !== null ? (int) $token->device_id : null,
            source: $token instanceof PersonalAccessToken && $token->device_id !== null ? 'mobile' : 'web',
        );
    }
}
