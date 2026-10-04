<?php

namespace App\Modules\Notifications\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.*.notification_type' => ['required', 'string', 'max:100'],
            'preferences.*.channel' => ['required', 'in:mail,database,whatsapp'],
            'preferences.*.is_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function preferences(): array
    {
        return $this->input('preferences', []);
    }
}
