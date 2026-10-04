<?php

namespace App\Modules\Audit\DTOs;

final readonly class WriteAuditLogData
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $event,
        public string $auditableType,
        public ?string $auditableId = null,
        public ?string $companyId = null,
        public ?string $actorType = null,
        public ?int $actorId = null,
        public ?string $requestId = null,
        public ?array $oldValues = null,
        public ?array $newValues = null,
        public ?array $metadata = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            event: (string) ($data['event'] ?? 'updated'),
            auditableType: (string) ($data['auditable_type'] ?? 'unknown'),
            auditableId: isset($data['auditable_id']) ? (string) $data['auditable_id'] : null,
            companyId: isset($data['company_id']) ? (string) $data['company_id'] : null,
            actorType: isset($data['actor_type']) ? (string) $data['actor_type'] : null,
            actorId: isset($data['actor_id']) ? (int) $data['actor_id'] : null,
            requestId: isset($data['request_id']) ? (string) $data['request_id'] : null,
            oldValues: is_array($data['old_values'] ?? null) ? $data['old_values'] : null,
            newValues: is_array($data['new_values'] ?? null) ? $data['new_values'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
        );
    }
}
