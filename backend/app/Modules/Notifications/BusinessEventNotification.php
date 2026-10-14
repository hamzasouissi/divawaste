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
     * @param  list<int>|null  $mailByDefault  the recipients who get the email unless they switched it off; the others
     *                                         get it only if they asked for it. Null = every recipient, by default.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $params,
        public readonly ?string $link,
        public readonly ?int $companyId,
        public readonly ?array $mailByDefault = null,
    ) {
        $this->afterCommit();
    }

    /**
     * In-app and, for the events worth it, email; each can be switched on or off per user, company and type. What a
     * person chose always wins; without a choice, the email goes to everyone told, or only to `mailByDefault`.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $chosen = DB::table('notification_preferences')
            ->where('user_id', $notifiable->id)->where('company_id', $this->companyId)
            ->where('notification_type', $this->type)->pluck('is_enabled', 'channel')->map(fn ($enabled) => (bool) $enabled);
        $mail = NotificationCatalog::sendsMail($this->type)
            && ($chosen['mail'] ?? ($this->mailByDefault === null || in_array((int) $notifiable->id, $this->mailByDefault, true)));

        return array_values(array_filter([
            ($chosen['database'] ?? true) ? TenantDatabaseChannel::class : null,
            $mail ? 'mail' : null,
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
