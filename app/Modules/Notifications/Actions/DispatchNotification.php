<?php

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\DTOs\DispatchNotificationData;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Str;

final class DispatchNotification
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(DispatchNotificationData $data): array
    {
        if (! NotificationCatalog::isValidType($data->type)) {
            throw new \InvalidArgumentException("Unsupported notification type: {$data->type}");
        }

        if (! NotificationCatalog::isValidChannel($data->channel)) {
            throw new \InvalidArgumentException("Unsupported notification channel: {$data->channel}");
        }

        $companyId = $data->companyId ?? ($this->context->hasTenant() ? $this->context->companyId() : null);

        $notification = Notification::query()->create([
            'id' => (string) Str::ulid(),
            'type' => $data->type,
            'notifiable_type' => $data->notifiableType,
            'notifiable_id' => $data->notifiableId,
            'company_id' => $companyId,
            'data' => $data->data,
        ]);

        $log = NotificationLog::query()->create([
            'company_id' => $companyId,
            'user_id' => $data->notifiableId,
            'recipient' => $data->notifiableType.':'.$data->notifiableId,
            'channel' => $data->channel,
            'notification_type' => $data->type,
            'related_type' => $data->notifiableType,
            'related_id' => $data->notifiableId,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        return [
            'notification' => $notification,
            'log' => $log,
        ];
    }
}
