<?php

namespace App\Notifications;

use App\Models\Meeting;

/** A meeting's minutes finished their review+signature lifecycle and are now approved. */
class MeetingMinutesApprovedNotification extends SystemNotification
{
    private readonly int $meetingId;

    private readonly string $title;

    public function __construct(Meeting $meeting)
    {
        $this->meetingId = $meeting->id;
        $this->title = (string) $meeting->title;
    }

    public function eventType(): string
    {
        return 'minutes_approved';
    }

    protected function payload(): array
    {
        return [
            'meeting_id' => $this->meetingId,
            'title_ar' => 'اعتماد محضر اجتماع',
            'title_en' => 'Meeting minutes approved',
            'body_ar' => "تم اعتماد محضر اجتماع «{$this->title}» بعد استكمال توقيعات الحضور.",
            'body_en' => "The minutes of meeting \"{$this->title}\" were approved after all attendee signatures were collected.",
        ];
    }
}
