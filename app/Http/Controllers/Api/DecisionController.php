<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decision\ExportDecisionRequest;
use App\Http\Requests\Decision\IndexDecisionRequest;
use App\Http\Requests\Decision\ShowDecisionDraftRequest;
use App\Http\Requests\Decision\StoreDecisionRequest;
use App\Http\Requests\Vote\StoreVoteRequest;
use App\Http\Resources\DecisionResource;
use App\Http\Resources\MeetingRequestResource;
use App\Http\Resources\VoteResource;
use App\Models\AppealStatus;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Template;
use App\Models\Vote;
use App\Services\ApprovalSignatureStorage;
use App\Services\DecisionDraftComposer;
use App\Services\DecisionEligibility;
use App\Services\NotificationDispatcher;
use App\Services\Reports\ReportDocument;
use App\Services\Reports\ReportExporter;
use App\Services\WorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 21 — committee members vote on an agenda item, then the committee
 * head records the outcome, which drives WorkflowService::transition()
 * directly (STAGE_PLAN's "no manual intervention"). Rides the `decisions`
 * screen's own permissions: `add` (R03/R04) to cast a vote, `approve` (R03)
 * to record the binding decision — the same view/write split MeetingController
 * uses for edit vs. delete on the `meetings` screen.
 *
 * Stage 25 adds the read side the screen was missing: a register of every
 * recorded decision (`view`), the same thing as a file (`export`), and the
 * worklist of items the signed-in member still owes a vote on. The write path
 * above is unchanged — the worklist's vote buttons post back to `vote()`.
 */
class DecisionController extends Controller
{
    /**
     * Column headings and outcome labels for the exported register.
     *
     * Server-side strings, deliberately not in the SPA's locale files: the
     * file is rendered here and the browser never sees them.
     */
    private const LABELS = [
        'ar' => [
            'title' => 'سجل القرارات والتوصيات',
            'generated' => 'تاريخ الإصدار',
            'filters' => 'عوامل التصفية',
            'no_filters' => 'بدون تصفية — كل القرارات',
            'outcome' => 'النتيجة',
            'committee' => 'اللجنة',
            'date_from' => 'من تاريخ',
            'date_to' => 'إلى تاريخ',
            'search' => 'بحث',
            'total' => 'إجمالي القرارات',
            'outcomes' => [
                'approve' => 'تمت الموافقة',
                'reject' => 'تم الرفض',
                'defer' => 'تم التأجيل',
                'conditional_approval' => 'اعتماد مشروط',
                'legal_opinion' => 'طلب رأي قانوني',
                'refer_other_body' => 'إحالة لجهة أخرى',
                'no_jurisdiction' => 'عدم اختصاص',
                // Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary.
                'appeal_accept' => 'قبول التظلم',
                'appeal_partial_accept' => 'قبول جزئي',
                'appeal_reject' => 'رفض التظلم',
                'appeal_refer' => 'إحالة التظلم',
                'appeal_redo' => 'إعادة الإجراءات',
            ],
            'columns' => [
                'الرقم المرجعي', 'الموضوع', 'اللجنة', 'الاجتماع', 'تاريخ الاجتماع',
                'النتيجة', 'موافق', 'رافض', 'مؤجل', 'مشروط', 'رأي قانوني', 'إحالة', 'عدم اختصاص',
                'قبول التظلم', 'قبول جزئي', 'رفض التظلم', 'إحالة التظلم', 'إعادة الإجراءات', 'ممتنع',
                'القالب', 'صاحب القرار', 'تاريخ القرار', 'الملاحظات',
            ],
            'none' => '—',
        ],
        'en' => [
            'title' => 'Decisions & Recommendations Register',
            'generated' => 'Generated',
            'filters' => 'Filters',
            'no_filters' => 'No filters — all decisions',
            'outcome' => 'Outcome',
            'committee' => 'Committee',
            'date_from' => 'From',
            'date_to' => 'To',
            'search' => 'Search',
            'total' => 'Total decisions',
            'outcomes' => [
                'approve' => 'Approved',
                'reject' => 'Rejected',
                'defer' => 'Deferred',
                'conditional_approval' => 'Conditionally Approved',
                'legal_opinion' => 'Legal Opinion Requested',
                'refer_other_body' => 'Referred to Another Body',
                'no_jurisdiction' => 'Outside Jurisdiction',
                // Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary.
                'appeal_accept' => 'Appeal Accepted',
                'appeal_partial_accept' => 'Partially Accepted',
                'appeal_reject' => 'Appeal Rejected',
                'appeal_refer' => 'Appeal Referred',
                'appeal_redo' => 'Procedures Redone',
            ],
            'columns' => [
                'Reference', 'Subject', 'Committee', 'Meeting', 'Meeting date',
                'Outcome', 'Approve', 'Reject', 'Defer', 'Conditional', 'Legal opinion', 'Referred', 'No jurisdiction',
                'Appeal accepted', 'Appeal partial', 'Appeal rejected', 'Appeal referred', 'Appeal redo', 'Abstain',
                'Template', 'Decided by', 'Decided at', 'Comment',
            ],
            'none' => '—',
        ],
    ];

    /**
     * How a vote outcome maps onto the workflow_transitions row seeded at
     * stage 7 (`receive_from_committee`). `reject` reuses the existing R03
     * self-loop `cancel` exception rather than inventing a second terminal
     * outcome; `defer` is the new Stage 21 self-loop.
     */
    // Stage 35 — three richer outcomes alongside the original three, each
    // still mapping onto one workflow_transitions row at stage 7. See
    // WorkflowTransitionSeeder and this stage's AGENT_NOTES entry for why
    // none of the three new ones need a signature.
    // Stage 49 — a seventh outcome, `no_jurisdiction`, on the same terms.
    private const ACTIONS = [
        'approve' => 'approve',
        'reject' => 'cancel',
        'defer' => 'defer',
        'conditional_approval' => 'conditional_approve',
        'legal_opinion' => 'request_legal_opinion',
        'refer_other_body' => 'refer_to_another_body',
        'no_jurisdiction' => 'declare_no_jurisdiction',
    ];

    /**
     * Stage 63, Track J — Art. 75 point 5's five-outcome appeal vocabulary.
     * Deliberately a flat list, not a map onto a workflow action like ACTIONS
     * above: an appeal never touches WorkflowService (Track J intro's scope
     * decision (2) — its own status machine, not the 14-stage
     * workflow_stages table). Recording one of these always advances the
     * appeal from `legal_review` to `committee_presentation`; which outcome
     * won only matters to Stage 64's later, deliberately separate, execution
     * step.
     */
    public const APPEAL_OUTCOMES = [
        'appeal_accept', 'appeal_partial_accept', 'appeal_reject', 'appeal_refer', 'appeal_redo',
    ];

    public function vote(
        StoreVoteRequest $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        DecisionEligibility $eligibility,
    ): JsonResponse {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $actor = $request->user();

        // Stage 25 — the three eligibility rules moved into a service so the
        // pending-votes worklist can be built from the same predicate. If this
        // guard and that query ever disagreed, the worklist would offer items
        // this endpoint refuses.
        if ($reason = $eligibility->reasonBlockingVote($agendaItem, $actor)) {
            return response()->json(['message' => $reason], 422);
        }

        $vote = Vote::updateOrCreate(
            ['meeting_request_id' => $agendaItem->id, 'user_id' => $actor->id],
            [
                'vote' => $request->validated('vote'),
                'comment' => $request->validated('comment'),
                'voted_at' => now(),
            ],
        );

        return (new VoteResource($vote->load('user:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Tally the votes by simple plurality and let the winning outcome drive
     * the workflow transition directly. Ties and zero-vote tallies are
     * rejected rather than guessed at — the head can wait for more votes or
     * ask for a re-vote instead.
     *
     * WorkflowService re-checks the actor's role and the transition's own
     * requires_comment/signature rules under its row lock, so this method
     * does not duplicate that validation.
     */
    public function record(
        StoreDecisionRequest $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        WorkflowService $workflow,
        ApprovalSignatureStorage $signatureStorage,
        NotificationDispatcher $notifications,
    ): JsonResponse {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        // Stage 63 — an appeal item never runs through WorkflowService (Track
        // J's own status machine instead); its five-outcome vocabulary and
        // "commit" step are different enough to live in their own method
        // rather than being threaded through the branches below.
        if ($agendaItem->item_type === 'appeal') {
            return $this->recordAppealDecision($request, $agendaItem);
        }

        // Stage 31 — an admin/emerging item has no request for
        // WorkflowService::transition() to move.
        if ($agendaItem->item_type !== 'employee_request') {
            return response()->json([
                'message' => 'لا يمكن تسجيل قرار على بند غير مرتبط بطلب.',
            ], 422);
        }

        if ($agendaItem->decision()->exists()) {
            return response()->json([
                'message' => 'تم تسجيل قرار هذا البند بالفعل.',
            ], 422);
        }

        $counts = Vote::query()
            ->where('meeting_request_id', $agendaItem->id)
            ->selectRaw('vote, count(*) as total')
            ->groupBy('vote')
            ->pluck('total', 'vote');

        $tally = collect(array_keys(self::ACTIONS))
            ->mapWithKeys(fn (string $outcome) => [$outcome => (int) ($counts[$outcome] ?? 0)]);

        // Stage 41 — tallied like any other vote, but never a candidate for
        // the plurality below: it has no ACTIONS entry, so it stays out of
        // $tally entirely and can never drive a workflow transition.
        $abstainCount = (int) ($counts['abstain'] ?? 0);

        $max = $tally->max();
        if ($max === 0) {
            return response()->json([
                'message' => 'لا توجد أصوات مسجلة على هذا البند بعد.',
            ], 422);
        }

        $leaders = $tally->filter(fn (int $count) => $count === $max);
        if ($leaders->count() > 1) {
            return response()->json([
                'message' => 'التصويت متعادل، لا يمكن حسم القرار تلقائياً.',
            ], 422);
        }

        $outcome = $leaders->keys()->first();
        $action = self::ACTIONS[$outcome];
        $comment = $request->validated('comment');
        $templateId = $request->validated('template_id');
        $referralAuthority = $request->validated('referral_authority');
        $actor = $request->user();

        $signaturePath = $action === 'approve' && $request->hasFile('signature')
            ? $signatureStorage->store($request->file('signature'), $agendaItem->request)
            : null;

        try {
            $decision = DB::transaction(function () use (
                $workflow, $agendaItem, $action, $actor, $comment, $templateId, $signaturePath, $outcome, $tally, $abstainCount, $referralAuthority,
            ) {
                $workflow->transition($agendaItem->request, $action, $actor, $comment, $signaturePath);

                // Stage 34 — the live runner's own progress state follows a
                // recorded decision automatically; the runner's manual state
                // endpoint refuses to set 'complete' on a request item
                // directly, so this is the only path that gets it there.
                $agendaItem->update(['item_state' => 'complete', 'state_changed_at' => now()]);

                return Decision::create([
                    'meeting_request_id' => $agendaItem->id,
                    'template_id' => $templateId,
                    'outcome' => $outcome,
                    'votes_approve_count' => $tally['approve'],
                    'votes_reject_count' => $tally['reject'],
                    'votes_defer_count' => $tally['defer'],
                    'votes_conditional_approval_count' => $tally['conditional_approval'],
                    'votes_legal_opinion_count' => $tally['legal_opinion'],
                    'votes_refer_other_body_count' => $tally['refer_other_body'],
                    'votes_no_jurisdiction_count' => $tally['no_jurisdiction'],
                    // Stage 63 — the appeal vocabulary never applies to an
                    // employee_request decision; set explicitly (rather than
                    // left unset) so the immediate response reads 0, not
                    // null, the same as every column above and as a
                    // subsequent fetch would already show via the DB default.
                    'votes_appeal_accept_count' => 0,
                    'votes_appeal_partial_accept_count' => 0,
                    'votes_appeal_reject_count' => 0,
                    'votes_appeal_refer_count' => 0,
                    'votes_appeal_redo_count' => 0,
                    'votes_abstain_count' => $abstainCount,
                    'comment' => $comment,
                    'referral_authority' => $referralAuthority,
                    'decided_by_user_id' => $actor->id,
                    'decided_at' => now(),
                ]);
            });
        } catch (WorkflowTransitionException $exception) {
            $signatureStorage->delete($signaturePath);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // Stage 23 — separate from the stage-change notification the same
        // transition raises: this one names the outcome and the tally, which
        // is the part the requester and the committee actually ask about.
        $notifications->decisionRecorded($agendaItem->request, $decision, $meeting, $actor);

        return (new DecisionResource($decision->load('decidedBy:id,name', 'template:id,code,name_ar,name_en')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Stage 63, Track J — Art. 75 point 5's five-outcome appeal decision.
     * Same plurality-tally shape as record() above, but the "commit" step is
     * entirely different: no WorkflowService call at all, since an appeal
     * never touches the 14-stage workflow_stages table (Track J intro's
     * scope decision (2)) — recording a decision here only ever advances the
     * appeal's own status from `legal_review` to `committee_presentation`.
     * Mutating the *original* request per the chosen outcome is Stage 64's
     * own, deliberately separate, scope.
     *
     * Every outcome requires a non-empty comment (generalizing "رفض مسبب"'s
     * explicit "reasoned" requirement to all five, the same kind of call
     * Stage 62's jurisdiction-test generalization made) and none requires a
     * signature (unlike `approve`, whose signature requirement is a side
     * effect of WorkflowService::APPROVAL_LEVELS gating a real multi-tier
     * approval chain that has no appeal equivalent).
     *
     * No notification is fired here — Stage 65's own Build bullet is
     * explicitly "a new appeal_decided event"; firing a generic
     * decisionRecorded() now would blur into that stage's "final result"
     * semantics and risk notifying the appellant twice.
     */
    private function recordAppealDecision(StoreDecisionRequest $request, MeetingRequest $agendaItem): JsonResponse
    {
        if ($agendaItem->decision()->exists()) {
            return response()->json([
                'message' => 'تم تسجيل قرار هذا البند بالفعل.',
            ], 422);
        }

        $appeal = $agendaItem->appeal;

        // Defensive, not redundant: MeetingController::addAgendaItem() already
        // gates nomination on legal_review, but a second meeting_requests row
        // for the same appeal (Appeal::committeeAgendaItem's own docblock
        // flags this as a known, rare edge case) could otherwise try to
        // re-decide an appeal that already moved past this status.
        if ($appeal === null || $appeal->status?->code !== 'legal_review') {
            return response()->json([
                'message' => 'لا يمكن تسجيل قرار على تظلم لم يجتز المراجعة القانونية بعد.',
            ], 422);
        }

        $counts = Vote::query()
            ->where('meeting_request_id', $agendaItem->id)
            ->selectRaw('vote, count(*) as total')
            ->groupBy('vote')
            ->pluck('total', 'vote');

        $tally = collect(self::APPEAL_OUTCOMES)
            ->mapWithKeys(fn (string $outcome) => [$outcome => (int) ($counts[$outcome] ?? 0)]);
        $abstainCount = (int) ($counts['abstain'] ?? 0);

        $max = $tally->max();
        if ($max === 0) {
            return response()->json([
                'message' => 'لا توجد أصوات مسجلة على هذا البند بعد.',
            ], 422);
        }

        $leaders = $tally->filter(fn (int $count) => $count === $max);
        if ($leaders->count() > 1) {
            return response()->json([
                'message' => 'التصويت متعادل، لا يمكن حسم القرار تلقائياً.',
            ], 422);
        }

        $outcome = $leaders->keys()->first();
        $comment = trim((string) $request->validated('comment'));

        if ($comment === '') {
            return response()->json([
                'message' => 'يجب إثبات سبب قرار اللجنة على التظلم.',
            ], 422);
        }

        $templateId = $request->validated('template_id');
        $referralAuthority = $request->validated('referral_authority');
        $actor = $request->user();

        $decision = DB::transaction(function () use (
            $appeal, $agendaItem, $outcome, $tally, $abstainCount, $comment, $referralAuthority, $templateId, $actor,
        ) {
            $appeal->update([
                'appeal_status_id' => AppealStatus::where('code', 'committee_presentation')->value('id'),
            ]);

            // Stage 34 — mirrors record()'s own progress-tracking side effect.
            $agendaItem->update(['item_state' => 'complete', 'state_changed_at' => now()]);

            return Decision::create([
                'meeting_request_id' => $agendaItem->id,
                'template_id' => $templateId,
                'outcome' => $outcome,
                // The employee_request vocabulary never applies here; set
                // explicitly so the immediate response reads 0, not null —
                // see record()'s matching comment on its own reverse case.
                'votes_approve_count' => 0,
                'votes_reject_count' => 0,
                'votes_defer_count' => 0,
                'votes_conditional_approval_count' => 0,
                'votes_legal_opinion_count' => 0,
                'votes_refer_other_body_count' => 0,
                'votes_no_jurisdiction_count' => 0,
                'votes_appeal_accept_count' => $tally['appeal_accept'],
                'votes_appeal_partial_accept_count' => $tally['appeal_partial_accept'],
                'votes_appeal_reject_count' => $tally['appeal_reject'],
                'votes_appeal_refer_count' => $tally['appeal_refer'],
                'votes_appeal_redo_count' => $tally['appeal_redo'],
                'votes_abstain_count' => $abstainCount,
                'comment' => $comment,
                'referral_authority' => $referralAuthority,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ]);
        });

        return (new DecisionResource($decision->load('decidedBy:id,name', 'template:id,code,name_ar,name_en')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Stage 42 — the interpolated draft for a chosen template, computed from
     * this agenda item's own request/employee/date data rather than the
     * template's static body. The record-decision panel calls this when a
     * template is picked and drops the result into the editable comment box;
     * record() above still just stores whatever comment text is submitted,
     * so nothing here is enforced beyond this preview.
     */
    public function draft(
        ShowDecisionDraftRequest $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        DecisionDraftComposer $composer,
    ): JsonResponse {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->item_type !== 'employee_request') {
            return response()->json([
                'message' => 'لا يمكن صياغة قرار على بند غير مرتبط بطلب.',
            ], 422);
        }

        $template = Template::query()
            ->where('is_active', true)
            ->findOrFail($request->validated('template_id'));

        $meeting->loadMissing('committee:id,name_ar,name_en');
        $agendaItem->setRelation('meeting', $meeting);
        $agendaItem->loadMissing([
            'request.createdBy:id,name',
            'request.department:id,name_ar,name_en',
            'request.requestType:id,name_ar,name_en',
        ]);

        return response()->json([
            'data' => $composer->compose($template, $agendaItem, $request->draftLocale()),
        ]);
    }

    /**
     * Stage 25 — the register: every recorded decision, filterable, away from
     * the meeting that produced it.
     */
    public function index(IndexDecisionRequest $request): AnonymousResourceCollection
    {
        $rows = $this->registerQuery($request->filters())
            ->paginate($request->validated('per_page') ?? 25)
            ->withQueryString();

        return DecisionResource::collection($rows);
    }

    /**
     * Stage 25 — agenda items this user still owes a vote on, across every
     * committee they sit on.
     *
     * Behind `decisions,view` rather than `add`: reading your own worklist is
     * reading. The vote buttons on it are gated by `decisions,add` in the SPA
     * and by the existing vote endpoint's own middleware on the way back in.
     */
    public function pending(Request $request, DecisionEligibility $eligibility): AnonymousResourceCollection
    {
        $items = $eligibility->pendingVotesQuery($request->user())
            ->with([
                'request:id,reference_number,title,status_id',
                'request.status:id,code,name_ar,name_en,color',
                // Stage 63 — the appeal riding an `appeal` item, mirroring the
                // request block above.
                'appeal:id,appellant_user_id,original_request_id,appeal_status_id',
                'appeal.appellant:id,name',
                'appeal.originalRequest:id,reference_number,title',
                'appeal.status:id,code,name_ar,name_en,color',
                'meeting:id,title,scheduled_at,committee_id',
                'meeting.committee:id,name_ar,name_en',
                'votes.user:id,name',
                'conflictDeclarations.user:id,name',
            ])
            ->get();

        return MeetingRequestResource::collection($items);
    }

    /**
     * Lookups travel separately so the filter bar works before any rows do.
     *
     * Stage 35 — `templates` also feeds the record-decision panel on the
     * meeting-detail and live-runner screens: `decisions,view` is seeded
     * `'*'`, the one grant every committee member actually has, unlike the
     * `templates` CRUD screen itself (R08-only).
     */
    public function filters(): JsonResponse
    {
        return response()->json([
            'data' => [
                'committees' => Committee::query()
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar', 'name_en']),
                'outcomes' => IndexDecisionRequest::OUTCOMES,
                'formats' => ReportExporter::FORMATS,
                'templates' => Template::query()
                    ->where('is_active', true)
                    ->where('category', Template::CATEGORY_DECISION)
                    ->orderBy('name_ar')
                    ->get(['id', 'code', 'name_ar', 'name_en', 'subject_ar', 'subject_en', 'body_ar', 'body_en']),
            ],
        ]);
    }

    /**
     * Stage 25 — the register as .xlsx or PDF, reusing Stage 24's writers. A
     * new document, not a new writer: that split is exactly what ReportDocument
     * and ReportExporter were built for.
     */
    public function export(ExportDecisionRequest $request, ReportExporter $exporter): Response
    {
        $filters = $request->filters();
        $locale = $request->exportLocale();
        $labels = self::LABELS[$locale];

        // Not paginated: an export that silently stopped at page one would be
        // worse than no export. The filters bound the size.
        $rows = $this->registerQuery($filters)->get();

        $document = new ReportDocument(
            slug: 'decisions-register',
            title: $labels['title'],
            columns: $labels['columns'],
            rows: $rows->map(fn (Decision $decision) => $this->exportRow($decision, $locale, $labels))->all(),
            meta: $this->metaLines($filters, $labels, $locale),
            summary: $this->summaryPairs($rows, $labels),
            rtl: $locale === 'ar',
        );

        return $exporter->download($document, $request->exportFormat());
    }

    /**
     * The register's one query. Shared by the screen and the export so a
     * forwarded file can never describe a different population than the table
     * it was exported from.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Decision>
     */
    private function registerQuery(array $filters): Builder
    {
        return Decision::query()
            ->with([
                'decidedBy:id,name',
                'template:id,code,name_ar,name_en',
                'meetingRequest:id,meeting_id,request_id,appeal_id',
                'meetingRequest.request:id,reference_number,title',
                // Stage 63 — the appeal riding an `appeal` item, mirroring the
                // request eager-load above.
                'meetingRequest.appeal:id,appellant_user_id,original_request_id,appeal_status_id',
                'meetingRequest.appeal.appellant:id,name',
                'meetingRequest.appeal.originalRequest:id,reference_number,title',
                'meetingRequest.meeting:id,title,scheduled_at,committee_id',
                'meetingRequest.meeting.committee:id,name_ar,name_en',
            ])
            ->when(
                $filters['outcome'] ?? null,
                fn (Builder $query, string $outcome) => $query->where('outcome', $outcome),
            )
            ->when(
                $filters['committee_id'] ?? null,
                fn (Builder $query, $committeeId) => $query->whereHas(
                    'meetingRequest.meeting',
                    fn (Builder $meeting) => $meeting->where('committee_id', $committeeId),
                ),
            )
            ->when(
                $filters['date_from'] ?? null,
                fn (Builder $query, string $from) => $query->whereDate('decided_at', '>=', $from),
            )
            ->when(
                $filters['date_to'] ?? null,
                fn (Builder $query, string $to) => $query->whereDate('decided_at', '<=', $to),
            )
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $term) => $query->where(fn (Builder $matches) => $matches
                    ->whereHas(
                        'meetingRequest.request',
                        fn (Builder $requestRecord) => $requestRecord
                            ->where('reference_number', 'like', "%{$term}%")
                            ->orWhere('title', 'like', "%{$term}%"),
                    )
                    // Stage 63 — an appeal decision's own "reference/subject"
                    // is its original request's, since the appeal itself has
                    // no reference number or title of its own.
                    ->orWhereHas(
                        'meetingRequest.appeal.originalRequest',
                        fn (Builder $requestRecord) => $requestRecord
                            ->where('reference_number', 'like', "%{$term}%")
                            ->orWhere('title', 'like', "%{$term}%"),
                    )),
            )
            ->latest('decided_at');
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<int, string|int|null>
     */
    private function exportRow(Decision $decision, string $locale, array $labels): array
    {
        $agendaItem = $decision->meetingRequest;
        $meeting = $agendaItem?->meeting;
        $committee = $meeting?->committee;
        // Stage 63 — an appeal item's own "reference/subject" is its
        // original request's, since the appeal has no reference number or
        // title of its own.
        $originalRequest = $agendaItem?->request ?? $agendaItem?->appeal?->originalRequest;

        return [
            $originalRequest?->reference_number ?? $labels['none'],
            $originalRequest?->title ?? $labels['none'],
            $this->localName($committee, $locale, $labels),
            $meeting?->title ?? $labels['none'],
            $meeting?->scheduled_at?->format('Y-m-d') ?? $labels['none'],
            $labels['outcomes'][$decision->outcome] ?? $decision->outcome,
            $decision->votes_approve_count,
            $decision->votes_reject_count,
            $decision->votes_defer_count,
            $decision->votes_conditional_approval_count,
            $decision->votes_legal_opinion_count,
            $decision->votes_refer_other_body_count,
            $decision->votes_no_jurisdiction_count,
            $decision->votes_appeal_accept_count,
            $decision->votes_appeal_partial_accept_count,
            $decision->votes_appeal_reject_count,
            $decision->votes_appeal_refer_count,
            $decision->votes_appeal_redo_count,
            $decision->votes_abstain_count,
            $this->localName($decision->template, $locale, $labels),
            $decision->decidedBy?->name ?? $labels['none'],
            $decision->decided_at?->format('Y-m-d H:i') ?? $labels['none'],
            $decision->comment ?? $labels['none'],
        ];
    }

    /**
     * Context lines printed under the title, so a forwarded file still says
     * what it is a register of.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $labels
     * @return array<int, string>
     */
    private function metaLines(array $filters, array $labels, string $locale): array
    {
        $applied = [];

        if ($outcome = $filters['outcome'] ?? null) {
            $applied[] = $labels['outcome'].': '.($labels['outcomes'][$outcome] ?? $outcome);
        }
        if ($id = $filters['committee_id'] ?? null) {
            $applied[] = $labels['committee'].': '.$this->localName(Committee::find($id), $locale, $labels);
        }
        if ($from = $filters['date_from'] ?? null) {
            $applied[] = $labels['date_from'].': '.$from;
        }
        if ($to = $filters['date_to'] ?? null) {
            $applied[] = $labels['date_to'].': '.$to;
        }
        if ($search = $filters['search'] ?? null) {
            $applied[] = $labels['search'].': '.$search;
        }

        return [
            $labels['generated'].': '.now()->format('Y-m-d H:i'),
            $applied === [] ? $labels['no_filters'] : $labels['filters'].' — '.implode(' | ', $applied),
        ];
    }

    /**
     * @param  Collection<int, Decision>  $rows
     * @param  array<string, mixed>  $labels
     * @return array<int, array{label: string, value: string}>
     */
    private function summaryPairs(Collection $rows, array $labels): array
    {
        return [
            ['label' => $labels['total'], 'value' => (string) $rows->count()],
            // Stage 63 — appeal outcomes are a second, independent vocabulary
            // sharing this same column; both need a per-outcome total.
            ...collect([...array_keys(self::ACTIONS), ...self::APPEAL_OUTCOMES])
                ->map(fn (string $outcome) => [
                    'label' => $labels['outcomes'][$outcome],
                    'value' => (string) $rows->where('outcome', $outcome)->count(),
                ])
                ->all(),
        ];
    }

    /** @param  array<string, mixed>  $labels */
    private function localName(mixed $model, string $locale, array $labels): string
    {
        if ($model === null) {
            return $labels['none'];
        }

        $preferred = $locale === 'ar' ? $model->name_ar : $model->name_en;
        $fallback = $locale === 'ar' ? $model->name_en : $model->name_ar;

        return $preferred ?: ($fallback ?: $labels['none']);
    }
}
