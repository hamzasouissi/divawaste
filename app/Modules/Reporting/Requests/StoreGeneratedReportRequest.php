<?php

namespace App\Modules\Reporting\Requests;

use App\Modules\Reporting\DTOs\CreateGeneratedReportData;
use Illuminate\Foundation\Http\FormRequest;

class StoreGeneratedReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('reports.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_type' => ['required', 'string', 'max:50'],
            'format' => ['sometimes', 'string', 'in:pdf,xlsx,csv'],
            'parameters' => ['nullable', 'array'],
            'stored_file_id' => ['nullable', 'integer', 'exists:stored_files,id'],
            'status' => ['nullable', 'in:queued,processing,completed,failed,expired'],
        ];
    }

    public function toData(): CreateGeneratedReportData
    {
        return CreateGeneratedReportData::fromArray($this->validated());
    }
}
