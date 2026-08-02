<?php

namespace App\Notifications;

use App\Models\Transaction;

/**
 * The Stage 17 nightly sweep has flagged an SLA breach.
 *
 * Raised only when overdue_at is first written, so a transaction that stays
 * late for a month is announced once rather than every night.
 */
class TransactionOverdueNotification extends SystemNotification
{
    private readonly int $transactionId;

    private readonly string $reference;

    private readonly string $title;

    private readonly ?string $dueDate;

    public function __construct(Transaction $transaction)
    {
        $this->transactionId = $transaction->id;
        $this->reference = (string) $transaction->reference_number;
        $this->title = (string) $transaction->title;
        $this->dueDate = $transaction->due_date?->toDateString();
    }

    public function eventType(): string
    {
        return 'transaction_overdue';
    }

    protected function payload(): array
    {
        $due = $this->dueDate ?? '—';

        return [
            'transaction_id' => $this->transactionId,
            'reference_number' => $this->reference,
            'due_date' => $this->dueDate,
            'title_ar' => 'تجاوز الموعد النهائي',
            'title_en' => 'Deadline exceeded',
            'body_ar' => "تجاوزت المعاملة {$this->reference} — {$this->title} موعدها النهائي ({$due}).",
            'body_en' => "Transaction {$this->reference} — {$this->title} has passed its due date ({$due}).",
        ];
    }
}
