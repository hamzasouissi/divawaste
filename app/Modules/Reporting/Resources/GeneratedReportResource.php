<?php

namespace App\Modules\Reporting\Resources;

use App\Modules\Reporting\Models\GeneratedReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GeneratedReport
 */
class GeneratedReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'report_type' => $this->report_type,
            'format' => $this->format,
            'status' => $this->status,
            'parameters' => array_intersect_key($this->parameters ?? [], array_flip(['from', 'to', 'site', 'lang'])),
            'downloadable' => $this->status === 'completed' && $this->stored_file_id !== null && ($this->expires_at === null || $this->expires_at->isFuture()),
            'error_message' => $this->error_message,
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
