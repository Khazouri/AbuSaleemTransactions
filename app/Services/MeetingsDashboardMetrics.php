<?php

namespace App\Services;

use App\Models\Decision;
use App\Models\Meeting;
use App\Models\MeetingTransaction;
use App\Models\Transaction;

/**
 * Stage 32 — the numbers behind the meetings-unit command dashboard.
 *
 * Deliberately separate from Stage 24's ReportMetricsService: that class
 * answers "how is the whole transaction pipeline doing" against an arbitrary
 * filter set; this one answers "how is the committee/meetings pipeline doing"
 * — a fixed, narrower slice scoped to committee-stage statuses and meetings.
 */
class MeetingsDashboardMetrics
{
    public function __construct(
        private readonly CommitteeStatusService $committeeStatus,
        private readonly MeetingReadinessService $readiness,
    ) {}

    public function kpis(): array
    {
        return [
            'candidates' => $this->committeeStatus->candidatesQuery()->count(),
            'upcoming_meetings' => Meeting::query()
                ->where('status', 'scheduled')
                ->where('scheduled_at', '>=', now())
                ->count(),
            'meetings_held' => Meeting::query()->where('status', 'completed')->count(),
            'pending_decisions' => MeetingTransaction::query()
                ->where('item_type', 'employee_request')
                ->whereDoesntHave('decision')
                ->count(),
            'overdue_committee_items' => $this->committeeStatus->candidatesQuery()
                ->whereNotNull('overdue_at')
                ->count(),
            'decisions_this_month' => Decision::query()
                ->whereBetween('decided_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];
    }

    /**
     * Five buckets a committee-bound request currently sits in, by its
     * CURRENT status — a request is counted exactly once, in whichever
     * bucket its live status maps to right now.
     */
    public function funnel(): array
    {
        return [
            'candidates' => $this->committeeStatus->candidatesQuery()->count(),
            'on_agenda' => $this->statusCount(['on_agenda']),
            'in_discussion' => $this->statusCount(['under_discussion', 'awaiting_recommendation_approval', 'completion_required']),
            'decided' => $this->statusCount(['decided']),
            'closed' => $this->statusCount(['approved', 'final_approved', 'archived', 'completed_closed']),
        ];
    }

    /**
     * Stage 33 — reads MeetingReadinessService rather than re-deriving its
     * own confirmed/total counts, so the dashboard tile can never disagree
     * with the dedicated readiness screen (per the Stage 32 note's own
     * open item).
     */
    public function nextMeeting(): ?array
    {
        $meeting = Meeting::query()
            ->with('committee:id,name_ar,name_en')
            ->withCount('agendaItems')
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        if ($meeting === null) {
            return null;
        }

        $readiness = $this->readiness->compute($meeting);

        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'committee' => $meeting->committee ? [
                'id' => $meeting->committee->id,
                'name_ar' => $meeting->committee->name_ar,
                'name_en' => $meeting->committee->name_en,
            ] : null,
            'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
            'location' => $meeting->location,
            'agenda_items_count' => $meeting->agenda_items_count,
            'readiness' => [
                'ready' => $readiness['ready'],
                'exceptions_count' => count($readiness['exceptions']),
            ],
        ];
    }

    private function statusCount(array $codes): int
    {
        return Transaction::query()
            ->whereHas('status', fn ($query) => $query->whereIn('code', $codes))
            ->count();
    }
}
