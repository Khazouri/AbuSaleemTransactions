<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Models\WorkflowStage;

/**
 * A transaction moved. Informational: it goes to the people following the
 * work, not to the person who now has to act on it — that's
 * ActionRequiredNotification, so the two can be muted independently.
 */
class TransactionStageChangedNotification extends SystemNotification
{
    private readonly int $transactionId;

    private readonly string $reference;

    private readonly ?string $fromStage;

    private readonly ?string $toStage;

    public function __construct(
        Transaction $transaction,
        ?WorkflowStage $fromStage,
        ?WorkflowStage $toStage,
        private readonly string $action,
        private readonly string $actorName,
    ) {
        $this->transactionId = $transaction->id;
        $this->reference = (string) $transaction->reference_number;
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
            'transaction_id' => $this->transactionId,
            'reference_number' => $this->reference,
            'action' => $this->action,
            'title_ar' => 'تحديث على المعاملة',
            'title_en' => 'Transaction updated',
            'body_ar' => "انتقلت المعاملة {$this->reference} من «{$from}» إلى «{$to}» بواسطة {$this->actorName}.",
            'body_en' => "Transaction {$this->reference} moved from \"{$from}\" to \"{$to}\" by {$this->actorName}.",
        ];
    }
}
