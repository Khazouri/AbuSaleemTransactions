<?php

namespace App\Notifications\Channels;

use App\Contracts\SmsSender;
use Illuminate\Notifications\Notification;

/**
 * Stage 23 — custom Laravel notification channel for SMS.
 *
 * Laravel ships `database` and `mail`; SMS needs this adapter. It stays thin
 * on purpose: deciding *whether* to send is NotificationSetting's job, and
 * deciding *how* to send is the bound SmsSender's.
 */
class SmsChannel
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        // routeNotificationFor('sms') on the User model; a number-less user
        // never reaches this channel, but a null here would still be a bug
        // worth failing quietly on rather than sending to nowhere.
        $to = $notifiable->routeNotificationFor('sms', $notification);

        if (blank($to) || ! method_exists($notification, 'toSms')) {
            return;
        }

        $message = $notification->toSms($notifiable);

        if (blank($message)) {
            return;
        }

        $this->sender->send($to, $message);
    }
}
