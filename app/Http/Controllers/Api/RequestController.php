<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Request\CloseRequest;
use App\Http\Requests\Request\IndexRequest;
use App\Http\Requests\Request\RecordJurisdictionTestRequest;
use App\Http\Requests\Request\ReopenRequest;
use App\Http\Requests\Request\StoreRequest;
use App\Http\Requests\Request\TransitionRequest;
use App\Http\Requests\Request\UpdateFinancialImpactRequest;
use App\Http\Resources\RequestDetailResource;
use App\Http\Resources\RequestResource;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalSignatureStorage;
use App\Services\ArtifactNumberGenerator;
use App\Services\NotificationDispatcher;
use App\Services\ReopenReasonCatalog;
use App\Services\RequestClosureService;
use App\Services\RequestDeadlineService;
use App\Services\RequestVisibility;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stage 11 read model for request work queues.
 *
 * Stage 13 adds the controlled intake write path; workflow actions remain a
 * later concern, leaving this controller responsible only for entering work.
 */
class RequestController extends Controller
{
    /** Stage 54 — requirements_check outcomes that require the jurisdiction test first. */
    private const JURISDICTION_TEST_GATED_ACTIONS = ['approve', 'declare_no_jurisdiction', 'reject_formally'];

    /**
     * Stage 66, Track J — [D] Arts. 34–37/78–79: only a request that has
     * genuinely concluded may be re-presented. Mirrors WorkflowService::
     * hasTerminalStatus()'s list minus `in_execution` — a request still
     * being executed hasn't concluded yet (Stage 37's own tracker owns its
     * eventual close), so re-presenting it mid-execution doesn't make sense.
     */
    private const REOPENABLE_STATUS_CODES = ['cancelled', 'archived', 'not_approved', 'completed_closed', 'decision_withdrawn', 'decision_amended'];

