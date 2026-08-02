<?php

namespace App\Notifications;

use App\Models\Meeting;

/** A committee meeting has been scheduled and the recipient is on its list. */
class MeetingScheduledNotification extends SystemNotification
{
    private readonly int $meetingId;

    private readonly string $title;

    private readonly ?string $scheduledAt;

    private readonly ?string $location;

    public function __construct(Meeting $meeting)
    {
        $this->meetingId = $meeting->id;
        $this->title = (string) $meeting->title;
        $this->scheduledAt = $meeting->scheduled_at?->format('Y-m-d H:i');
        $this->location = $meeting->location;
    }

    public function eventType(): string
    {
        return 'meeting_scheduled';
    }

    protected function payload(): array
    {
        $when = $this->scheduledAt ?? '—';
        $where = filled($this->location) ? $this->location : '—';

        return [
            'meeting_id' => $this->meetingId,
            'title_ar' => 'دعوة لاجتماع',
            'title_en' => 'Meeting invitation',
            'body_ar' => "اجتماع «{$this->title}» بتاريخ {$when} في {$where}.",
            'body_en' => "Meeting \"{$this->title}\" on {$when} at {$where}.",
        ];
    }
}
