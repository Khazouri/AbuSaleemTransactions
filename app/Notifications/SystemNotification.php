<?php

namespace App\Notifications;

use App\Models\NotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for every Stage 23 notification.
 *
 * Three things live here so no individual event class can get them wrong:
 *
 *  - via() consults notification_settings, which is what makes "the enabled
 *    channels only" a property of the system rather than of each class.
 *  - The payload is bilingual (Arabic first, matching the primary audience)
 *    and the same text feeds all three channels, so a user who switches
 *    channels doesn't get a different story.
 *  - Subclasses take models in their constructors but keep only scalars.
 *    A queued notification is serialised and may run minutes later; holding
 *    a model would either drag its relations along or resurrect a stale copy.
 *
 * Delivery is deferred until the surrounding database transaction commits —
 * see NotificationDispatcher::send(), which is where that is turned on.
 */
abstract class SystemNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Which NotificationSetting::EVENT_TYPES key this notification answers to. */
    abstract public function eventType(): string;

    /**
     * The event-specific body of the stored payload.
     *
     * Must include `title_ar`/`title_en`/`body_ar`/`body_en`; anything else is
     * carried through to the client untouched (ids the UI links to, mostly).
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationSetting::channelsFor($notifiable, $this->eventType());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['event_type' => $this->eventType()] + $this->payload();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payload = $this->payload();

        return (new MailMessage)
            ->subject($payload['title_ar'])
            ->greeting($payload['title_ar'])
            ->line($payload['body_ar'])
            // The English half goes in the same message rather than in a
            // second mail: one event, one email, whatever the reader's locale.
            ->line($payload['body_en']);
    }

    /** SMS is length-sensitive, so it carries the title and body only. */
    public function toSms(object $notifiable): string
    {
        $payload = $this->payload();

        return "{$payload['title_ar']}: {$payload['body_ar']}";
    }
}
