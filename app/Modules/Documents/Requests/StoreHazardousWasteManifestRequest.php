<?php

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\DTOs\CreateHazardousWasteManifestData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreHazardousWasteManifestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'regulatory_waste_code_id' => ['required', 'integer', 'exists:regulatory_waste_codes,id'],
            'waste_type' => ['nullable', 'string', 'max:100'],
            'waste_type_id' => ['nullable', 'integer', 'exists:waste_types,id'],
            'package_count' => ['nullable', 'integer', 'min:1'],
            'declared_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'producer_snapshot' => ['nullable', 'array'],
            'transporter_snapshot' => ['nullable', 'array'],
            'receiver_snapshot' => ['nullable', 'array'],
            'transporter_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'receiver_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function toData(): CreateHazardousWasteManifestData
    {
        return new CreateHazardousWasteManifestData(
            regulatoryWasteCodeId: (int) $this->input('regulatory_waste_code_id'),
            wasteType: $this->input('waste_type', $this->input('waste_type_id')),
            packageCount: $this->input('package_count'),
            declaredWeightKg: $this->input('declared_weight_kg'),
            producerSnapshot: (array) $this->input('producer_snapshot', []),
            transporterSnapshot: (array) $this->input('transporter_snapshot', []),
            receiverSnapshot: (array) $this->input('receiver_snapshot', []),
            transporterCompanyId: $this->input('transporter_company_id') !== null ? (int) $this->input('transporter_company_id') : null,
            receiverCompanyId: $this->input('receiver_company_id') !== null ? (int) $this->input('receiver_company_id') : null,
            status: $this->input('status', 'draft'),
        );
    }
}
