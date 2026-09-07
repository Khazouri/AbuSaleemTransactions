<?php

namespace App\Notifications;

use App\Models\Decision;
use App\Models\Request;

/**
 * The committee's binding decision on an agenda item has been recorded.
 *
 * Distinct from the stage change that same decision triggers: this one names
 * the outcome and the vote counts, which is the part people ask about.
 */
class DecisionRecordedNotification extends SystemNotification
{
    private const OUTCOMES = [
        'approve' => ['ar' => 'الموافقة', 'en' => 'approved'],
        'reject' => ['ar' => 'الرفض', 'en' => 'rejected'],
        'defer' => ['ar' => 'التأجيل', 'en' => 'deferred'],
    ];

    private readonly int $requestId;

    private readonly string $reference;

    private readonly string $outcome;

    private readonly int $approveCount;

    private readonly int $rejectCount;

    private readonly int $deferCount;

    public function __construct(Request $requestRecord, Decision $decision)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
        $this->outcome = (string) $decision->outcome;
        $this->approveCount = (int) $decision->votes_approve_count;
        $this->rejectCount = (int) $decision->votes_reject_count;
        $this->deferCount = (int) $decision->votes_defer_count;
    }

    public function eventType(): string
    {
        return 'decision_recorded';
    }

    protected function payload(): array
    {
        $labels = self::OUTCOMES[$this->outcome] ?? ['ar' => $this->outcome, 'en' => $this->outcome];
        $tally = "{$this->approveCount}/{$this->rejectCount}/{$this->deferCount}";

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'outcome' => $this->outcome,
            'title_ar' => 'قرار لجنة',
            'title_en' => 'Committee decision',
            'body_ar' => "قررت اللجنة {$labels['ar']} بشأن الطلب {$this->reference} (موافقة/رفض/تأجيل: {$tally}).",
            'body_en' => "The committee {$labels['en']} request {$this->reference} (approve/reject/defer: {$tally}).",
        ];
    }
}
