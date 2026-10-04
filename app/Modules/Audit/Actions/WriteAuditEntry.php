<?php

namespace App\Modules\Audit\Actions;

use App\Modules\Audit\DTOs\WriteAuditLogData;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;

final class WriteAuditEntry
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(WriteAuditLogData $data): AuditLog
    {
        $this->auditLogger->record([
            'event' => $data->event,
            'auditable_type' => $data->auditableType,
            'auditable_id' => $data->auditableId,
            'company_id' => $data->companyId,
            'actor_type' => $data->actorType,
            'actor_user_id' => $data->actorId,
            'request_id' => $data->requestId,
            'old_values' => $data->oldValues,
            'new_values' => $data->newValues,
            'tags' => $data->metadata,
        ]);

        return AuditLog::query()
            ->where('auditable_type', $data->auditableType)
            ->where('auditable_id', $data->auditableId)
            ->latest('id')
            ->firstOrFail();
    }
}