    public function index(IndexRequest $request, RequestVisibility $visibility): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $requests = $visibility->apply(Request::query(), $request->user())
            ->with([
                'department:id,name_ar,name_en,code',
                'requestType:id,code,name_ar,name_en,decision_grade_threshold',
                'status:id,code,name_ar,name_en,color',
                // Stage 52 — target_days_* feed stageTimeliness(); latestStageLog
                // is a single-subquery eager load (latestOfMany), not per-row N+1.
                'currentStage:id,order_no,code,name_ar,name_en,target_days_min,target_days_max',
                // No column restriction here — latestOfMany's generated join
                // needs the full row shape or the subquery's column names
                // collide ("ambiguous column name: request_id").
                'latestStageLog',
            ])
            ->when($filters['status'] ?? null, function ($query, string $status) {
                $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('code', $status));
            })
            ->when($filters['department_id'] ?? null, fn ($query, int $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['type_id'] ?? null, fn ($query, int $typeId) => $query->where('request_type_id', $typeId))
            ->when($filters['date_from'] ?? null, fn ($query, string $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn ($query, string $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($inner) => $inner
                    ->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%"),
            ))
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return RequestResource::collection($requests);
    }

    /** Lookup values travel separately so filters are useful even with no rows. */
    public function filters(): JsonResponse
    {
        return response()->json([
            'data' => [
                'statuses' => RequestStatus::query()
                    ->orderBy('name_ar')
                    ->get(['code', 'name_ar', 'name_en', 'color']),
                'departments' => Department::query()
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar', 'name_en', 'code']),
                'types' => RequestType::query()
                    ->orderBy('name_ar')
                    ->get(['id', 'code', 'name_ar', 'name_en']),
            ],
        ]);
    }

    /** Lookup values for intake are separate from work-queue access rights. */
    public function intakeOptions(): JsonResponse
    {
        return response()->json([
            'data' => [
                'departments' => Department::query()
                    ->where('is_active', true)
                    ->whereNotNull('code')
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar', 'name_en', 'code']),
                'types' => RequestType::query()
                    ->where('is_active', true)
                    ->orderBy('name_ar')
                    ->get(['id', 'code', 'name_ar', 'name_en', 'decision_grade_threshold', 'required_documents']),
            ],
        ]);
    }

    public function store(
        StoreRequest $request,
        ArtifactNumberGenerator $numbers,
        RequestDeadlineService $deadlines,
        NotificationDispatcher $notifications,
        WorkflowService $workflow,
    ): JsonResponse {
        $data = $request->validated();
        $storedPaths = [];

        try {
            $requestRecord = DB::transaction(function () use ($data, $request, $numbers, $deadlines, $workflow, &$storedPaths) {
                $department = Department::query()->findOrFail($data['department_id']);
                $type = RequestType::query()->findOrFail($data['request_type_id']);
                $newStatus = RequestStatus::query()->where('code', 'new')->firstOrFail();
                $firstStage = WorkflowStage::query()->where('code', 'receive_from_municipality')->firstOrFail();
                $submittedAt = now();

                $requestRecord = Request::create([
                    // Stage 70 — [D] Art. 15: handing a request to the direct
                    // manager "لا يعد ... قيدًا للموضوع لدى لجنة شؤون الموظفين",
                    // and Art. 20 grants the رقم إشاري only "بعد ثبوت اكتمال
                    // الملف". So intake mints NO reference_number at all; the
                    // employee gets a receipt instead, and WorkflowService
                    // allocates the real reference when the file reaches
                    // Art. 38's status 06.
                    'intake_receipt_number' => $numbers->nextIntakeReceipt(),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'department_id' => $department->id,
                    'request_type_id' => $data['request_type_id'],
                    'status_id' => $newStatus->id,
                    'current_stage_id' => $firstStage->id,
                    'created_by_user_id' => $request->user()->id,
                    'submitted_at' => $submittedAt,
                    'due_date' => $deadlines->dueDateFor($type, $submittedAt),
                    'decision_grade' => $data['decision_grade'] ?? null,
                    // Stage 47 — starting value only; a study-stage reviewer
                    // can correct it later via updateFinancialImpact().
                    'has_financial_impact' => $type->default_has_financial_impact,
                ]);

                foreach ($request->file('attachments', []) as $index => $attachmentInput) {
                    $file = $attachmentInput['file'];
                    $path = $file->store("attachments/{$requestRecord->id}", 'local');
                    $storedPaths[] = $path;

                    Attachment::create([
                        'request_id' => $requestRecord->id,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                        'label' => $data['attachments'][$index]['label'] ?? null,
                        'uploaded_by_user_id' => $request->user()->id,
                    ]);
                }

                // Intake is the first observable state, so later timelines
                // have a truthful origin rather than starting at stage two.
                RequestStageLog::create([
                    'request_id' => $requestRecord->id,
                    'to_stage_id' => $firstStage->id,
                    'action' => 'intake',
                    'acted_by_user_id' => $request->user()->id,
                    'acted_at' => now(),
                ]);
                RequestStatusHistory::create([
                    'request_id' => $requestRecord->id,
                    'to_status_id' => $newStatus->id,
                    'changed_by_user_id' => $request->user()->id,
                    'changed_at' => now(),
                ]);

                // Diagram-alignment redesign (see AGENT_NOTES.md): intake no
                // longer leaves the request sitting at receive_from_municipality
                // — it hands off into direct_manager_review in the same beat.
                // This is a system hop, not the intake clerk's own workflow
                // action (intake may be performed by any of R01-R06, but the
                // seeded `submit` row is R01-gated), so it goes through
                // applySystemTransition() rather than transition()'s
                // actor-checked path.
                $requestRecord = $workflow->applySystemTransition(
                    $requestRecord,
                    'receive_from_municipality',
                    'submit',
                    $request->user(),
                );

                return $requestRecord->load([
                    'department:id,name_ar,name_en,code',
                    'requestType:id,code,name_ar,name_en,decision_grade_threshold',
                    'status:id,code,name_ar,name_en,color',
                    'currentStage:id,order_no,code,name_ar,name_en',
                ]);
            });
        } catch (Throwable $exception) {
            // DB rollback cannot roll back private disk writes; remove only
            // paths made by this request so failed intake leaves no residue.
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

        // Stage 23 — announced only once the intake request has committed,
        // so nobody is told about a reference number that was rolled back.
        $notifications->requestCreated($requestRecord, $request->user());

        return (new RequestResource($requestRecord))
            ->response()
            ->setStatusCode(201);
    }

    /** Stage 15 — one complete request workspace, including its audit timeline. */
    public function show(HttpRequest $request, Request $requestRecord, WorkflowService $workflow, RequestVisibility $visibility): RequestDetailResource
    {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        return $this->detailResource($requestRecord, $workflow, $request->user());
    }

    /** Stage 15 — adapt the state-machine failure into the SPA's normal 422 shape. */
    public function transition(
        TransitionRequest $request,
        Request $requestRecord,
        WorkflowService $workflow,
        ApprovalSignatureStorage $signatureStorage,
        RequestVisibility $visibility,
    ): RequestDetailResource {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        $action = $request->validated('action');

        // Stage 18 — the older generic workspace endpoint must not become a
        // back door around a revoked approval-screen capability.
        if ($action === 'approve' && ! $this->actorCanApproveCurrentLevel($requestRecord, $request->user())) {
            throw ValidationException::withMessages([
                'action' => ['لا تملك صلاحية الاعتماد في هذه المرحلة.'],
            ]);
        }

        // Stage 54 — [D] Art. 45's jurisdiction test must be answered before
        // requirements_check's classifying outcomes (approve / declare no
        // jurisdiction / formal rejection) can be taken; return_missing_docs
        // is exempt, since an incomplete file can't be honestly classified yet.
        if (in_array($action, self::JURISDICTION_TEST_GATED_ACTIONS, true)
            && $requestRecord->currentStage()->value('code') === 'requirements_check'
            && $requestRecord->jurisdiction_test === null) {
            throw ValidationException::withMessages([
                'action' => ['يجب إكمال اختبار الاختصاص (المادة 45) قبل اتخاذ هذا الإجراء.'],
            ]);
        }

        // Stage 19 — only an approval writes signature evidence; ordinary
        // forwards and exception commands remain compact JSON/form commands.
        $signaturePath = $action === 'approve'
            ? $signatureStorage->store($request->file('signature'), $requestRecord)
            : null;

        try {
            $requestRecord = $workflow->transition(
                $requestRecord,
                $action,
                $request->user(),
                $request->validated('comment'),
                $signaturePath,
            );
        } catch (WorkflowTransitionException $exception) {
            $signatureStorage->delete($signaturePath);

            throw ValidationException::withMessages([
                'action' => [$exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            $signatureStorage->delete($signaturePath);

            throw $exception;
        }

        return $this->detailResource($requestRecord, $workflow, $request->user());
    }

    /**
     * Stage 47 — correct the auto-derived financial-impact flag. Rides the
     * existing notes_attachments,edit grant (R01/R02) rather than a new
     * request_details edit tier: this is a narrow ancillary correction, not
     * general request-field editing.
     */
    public function updateFinancialImpact(
        UpdateFinancialImpactRequest $request,
        Request $requestRecord,
        WorkflowService $workflow,
        RequestVisibility $visibility,
    ): RequestDetailResource {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        $requestRecord->update(['has_financial_impact' => $request->boolean('has_financial_impact')]);

        return $this->detailResource($requestRecord, $workflow, $request->user());
    }

    /**
     * Stage 54 — record [D] Art. 45's 6-question jurisdiction test. Rides the
     * same notes_attachments,edit grant updateFinancialImpact() uses, and
     * carries no stage restriction of its own — it is a working draft that
     * can be revised any time before (or after) requirements_check acts on
     * it, mirroring that method's own precedent.
     */
    public function recordJurisdictionTest(
        RecordJurisdictionTestRequest $request,
        Request $requestRecord,
        WorkflowService $workflow,
        RequestVisibility $visibility,
    ): RequestDetailResource {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        $requestRecord->update(['jurisdiction_test' => $request->validated()]);

        return $this->detailResource($requestRecord, $workflow, $request->user());
    }

    /**
     * Stage 66, Track J — [D] Arts. 34–37/78–79's re-presentation path for a
     * concluded request, independent of any Appeal (Stage 64's appeal_redo
     * already covers the appeal-driven case via a different status —
     * `reopened_by_appeal` — and stays untouched). Rides the same
     * `appeals,edit` grant (R02 + R08) Track J's other post-decision
     * reconsideration actions use on this exact model — Stage 64's
     * executeOutcome() already mutates a Request under that grant — rather
     * than a new `request_details` edit tier, since this is the same class
     * of actor handling the same class of action.
     *
     * Deliberately skips RequestVisibility::canView(): that gate excludes
     * every terminal-status request from a non-creator's assignment-based
     * visibility by design (see its own `$terminalStatusIds` comment), which
     * would 404 the very actor this action exists for. The `appeals,edit`
     * screen permission is the real authorization here, the same way
     * Appeal::isVisibleTo()'s third branch already treats it as sufficient.
     */
    public function reopen(ReopenRequest $request, Request $requestRecord, WorkflowService $workflow): RequestDetailResource|JsonResponse
    {
        $actor = $request->user();

        if ($requestRecord->created_by_user_id === $actor->id) {
            return response()->json([
                'message' => 'لا يجوز لمقدّم الطلب إعادة فتح طلبه بنفسه.',
            ], 422);
        }

        if (! in_array($requestRecord->status?->code, self::REOPENABLE_STATUS_CODES, true)) {
            return response()->json([
                'message' => 'لا يمكن إعادة عرض طلب لم تُختتم إجراءاته بعد.',
            ], 422);
        }

        $validated = $request->validated();
        $targetStage = WorkflowStage::findOrFail($validated['target_stage_id']);
        $note = trim((string) ($validated['note'] ?? ''));
        $reasonText = ReopenReasonCatalog::label($validated['reason_code']).($note !== '' ? ': '.$note : '');

        try {
            $requestRecord = $workflow->reopenAtStage(
                $requestRecord,
                $targetStage,
                $actor,
                $reasonText,
                action: 'reopen',
                statusCode: 'reopened_for_representation',
                enforceBackwardOnly: false,
            );
        } catch (WorkflowTransitionException $exception) {
            throw ValidationException::withMessages(['target_stage_id' => [$exception->getMessage()]]);
        }

        // Stage 75 — a reopened request must not keep carrying the previous
        // lap's Art. 37 closure card, or Stage 75's own one-shot gate would
        // stay stuck refusing a second, genuine closure. Same clearing
        // AppealController::reopen() already does for Stage 65's bookkeeping.
        if ($requestRecord->closed_at !== null) {
            $requestRecord->update([
                'closure' => null,
                'closure_audit' => null,
                'closed_by_user_id' => null,
                'closed_at' => null,
            ]);
        }

        // Stage 76 — the same rule one step earlier, for the same reason: a
        // reopened request that carries the previous lap's execution record
        // would be refused a second, genuine execution by that stage's one-shot
        // gate. The Appendix 70 marks are cleared with it, deliberately: a
        // fresh lap needs fresh دليل التنفيذ, and leaving the marks would let a
        // document produced for a superseded execution satisfy the evidence
        // requirement for one it was never produced for. The documents
        // themselves stay in the file; only their evidence designation goes.
        if ($requestRecord->executed_at !== null) {
            $requestRecord->attachments()
                ->whereNotNull('execution_evidence_type')
                ->update(['execution_evidence_type' => null]);

            $requestRecord->update([
                'execution' => null,
                'execution_checklist' => null,
                'executed_by_user_id' => null,
                'executed_at' => null,
            ]);
        }

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    /**
     * Stage 75 — [D] Art. 37's الإقفال.
     *
     * The sole entry point for Art. 38's code 20. Stage 37/69's meeting-scoped
     * close was removed in favour of this one: Art. 37's second and third final
     * paths (عدم الموافقة، عدم الاختصاص) close requests that may never have
     * ridden a committee agenda at all, so a meeting-scoped action cannot serve
     * them, and two writers of the same terminal status is the dual-path trap
     * Stage 54's jurisdiction gate had to design around.
     *
     * The refusal check runs here and again inside the service's own lock —
     * an appeal can be filed between the two.
     */
    public function close(
        CloseRequest $request,
        Request $requestRecord,
        RequestClosureService $closure,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();

        $requestRecord->loadMissing('status:id,code');

        $validated = $request->validated();
        $audit = $validated['audit'];

        if (($reason = $closure->refusalReason($requestRecord, $audit)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        try {
            $requestRecord = $closure->close($requestRecord, $actor, $validated, $audit);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    private function detailResource(Request $requestRecord, WorkflowService $workflow, $actor): RequestDetailResource
    {
        $requestRecord->load([
            'department:id,name_ar,name_en,code',
            // Stage 56 — default_administrative_route feeds the SPA's
            // suggested-route badge on the administrative_routing screen.
            'requestType:id,code,name_ar,name_en,decision_grade_threshold,default_administrative_route,required_documents',
            'status:id,code,name_ar,name_en,color',
            // Stage 52 — target_days_* feed stageTimeliness(); latestStageLog
            // gives it the current-stage entry timestamp without re-deriving
            // it from the full stageLogs history loaded below.
            'currentStage:id,order_no,code,name_ar,name_en,target_days_min,target_days_max',
            // No column restriction here — latestOfMany's generated join
            // needs the full row shape or the subquery's column names
            // collide ("ambiguous column name: request_id").
            'latestStageLog',
            'createdBy:id,name',
            // Stage 76 — execution_evidence_type flags which documents are
            // Appendix 70's دليل التنفيذ; omitting it from this restricted
            // list would make AttachmentResource report every document as
            // unmarked on the one screen that shows the execution record.
            'attachments:id,request_id,original_name,mime_type,size_bytes,label,execution_evidence_type,uploaded_by_user_id,created_at',
            'stageLogs' => fn ($query) => $query->orderBy('acted_at')->orderBy('id'),
            'stageLogs.fromStage:id,order_no,code,name_ar,name_en',
            'stageLogs.toStage:id,order_no,code,name_ar,name_en',
            'stageLogs.actedBy:id,name',
            'approvals' => fn ($query) => $query
                ->select([
                    'id',
                    'request_id',
                    'level',
                    'role_id',
                    'approved_by_user_id',
                    'action',
                    'comment',
                    'signature_path',
                    'approved_at',
                ])
                ->orderBy('approved_at')
                ->orderBy('id'),
            'approvals.role:id,code,name_ar,name_en',
            'approvals.approvedBy:id,name',
            // Stage 51 — the latest agenda appearance drives committee_summary.
            'meetingRequests.meeting:id,meeting_number,scheduled_at',
            'meetingRequests.decision:id,meeting_request_id,outcome,decided_at',
            // Stage 68 — no column restriction, same latestOfMany join reason
            // as latestStageLog above.
            'latestLegalReview',
            'latestLegalReview.reviewedBy:id,name',
            // Stage 75 — النموذج 18's مسؤول الإقفال, named on the closure card.
            'closedBy:id,name',
            // Stage 76 — النموذج 17's executing officer.
            'executedBy:id,name',
        ]);
        $requestRecord->loadCount('legalReviews');
        // Stage 75 — Appendix 48's refusal, computed by the same service the
        // close endpoint enforces with, so the screen can never offer a button
        // that endpoint would refuse.
        $requestRecord->setAttribute(
            'closure_refusal',
            app(RequestClosureService::class)->refusalReason($requestRecord),
        );
        $availableTransitions = $workflow->availableTransitions($requestRecord, $actor)
            ->filter(fn ($rule) => $rule->action !== 'approve'
                || $this->actorCanApproveCurrentLevel($requestRecord, $actor))
            // Stage 54 — the preview must agree with transition()'s own gate
            // above, or the SPA could offer a button the endpoint refuses.
            ->filter(fn ($rule) => ! in_array($rule->action, self::JURISDICTION_TEST_GATED_ACTIONS, true)
                || $requestRecord->currentStage()->value('code') !== 'requirements_check'
                || $requestRecord->jurisdiction_test !== null)
            ->values();
        $requestRecord->setAttribute('available_actions', $availableTransitions->pluck('action')->all());
        $requestRecord->setAttribute('available_transitions', $availableTransitions->map(fn ($rule) => [
            'action' => $rule->action,
            'is_exception' => $rule->is_exception,
            'requires_comment' => $rule->requires_comment,
        ])->values()->all());

        return new RequestDetailResource($requestRecord);
    }

    /** Approval screen paired with each of Stage 18's six checkpoints. */
    private function actorCanApproveCurrentLevel(Request $requestRecord, User $actor): bool
    {
        // Stage 57 — competent_authority no longer exists as an approval
        // checkpoint (see AGENT_NOTES.md); local_governance_ministry's
        // approve now hands straight to final_approval_archiving.
        $screenByStage = [
            'requirements_check' => 'reviewer_approval',
            'receive_from_committee' => 'committee_head_approval',
            'approval_by_authority' => 'admin_manager_approval',
            'local_governance_ministry' => 'ministry_approval',
            'final_approval_archiving' => 'final_approval',
        ];
        $stageCode = $requestRecord->currentStage()->value('code');
        $screenCode = $screenByStage[$stageCode] ?? null;

        return $screenCode === null || $actor->hasScreenPermission($screenCode, 'can_approve');
    }
}
