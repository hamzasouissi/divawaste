<?php

namespace App\Modules\Tenancy\Resources;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'company_type' => $this->company_type,
            'status' => $this->status,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'tax_id' => $this->tax_id,
            'vat_number' => $this->vat_number,
            'country' => DB::table('countries')->where('id', $this->country_id)->value('iso2'),
            'currency_code' => $this->currency_code,
            'timezone' => $this->timezone,
            'default_locale' => $this->default_locale,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'region' => $this->region,
            'phone' => $this->phone,
            'email' => $this->email,
            'billing_email' => $this->billing_email,
            'has_logo' => $this->logo_stored_file_id !== null,
            'activities' => DB::table('company_textile_activities as cta')
                ->join('textile_activities as a', 'a.id', '=', 'cta.textile_activity_id')
                ->where('cta.company_id', $this->id)->orderBy('a.sort_order')->pluck('a.code'),
            'weight_variance_threshold_pct' => (string) ($this->settings['weight_variance_threshold_pct'] ?? '5.00'),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
        ];
    }
}
