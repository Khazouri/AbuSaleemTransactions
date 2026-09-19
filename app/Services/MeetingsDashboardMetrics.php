<?php

namespace App\Services;

use App\Models\Decision;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\User;
use App\Services\Performance\CommitteeBoardService;
use App\Services\Performance\EarlyWarningService;

/**
 * Stage 32 — the numbers behind the meetings-unit command dashboard.
 *
 * Deliberately separate from Stage 24's ReportMetricsService: that class
 * answers "how is the whole request pipeline doing" against an arbitrary
 * filter set; this one answers "how is the committee/meetings pipeline doing"
 * — a fixed, narrower slice scoped to committee-stage statuses and meetings.
 */
class MeetingsDashboardMetrics
{
    public function __construct(
        private readonly CommitteeStatusService $committeeStatus,
        private readonly MeetingReadinessService $readiness,
        private readonly CommitteeBoardService $committeeBoard,
        private readonly EarlyWarningService $warnings,
        private readonly MeetingVisibility $visibility,
    ) {}

    /**
     * Membership gate — the meeting-derived numbers count only the actor's
     * own committees.
     *
     * `candidates` and `overdue_committee_items` are deliberately NOT scoped,
     * and cannot be: they count requests sitting at the committee stage, and a
     * request is not attached to any committee until it lands on an agenda.
     * There is nothing to filter them by. That is not a disclosure — this
     * screen is member-only now, and both figures are counts with no file in
     * them.
     */
    public function kpis(User $actor): array
    {
        $committeeIds = $this->visibility->visibleCommitteeIds($actor);
        $mine = fn ($query) => $query->when(
            $committeeIds !== null,
            fn ($scoped) => $scoped->whereIn('committee_id', $committeeIds),
        );

        return [
            'candidates' => $this->committeeStatus->candidatesQuery()->count(),
            'upcoming_meetings' => $mine(Meeting::query())
                ->where('status', 'scheduled')
                ->where('scheduled_at', '>=', now())
                ->count(),
            'meetings_held' => $mine(Meeting::query())->where('status', 'completed')->count(),
            'pending_decisions' => MeetingRequest::query()
                ->where('item_type', 'employee_request')
                ->whereDoesntHave('decision')
                ->whereHas('meeting', fn ($meeting) => $mine($meeting))
                ->count(),
            'overdue_committee_items' => $this->committeeStatus->candidatesQuery()
                ->whereNotNull('overdue_at')
                ->count(),
            'decisions_this_month' => Decision::query()
                ->whereBetween('decided_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->whereHas('meetingRequest.meeting', fn ($meeting) => $mine($meeting))
                ->count(),
        ];
    }

    /**
     * Stage 81 — [D] Appendix 11's لوحة متابعة أعمال اللجنة.
     *
     * This REPLACES Stage 32's own six-bucket funnel. Those buckets were that
     * stage's invention; Appendix 11's ten are the sourced version of the same
     * question, and Track K exists to make the system identical to [D].
     * Rendering both would show one request twice under two groupings.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function board(array $filters = [], string $locale = 'ar'): array
    {
        return $this->committeeBoard->board($filters, $locale);
    }

    /**
     * Stage 81 — [D] Appendix 10's ten early-warning conditions, as a tally
     * plus the worst few files.
     *
     * On this screen because the appendix addresses those alerts to مقرر
     * اللجنة, whose own screen this is.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function earlyWarnings(array $filters = [], string $locale = 'ar', ?User $actor = null): array
    {
        return [
            'summary' => $this->warnings->summary($filters, $locale, $actor),
            // A dashboard card, not the full triage list — the reports screen
            // carries that.
            'top' => $this->warnings->alerts($filters, $locale, 5, $actor),
        ];
    }

    /**
     * Stage 33 — reads MeetingReadinessService rather than re-deriving its
     * own confirmed/total counts, so the dashboard tile can never disagree
     * with the dedicated readiness screen (per the Stage 32 note's own
     * open item).
     */
    public function nextMeeting(User $actor): ?array
    {
        $meeting = $this->visibility->apply(Meeting::query(), $actor)
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
}
