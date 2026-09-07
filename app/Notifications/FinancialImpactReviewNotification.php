<?php

namespace App\Notifications;

use App\Models\Request;

/**
 * A financially-impactful request reached the study stage (`observations`) —
 * Stage 47. Sent to قسم المرتبات والمزايا, who hold no formal seat in
 * workflow_transitions ([D] doesn't name one; see AGENT_NOTES.md), so this is
 * advisory, not an "act on this" prompt tied to a button they'd find.
 */
class FinancialImpactReviewNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    private readonly string $title;

    public function __construct(Request $requestRecord)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
        $this->title = (string) $requestRecord->title;
    }

    public function eventType(): string
    {
        return 'financial_impact_review';
    }

    protected function payload(): array
    {
        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'title_ar' => 'طلب ذو أثر مالي قيد الدراسة',
            'title_en' => 'A financially-impactful request is under study',
            'body_ar' => "الطلب {$this->reference} — {$this->title} له أثر مالي ووصل إلى مرحلة الدراسة. يرجى إبداء الرأي عند الحاجة.",
            'body_en' => "Request {$this->reference} — {$this->title} has a financial effect and has reached the study stage. Please weigh in if needed.",
        ];
    }
}
