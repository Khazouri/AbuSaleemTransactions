<?php

namespace App\Notifications;

use App\Models\Request;
use App\Models\WorkflowStage;

/**
 * A request has arrived at a stage this recipient's role can act on.
 *
 * Recipients come from the same workflow_transitions rows that decide which
 * buttons the detail screen renders (see NotificationDispatcher), so nobody is
 * told to act on something they'd find no action for.
 */
class ActionRequiredNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    private readonly string $title;

    private readonly ?string $stage;

    public function __construct(Request $requestRecord, ?WorkflowStage $stage)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
        $this->title = (string) $requestRecord->title;
        $this->stage = $stage?->name_ar;
    }

    public function eventType(): string
    {
        return 'action_required';
    }

    protected function payload(): array
    {
        $stage = $this->stage ?? '—';

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'title_ar' => 'طلب بانتظار إجراءك',
            'title_en' => 'A request is waiting for you',
            'body_ar' => "وصل الطلب {$this->reference} — {$this->title} إلى مرحلة «{$stage}» وينتظر إجراءك.",
            'body_en' => "Request {$this->reference} — {$this->title} reached the \"{$stage}\" stage and is waiting for your action.",
        ];
    }
}
