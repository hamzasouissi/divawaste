<?php

namespace App\Modules\Audit\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('audit_logs.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter.entity' => ['nullable', 'string', 'max:50'],
            'filter.user' => ['nullable', 'integer'],
            'filter.between' => ['nullable', 'array'],
            'filter.between.0' => ['nullable', 'date'],
            'filter.between.1' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
