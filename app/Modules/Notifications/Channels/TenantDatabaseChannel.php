<?php

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\BusinessEventNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Database channel that stamps the company the event belongs to, so a user who is a member of
 * several companies only sees the notifications of the company he is working in.
 */
final class TenantDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return parent::buildPayload($notifiable, $notification) + [
            'company_id' => $notification instanceof BusinessEventNotification ? $notification->companyId : null,
        ];
    }
}
