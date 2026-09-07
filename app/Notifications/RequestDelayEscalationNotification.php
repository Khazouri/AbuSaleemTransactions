<?php

namespace App\Notifications;

use App\Models\Request;
use App\Models\WorkflowStage;

/**
 * Stage 71 — [D] Appendix 38's delay ladder has climbed a rung.
 *
 * One class rather than three, and one event type rather than three, because
 * the three rungs go to three DIFFERENT audiences (أصفر the current owner,
 * أحمر the rapporteur + admin manager, حرج the committee head + the competent
 * authority) — a per-rung mute would let nobody actually mute a rung they
 * receive, while costing the preferences matrix three rows for one concern.
 * The level travels in the payload instead, and each rung says what Appendix
 * 38 says it means.
 *
 * Raised once per rung per stage by the nightly sweep (see
 * App\Console\Commands\EscalateDelayedRequests), so a file that sits red for a
 * fortnight is announced once, not fourteen times.
 */
class RequestDelayEscalationNotification extends SystemNotification
{
    /** Appendix 38's own wording for each rung. */
    private const LEVELS = [
        'yellow' => ['ar' => 'قرب تجاوز المدة', 'en' => 'Approaching the target duration'],
        'red' => ['ar' => 'تأخر عن المدة', 'en' => 'Past the target duration'],
        'critical' => ['ar' => 'تأخير حرج مرتبط بمدة قانونية', 'en' => 'Critical delay tied to a legal deadline'],
    ];

    private readonly int $requestId;

    private readonly string $reference;

    private readonly string $title;

    private readonly string $level;

    private readonly int $elapsedDays;

    private readonly ?string $stageAr;

    private readonly ?string $stageEn;

    public function __construct(Request $requestRecord, string $level, int $elapsedDays, ?WorkflowStage $stage)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
        $this->title = (string) $requestRecord->title;
        $this->level = $level;
        $this->elapsedDays = $elapsedDays;
        $this->stageAr = $stage?->name_ar;
        $this->stageEn = $stage?->name_en;
    }

    public function eventType(): string
    {
        return 'delay_escalation';
    }

    protected function payload(): array
    {
        $labels = self::LEVELS[$this->level] ?? ['ar' => $this->level, 'en' => $this->level];
        $stageAr = $this->stageAr ?? '—';
        $stageEn = $this->stageEn ?? '—';

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'level' => $this->level,
            'elapsed_days' => $this->elapsedDays,
            'title_ar' => "تصعيد تأخير: {$labels['ar']}",
            'title_en' => "Delay escalation: {$labels['en']}",
            'body_ar' => "الطلب {$this->reference} — {$this->title} في مرحلة ({$stageAr}) منذ {$this->elapsedDays} يومًا: {$labels['ar']}.",
            'body_en' => "Request {$this->reference} — {$this->title} has been at stage ({$stageEn}) for {$this->elapsedDays} day(s): {$labels['en']}.",
        ];
    }
}
