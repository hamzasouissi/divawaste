<?php

namespace App\Modules\Documents\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AnnualDeclarationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'site_id' => $this->site?->ulid ?? $this->site_id,
            'format_code' => $this->format_code,
            'reporting_year' => $this->reporting_year,
            'status' => $this->status?->value ?? $this->status,
            'data' => $this->data,
            'computed_at' => $this->computed_at?->toISOString(),
            'validated_at' => $this->validated_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'submission_reference' => $this->submission_reference,
            'document_id' => $this->document?->ulid ?? $this->document_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
