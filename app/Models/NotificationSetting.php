<?php

namespace App\Models;

use App\Notifications\Channels\SmsChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's channel preferences for one kind of event — Stage 23.
 *
 * This model owns the whole notion of "which channels are on": the event
 * registry, the defaults, and the resolution that SystemNotification::via()
 * calls. Keeping it in one class is what lets the preferences screen and the
 * delivery path disagree about nothing.
 */
class NotificationSetting extends Model
{
    /**
     * Every event the system can raise, and the channels it starts on.
     *
     * Adding an entry here is all a new notification class needs: the
     * preferences screen renders this list, the validator accepts exactly
     * these keys, and a user with no stored row gets these defaults.
     *
     * Email is opt-out for the events that need someone to do something, and
     * off for the purely informational `stage_changed` — that one fires on
     * every move and would otherwise become the noise that trains people to
     * ignore the mailbox. SMS is opt-in everywhere: it costs money per send.
     *
     * @var array<string, array<string, bool>>
     */
    public const EVENT_TYPES = [
        'request_created' => ['in_app' => true, 'email' => false, 'sms' => false],
        'stage_changed' => ['in_app' => true, 'email' => false, 'sms' => false],
        'action_required' => ['in_app' => true, 'email' => true, 'sms' => false],
        'request_overdue' => ['in_app' => true, 'email' => true, 'sms' => false],
        'meeting_scheduled' => ['in_app' => true, 'email' => true, 'sms' => false],
        'decision_recorded' => ['in_app' => true, 'email' => true, 'sms' => false],
        'minutes_approved' => ['in_app' => true, 'email' => true, 'sms' => false],
        // Stage 47 — asks Salaries & Benefits to form an opinion, not just FYI.
        'financial_impact_review' => ['in_app' => true, 'email' => true, 'sms' => false],
        // Stage 65, Track J — Art. 75 point 6: the appellant's written notice
        // of the appeal's final result. A final outcome, same defaults as
        // `decision_recorded`.
        'appeal_decided' => ['in_app' => true, 'email' => true, 'sms' => false],
    ];

    /** The preference columns, in the order the preferences screen shows them. */
    public const CHANNELS = ['in_app', 'email', 'sms'];

    /** Preference column => the Laravel channel it switches on. */
    private const CHANNEL_DRIVERS = [
        'in_app' => 'database',
        'email' => 'mail',
        'sms' => SmsChannel::class,
    ];

    protected $fillable = [
        'user_id',
        'event_type',
        'in_app',
        'email',
        'sms',
    ];

    protected function casts(): array
    {
        return [
            'in_app' => 'boolean',
            'email' => 'boolean',
            'sms' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Laravel channel names this user wants for this event.
     *
     * The one place notification_settings is honoured — every notification
     * routes through it via SystemNotification::via(), so "enabled channels
     * only" cannot be forgotten by an individual notification class.
     *
     * An unknown event type resolves to no channels rather than to every
     * channel: a typo should go quiet, not spam everyone.
     *
     * @return list<string>
     */
    public static function channelsFor(User $user, string $eventType): array
    {
        $enabled = static::flagsFor($user, $eventType);
        $channels = [];

        foreach (self::CHANNEL_DRIVERS as $preference => $driver) {
            if (! ($enabled[$preference] ?? false)) {
                continue;
            }

            // Queueing an SMS for someone with no number would fail in the
            // worker, far from the person who could fix it. Drop it here.
            if ($preference === 'sms' && blank($user->phone)) {
                continue;
            }

            $channels[] = $driver;
        }

        return $channels;
    }

    /**
     * This user's effective flags for one event: their stored row if they have
     * one, otherwise the registry defaults.
     *
     * @return array<string, bool>
     */
    public static function flagsFor(User $user, string $eventType): array
    {
        $defaults = self::EVENT_TYPES[$eventType] ?? null;

        if ($defaults === null) {
            return array_fill_keys(self::CHANNELS, false);
        }

        $stored = static::query()
            ->where('user_id', $user->getKey())
            ->where('event_type', $eventType)
            ->first();

        if ($stored === null) {
            return $defaults;
        }

        return [
            'in_app' => $stored->in_app,
            'email' => $stored->email,
            'sms' => $stored->sms,
        ];
    }

    /**
     * The whole preferences matrix for one user, every event type present.
     *
     * Built from EVENT_TYPES rather than from the stored rows so the screen
     * always shows every event, including the ones the user has never touched.
     *
     * @return array<string, array<string, bool>>
     */
    public static function matrixFor(User $user): array
    {
        $stored = static::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy('event_type');

        $matrix = [];

        foreach (self::EVENT_TYPES as $eventType => $defaults) {
            $row = $stored->get($eventType);

            $matrix[$eventType] = $row === null
                ? $defaults
                : ['in_app' => $row->in_app, 'email' => $row->email, 'sms' => $row->sms];
        }

        return $matrix;
    }
}
