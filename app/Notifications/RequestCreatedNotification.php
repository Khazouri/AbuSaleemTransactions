<?php

namespace App\Notifications;

use App\Models\Request;

/** A new request has been registered and is waiting at the first stage. */
class RequestCreatedNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    private readonly string $title;

    public function __construct(Request $requestRecord, private readonly string $actorName)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
        $this->title = (string) $requestRecord->title;
    }

    public function eventType(): string
    {
        return 'request_created';
    }

    protected function payload(): array
    {
        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'title_ar' => 'طلب جديد',
            'title_en' => 'New request',
            'body_ar' => "سجّل {$this->actorName} الطلب {$this->reference} — {$this->title}.",
            'body_en' => "{$this->actorName} registered request {$this->reference} — {$this->title}.",
        ];
    }
}
