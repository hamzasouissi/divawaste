<?php

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\DTOs\CreateAnnualDeclarationData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAnnualDeclarationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format_code' => ['required', 'string', 'max:40'],
            'reporting_year' => ['required', 'integer', 'min:2024', 'max:2100'],
            'data' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function toData(): CreateAnnualDeclarationData
    {
        return new CreateAnnualDeclarationData(
            formatCode: (string) $this->input('format_code'),
            reportingYear: (int) $this->input('reporting_year'),
            data: $this->input('data', []),
            status: $this->input('status', 'draft'),
        );
    }
}
