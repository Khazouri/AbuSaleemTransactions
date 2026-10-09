<?php

namespace App\Notifications;

use App\Models\Meeting;

/** A member answered the proposed date the مقرر sent out (Stage 102). */
class MeetingInvitationResponseNotification extends SystemNotification
{
    private readonly int $meetingId;

    private readonly string $title;

    private readonly bool $confirmed;

    public function __construct(Meeting $meeting, private readonly string $memberName, private readonly string $response)
    {
        $this->meetingId = $meeting->id;
        $this->title = (string) $meeting->title;
        $this->confirmed = $meeting->status === 'scheduled';
    }

    public function eventType(): string
    {
        return 'meeting_invitation_response';
    }

    protected function payload(): array
    {
        $accepted = $this->response === 'accept';

        // The last acceptance is also the moment the meeting is confirmed;
        // saying so here spares the مقرر opening the meeting to find out.
        return [
            'meeting_id' => $this->meetingId,
            'response' => $this->response,
            'meeting_confirmed' => $this->confirmed,
            'title_ar' => $accepted ? 'قبول موعد اجتماع' : 'اعتذار عن موعد اجتماع',
            'title_en' => $accepted ? 'Meeting date accepted' : 'Meeting date declined',
            'body_ar' => ($accepted
                ? "وافق {$this->memberName} على موعد اجتماع «{$this->title}»."
                : "اعتذر {$this->memberName} عن موعد اجتماع «{$this->title}»، ويلزم اقتراح موعد آخر.")
                .($this->confirmed ? ' وافق جميع الأعضاء وتأكد الموعد.' : ''),
            'body_en' => ($accepted
                ? "{$this->memberName} accepted the date of meeting \"{$this->title}\"."
                : "{$this->memberName} declined the date of meeting \"{$this->title}\"; a new date is needed.")
                .($this->confirmed ? ' Every member has accepted; the meeting is confirmed.' : ''),
        ];
    }
}
