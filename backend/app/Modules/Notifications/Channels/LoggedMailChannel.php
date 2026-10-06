<?php

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Support\EmailLog;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * The mail channel of every notification, with a trace: each email is written to notification_logs as sent or failed.
 *
 * When jobs run inline (QUEUE_CONNECTION=sync, the local set-ups) a refused email is reported and logged as failed
 * instead of failing the action that triggered it: the decision is already saved and must not look broken.
 * With a real queue the error is rethrown, so the worker retries and the job ends in failed_jobs.
 */
final class LoggedMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        $transport = (string) config('mail.mailers.'.config('mail.default').'.transport', config('mail.default'));

        try {
            $sent = parent::send($notifiable, $notification);
        } catch (Throwable $error) {
            EmailLog::failed($notifiable, $notification, $transport, $this->subject($notifiable, $notification), $error);
            if (config('queue.default') !== 'sync') {
                throw $error;
            }
            report($error);

            return null;
        }

        if ($sent instanceof SentMessage) {
            EmailLog::sent($notifiable, $notification, $sent, $transport);
        }

        return $sent;
    }

    private function subject(mixed $notifiable, Notification $notification): ?string
    {
        try {
            $message = method_exists($notification, 'toMail') ? $notification->toMail($notifiable) : null;

            return $message instanceof MailMessage ? $message->subject : null;
        } catch (Throwable) {
            return null;
        }
    }
}
