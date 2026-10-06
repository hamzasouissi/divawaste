<?php

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\DTOs\UploadCertificateData;
use Illuminate\Foundation\Http\FormRequest;

final class UploadCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $lotField = $this->input('lots') !== null ? 'lots' : 'lot_numbers';

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'max:20480',
            ],
            'type' => [
                'required',
                'string',
                'in:recycling,destruction',
            ],
            $lotField => [
                'required',
                'array',
                'min:1',
                'max:32',
            ],
            $lotField.'.*' => [
                'required',
                'string',
                'max:100',
            ],
            'title' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    public function toData(): UploadCertificateData
    {
        $lotNumbers = $this->input('lots', $this->input('lot_numbers', []));

        return new UploadCertificateData(
            file: $this->file('file'),
            lotNumbers: array_values(array_unique(
                array_map('strtoupper', (array) $lotNumbers)
            )),
            type: (string) ($this->input('type', 'recycling') ?? 'recycling'),
            title: $this->input('title'),
        );
    }
}
