<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Models\WorkflowStage;

/**
 * A transaction has arrived at a stage this recipient's role can act on.
 *
 * Recipients come from the same workflow_transitions rows that decide which
 * buttons the detail screen renders (see NotificationDispatcher), so nobody is
 * told to act on something they'd find no action for.
 */
class ActionRequiredNotification extends SystemNotification
{
    private readonly int $transactionId;

    private readonly string $reference;

    private readonly string $title;

    private readonly ?string $stage;

    public function __construct(Transaction $transaction, ?WorkflowStage $stage)
    {
        $this->transactionId = $transaction->id;
        $this->reference = (string) $transaction->reference_number;
        $this->title = (string) $transaction->title;
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
            'transaction_id' => $this->transactionId,
            'reference_number' => $this->reference,
            'title_ar' => 'معاملة بانتظار إجراءك',
            'title_en' => 'A transaction is waiting for you',
            'body_ar' => "وصلت المعاملة {$this->reference} — {$this->title} إلى مرحلة «{$stage}» وتنتظر إجراءك.",
            'body_en' => "Transaction {$this->reference} — {$this->title} reached the \"{$stage}\" stage and is waiting for your action.",
        ];
    }
}
