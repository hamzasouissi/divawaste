<?php

namespace App\Modules\Tenancy\Requests;

use App\Modules\Identity\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Public registration form (JSON for industrials, multipart for providers who attach their accreditations).
 */
class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $provider = $this->input('company_type') === 'provider';
        $countryId = DB::table('countries')->where('iso2', strtoupper((string) $this->input('company.country')))->value('id');

        return [
            'company_type' => ['required', Rule::in(['industrial', 'provider'])],

            'company.legal_name' => ['required', 'string', 'max:191'],
            'company.trade_name' => ['nullable', 'string', 'max:191'],
            'company.tax_id' => ['required', 'string', 'max:50', Rule::unique('companies', 'tax_id')->where('country_id', $countryId)],
            'company.country' => ['required', 'string', 'size:2', Rule::exists('countries', 'iso2')->where('is_signup_enabled', true)],
            'company.activities' => [$provider ? 'nullable' : 'required', 'array', 'min:1'],
            'company.activities.*' => ['string', 'distinct', Rule::exists('textile_activities', 'code')->where('is_active', true)],
            'company.address_line1' => ['nullable', 'string', 'max:191'],
            'company.city' => ['nullable', 'string', 'max:100'],
            'company.postal_code' => ['nullable', 'string', 'max:20'],
            'company.phone' => ['nullable', 'string', 'max:30'],

            'contact.first_name' => ['required', 'string', 'max:100'],
            'contact.last_name' => ['required', 'string', 'max:100'],
            'contact.email' => ['required', 'string', 'email', 'max:191', Rule::unique('users', 'email')],
            'contact.phone' => ['required', 'string', 'max:30'],
            'contact.password' => PasswordRules::rules(),

            // M0-03: industrials choose a formula; providers use the platform free of charge.
            'plan' => [$provider ? 'nullable' : 'required', 'string', Rule::exists('subscription_plans', 'code')->where('is_public', true)->where('is_active', true)],

            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],

            // M0-05.
            'provider.roles' => [$provider ? 'required' : 'exclude', 'array', 'min:1'],
            'provider.roles.*' => ['string', 'distinct', Rule::in(['collector', 'transporter', 'recycler', 'eliminator'])],
            'provider.service_area' => [$provider ? 'nullable' : 'exclude', 'string', 'max:500'],
            'provider.accreditations' => [$provider ? 'required' : 'exclude', 'array', 'min:1', 'max:10'],
            'provider.accreditations.*.type' => ['required', 'string', Rule::exists('accreditation_types', 'code')->where('country_id', $countryId)->where('is_active', true)],
            'provider.accreditations.*.reference_number' => ['required', 'string', 'max:100'],
            'provider.accreditations.*.issuing_authority' => ['nullable', 'string', 'max:191'],
            'provider.accreditations.*.valid_from' => ['required', 'date'],
            'provider.accreditations.*.expires_on' => ['required', 'date', 'after:today'],
            'provider.accreditations.*.file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact.email'))) {
            $this->merge(['contact' => ['email' => mb_strtolower(trim($this->input('contact.email')))] + (array) $this->input('contact')]);
        }
    }
}
