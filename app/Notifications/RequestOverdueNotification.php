<?php

namespace App\Notifications;

use App\Models\Request;

/**
 * The Stage 17 nightly sweep has flagged an SLA breach.
 *
 * Raised only when overdue_at is first written, so a request that stays
 * late for a month is announced once rather than every night.
 */
class RequestOverdueNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    private readonly string $title;

    private readonly ?string $dueDate;

    public function __construct(Request $requestRecord)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
        $this->title = (string) $requestRecord->title;
        $this->dueDate = $requestRecord->due_date?->toDateString();
    }

    public function eventType(): string
    {
        return 'request_overdue';
    }

    protected function payload(): array
    {
        $due = $this->dueDate ?? '—';

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'due_date' => $this->dueDate,
            'title_ar' => 'تجاوز الموعد النهائي',
            'title_en' => 'Deadline exceeded',
            'body_ar' => "تجاوز الطلب {$this->reference} — {$this->title} موعده النهائي ({$due}).",
            'body_en' => "Request {$this->reference} — {$this->title} has passed its due date ({$due}).",
        ];
    }
}
