<?php

namespace App\Services;

use App\Models\ApprovalReturn;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stage 82 — [D] Art. 83's agenda ordering and Appendix 24's per-item profile.
 *
 * **Art. 83 orders the agenda by rule, and its fifth rule is why the manual
 * order survives.** The article ranks: (1) الموضوعات المؤجلة من اجتماعات
 * سابقة، (2) الموضوعات المرتبطة بمدد قانونية، (3) الموضوعات العاجلة المعتمدة،
 * (4) المعاملات المكتملة بحسب تاريخ جاهزيتها، and (5) "أي ترتيب يقرره رئيس
 * اللجنة بما لا يخل بالمساواة وسلامة الإجراءات". Rule 5 is a permission, not a
 * bucket, so this service *offers* the computed order rather than imposing it:
 * a departure stays legal, and Appendix 24 is what makes it accountable —
 * "ولا يجوز استخدام الأولوية لتجاوز ترتيب المعاملات دون مبرر إداري موثق". The
 * meeting's own `agenda_order_justification` is that record, and
 * MeetingReadinessService refuses to convene without one when the order
 * departs.
 *
 * Rank 5 here is therefore not Art. 83's rule 5 but its complement: the items
 * the article does not categorise at all (an administrative or emerging item,
 * a file with no recorded readiness date). They keep their existing relative
 * order, which is the chair's own arrangement — rule 5 applied literally.
 *
 * **Ties inside a rank fall back to the current `agenda_order`**, so applying
 * the rule never reshuffles items the article ranks equally; within rank 4 the
 * readiness date orders them, oldest first, since that is what the article
 * says to sort by.
 *
 * **Readiness is the LATEST entry into `ready`, not the first.** A file that
 * was sent back for completion and later re-readied is ready as of the later
 * date; crediting it with a readiness it subsequently lost would let a stalled
 * file jump ahead of one that has been ready continuously.
 *
 * Every profile is computed for the whole collection in a fixed number of
 * queries regardless of its size (Stage 81's batching discipline), because the
 * agenda register reads the same profiles across every meeting at once.
 */
class AgendaOrderingService
{
    /** Art. 83's own order, plus the uncategorised tail. */
    public const RANKS = [
        1 => ['code' => 'deferred_from_previous', 'ar' => 'مؤجل من اجتماع سابق', 'en' => 'Deferred from a previous sitting'],
        2 => ['code' => 'legal_deadline', 'ar' => 'مرتبط بمدة قانونية', 'en' => 'Tied to a legal deadline'],
        3 => ['code' => 'urgent', 'ar' => 'عاجل معتمد', 'en' => 'Approved as urgent'],
        4 => ['code' => 'ready_by_date', 'ar' => 'مكتمل بحسب تاريخ الجاهزية', 'en' => 'Complete, by readiness date'],
        5 => ['code' => 'chair_order', 'ar' => 'بترتيب رئيس اللجنة', 'en' => "By the chair's own arrangement"],
    ];

    /**
     * Appendix 24's أولوية عالية conditions. Three of the four are facts the
     * system already records; the fourth (ضرر وظيفي واضح) is judgment, so it
     * stays the rapporteur's own declaration and, per the appendix's closing
     * rule, has to carry a written justification when nothing derived backs
     * it up.
     */
    public const PRIORITY_GROUNDS = [
        'legal_deadline' => ['ar' => 'مرتبطة بمدة قانونية', 'en' => 'Tied to a legal deadline'],
        'returned_from_approving_body' => ['ar' => 'أعيدت من جهة الاعتماد', 'en' => 'Returned by the approving body'],
        'deferred_from_previous' => ['ar' => 'مؤجلة من اجتماع سابق', 'en' => 'Deferred from a previous sitting'],
        'declared' => ['ar' => 'ضرر وظيفي واضح (إقرار المقرر)', 'en' => 'Clear job harm — declared by the rapporteur'],
    ];

    /**
     * One profile per item, keyed by agenda-item id.
     *
     * @param  Collection<int, MeetingRequest>  $items
     * @return array<int, array<string, mixed>>
     */
    public function profiles(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $items->loadMissing([
            'meeting:id,meeting_number,scheduled_at',
            'request:id,reference_number,title,status_id,request_type_id,created_by_user_id',
            'request.status:id,code,name_ar,name_en',
            'request.requestType:id,name_ar,name_en',
            'request.createdBy:id,name',
            'request.latestLegalReview',
            'appeal:id,appellant_user_id,original_request_id',
            'appeal.appellant:id,name',
            'appeal.originalRequest:id,reference_number,title',
        ]);

        $requestIds = $items->pluck('request_id')->filter()->unique()->values();
        $appealIds = $items->pluck('appeal_id')->filter()->unique()->values();

        $priorAppearances = $this->priorAppearances($requestIds, $appealIds);
        $returnedRequestIds = $this->returnedRequestIds($requestIds);
        $readinessDates = $this->readinessDates($requestIds);

        $profiles = [];

        foreach ($items as $item) {
            $previous = $this->latestPriorAppearance($item, $priorAppearances);
            $review = $item->request?->latestLegalReview;

            $grounds = [];

            if ($previous !== null && $previous['outcome'] === 'defer') {
                $grounds[] = 'deferred_from_previous';
            }

            if (filled($review?->legal_deadline)) {
                $grounds[] = 'legal_deadline';
            }

            if ($item->request_id !== null && in_array($item->request_id, $returnedRequestIds, true)) {
                $grounds[] = 'returned_from_approving_body';
            }

            if ($item->priority === 'high') {
                $grounds[] = 'declared';
            }

            $readyAt = $item->request_id === null ? null : ($readinessDates[$item->request_id] ?? null);

            $profiles[$item->id] = [
                'id' => $item->id,
                'agenda_order' => $item->agenda_order,
                'rank' => $this->rankFor($grounds, $readyAt),
                'priority_level' => $grounds === [] ? 'normal' : 'high',
                'priority_declared' => $item->priority,
                'priority_reason' => $item->priority_reason,
                'priority_grounds' => $grounds,
                'ready_at' => $readyAt,
                // Appendix 24's own twelve item fields, in its order. What is
                // not recorded reports null rather than a guess.
                'fields' => $this->appendixTwentyFourFields($item, $review, $previous, $grounds),
            ];
        }

        return $profiles;
    }

    /**
     * The ids of this agenda in Art. 83's order.
     *
     * @param  Collection<int, MeetingRequest>  $items
     * @param  array<int, array<string, mixed>>  $profiles
     * @return list<int>
     */
    public function orderedIds(Collection $items, array $profiles): array
    {
        $sortable = $items->all();

        // A plain usort rather than Collection::sortBy([...]) — that helper
        // treats a callable in the array as a two-argument *comparator*, not
        // as a value extractor, which is the opposite of what this needs.
        usort($sortable, function (MeetingRequest $a, MeetingRequest $b) use ($profiles) {
            return $this->sortKey($profiles[$a->id], $a) <=> $this->sortKey($profiles[$b->id], $b);
        });

        return array_map(fn (MeetingRequest $item) => $item->id, $sortable);
    }

    /**
     * Whether the agenda as it stands already follows the rule. Two orders
     * that differ only inside a rank are still "following it" — the sort is
     * stable on `agenda_order` precisely so that a chair's arrangement within
     * a bucket is not reported as a departure.
     *
     * @param  Collection<int, MeetingRequest>  $items
     * @param  array<int, array<string, mixed>>  $profiles
     */
    public function matchesRule(Collection $items, array $profiles): bool
    {
        $current = $items->sortBy('agenda_order')->pluck('id')->values()->all();

        return $current === $this->orderedIds($items, $profiles);
    }

    /** The whole ordering picture for one meeting, as the builder screen reads it. */
    public function forMeeting(Meeting $meeting): array
    {
        $items = $meeting->agendaItems()->orderBy('agenda_order')->get();
        $profiles = $this->profiles($items);

        return [
            'items' => array_values($profiles),
            'suggested_order' => $this->orderedIds($items, $profiles),
            'matches_rule' => $this->matchesRule($items, $profiles),
            'justification' => $meeting->agenda_order_justification,
        ];
    }

    /**
     * (rank, readiness date within rank 4, current position) — the three keys
     * Art. 83 sorts by, in that order. Ranks other than 4 use an empty
     * readiness key so the agenda's existing arrangement decides inside a
     * bucket, which is rule 5's own discretion left alone.
     *
     * @param  array<string, mixed>  $profile
     * @return list<int|string>
     */
    private function sortKey(array $profile, MeetingRequest $item): array
    {
        return [
            $profile['rank'],
            $profile['rank'] === 4 ? ($profile['ready_at'] ?? '9999') : '',
            $item->agenda_order,
        ];
    }

    /** @param list<string> $grounds */
    private function rankFor(array $grounds, ?string $readyAt): int
    {
        if (in_array('deferred_from_previous', $grounds, true)) {
            return 1;
        }

        if (in_array('legal_deadline', $grounds, true)) {
            return 2;
        }

        // Art. 83's "العاجلة المعتمدة" plus Appendix 24's remaining عالية
        // ground (أعيدت من جهة الاعتماد), which the article's own list has no
        // rank for. Both are matters the appendix says come before an
        // ordinary complete file, so they share the rank the article gives
        // urgency.
        if ($grounds !== []) {
            return 3;
        }

        return $readyAt === null ? 5 : 4;
    }

    /** @return Collection<int, MeetingRequest> */
    private function priorAppearances(Collection $requestIds, Collection $appealIds): Collection
    {
        if ($requestIds->isEmpty() && $appealIds->isEmpty()) {
            return collect();
        }

        // Deliberately NOT excluding the ids in $items. An earlier appearance
        // of the same file can itself be one of the rows being profiled — the
        // agenda register pages across every meeting at once, so a sitting and
        // its predecessor routinely land on the same page. Excluding them made
        // the register report "لم يسبق عرضه" for a file it was listing twice.
        // latestPriorAppearance() drops the item itself on its own, since no
        // row is scheduled strictly before itself.
        return MeetingRequest::query()
            ->where(function ($query) use ($requestIds, $appealIds) {
                if ($requestIds->isNotEmpty()) {
                    $query->orWhereIn('request_id', $requestIds);
                }

                if ($appealIds->isNotEmpty()) {
                    $query->orWhereIn('appeal_id', $appealIds);
                }
            })
            ->with(['meeting:id,meeting_number,scheduled_at', 'decision:id,meeting_request_id,outcome,decided_at'])
            ->get();
    }

    /**
     * The item's own most recent earlier appearance — "هل سبق عرضه؟" and
     * "رقم الاجتماع السابق إن وجد". Earlier means the sitting was scheduled
     * before this one, or, for two sittings at the same instant, the agenda
     * row was created first.
     *
     * @param  Collection<int, MeetingRequest>  $priorAppearances
     * @return array<string, mixed>|null
     */
    private function latestPriorAppearance(MeetingRequest $item, Collection $priorAppearances): ?array
    {
        $mine = $priorAppearances->filter(function (MeetingRequest $other) use ($item) {
            $sameSubject = ($item->request_id !== null && $other->request_id === $item->request_id)
                || ($item->appeal_id !== null && $other->appeal_id === $item->appeal_id);

            if (! $sameSubject) {
                return false;
            }

            $mineAt = $item->meeting?->scheduled_at;
            $otherAt = $other->meeting?->scheduled_at;

            if ($mineAt === null || $otherAt === null) {
                return $other->id < $item->id;
            }

            return $otherAt->lt($mineAt) || ($otherAt->eq($mineAt) && $other->id < $item->id);
        });

        $previous = $mine
            ->sortBy(fn (MeetingRequest $other) => sprintf(
                '%020d-%020d',
                $other->meeting?->scheduled_at?->getTimestamp() ?? 0,
                $other->id,
            ))
            ->last();

        if ($previous === null) {
            return null;
        }

        return [
            'meeting_id' => $previous->meeting_id,
            'meeting_number' => $previous->meeting?->meeting_number,
            'meeting_date' => $previous->meeting?->scheduled_at?->toDateString(),
            'outcome' => $previous->decision?->outcome,
        ];
    }

    /** @return list<int> */
    private function returnedRequestIds(Collection $requestIds): array
    {
        if ($requestIds->isEmpty()) {
            return [];
        }

        return ApprovalReturn::query()
            ->whereIn('request_id', $requestIds)
            ->distinct()
            ->pluck('request_id')
            ->all();
    }

    /**
     * The latest moment each request entered Art. 38's `ready` status — the
     * article's own "تاريخ جاهزيتها".
     *
     * @return array<int, string>
     */
    private function readinessDates(Collection $requestIds): array
    {
        if ($requestIds->isEmpty()) {
            return [];
        }

        return DB::table('request_status_history')
            ->join('request_statuses', 'request_statuses.id', '=', 'request_status_history.to_status_id')
            ->whereIn('request_status_history.request_id', $requestIds)
            ->where('request_statuses.code', 'ready')
            ->groupBy('request_status_history.request_id')
            ->selectRaw('request_status_history.request_id as request_id, MAX(request_status_history.changed_at) as ready_at')
            ->pluck('ready_at', 'request_id')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    /**
     * Appendix 24's twelve per-item fields. Six of them already existed on the
     * agenda row or its request; three are Stage 68's own recorded legal card
     * (which is also what closes Appendix 7's last two readiness questions);
     * two are derived from the item's earlier appearances; and the priority
     * level is derived from the grounds above.
     *
     * @param  array<string, mixed>|null  $previous
     * @param  list<string>  $grounds
     */
    private function appendixTwentyFourFields(
        MeetingRequest $item,
        mixed $review,
        ?array $previous,
        array $grounds,
    ): array {
        return [
            'item_number' => $item->agenda_order,
            'reference_number' => $item->request?->reference_number
                ?? $item->appeal?->originalRequest?->reference_number,
            'employee_name' => $item->request?->createdBy?->name
                ?? $item->appeal?->appellant?->name,
            'request_type' => $item->request?->requestType
                ? ['name_ar' => $item->request->requestType->name_ar, 'name_en' => $item->request->requestType->name_en]
                : null,
            'subject' => $item->request?->title ?? $item->subject ?? $item->appeal?->originalRequest?->title,
            'readiness_status' => $item->request?->status
                ? ['code' => $item->request->status->code, 'name_ar' => $item->request->status->name_ar, 'name_en' => $item->request->status->name_en]
                : null,
            'legal_opinion' => $review === null ? null : [
                'verdict' => $review->verdict,
                'note' => $review->legal_note,
                'legal_deadline' => $review->legal_deadline,
            ],
            // Appendix 22's اختصاص اللجنة IS "نوع القرار المطلوب" — the
            // instrument the committee is being asked to issue (Art. 90).
            'required_instrument' => $review?->committee_mandate,
            'expected_approving_body' => $review?->approving_body,
            'priority_level' => $grounds === [] ? 'normal' : 'high',
            'previously_presented' => $previous !== null,
            'previous_meeting' => $previous,
        ];
    }
}
