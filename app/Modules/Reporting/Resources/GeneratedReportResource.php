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
            'parameters' => $this->parameters,
            'stored_file_id' => $this->stored_file_id,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
