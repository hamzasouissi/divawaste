<?php

namespace App\Modules\Sync\Requests;

use App\Modules\Sync\DTOs\RegisterDeviceData;
use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('app.mobile.access');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device' => ['required', 'string', 'ulid'],
            'name' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'in:android,ios,other'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function toData(): RegisterDeviceData
    {
        return new RegisterDeviceData(
            ulid: strtoupper((string) $this->validated('device')),
            name: (string) $this->validated('name'),
            platform: $this->validated('platform'),
            appVersion: $this->validated('app_version'),
            userAgent: substr((string) $this->userAgent(), 0, 512) ?: null,
        );
    }
}
