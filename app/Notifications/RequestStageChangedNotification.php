<?php

namespace App\Notifications;

use App\Models\Request;
use App\Models\WorkflowStage;

/**
 * A request moved. Informational: it goes to the people following the
 * work, not to the person who now has to act on it — that's
 * ActionRequiredNotification, so the two can be muted independently.
 */
class RequestStageChangedNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    private readonly ?string $fromStage;

    private readonly ?string $toStage;

    public function __construct(
        Request $requestRecord,
        ?WorkflowStage $fromStage,
        ?WorkflowStage $toStage,
        private readonly string $action,
        private readonly string $actorName,
    ) {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->reference_number;
        $this->fromStage = $fromStage?->name_ar;
        $this->toStage = $toStage?->name_ar;
    }

    public function eventType(): string
    {
        return 'stage_changed';
    }

    protected function payload(): array
    {
        $from = $this->fromStage ?? '—';
        $to = $this->toStage ?? '—';

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'action' => $this->action,
            'title_ar' => 'تحديث على الطلب',
            'title_en' => 'Request updated',
            'body_ar' => "انتقل الطلب {$this->reference} من «{$from}» إلى «{$to}» بواسطة {$this->actorName}.",
            'body_en' => "Request {$this->reference} moved from \"{$from}\" to \"{$to}\" by {$this->actorName}.",
        ];
    }
}
