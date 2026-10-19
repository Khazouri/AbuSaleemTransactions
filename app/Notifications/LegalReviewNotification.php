<?php

namespace App\Notifications;

use App\Models\Request;

/**
 * 2026-10-19 (user decision) — the two hand-offs of the pre-meeting legal
 * review: the مقرر sends a file to العضو القانوني, and the legal member's
 * recommendation comes back. One event type for both, like `request_notice`:
 * it is one conversation about one file, so one mute switch.
 */
class LegalReviewNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    private readonly string $title;

    /** @param  bool  $recorded  false: handed to the legal member; true: their review is in */
    public function __construct(Request $requestRecord, private readonly bool $recorded)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
        $this->title = (string) $requestRecord->title;
    }

    public function eventType(): string
    {
        return 'legal_review';
    }

    protected function payload(): array
    {
        $subject = "{$this->reference} — {$this->title}";

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            ...($this->recorded
                ? [
                    'title_ar' => 'سجّل العضو القانوني توصيته',
                    'title_en' => 'The legal member recorded a recommendation',
                    'body_ar' => "سجّل العضو القانوني توصيته في الطلب {$subject}.",
                    'body_en' => "The legal member recorded a recommendation on request {$subject}.",
                ]
                : [
                    'title_ar' => 'طلب بانتظار توصيتك القانونية',
                    'title_en' => 'A request awaits your legal recommendation',
                    'body_ar' => "أحال المقرر الطلب {$subject} إلى المراجعة القانونية، وينتظر توصيتك.",
                    'body_en' => "The rapporteur referred request {$subject} for legal review; it awaits your recommendation.",
                ]),
        ];
    }
}
