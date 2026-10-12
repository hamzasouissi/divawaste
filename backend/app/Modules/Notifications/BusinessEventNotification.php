<?php

namespace App\Modules\Notifications;

use App\Modules\Common\Support\FrontendUrl;
use App\Modules\Common\Support\QuantityText;
use App\Modules\Notifications\Channels\TenantDatabaseChannel;
use App\Modules\Notifications\Support\NotificationCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * M7-01: one notification class for every business event. The in-app row carries the type and its parameters
 * (the client renders the text in the user's language); the email is rendered from the language files.
 */
final class BusinessEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public readonly string $type,
        public readonly array $params,
        public readonly ?string $link,
        public readonly ?int $companyId,
    ) {
        $this->afterCommit();
    }

    /**
     * In-app and email by default; each can be switched off per user, company and type.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $disabled = DB::table('notification_preferences')
            ->where('user_id', $notifiable->id)->where('company_id', $this->companyId)
            ->where('notification_type', $this->type)->where('is_enabled', false)->pluck('channel')->all();

        return array_values(array_filter([
            in_array('database', $disabled, true) ? null : TenantDatabaseChannel::class,
            in_array('mail', $disabled, true) || ! NotificationCatalog::sendsMail($this->type) ? null : 'mail',
        ]));
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['params' => $this->params, 'link' => $this->link];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Rendered in the language of the person it is sent to: figures written for that language replace the default ones.
        $params = array_map(fn ($value) => (string) ($value ?? '—'), QuantityText::forLocale($this->params, app()->getLocale()));
        $mail = (new MailMessage)
            ->subject(__("notifications.{$this->type}.title", $params))
            ->greeting(__('mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__("notifications.{$this->type}.body", $params));

        return $this->link === null ? $mail : $mail->action(__('mail.event.action'), FrontendUrl::to($this->link));
    }
}
