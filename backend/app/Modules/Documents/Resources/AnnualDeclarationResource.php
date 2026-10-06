<?php

namespace App\Modules\Documents\Resources;

use App\Modules\Documents\Models\AnnualDeclaration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin AnnualDeclaration
 */
final class AnnualDeclarationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $site = DB::table('sites')->where('id', $this->site_id)->first(['ulid', 'code', 'name']);

        return [
            'ulid' => $this->ulid,
            'site' => $site === null ? null : (array) $site,
            'format_code' => $this->format_code,
            'reporting_year' => $this->reporting_year,
            'status' => $this->status,
            // Not named "data": a resource with that key loses the standard response envelope.
            'figures' => $this->data,
            'computed_at' => $this->computed_at?->toIso8601ZuluString(),
            'validated_at' => $this->validated_at?->toIso8601ZuluString(),
            'submission_reference' => $this->submission_reference,
            'document' => $this->document_id === null ? null : DB::table('documents')->where('id', $this->document_id)->value('ulid'),
        ];
    }
}
