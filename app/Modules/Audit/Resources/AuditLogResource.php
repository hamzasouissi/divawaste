<?php

namespace App\Modules\Audit\Resources;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'auditable_ulid' => $this->auditable_ulid,
            'company_id' => $this->company_id,
            'actor_type' => $this->actor_type,
            'actor_user_id' => $this->actor_user_id,
            'actor_company_id' => $this->actor_company_id,
            'request_id' => $this->request_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'url' => $this->url,
            'http_method' => $this->http_method,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
