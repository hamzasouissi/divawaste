<?php

namespace App\Modules\Tenancy\Resources;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'currency_code' => $this->currency_code,
            'timezone' => $this->timezone,
        ];
    }
}
