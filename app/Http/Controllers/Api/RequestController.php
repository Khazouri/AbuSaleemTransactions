<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Request\ArchiveFileRequest;
use App\Http\Requests\Request\CloseRequest;
use App\Http\Requests\Request\IndexRequest;
use App\Http\Requests\Request\LiftSuspensionRequest;
use App\Http\Requests\Request\PrepareEmploymentFileRequest;
use App\Http\Requests\Request\RecordApprovalReferralRequest;
use App\Http\Requests\Request\RecordApprovalReferralResultRequest;
use App\Http\Requests\Request\RecordApprovalReturnRequest;
use App\Http\Requests\Request\RecordExecutionSoundnessRequest;
use App\Http\Requests\Request\RecordIntakeGateRequest;
use App\Http\Requests\Request\RecordJurisdictionTestRequest;
use App\Http\Requests\Request\ReopenRequest;
use App\Http\Requests\Request\ResolveApprovalReturnRequest;
use App\Http\Requests\Request\StoreRequest;
use App\Http\Requests\Request\SuspendRequest;
use App\Http\Requests\Request\TransitionRequest;
use App\Http\Requests\Request\UpdateFinancialImpactRequest;
use App\Http\Resources\RequestDetailResource;
use App\Http\Resources\RequestResource;
use App\Models\ApprovalReferral;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestDraft;
use App\Models\RequestDraftAttachment;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalReferralService;
use App\Services\ApprovalReturnService;
use App\Services\ArtifactNumberGenerator;
use App\Services\EmployeeNoticeRegister;
use App\Services\EmployeeNoticeService;
use App\Services\EmploymentFilePreparationService;
use App\Services\ExecutionSoundnessService;
use App\Services\IntakeGateService;
use App\Services\Lifecycle\DuplicatePolicy;
use App\Services\Lifecycle\SpecialCaseRules;
use App\Services\NotificationDispatcher;
use App\Services\Performance\TimeCardCompiler;
use App\Services\ReopenReasonCatalog;
use App\Services\RequestClosureService;
use App\Services\RequestDeadlineService;
use App\Services\RequestSuspensionService;
use App\Services\RequestTimelineCompiler;
use App\Services\RequestVisibility;
use App\Services\WorkflowService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
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
     * Stage 77 — the refusal both `approve` entry points and the detail
     * screen's own preview filter share, so a button the SPA offers and the
     * endpoint behind it can never disagree about an open return.
     */
    public const APPROVAL_RETURN_BLOCK_MESSAGE = 'لا يعتمد المحضر المعاد من جهة الاعتماد قبل إثبات إجراء إعادة المعالجة.';

    /**
     * Stage 78 — [D] Appendix 63's own sentence, enforced: "وتمنع المنظومة
     * الإلكترونية الانتقال إذا كانت متطلبات البوابة غير مكتملة".
     *
     * One predicate, read by all three sites that must agree — this
     * controller's transition(), ApprovalController::store() (the other way
     * the same `approve` reaches WorkflowService) and detailResource()'s
     * preview filter, so the SPA can never offer a button either endpoint
     * refuses. The dual-path trap Stages 54, 70 and 77 each had to close the
     * same way.
     *
     * Returns null when the action may proceed, else the Arabic reason it may
     * not, one message at a time in the order the file meets the gates.
     */
    public static function controlGateRefusal(Request $requestRecord, string $action): ?string
    {
        // Stage 98 — [D] Appendix 6 row 3. The only non-`approve` hop with a
        // gate of its own: HR assembles الملف الوظيفي before registering the
        // file, at the stage R12 already holds. Answered before the `approve`
        // short-circuit below rather than folded into it, because it is a
        // different party checking a different list at a different stage.
        if ($action === EmploymentFilePreparationService::GATED_ACTION) {
            return $requestRecord->currentStage()->value('code') === EmploymentFilePreparationService::GATED_STAGE
                ? app(EmploymentFilePreparationService::class)->refusalReason($requestRecord)
                : null;
        }

        if ($action !== 'approve') {
            return null;
        }

        // Art. 105 first, and at every checkpoint rather than one: "قبل ترتيب
        // أثر جديد عليه" means a suspended file arranges no new effect
        // anywhere, not merely at the stage where the doubt surfaced.
        if ($requestRecord->openSuspension()->exists()) {
            return RequestSuspensionService::BLOCK_MESSAGE;
        }

        // Stage 83 — [D] Appendix 60's two cases that stop the file moving on:
        // an invalid document discovered after the decision *when the recorder
        // answered مؤثر*, and a legislative change *when the recorder set the
        // halt* — each qualified exactly as the appendix qualifies it. The
        // other four special cases block nothing; see SpecialCaseRules.
        if (($specialCase = app(SpecialCaseRules::class)->approveRefusal($requestRecord)) !== null) {
            return $specialCase;
        }

        $stageCode = $requestRecord->currentStage()->value('code');

        // بوابة 1. Appendix 63 calls it "قبل القيد", but since the قيد moved
        // to the receiving body's `register` action the file is already
        // numbered by the time it gets here; this hop is now the entry to the
        // rapporteur's substantive review, which is the boundary this gate can
        // actually guard. See IntakeGateService's docblock for why it could
        // not move with the قيد.
        if ($stageCode === IntakeGateService::GATED_STAGE) {
            return app(IntakeGateService::class)->refusalReason($requestRecord);
        }

        // Art. 103 — "قبل إحالة النتيجة للتنفيذ يتم التحقق من" twelve things.
        if ($stageCode === ExecutionSoundnessService::GATED_STAGE) {
            return app(ExecutionSoundnessService::class)->refusalReason($requestRecord);
        }

        return null;
    }

    /**
     * Stage 66, Track J — [D] Arts. 34–37/78–79: only a request that has
     * genuinely concluded may be re-presented. Mirrors WorkflowService::
     * hasTerminalStatus()'s list minus `in_execution` — a request still
     * being executed hasn't concluded yet (Stage 37's own tracker owns its
     * eventual close), so re-presenting it mid-execution doesn't make sense.
     */
    private const REOPENABLE_STATUS_CODES = ['cancelled', 'archived', 'not_approved', 'completed_closed', 'decision_withdrawn', 'decision_amended'];

    /**
     * Stage 95 — is the actor the filer or صاحب العلاقة?
     *
     * [D] Appendix 19's separation of duties names مقدم الطلب, but the
     * employee a file is ABOUT attesting to its own completeness (or testing
     * its jurisdiction, or reopening it) is the worse of the two cases, so
     * both are refused. WorkflowService carries the same predicate for the
     * approve chain — that one has to, since the transition endpoint and the
     * approval queue both reach it.
     */
    private function actorIsAnInterestedParty(Request $requestRecord, User $actor): bool
    {
        return $requestRecord->created_by_user_id === $actor->id
            || $requestRecord->subject_user_id === $actor->id;
    }

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
    public function intakeOptions(HttpRequest $request): JsonResponse
    {
        // Stage 95 — the صاحب العلاقة picker's options, present only for a
        // caller who may actually name someone else (request_intake,approve).
        // Folded into the payload the intake screen already fetches rather
        // than given a route of its own, and deliberately NOT reusing
        // committees/user-options: Stage 92's membership gate zeroes
        // `meetings,can_view` for anyone with no committee seat, so an R05
        // who sits on none would 403 on it.
        $mayFileForOthers = $request->user()->hasScreenPermission('request_intake', 'can_approve');

        return response()->json([
            'data' => [
                'may_file_for_others' => $mayFileForOthers,
                'subject_options' => $mayFileForOthers
                    ? User::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get(['id', 'name'])
                    : [],
                'departments' => Department::query()
                    ->where('is_active', true)
                    ->whereNotNull('code')
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar', 'name_en', 'code']),
                'types' => RequestType::query()
                    ->where('is_active', true)
                    ->orderBy('name_ar')
                    ->get(['id', 'code', 'name_ar', 'name_en', 'decision_grade_threshold', 'required_documents'])
                    // Each Appendix 57 row with the key an attachment stores
                    // against it. Sent from here because the key is a sha1
                    // slug: hashing it in the browser would be a second
                    // implementation of an identifier that must not drift, and
                    // an async one at that. `required_documents` is left as it
                    // was so the read-only checklist keeps rendering unchanged.
                    ->map(fn (RequestType $type): array => [
                        ...$type->only([
                            'id', 'code', 'name_ar', 'name_en',
                            'decision_grade_threshold', 'required_documents',
                        ]),
                        'document_options' => collect($type->documentOptions())
                            ->map(fn (array $document, string $key): array => ['key' => $key, ...$document])
                            ->values(),
                    ]),
            ],
        ]);
    }

    public function store(
        StoreRequest $request,
        ArtifactNumberGenerator $numbers,
        RequestDeadlineService $deadlines,
        NotificationDispatcher $notifications,
        WorkflowService $workflow,
        DuplicatePolicy $duplicates,
    ): JsonResponse {
        $data = $request->validated();
        $storedPaths = [];

        // Stage 95 — صاحب العلاقة. Defaults to the filer, which is what an
        // ordinary intake means and what every request before this stage
        // was. Naming anyone else needs the grant, checked here rather than
        // in the FormRequest because it is a permission question.
        $subject = User::query()->find($data['subject_user_id'] ?? null) ?? $request->user();

        if (! $subject->is($request->user())
            && ! $request->user()->hasScreenPermission('request_intake', 'can_approve')) {
            throw ValidationException::withMessages([
                'subject_user_id' => ['لا يجوز تقديم طلب نيابة عن موظف آخر.'],
            ]);
        }

        // Stage 88 — a submission from a saved draft. The draft supplies the
        // FILES ONLY; every field above still came in this payload and was
        // validated there, so a stale draft can never file something other
        // than what the review screen just showed. Re-scoped to the caller
        // here as well as in the rule: this is the point where its files are
        // about to be copied into somebody's request.
        $draftId = $data['draft_id'] ?? null;
        $draft = $draftId === null
            ? null
            : RequestDraft::query()
                ->where('created_by_user_id', $request->user()->id)
                ->with('attachments')
                ->find($draftId);

        // Stage 83 — [D] Appendix 16. Checked before anything is written: an
        // open file on the same subject means "لا تنشأ معاملة جديدة", and a
        // closed one means the new request must be classified — with تظلم and
        // إعادة عرض refused outright, because in this system those are an
        // `appeals` row and Stage 66's reopen, not a second request.
        // Appendix 16 searches «برقم الموظف», so the prior files that matter
        // are صاحب العلاقة's, not the filer's — otherwise a clerk who files
        // one promotion could never file the next employee's.
        if (($duplicate = $duplicates->refusalReason($subject, $data)) !== null) {
            throw ValidationException::withMessages(['request_type_id' => [$duplicate]]);
        }

        // Only the two "genuinely new" classifications are ever stored; the
        // other two never reach here.
        $priorRelation = $data['prior_relation'] ?? null;
        $priorRequestId = $priorRelation === null
            ? null
            : $duplicates->priorRequests($subject, (int) $data['request_type_id'])->first()?->getKey();

        try {
            $requestRecord = DB::transaction(function () use ($data, $request, $subject, $numbers, $deadlines, $workflow, $priorRelation, $priorRequestId, $draft, &$storedPaths) {
                $department = Department::query()->findOrFail($data['department_id']);
                $type = RequestType::query()->findOrFail($data['request_type_id']);
                $newStatus = RequestStatus::query()->where('code', 'new')->firstOrFail();
                $firstStage = WorkflowStage::query()->where('code', 'receive_from_municipality')->firstOrFail();
                $submittedAt = now();

                $requestRecord = Request::create([
                    // [D] Art. 15: handing a request to the direct manager
                    // "لا يعد ... قيدًا للموضوع لدى لجنة شؤون الموظفين". So
                    // intake mints NO reference_number at all; the employee
                    // gets a receipt instead, and WorkflowService allocates
                    // the real reference when the file reaches Art. 38's
                    // status 06 — which is now the receiving body's own
                    // `register` action. The submitter is told when that
                    // happens (NotificationDispatcher::referenceAssigned),
                    // because this receipt number stops identifying the file.
                    'intake_receipt_number' => $numbers->nextIntakeReceipt(),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    // Stage 90 — [G]'s «الأسباب», stated separately from the
                    // description it used to fold into.
                    'reasons' => $data['reasons'] ?? null,
                    'department_id' => $department->id,
                    'request_type_id' => $data['request_type_id'],
                    'status_id' => $newStatus->id,
                    'current_stage_id' => $firstStage->id,
                    'created_by_user_id' => $request->user()->id,
                    // Stage 95 — who this file is ABOUT. The filer unless
                    // somebody with the grant named another employee.
                    'subject_user_id' => $subject->id,
                    'submitted_at' => $submittedAt,
                    'due_date' => $deadlines->dueDateFor($type, $submittedAt),
                    'decision_grade' => $data['decision_grade'] ?? null,
                    // Stage 83 — Appendix 16's classification of a request
                    // raised after an earlier file on the same subject closed.
                    'prior_relation' => $priorRelation,
                    'prior_request_id' => $priorRequestId,
                    // Stage 47 — starting value only; a study-stage reviewer
                    // can correct it later via updateFinancialImpact().
                    'has_financial_impact' => $type->default_has_financial_impact,
                ]);

                // Loaded once rather than per file: every attachment on this
                // request answers the same type's matrix.
                $requestType = RequestType::findOrFail($data['request_type_id']);

                foreach ($this->intakeAttachmentSources($request, $data, $draft) as $source) {
                    $documentKey = $source['required_document_key'];
                    $target = "attachments/{$requestRecord->id}";

                    // Stage 88 — a draft's file is COPIED rather than moved,
                    // and its own copy is deleted only after this transaction
                    // commits. A move would leave a rolled-back submission
                    // with the employee's file gone from a draft the database
                    // still says is there; a copy is covered by the same
                    // $storedPaths compensation an inline upload already gets.
                    $path = $source['stored'] === null
                        ? $source['upload']->store($target, 'local')
                        : $this->copyDraftFile($source['stored'], $target);
                    $storedPaths[] = $path;

                    Attachment::create([
                        'request_id' => $requestRecord->id,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => $source['original_name'],
                        'mime_type' => $source['mime_type'],
                        'size_bytes' => $source['size_bytes'],
                        'label' => $source['label'],
                        // Which of the type's [D] Appendix 57 recommended
                        // documents the submitter says this file is — the same
                        // key Stage 78's intake gate answers under, so the
                        // officer's completeness check reads the employee's own
                        // uploads rather than a parallel list.
                        'required_document_key' => $documentKey,
                        // [D] Appendix 14's folder, DERIVED from that answer
                        // rather than asked separately: the seeder declares it
                        // per row, so this is recorded data, not a guess, and
                        // never the default nobody chose.
                        'file_section' => $requestType->sectionForDocument($documentKey),
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

        // Stage 88 — the draft has served its purpose and its files now live
        // on the request. Deleted only here, after the commit: until this
        // point a rollback has to leave the employee's own copy intact, since
        // it is the only one they could resume from.
        if ($draft !== null) {
            Storage::disk('local')->deleteDirectory("request-drafts/{$draft->id}");
            $draft->delete();
        }

        // Stage 23 — announced only once the intake request has committed,
        // so nobody is told about a reference number that was rolled back.
        $notifications->requestCreated($requestRecord, $request->user());

        return (new RequestResource($requestRecord))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Stage 88 — the files this intake is being filed with, from either
     * source, in one shape.
     *
     * Normalised here so the Attachment row written below is identical
     * whichever way the file arrived: an inline multipart upload (every
     * caller before this stage, and anyone without `request_intake,edit`) or
     * a draft the employee built up over several sittings. Two loops writing
     * two nearly-identical rows is how the [D] Appendix 14 derivation or the
     * Appendix 57 key would eventually come to differ between them.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{stored: ?array{string, string}, upload: ?UploadedFile, original_name: string, mime_type: ?string, size_bytes: ?int, label: ?string, required_document_key: ?string}>
     */
    private function intakeAttachmentSources(StoreRequest $request, array $data, ?RequestDraft $draft): array
    {
        if ($draft !== null) {
            return $draft->attachments
                ->map(fn (RequestDraftAttachment $attachment): array => [
                    'stored' => [$attachment->disk, $attachment->path],
                    'upload' => null,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'label' => $attachment->label,
                    'required_document_key' => $attachment->required_document_key,
                ])
                ->all();
        }

        $sources = [];

        foreach ($request->file('attachments', []) as $index => $attachmentInput) {
            $file = $attachmentInput['file'];

            $sources[] = [
                'stored' => null,
                'upload' => $file,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'label' => $data['attachments'][$index]['label'] ?? null,
                'required_document_key' => $data['attachments'][$index]['required_document_key'] ?? null,
            ];
        }

        return $sources;
    }

    /**
     * Copy one draft file into the new request's own directory.
     *
     * The stored basename is already a random hash, so reusing it cannot
     * collide and keeps the two copies traceable to each other while both
     * briefly exist.
     *
     * @param  array{string, string}  $stored
     */
    private function copyDraftFile(array $stored, string $target): string
    {
        [$disk, $path] = $stored;
        $destination = $target.'/'.basename($path);

        Storage::disk($disk)->copy($path, $destination);

        return $destination;
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

        // Stage 77 — [D] Art. 94: a محضر the approving body sent back is not
        // approved onward until the re-processing action has been recorded.
        // Enforced here AND in ApprovalController::store(), which is the other
        // way this same `approve` reaches WorkflowService — gating one alone
        // leaves the other wide open, the dual-path trap Stage 54's own
        // jurisdiction gate had to close the same way.
        if ($action === 'approve' && $requestRecord->openApprovalReturn()->exists()) {
            throw ValidationException::withMessages([
                'action' => [self::APPROVAL_RETURN_BLOCK_MESSAGE],
            ]);
        }
        // Stage 78 — Appendix 63's four control gates, plus Arts. 103 and 105.
        // Repeated verbatim in ApprovalController::store() and in the preview
        // filter below; see controlGateRefusal() for why all three read one
        // predicate.
        if (($gateRefusal = self::controlGateRefusal($requestRecord, $action)) !== null) {
            throw ValidationException::withMessages(['action' => [$gateRefusal]]);
        }

        try {
            $requestRecord = $workflow->transition(
                $requestRecord,
                $action,
                $request->user(),
                $request->validated('comment'),
            );
        } catch (WorkflowTransitionException $exception) {
            throw ValidationException::withMessages([
                'action' => [$exception->getMessage()],
            ]);
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
     *
     * Stage 84 — but never by the person who filed the request. [D] Appendix
     * 19's مصفوفة الفصل بين الصلاحيات is explicit ("لا يكون مقدم الطلب هو
     * معتمد الطلب"), Appendix 6's RACI leaves the الموظف column empty for both
     * فحص اكتمال ملف اللجنة and القيد, and Appendix 45 gives الفحص to المقرر
     * alone. Same block AppealController::recordJurisdictionTest() has carried
     * since Stage 62, and the same shape as reopen()'s own creator refusal.
     */
    public function recordJurisdictionTest(
        RecordJurisdictionTestRequest $request,
        Request $requestRecord,
        WorkflowService $workflow,
        RequestVisibility $visibility,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();

        abort_unless($visibility->canView($actor, $requestRecord), 404);

        // Stage 95 — and not صاحب العلاقة either: Appendix 19's rule names
        // مقدم الطلب, but an employee testing jurisdiction on the matter their
        // own file is ABOUT is the worse of the two cases.
        if ($this->actorIsAnInterestedParty($requestRecord, $actor)) {
            return response()->json([
                'message' => 'لا يجوز لمقدّم الطلب أو صاحب العلاقة إجراء اختبار الاختصاص على الطلب بنفسه.',
            ], 422);
        }

        $requestRecord->update([
            'jurisdiction_test' => $request->validated(),
            'jurisdiction_tested_by_user_id' => $actor->id,
            'jurisdiction_tested_at' => now(),
        ]);

        return $this->detailResource($requestRecord, $workflow, $actor);
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

        if ($this->actorIsAnInterestedParty($requestRecord, $actor)) {
            return response()->json([
                'message' => 'لا يجوز لمقدّم الطلب أو صاحب العلاقة إعادة فتح الطلب بنفسه.',
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

        // Stage 100 — both archive records, for the same reason, and outside
        // the guard above: a file can be archived on a closable status and
        // reopened (not_approved is both) before anyone closed it.
        $requestRecord->update([
            'committee_file_location' => null,
            'committee_file_archived_by_user_id' => null,
            'committee_file_archived_at' => null,
            'service_file_location' => null,
            'service_file_archived_by_user_id' => null,
            'service_file_archived_at' => null,
        ]);

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

        // Stage 78 — a reopened file re-walks whichever gates lie ahead of the
        // stage it lands on, and the previous lap's answers say nothing about
        // this one: `new_document` and `material_error_correction` are two of
        // ReopenReasonCatalog's own six reasons, and each is precisely a claim
        // that a gate 1 or Art. 103 answer has changed. Cleared for the same
        // reason the closure and execution cards above are.
        //
        // The Art. 105 suspensions are deliberately NOT cleared: like Stage
        // 77's returns they are a register whose whole value is that earlier
        // rounds stay readable, and an open one cannot survive a reopen in
        // practice — `execution_suspended` is not a REOPENABLE_STATUS_CODES
        // entry, so a reopened request only ever carries resolved rounds.
        //
        // Stage 84 — `jurisdiction_test` is cleared here too, which it was
        // not before. Art. 45's answers are the other half of gate 1, and
        // leaving them behind meant a reopened file arrived already
        // satisfying the `!== null` gate on `requirements_check → approve`
        // using the previous lap's answers — exactly the staleness clearing
        // the other two records was meant to prevent.
        //
        // Stage 98 — and HR's employment-file card for the same reason: a
        // reopened file re-walks `receive_and_register`, and the previous
        // lap's card would let it register on an assembly nobody re-checked
        // against whatever the reopen reason changed.
        if ($requestRecord->intake_gate !== null
            || $requestRecord->execution_soundness !== null
            || $requestRecord->employment_file !== null
            || $requestRecord->jurisdiction_test !== null) {
            $requestRecord->update([
                'employment_file' => null,
                'employment_file_prepared_by_user_id' => null,
                'employment_file_prepared_at' => null,
                'jurisdiction_test' => null,
                'jurisdiction_tested_by_user_id' => null,
                'jurisdiction_tested_at' => null,
                'intake_gate' => null,
                'intake_gate_checked_by_user_id' => null,
                'intake_gate_checked_at' => null,
                'execution_soundness' => null,
                'execution_soundness_checked_by_user_id' => null,
                'execution_soundness_checked_at' => null,
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
     *
     * Stage 92 — rides `meeting_outputs,approve` (R02 + R03 + R12), not `edit`:
     * closure's `executing_body` field is the same [F] step 10 question
     * execute() answers, so the acting grant now matches it, reaching R12 (HR
     * Manager) alongside R02/R03.
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
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    /** Stage 100 — Appendix 6 row 15: المقرر «مسؤول ملف اللجنة». */
    public function archiveCommitteeFile(
        ArchiveFileRequest $request,
        Request $requestRecord,
        RequestClosureService $closure,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        return $this->recordArchive($request, $requestRecord, $closure, $workflow, 'committee');
    }

    /** Stage 100 — Appendix 6 row 15: الموارد البشرية «مسؤول ملف الخدمة». */
    public function archiveServiceFile(
        ArchiveFileRequest $request,
        Request $requestRecord,
        RequestClosureService $closure,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        return $this->recordArchive($request, $requestRecord, $closure, $workflow, 'service');
    }

    /** @param  'committee'|'service'  $file */
    private function recordArchive(
        ArchiveFileRequest $request,
        Request $requestRecord,
        RequestClosureService $closure,
        WorkflowService $workflow,
        string $file,
    ): RequestDetailResource|JsonResponse {
        $requestRecord->loadMissing('status:id,code');

        try {
            $closure->archive($requestRecord, $request->user(), $file, $request->validated('location'));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->detailResource($requestRecord, $workflow, $request->user());
    }

    /**
     * Stage 100 — [D] Appendix 6 row 14: المقرر is «مسؤول إجرائيًا» for
     * Art. 101's notices.
     *
     * The notices themselves stay automatic — RequestStatusNoticeObserver is
     * what makes one impossible to forget. This is the human half: المقرر
     * issues the notice for the file's CURRENT state in their own name, for
     * what the automatic send cannot cover (an employee whose account was
     * inactive when it fired, a notice to be repeated on request). The moment
     * comes from the same EmployeeNoticeService mapping the observer uses, so
     * المقرر cannot issue a notice the file's state does not warrant.
     */
    public function issueNotice(
        HttpRequest $request,
        Request $requestRecord,
        EmployeeNoticeService $notices,
        NotificationDispatcher $dispatcher,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();
        $current = $notices->currentMoment($requestRecord);

        if ($current === null) {
            return response()->json(['message' => 'لا تستوجب حالة المعاملة الحالية إشعارًا وفق المادة 101.'], 422);
        }

        if (! $requestRecord->subject?->is_active) {
            return response()->json(['message' => 'لا يمكن إشعار صاحب العلاقة: لا يوجد له حساب مفعّل.'], 422);
        }

        $dispatcher->requestNotice(
            $requestRecord,
            $current['moment'],
            $notices->contextFor($requestRecord, $current['moment'], $current['reason']),
            issuedBy: $actor->name,
        );

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    /**
     * Stage 77 — [D] Art. 94's سبب الإعادة half: the approving body sent the
     * file back, and Appendix 34 classifies the return as شكلية or موضوعية.
     *
     * Status-only. Art. 94 is explicit that an approved محضر is never quietly
     * amended — "بل ينشأ إجراء إعادة معالجة" — so recording the return does not
     * move the file anywhere; resolveApprovalReturn() below is the action that
     * does, and it routes per the kind recorded here.
     *
     * Rides `meeting_outputs,edit` (R02 + R03), the grant that already owns the
     * post-decision follow-up actions. Art. 30 addresses this register to مقرر
     * اللجنة, which is R02's own role name.
     */
    public function recordApprovalReturn(
        RecordApprovalReturnRequest $request,
        Request $requestRecord,
        ApprovalReturnService $returns,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();
        $requestRecord->loadMissing(['status:id,code', 'currentStage:id,code']);
        $validated = $request->validated();

        if (($reason = $returns->refusalReason($requestRecord)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        // Appendix 34's own classification of the reason must agree with the
        // kind the recorder chose; only `other` is free either way, since both
        // of the appendix's lists are introduced with "مثل".
        if (($mismatch = $returns->kindMismatch($validated['return_kind'], $validated['return_reason_code'])) !== null) {
            return response()->json(['message' => $mismatch], 422);
        }

        try {
            $returns->record($requestRecord, $actor, $validated);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->detailResource($requestRecord->refresh(), $workflow, $actor);
    }

    /**
     * Stage 77 — [D] Art. 94's الإجراء الذي اتخذ بشأنها half, and the routing
     * Appendix 34 attaches to its own classification.
     *
     * Where the file goes is not a parameter: a شكلية return is re-referred to
     * the same body the مقرر corrected it for, and a موضوعية one goes back to
     * the committee ("لا يعدل المقرر القرار من تلقاء نفسه"). Letting the
     * resolver pick would let a substantive remark be answered by the مقرر
     * alone, which is the one thing the appendix rules out.
     */
    public function resolveApprovalReturn(
        ResolveApprovalReturnRequest $request,
        Request $requestRecord,
        ApprovalReturnService $returns,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();
        $open = $returns->openReturn($requestRecord);

        if ($open === null) {
            return response()->json([
                'message' => 'لا توجد إعادة من جهة الاعتماد بانتظار إثبات الإجراء المتخذ بشأنها.',
            ], 422);
        }

        try {
            $requestRecord = $returns->resolve(
                $requestRecord,
                $open,
                $actor,
                $request->validated('resolution_action'),
            );
        } catch (WorkflowTransitionException|DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    /**
     * Stage 80 — [D] Art. 30's outward register entry: the committee's result
     * has been referred to an approving body, and the مقرر records تاريخ
     * الإحالة · رقم كتاب الإحالة · الجهة المحال إليها.
     *
     * Deliberately a record, never a gate. The article says "ويسجل مقرر
     * اللجنة", not "ولا يحال قبل" — nothing refuses the approval transition
     * because this has not been entered, and Appendix 63's four control gates
     * are Stage 78's own scope.
     *
     * Rides `meeting_outputs,edit` (R02 + R03), the same grant Stage 77's
     * return register uses and for the same reason: Art. 30 addresses this
     * register to مقرر اللجنة, which is R02's own RoleSeeder name.
     */
    public function recordApprovalReferral(
        RecordApprovalReferralRequest $request,
        Request $requestRecord,
        ApprovalReferralService $referrals,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();
        $requestRecord->loadMissing(['status:id,code']);

        if (($reason = $referrals->refusalReason($requestRecord)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        try {
            $referrals->record($requestRecord, $actor, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->detailResource($requestRecord->refresh(), $workflow, $actor);
    }

    /**
     * Stage 80 — [D] Art. 30's inward register entry: what the approving body
     * answered, recorded against the referral it answered.
     *
     * A second write rather than three more fields on the first form, because
     * nobody knows تاريخ ورود النتيجة or رقم قرار الاعتماد at the moment the
     * file leaves — the same two-moment shape Stage 77's return register has.
     */
    public function recordApprovalReferralResult(
        RecordApprovalReferralResultRequest $request,
        Request $requestRecord,
        ApprovalReferral $referral,
        ApprovalReferralService $referrals,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        abort_unless($referral->request_id === $requestRecord->id, 404);

        $actor = $request->user();

        try {
            $referrals->recordResult($referral, $actor, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->detailResource($requestRecord->refresh(), $workflow, $actor);
    }

    /**
     * Stage 78 — record [D] Appendix 63's بوابة 1 (قبل القيد): one answer per
     * document Appendix 57 requires for this request's type, plus Appendix
     * 20's own attestation that the facts themselves are sound.
     *
     * Rides the same `notes_attachments,edit` grant Stage 54's jurisdiction
     * test and Stage 47's financial-impact correction already use, and
     * carries no stage restriction of its own for the same reason those two
     * don't: it is a working record the officer builds while the file is
     * being checked, and `requirements_check → approve` is what reads it.
     *
     * Stage 84 — never by the person who filed the request. This is the
     * completeness attestation the قيد hangs on, and [D] Appendix 19 forbids
     * مقدم الطلب from being معتمد الطلب; Appendix 6's RACI makes فحص اكتمال
     * ملف اللجنة مقرر اللجنة's alone, with the الموظف column empty. R01 also
     * lost `notes_attachments,edit` in the same stage, so this block is the
     * second of two — it additionally covers an R02 who filed on someone's
     * behalf and would otherwise be checking their own work.
     */
    public function recordIntakeGate(
        RecordIntakeGateRequest $request,
        Request $requestRecord,
        IntakeGateService $gate,
        WorkflowService $workflow,
        RequestVisibility $visibility,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();

        abort_unless($visibility->canView($actor, $requestRecord), 404);

        if ($this->actorIsAnInterestedParty($requestRecord, $actor)) {
            return response()->json([
                'message' => 'لا يجوز لمقدّم الطلب أو صاحب العلاقة إثبات اكتمال الملف بنفسه.',
            ], 422);
        }

        $requestRecord->loadMissing('requestType:id,required_documents');

        $requestRecord->update([
            'intake_gate' => $gate->record(
                $requestRecord,
                $request->validated('documents'),
                $request->boolean('facts_verified'),
            ),
            'intake_gate_checked_by_user_id' => $actor->id,
            'intake_gate_checked_at' => now(),
        ]);

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    /**
     * Stage 98 — record [D] Appendix 6 row 3's تجهيز الملف الوظيفي: one answer
     * per service-file row of Appendix 57's matrix, plus HR's own attestation
     * that the file is assembled.
     *
     * Rides `notes_attachments,add` rather than `edit`. R12 holds `add`
     * (Stage 87); `edit` is R02's alone after Stage 84, and taking it would
     * hand HR فحص اكتمال ملف اللجنة — row 4's cell, not row 3's. No stage
     * restriction of its own, for the same reason Stage 78's gate has none:
     * it is a working record HR builds while the file is in front of them,
     * and `receive_and_register → register` is what reads it.
     *
     * `add` also covers R01, so the interested-party refusal here is
     * load-bearing rather than defensive: row 3 gives الموظف a literal `—`,
     * so neither the filer nor صاحب العلاقة may attest to their own
     * employment file.
     */
    public function prepareEmploymentFile(
        PrepareEmploymentFileRequest $request,
        Request $requestRecord,
        EmploymentFilePreparationService $preparation,
        WorkflowService $workflow,
        RequestVisibility $visibility,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();

        abort_unless($visibility->canView($actor, $requestRecord), 404);

        if ($this->actorIsAnInterestedParty($requestRecord, $actor)) {
            return response()->json([
                'message' => 'لا يجوز لمقدّم الطلب أو صاحب العلاقة تجهيز ملفه الوظيفي بنفسه.',
            ], 422);
        }

        $requestRecord->update([
            'employment_file' => $preparation->record(
                $requestRecord,
                $request->validated('documents'),
                $request->boolean('assembled'),
            ),
            'employment_file_prepared_by_user_id' => $actor->id,
            'employment_file_prepared_at' => now(),
        ]);

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    /**
     * Stage 78 — record [D] Art. 103's قائمة فحص سلامة القرار, the twelve
     * things verified "قبل إحالة النتيجة للتنفيذ".
     *
     * Only the four attested checks are accepted from the caller; the other
     * eight are read from real state by the service, so a spoofed answer to
     * one of them never reaches the record — Art. 104's "صحة المستند
     * والاختصاص ليستا إجراءات شكلية" is the reason.
     *
     * Rides `meeting_outputs,edit` (R02 + R03) rather than R07's own
     * `final_approval` grant, deliberately: the file is prepared by the مقرر
     * who holds it and referred to execution by the authority who approves it,
     * which keeps preparation and decision in different hands — Appendix 9's
     * third and ninth prohibited practices are the same principle.
     */
    public function recordExecutionSoundness(
        RecordExecutionSoundnessRequest $request,
        Request $requestRecord,
        ExecutionSoundnessService $soundness,
        WorkflowService $workflow,
    ): RequestDetailResource {
        $actor = $request->user();
        $requestRecord->loadMissing('status:id,code');

        $requestRecord->update([
            'execution_soundness' => $soundness->record($requestRecord, $request->validated('checks')),
            'execution_soundness_checked_by_user_id' => $actor->id,
            'execution_soundness_checked_at' => now(),
        ]);

        return $this->detailResource($requestRecord->refresh(), $workflow, $actor);
    }

    /**
     * Stage 78 — [D] Art. 105: "يوقف التنفيذ فورًا من الناحية الإجرائية ويحال
     * الموضوع للمراجعة القانونية والجهة المختصة قبل ترتيب أثر جديد عليه".
     *
     * Status-only, exactly like Stage 77's return: nothing has moved, the file
     * is held where it stands. Rides `meeting_outputs,edit` (R02 + R03), the
     * grant that already owns every post-decision follow-up register.
     */
    public function suspend(
        SuspendRequest $request,
        Request $requestRecord,
        RequestSuspensionService $suspensions,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();
        $requestRecord->loadMissing('status:id,code');

        if (($reason = $suspensions->refusalReason($requestRecord)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        try {
            $suspensions->suspend($requestRecord, $actor, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->detailResource($requestRecord->refresh(), $workflow, $actor);
    }

    /**
     * Stage 78 — lift an Art. 105 suspension once the legal review it mandates
     * has actually reported back.
     *
     * Where the file goes is not a parameter, for Stage 77's reason: the two
     * outcomes carry their own routing, so "the doubt was cleared" cannot be
     * paired with a move to the committee, nor the reverse.
     */
    public function liftSuspension(
        LiftSuspensionRequest $request,
        Request $requestRecord,
        RequestSuspensionService $suspensions,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        $actor = $request->user();
        $open = $suspensions->openSuspension($requestRecord);

        if (($reason = $suspensions->liftRefusalReason($requestRecord, $open)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        try {
            $requestRecord = $suspensions->lift(
                $requestRecord,
                $open,
                $actor,
                $request->validated('resolution_action'),
                $request->validated('resolution_note'),
            );
        } catch (WorkflowTransitionException|DomainException $exception) {
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
            'subject:id,name',
            // Stage 76 — execution_evidence_type flags which documents are
            // Appendix 70's دليل التنفيذ; omitting it from this restricted
            // list would make AttachmentResource report every document as
            // unmarked on the one screen that shows the execution record.
            // `required_document_key` and `file_section` are listed because
            // AttachmentResource exposes both and a restricted eager load
            // omitting a column makes it read back as null rather than as
            // missing — the same silent shape the Stage 63 note records for a
            // partially-selected relation. Both were absent here since the
            // stages that added them (80 and 91), so the request workspace
            // reported every document as unclassified; Stage 88's review step
            // shows the employee exactly these two answers before they file,
            // and the workspace contradicting it a moment later is the bug.
            'attachments:id,request_id,original_name,mime_type,size_bytes,label,required_document_key,file_section,execution_evidence_type,uploaded_by_user_id,created_at',
            'stageLogs' => fn ($query) => $query->orderBy('acted_at')->orderBy('id'),
            // `responsible_role_id` is in these column lists because the
            // nested responsibleRole eager-load below cannot resolve without
            // its own foreign key — the restricted-select gotcha Stage 52 and
            // Stage 74 both recorded.
            'stageLogs.fromStage:id,order_no,code,name_ar,name_en,responsible_role_id',
            'stageLogs.toStage:id,order_no,code,name_ar,name_en,responsible_role_id',
            // Stage 80 — Art. 100's الجهة: the acting user's own department,
            // with the stage's seeded responsible role as the fallback for a
            // system move that has no actor at all.
            'stageLogs.actedBy:id,name,department_id',
            'stageLogs.actedBy.department:id,name_ar,name_en',
            'stageLogs.fromStage.responsibleRole:id,name_ar,name_en',
            'stageLogs.toStage.responsibleRole:id,name_ar,name_en',
            'approvals' => fn ($query) => $query
                ->select([
                    'id',
                    'request_id',
                    'level',
                    'role_id',
                    'approved_by_user_id',
                    'action',
                    'comment',
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
            // Stage 100 — Appendix 6 row 15's two archive owners.
            'committeeFileArchivedBy:id,name',
            'serviceFileArchivedBy:id,name',
            // Stage 76 — النموذج 17's executing officer.
            'executedBy:id,name',
            // Stage 77 — every round of Art. 94's إجراء إعادة معالجة, oldest
            // first, since Art. 98's own register is a register of returns.
            'approvalReturns' => fn ($query) => $query->orderBy('id'),
            'approvalReturns.returnedFromStage:id,code,name_ar,name_en',
            'approvalReturns.resolutionTargetStage:id,code,name_ar,name_en',
            'approvalReturns.recordedBy:id,name',
            'approvalReturns.resolvedBy:id,name',
            // Stage 80 — every entry in Art. 30's سجل الإحالات للاعتماد,
            // oldest first, for the same reason approvalReturns is a history.
            'approvalReferrals' => fn ($query) => $query->orderBy('id'),
            'approvalReferrals.referredFromStage:id,code,name_ar,name_en',
            'approvalReferrals.recordedBy:id,name',
            'approvalReferrals.resultRecordedBy:id,name',
            // Stage 78 — every round of Art. 105's إيقاف إجرائي, oldest
            // first, for the same reason approvalReturns is a history.
            'suspensions' => fn ($query) => $query->orderBy('id'),
            'suspensions.suspendedFromStatus:id,code,name_ar,name_en',
            'suspensions.suspendedBy:id,name',
            'suspensions.resolvedBy:id,name',
            'jurisdictionTestedBy:id,name',
            'intakeGateCheckedBy:id,name',
            'employmentFilePreparedBy:id,name',
            'executionSoundnessCheckedBy:id,name',
        ]);
        $requestRecord->loadCount('legalReviews');
        // Stage 75 — Appendix 48's refusal, computed by the same service the
        // close endpoint enforces with, so the screen can never offer a button
        // that endpoint would refuse.
        $requestRecord->setAttribute(
            'closure_refusal',
            app(RequestClosureService::class)->refusalReason($requestRecord),
        );
        // Stage 77 — Appendix 34's own refusal, from the same service the two
        // return endpoints enforce with.
        $approvalReturns = app(ApprovalReturnService::class);
        $requestRecord->setAttribute(
            'approval_return_refusal',
            $approvalReturns->refusalReason($requestRecord),
        );
        $openApprovalReturn = $approvalReturns->openReturn($requestRecord);
        $requestRecord->setAttribute('open_approval_return_id', $openApprovalReturn?->id);
        // Stage 80 — Art. 30's own refusal and the referral still awaiting
        // an answer, from the same service the two referral endpoints
        // enforce with.
        $approvalReferrals = app(ApprovalReferralService::class);
        $requestRecord->setAttribute(
            'approval_referral_refusal',
            $approvalReferrals->refusalReason($requestRecord),
        );
        $requestRecord->setAttribute(
            'open_approval_referral_id',
            $approvalReferrals->openReferral($requestRecord)?->id,
        );
        // Stage 78 — Appendix 63's four-gate matrix, rendered for this one
        // file. Every value here comes from the same services the endpoints
        // enforce with, so the screen and the refusal can never disagree.
        $requestRecord->setAttribute('control_gates', $this->controlGateState($requestRecord));
        // Stage 79 — [D] Art. 101's notices actually delivered for this file.
        // Stage 89 moved the query into EmployeeNoticeRegister so this card and
        // the employee tracking panel read one register rather than two copies
        // of the same question; see that class for why it reads Laravel's own
        // `notifications` rows and why it is honestly the in-app half only.
        $requestRecord->setAttribute(
            'employee_notices',
            app(EmployeeNoticeRegister::class)->for($requestRecord),
        );
        // Stage 80 — [D] Art. 100's six-column السجل الزمني. Computed here
        // rather than in the resource because the linked-document column is
        // derived from three other tables; see RequestTimelineCompiler.
        $requestRecord->setAttribute(
            'art_100_timeline',
            app(RequestTimelineCompiler::class)->compile($requestRecord),
        );
        // Stage 81 — [D] Appendix 71's بطاقة قياس زمن المعاملة, T1–T10.
        // The appendix's own purpose is to locate a delay rather than total
        // one ("بدل اعتبار اللجنة مسؤولة عن كامل مدة المعاملة"), so the card
        // is ten independent segments and T10 is not their sum. Same
        // derivation Art. 106's averages read, so this file's card and the
        // municipality-wide indicator cannot disagree about it.
        $requestRecord->setAttribute(
            'time_card',
            app(TimeCardCompiler::class)->forRequest($requestRecord)->toArray(app()->getLocale() === 'en' ? 'en' : 'ar'),
        );
        $availableTransitions = $workflow->availableTransitions($requestRecord, $actor)
            ->filter(fn ($rule) => $rule->action !== 'approve'
                || $this->actorCanApproveCurrentLevel($requestRecord, $actor))
            // Stage 54 — the preview must agree with transition()'s own gate
            // above, or the SPA could offer a button the endpoint refuses.
            ->filter(fn ($rule) => ! in_array($rule->action, self::JURISDICTION_TEST_GATED_ACTIONS, true)
                || $requestRecord->currentStage()->value('code') !== 'requirements_check'
                || $requestRecord->jurisdiction_test !== null)
            // Stage 77 — the third site that must agree with transition() and
            // ApprovalController::store() about an open return, or the SPA
            // would offer an approve button both of them refuse.
            ->filter(fn ($rule) => $rule->action !== 'approve' || $openApprovalReturn === null)
            // Stage 78 — the same three-site rule, one stage later: the SPA
            // must not offer an approve that Appendix 63's gates refuse.
            ->filter(fn ($rule) => self::controlGateRefusal($requestRecord, $rule->action) === null)
            ->values();
        $requestRecord->setAttribute('available_actions', $availableTransitions->pluck('action')->all());
        $requestRecord->setAttribute('available_transitions', $availableTransitions->map(fn ($rule) => [
            'action' => $rule->action,
            'is_exception' => $rule->is_exception,
            'requires_comment' => $rule->requires_comment,
        ])->values()->all());

        return new RequestDetailResource($requestRecord);
    }

    /**
     * Stage 78 — [D] Appendix 63's مصفوفة الرقابة الداخلية, per request.
     *
     * The four gates are answered from four different owners because they
     * genuinely live in four different places, and two of them predate this
     * stage: gate 2 is Stage 33's MeetingReadinessService (meeting-scoped, so
     * it is reported here only as the agenda placement that reached it) and
     * gate 4 is Stage 75's RequestClosureService in full. Building a second
     * copy of either would be the dual-path trap the rest of this stage is
     * careful to avoid.
     *
     * @return array<string, mixed>
     */
    private function controlGateState(Request $requestRecord): array
    {
        $intake = app(IntakeGateService::class);
        $soundness = app(ExecutionSoundnessService::class);
        $employmentFile = app(EmploymentFilePreparationService::class);

        return [
            // Stage 98 — [D] Appendix 6 row 3. Not one of Appendix 63's four
            // gates, and reported beside them rather than inside `intake`
            // because it is a different party's card: HR assembles الملف
            // الوظيفي before registering, المقرر then checks the committee
            // file at the قيد hop.
            'employment_file' => [
                'prepared_at' => $requestRecord->employment_file_prepared_at?->toIso8601String(),
                'prepared_by' => $requestRecord->employmentFilePreparedBy ? [
                    'id' => $requestRecord->employmentFilePreparedBy->id,
                    'name' => $requestRecord->employmentFilePreparedBy->name,
                ] : null,
                'record' => $requestRecord->employment_file,
                'required_documents' => $employmentFile->requiredDocuments($requestRecord),
                'refusal' => $employmentFile->refusalReason($requestRecord),
            ],
            // بوابة 1 — قبل القيد: هل الملف صالح للدخول إلى مسار اللجنة؟
            'intake' => [
                'recorded_at' => $requestRecord->intake_gate_checked_at?->toIso8601String(),
                'recorded_by' => $requestRecord->intakeGateCheckedBy ? [
                    'id' => $requestRecord->intakeGateCheckedBy->id,
                    'name' => $requestRecord->intakeGateCheckedBy->name,
                ] : null,
                'record' => $requestRecord->intake_gate,
                'required_documents' => $intake->requiredDocuments($requestRecord),
                'refusal' => $intake->refusalReason($requestRecord),
                // Art. 45's test is the other half of this same gate, and it
                // has been enforced on the same hop since Stage 54. Stage 84
                // gave it the same who/when the record above has always had,
                // so both halves of gate 1 now name their author.
                'jurisdiction_test' => [
                    'recorded' => $requestRecord->jurisdiction_test !== null,
                    'recorded_at' => $requestRecord->jurisdiction_tested_at?->toIso8601String(),
                    'recorded_by' => $requestRecord->jurisdictionTestedBy ? [
                        'id' => $requestRecord->jurisdictionTestedBy->id,
                        'name' => $requestRecord->jurisdictionTestedBy->name,
                    ] : null,
                ],
            ],
            // بوابة 3 — قبل الاعتماد lives on the محضر, not on the request:
            // Appendix 8's checks are meeting-wide. Reported as the decision's
            // own meeting so the screen can link to it.
            'minutes' => [
                'checked' => $requestRecord->meetingRequests
                    ->contains(fn ($item) => $item->meeting?->meetingMinutes?->status === 'approved'),
            ],
            // Art. 103 — قبل إحالة النتيجة للتنفيذ.
            'execution_soundness' => [
                'recorded_at' => $requestRecord->execution_soundness_checked_at?->toIso8601String(),
                'recorded_by' => $requestRecord->executionSoundnessCheckedBy ? [
                    'id' => $requestRecord->executionSoundnessCheckedBy->id,
                    'name' => $requestRecord->executionSoundnessCheckedBy->name,
                ] : null,
                'record' => $requestRecord->execution_soundness,
                'derived' => $soundness->derive($requestRecord),
                'refusal' => $soundness->refusalReason($requestRecord),
            ],
            // بوابة 4 — قبل الإقفال, already Stage 75's in full.
            'closure' => [
                'closed_at' => $requestRecord->closed_at?->toIso8601String(),
                'refusal' => $requestRecord->getAttribute('closure_refusal'),
            ],
            // Art. 105's hold, which sits across all of them.
            'suspension' => [
                'refusal' => app(RequestSuspensionService::class)->refusalReason($requestRecord),
                'open_id' => $requestRecord->suspensions->firstWhere('resolved_at', null)?->id,
            ],
        ];
    }

    /** Approval screen paired with each of Stage 18's six checkpoints. */
    private function actorCanApproveCurrentLevel(Request $requestRecord, User $actor): bool
    {
        // Stage 57 — competent_authority no longer exists as an approval
        // checkpoint (see AGENT_NOTES.md); local_governance_ministry's
        // approve now hands straight to final_approval_archiving.
        // One map, three readers — see ApprovalController::LEVELS. This used
        // to be a second copy of the same stage-to-screen fact.
        $screenByStage = collect(ApprovalController::LEVELS)
            ->mapWithKeys(fn (array $level) => [$level['stage'] => $level['screen']])
            ->all();
        $stageCode = $requestRecord->currentStage()->value('code');
        $screenCode = $screenByStage[$stageCode] ?? null;

        return $screenCode === null || $actor->hasScreenPermission($screenCode, 'can_approve');
    }
}
