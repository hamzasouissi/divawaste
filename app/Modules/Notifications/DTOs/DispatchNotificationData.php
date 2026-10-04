<?php

namespace App\Modules\Notifications\DTOs;

final readonly class DispatchNotificationData
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public string $channel,
        public int $notifiableId,
        public string $notifiableType,
        public array $data = [],
        public ?int $companyId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? 'account.pending_approval'),
            channel: (string) ($data['channel'] ?? 'mail'),
            notifiableId: (int) ($data['notifiable_id'] ?? 0),
            notifiableType: (string) ($data['notifiable_type'] ?? 'user'),
            data: is_array($data['data'] ?? null) ? $data['data'] : [],
            companyId: isset($data['company_id']) ? (int) $data['company_id'] : null,
        );
    }
}
