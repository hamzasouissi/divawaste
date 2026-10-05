<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * One row of notification_logs per email the application produced: who, what, through which transport, and
 * whether the transport accepted it. "sent" means "accepted by the transport": with the "log" or "array"
 * transport nothing leaves the server, and with SMTP the mail server may still bounce the message later.
 *
 * Outside production the HTML of each email is also kept in storage/app/mail-outbox so it can be read without a mailbox.
 */
final class EmailLog
{
    /** Transports that write the message somewhere instead of delivering it. */
    public const LOCAL_TRANSPORTS = ['log', 'array'];

    public static function sent(mixed $notifiable, Notification $notification, SentMessage $sent, string $transport): void
    {
        $email = $sent->getOriginalMessage();
        $id = self::write($notifiable, $notification, $transport, [
            'recipient' => $email instanceof Email ? implode(', ', array_map(fn ($address) => $address->getAddress(), $email->getTo())) : self::address($notifiable, $notification),
            'subject' => $email instanceof Email ? $email->getSubject() : null,
            'status' => 'sent', 'provider_message_id' => Str::limit($sent->getMessageId(), 191, ''), 'sent_at' => now(),
        ]);

        if ($id !== null && $email instanceof Email && config('mail.outbox.keep_bodies')) {
            File::ensureDirectoryExists(self::directory());
            File::put(self::bodyPath($id), (string) ($email->getHtmlBody() ?? nl2br(e((string) $email->getTextBody()))));
        }
    }

    public static function failed(mixed $notifiable, Notification $notification, string $transport, ?string $subject, Throwable $error): void
    {
        self::write($notifiable, $notification, $transport, [
            'recipient' => self::address($notifiable, $notification), 'subject' => $subject,
            'status' => 'failed', 'error_message' => Str::limit($error->getMessage(), 1000, ''),
        ]);
    }

    public static function directory(): string
    {
        return (string) config('mail.outbox.path', storage_path('app'.DIRECTORY_SEPARATOR.'mail-outbox'));
    }

    public static function bodyPath(int $id): string
    {
        return self::directory().DIRECTORY_SEPARATOR.$id.'.html';
    }

    /**
     * The business name of the email: "invoice.issued", "company_approved", "verify_email"…
     */
    public static function type(Notification $notification): string
    {
        return match (true) {
            $notification instanceof BusinessEventNotification => $notification->type,
            property_exists($notification, 'event') && is_string($notification->event) => $notification->event,
            default => Str::of(class_basename($notification))->beforeLast('Notification')->snake()->value(),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function write(mixed $notifiable, Notification $notification, string $transport, array $values): ?int
    {
        try {
            $id = (int) DB::table('notification_logs')->insertGetId($values + [
                'company_id' => $notification instanceof BusinessEventNotification ? $notification->companyId : null,
                'user_id' => $notifiable instanceof User ? $notifiable->id : null,
                'channel' => 'mail', 'transport' => Str::limit($transport, 30, ''), 'notification_type' => self::type($notification),
                'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // Numbers start again when a database is rebuilt: never show the body of an older email under a new number.
            File::delete(self::bodyPath($id));

            return $id;
        } catch (Throwable $error) {
            // The log must never be the reason an email or a business action fails.
            report($error);

            return null;
        }
    }

    private static function address(mixed $notifiable, Notification $notification): string
    {
        $route = is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('mail', $notification) : null;

        return Str::limit(is_array($route) ? implode(', ', array_map(fn ($key, $value) => is_string($key) ? $key : (string) $value, array_keys($route), $route)) : (string) $route, 191, '');
    }
}
