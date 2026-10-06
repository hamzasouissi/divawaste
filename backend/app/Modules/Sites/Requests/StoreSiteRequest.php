<?php

namespace App\Modules\Sites\Requests;

use App\Modules\Sites\DTOs\CreateSiteData;
use App\Modules\Sites\Enums\SiteKind;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sites.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(TenantContext::class)->companyId();

        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('sites', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:150'],
            'site_kind' => ['sometimes', Rule::enum(SiteKind::class)],
            'country' => ['required', 'string', 'size:2', Rule::exists('countries', 'iso2')->where('is_active', true)],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'region' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'timezone:all'],
            'regulatory_identifier' => ['nullable', 'string', 'max:64'],
            'capacity_kg' => ['nullable', 'decimal:0,3', 'min:0'],
        ];
    }

    public function toData(): CreateSiteData
    {
        return CreateSiteData::fromArray($this->validated());
    }
}
