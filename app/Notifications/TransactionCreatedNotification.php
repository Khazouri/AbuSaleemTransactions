<?php

namespace App\Notifications;

use App\Models\Transaction;

/** A new transaction has been registered and is waiting at the first stage. */
class TransactionCreatedNotification extends SystemNotification
{
    private readonly int $transactionId;

    private readonly string $reference;

    private readonly string $title;

    public function __construct(Transaction $transaction, private readonly string $actorName)
    {
        $this->transactionId = $transaction->id;
        $this->reference = (string) $transaction->reference_number;
        $this->title = (string) $transaction->title;
    }

    public function eventType(): string
    {
        return 'transaction_created';
    }

    protected function payload(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'reference_number' => $this->reference,
            'title_ar' => 'معاملة جديدة',
            'title_en' => 'New transaction',
            'body_ar' => "سجّل {$this->actorName} المعاملة {$this->reference} — {$this->title}.",
            'body_en' => "{$this->actorName} registered transaction {$this->reference} — {$this->title}.",
        ];
    }
}
