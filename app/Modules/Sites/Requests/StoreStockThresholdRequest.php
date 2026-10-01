<?php

namespace App\Modules\Sites\Requests;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Sites\DTOs\CreateStockThresholdData;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Rules\TenantExists;
use Illuminate\Foundation\Http\FormRequest;

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
        $siteId = $this->site()?->id ?? 0;

        return [
            'site' => ['required', 'string', new TenantExists(Site::class)],
            'zone' => ['nullable', 'string', new TenantExists(Zone::class, fn ($query) => $query->where('site_id', $siteId))],
            'waste_type' => ['nullable', 'string', new TenantExists(WasteType::class)],
            'max_quantity_kg' => ['required', 'decimal:0,3', 'gt:0'],
            'warning_pct' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function toData(): CreateStockThresholdData
    {
        return new CreateStockThresholdData(
            siteUlid: strtoupper((string) $this->validated('site')),
            maxQuantityKg: (string) $this->validated('max_quantity_kg'),
            warningPct: (int) $this->validated('warning_pct', 90),
            zoneUlid: $this->filled('zone') ? strtoupper((string) $this->validated('zone')) : null,
            wasteTypeUlid: $this->filled('waste_type') ? strtoupper((string) $this->validated('waste_type')) : null,
        );
    }

    private function site(): ?Site
    {
        $ulid = $this->input('site');

        return is_string($ulid) ? Site::query()->select(['id', 'ulid', 'company_id'])->where('ulid', strtoupper($ulid))->first() : null;
    }
}
