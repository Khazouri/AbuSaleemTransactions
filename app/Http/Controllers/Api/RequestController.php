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
use App\Http\Requests\Request\RecordIntakeGateRequest;
use App\Http\Requests\Request\RecordJurisdictionTestRequest;
use App\Http\Requests\Request\RecordStepReviewRequest;
use App\Http\Requests\Request\ReopenRequest;
use App\Http\Requests\Request\ResolveApprovalReturnRequest;
use App\Http\Requests\Request\StoreRequest;
use App\Http\Requests\Request\SuspendRequest;
use App\Http\Requests\Request\TransitionRequest;
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
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Services\ApprovalReferralService;
use App\Services\ApprovalReturnService;
use App\Services\ArtifactNumberGenerator;
use App\Services\CommitteeStatusService;
use App\Services\DocumentReview;
use App\Services\EmployeeNoticeRegister;
use App\Services\EmployeeNoticeService;
use App\Services\EmploymentFilePreparationService;
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
use App\Services\StepChecklistService;
use App\Services\Tasks\MeetingDuties;
use App\Services\Tasks\RequestActs;
use App\Services\WorkflowService;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
     * The paper sitting (user decision 2026-10-18): جهة الاعتماد decides a
     * recommended file from its meeting's page, next to the committee's توصية
     * and the محضر, with its own paper قرار (2026-10-25). The request page
     * and the approval queue therefore refuse the decision, and
     * MeetingResultsController::recordAuthorityDecisions() takes it instead.
     *
     * Only for a file a meeting actually recommended on: one R08 escalated by
     * `deadline_expired` reaches the ministry with no توصية (or only a
     * deferral), so no meeting page can take it, and refusing it here too
     * left it decidable nowhere.
     */
    public static function authorityStageRefusal(Request $requestRecord, string $action): ?string
    {
        return in_array($action, ['approve', 'reject'], true)
            && array_key_exists((string) $requestRecord->currentStage()->value('code'), MeetingDuties::AUTHORITY_STAGES)
            && in_array($requestRecord->latestDecidedItem()->first()?->decision?->outcome, ['approve', 'reject'], true)
            ? 'يُسجَّل قرار جهة الاعتماد من صفحة الاجتماع.'
            : null;
    }

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
        // Workflow designer — slice 4. An outcome an admin marked as waiting
        // on its step's checklist. First, because it is the only gate a custom
        // step has; Art. 105 still comes before it, as at every checkpoint.
        $checklist = app(StepChecklistService::class);
        if ($checklist->gates($requestRecord, $action)) {
            return $requestRecord->openSuspension()->exists()
                ? RequestSuspensionService::BLOCK_MESSAGE
                : $checklist->refusalReason($requestRecord);
        }

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

        return null;
    }

    /**
     * Why an action the actor may otherwise take is being held back, or null.
     * Read by transition() to refuse and by detailResource() both to hide the
     * button and to report it as blocked, so the three cannot drift apart.
     */
    private function blockReason(Request $requestRecord, string $action, bool $hasOpenApprovalReturn): ?string
    {
        if (($meetingPage = self::authorityStageRefusal($requestRecord, $action)) !== null) {
            return $meetingPage;
        }

        // Stage 54 — [D] Art. 45's jurisdiction test must be answered before
        // requirements_check's classifying outcomes (approve / declare no
        // jurisdiction / formal rejection) can be taken; return_missing_docs
        // is exempt, since an incomplete file can't be honestly classified yet.
        if (in_array($action, self::JURISDICTION_TEST_GATED_ACTIONS, true)
            && $requestRecord->currentStage()->value('code') === 'requirements_check'
            && $requestRecord->jurisdiction_test === null) {
            return 'يجب إكمال اختبار الاختصاص (المادة 45) قبل اتخاذ هذا الإجراء.';
        }

        // Stage 77 — [D] Art. 94: a محضر the approving body sent back is not
        // approved onward until the re-processing action has been recorded.
        // Also enforced in ApprovalController::store(), the other way this
        // same `approve` reaches WorkflowService.
        if ($action === 'approve' && $hasOpenApprovalReturn) {
            return self::APPROVAL_RETURN_BLOCK_MESSAGE;
        }

        // Stage 78 — Appendix 63's four control gates, plus Arts. 103 and 105;
        // see controlGateRefusal() for why every site reads one predicate.
        return self::controlGateRefusal($requestRecord, $action);
    }

    /**
     * Stage 66, Track J — [D] Arts. 34–37/78–79: only a request that has
     * genuinely concluded may be re-presented. Mirrors WorkflowService::
     * hasTerminalStatus()'s list minus `in_execution` — a request still
     * being executed hasn't concluded yet (Stage 37's own tracker owns its
     * eventual close), so re-presenting it mid-execution doesn't make sense.
     */
    public const REOPENABLE_STATUS_CODES = ['cancelled', 'archived', 'not_approved', 'completed_closed', 'decision_withdrawn', 'decision_amended'];

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
    private static function actorIsAnInterestedParty(Request $requestRecord, User $actor): bool
    {
        return $requestRecord->created_by_user_id === $actor->id
            || $requestRecord->subject_user_id === $actor->id;
    }

    /**
     * Decision wizard — sub-project 3. Why the actor may not reopen this file,
     * or null — reopen()'s two refusals, in its order, for RequestActs too.
     */
    public static function reopenRefusal(Request $requestRecord, User $actor): ?string
    {
        if (self::actorIsAnInterestedParty($requestRecord, $actor)) {
            return 'لا يجوز لمقدّم الطلب أو صاحب العلاقة إعادة فتح الطلب بنفسه.';
        }

        if (! in_array($requestRecord->status?->code, self::REOPENABLE_STATUS_CODES, true)) {
            return 'لا يمكن إعادة عرض طلب لم تُختتم إجراءاته بعد.';
        }

        return null;
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
    public function intakeOptions(): JsonResponse
    {
        return response()->json([
            'data' => [
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

        // صاحب العلاقة is always the filer: filing on another employee's
        // behalf (Stage 95) was removed by user decision 2026-10-07.
        $subject = $request->user();

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
                $type = RequestType::query()->findOrFail($data['request_type_id']);
                $newStatus = RequestStatus::query()->where('code', 'new')->firstOrFail();
                // Workflow designer — slices 1 and 3. A new request is filed
                // onto the published version of its type's workflow; it then
                // stays on that version's stages whatever is published after.
                $firstStage = ($type->workflow?->publishedVersion ?? Workflow::standard()->publishedVersion)
                    ->stage('receive_from_municipality');
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
                    // The filer's own unit, not a choice: صاحب العلاقة is
                    // always the filer, so the request belongs where they work.
                    'department_id' => $subject->department_id,
                    'request_type_id' => $data['request_type_id'],
                    'status_id' => $newStatus->id,
                    'current_stage_id' => $firstStage->id,
                    'created_by_user_id' => $request->user()->id,
                    'subject_user_id' => $subject->id,
                    'submitted_at' => $submittedAt,
                    'due_date' => $deadlines->dueDateFor($type, $submittedAt),
                    'decision_grade' => $data['decision_grade'] ?? null,
                    'entitled_grade' => $data['entitled_grade'],
                    'grade_date' => $data['grade_date'],
                    // Stage 83 — Appendix 16's classification of a request
                    // raised after an earlier file on the same subject closed.
                    'prior_relation' => $priorRelation,
                    'prior_request_id' => $priorRequestId,
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
                        'original_name' => Attachment::displayName(
                            $requestType, $documentKey, $source['label'], $subject, $source['original_name'],
                        ),
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

        // Stages 54, 77, 78 — the same predicate detailResource() previews
        // with, so a button the SPA offers and this endpoint cannot disagree.
        $blocked = $this->blockReason($requestRecord, $action, $requestRecord->openApprovalReturn()->exists());
        if ($blocked !== null) {
            throw ValidationException::withMessages(['action' => [$blocked]]);
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
     * Stage 54 — record [D] Art. 45's 6-question jurisdiction test. Rides the
     * notes_attachments,edit grant, and carries no stage restriction of its
     * own — it is a working draft that can be revised any time before (or
     * after) requirements_check acts on it.
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
     * The route's `request.visible` gate does not lock R02 out of terminal
     * files: assignment visibility drops them, but RequestVisibility's closer
     * clause still shows R02 a file that is closed, `not_approved` or carries
     * a reference number. That is every reopenable file the committee
     * handled. A file cancelled before the قيد stays its filer's own.
     */
    public function reopen(ReopenRequest $request, Request $requestRecord, WorkflowService $workflow): RequestDetailResource|JsonResponse
    {
        $actor = $request->user();

        if (($reason = self::reopenRefusal($requestRecord, $actor)) !== null) {
            return response()->json(['message' => $reason], 422);
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

        // Stage 100 — the archive records (ملف اللجنة only from before
        // 2026-10-25), for the same reason, and outside
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

        // Stage 78 — a reopened file re-walks whichever gates lie ahead of the
        // stage it lands on, and the previous lap's answers say nothing about
        // this one: `new_document` and `material_error_correction` are two of
        // ReopenReasonCatalog's own six reasons, and each is precisely a claim
        // that a gate 1 answer has changed. Cleared for the same reason the
        // closure card above is.
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

    /**
     * Stage 100 — Appendix 6 row 15: الموارد البشرية «مسؤول ملف الخدمة»; the
     * only archive since 2026-10-25.
     */
    public function archiveServiceFile(
        ArchiveFileRequest $request,
        Request $requestRecord,
        RequestClosureService $closure,
        WorkflowService $workflow,
    ): RequestDetailResource|JsonResponse {
        $requestRecord->loadMissing('status:id,code');

        try {
            // archive() works on its own locked copy, and may close the file.
            $requestRecord = $closure->archive($requestRecord, $request->user(), $request->validated('location'));
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

        if (($reason = $notices->issueRefusal($requestRecord)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        $current = $notices->currentMoment($requestRecord);

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
        $requestRecord->loadMissing('currentStage:id,code');

        // Decision wizard — sub-project 3: the refusal RequestActs reports.
        if (($reason = $returns->resolveRefusal($requestRecord)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        $open = $returns->openReturn($requestRecord);

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
     * test already uses, and carries no stage restriction of its own for the
     * same reason it doesn't: it is a working record the officer builds while the file is
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

        $this->refuseUnpreviewed(app(DocumentReview::class), $requestRecord, $request->validated('documents'), $actor);

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

        $this->refuseUnpreviewed($preparation->review($requestRecord), $requestRecord, $request->validated('documents'), $actor);

        $requestRecord->update([
            'employment_file' => $preparation->record(
                $requestRecord,
                $request->validated('documents'),
                $request->boolean('assembled'),
                $request->boolean('grade_verified'),
            ),
            'employment_file_prepared_by_user_id' => $actor->id,
            'employment_file_prepared_at' => now(),
        ]);

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    /**
     * Workflow designer — slice 4. Record a custom step's checklist.
     *
     * Only someone this step offers a checked outcome may fill it: the card is
     * that person's attestation, and availableTransitions() already keeps the
     * filer and صاحب العلاقة off those outcomes (actorMayUse()), so it keeps
     * them off the card too.
     */
    public function recordStepReview(
        RecordStepReviewRequest $request,
        Request $requestRecord,
        StepChecklistService $checklist,
        WorkflowService $workflow,
        RequestVisibility $visibility,
    ): RequestDetailResource {
        $actor = $request->user();

        abort_unless($visibility->canView($actor, $requestRecord), 404);
        abort_unless($workflow->availableTransitions($requestRecord, $actor)->contains('requires_checks', true), 403);

        $checklist->record($requestRecord, $actor, $request->validated('answers'));

        return $this->detailResource($requestRecord, $workflow, $actor);
    }

    /**
     * Both document gates take a verdict only on a file the reviewer opened in
     * full; the server knows because AttachmentController::previewed()
     * recorded the open, so the client cannot simply claim it.
     *
     * @param  array<int|string, string>  $verdicts
     */
    private function refuseUnpreviewed(DocumentReview $review, Request $requestRecord, array $verdicts, User $actor): void
    {
        $unpreviewed = $review->unpreviewed($requestRecord, $verdicts, $actor);

        if ($unpreviewed !== null) {
            throw ValidationException::withMessages(['documents' => $unpreviewed]);
        }
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
            // `required_document_key` and `file_section` are listed because
            // AttachmentResource exposes both and a restricted eager load
            // omitting a column makes it read back as null rather than as
            // missing — the same silent shape the Stage 63 note records for a
            // partially-selected relation. Both were absent here since the
            // stages that added them (80 and 91), so the request workspace
            // reported every document as unclassified; Stage 88's review step
            // shows the employee exactly these two answers before they file,
            // and the workspace contradicting it a moment later is the bug.
            'attachments:id,request_id,original_name,mime_type,size_bytes,label,required_document_key,file_section,uploaded_by_user_id,created_at',
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
        ]);
        $requestRecord->loadCount('legalReviews');
        // Stage 77 — an open return still holds `approve` back (blockReason()).
        $openApprovalReturn = app(ApprovalReturnService::class)->openReturn($requestRecord);
        // Stage 78 — Appendix 63's four-gate matrix, rendered for this one
        // file. Every value here comes from the same services the endpoints
        // enforce with, so the screen and the refusal can never disagree.
        $requestRecord->setAttribute('control_gates', $this->controlGateState($requestRecord, $actor));
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
        // An approve the actor holds no approval grant for is "not yours", not
        // "not yet", so it is dropped before anything is reported as blocked.
        $mine = $workflow->availableTransitions($requestRecord, $actor)
            ->filter(fn ($rule) => $rule->action !== 'approve'
                || $this->actorCanApproveCurrentLevel($requestRecord, $actor))
            ->values();
        EloquentCollection::make($mine->all())
            ->load(['toStage:id,code,name_ar,name_en', 'setStatus:id,code,name_ar,name_en']);
        $mine = $mine->map(fn ($rule) => [$rule, $this->blockReason($requestRecord, $rule->action, $openApprovalReturn !== null)]);
        $shape = fn ($rule) => [
            'action' => $rule->action,
            'is_exception' => $rule->is_exception,
            'requires_comment' => $rule->requires_comment,
            // Workflow designer — slice 4. The wizard shows the checklist only to someone offered such an outcome.
            'requires_checks' => $rule->requires_checks,
            // Workflow designer — slice 3. A custom outcome has no locale key.
            'label_ar' => $rule->label_ar,
            'label_en' => $rule->label_en,
        ];
        $availableTransitions = $mine->filter(fn (array $pair) => $pair[1] === null)->pluck(0)->values();
        $requestRecord->setAttribute('available_actions', $availableTransitions->pluck('action')->all());
        // Decision wizard — sub-project 1. Where each action takes the file, so
        // the wizard can say so before the click.
        $requestRecord->setAttribute('available_transitions', $availableTransitions->map(fn ($rule) => [
            ...$shape($rule),
            'to_stage' => $rule->toStage?->only(['code', 'name_ar', 'name_en']),
            'to_status' => $rule->setStatus?->only(['code', 'name_ar', 'name_en']),
        ])->values()->all());
        // Decision wizard — sub-project 1. The actions a gate is holding back,
        // with the refusal the endpoint would give, so the wizard can explain
        // a missing button instead of leaving it to be guessed at.
        $requestRecord->setAttribute('blocked_transitions', $mine
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->map(fn (array $pair) => [...$shape($pair[0]), 'reason' => $pair[1]])
            ->values()->all());

        // Decision wizard — sub-project 2. The committee's own moves on this
        // file, offered only when their endpoint would take them. A status
        // move whose origin does not match is "not now", not a gate holding
        // it back, so it is left out rather than reported blocked.
        $committeeStatus = app(CommitteeStatusService::class);
        $committeeActions = [];
        foreach ([
            'require_completion' => ['committee_candidates', true],
            'send_to_legal_review' => ['legal_review', false],
        ] as $action => [$screen, $requiresComment]) {
            if ($actor->hasScreenPermission($screen, 'can_edit') && $committeeStatus->refusal($requestRecord, $action) === null) {
                $committeeActions[] = ['action' => $action, 'requires_comment' => $requiresComment];
            }
        }
        // The legal member's opinion — Art. 21's pre-meeting review or Art.
        // 105's suspension review, i.e. exactly their own queue.
        if ($actor->hasScreenPermission('legal_review', 'can_add')
            && $committeeStatus->legalReviewQueueQuery()->whereKey($requestRecord->id)->exists()) {
            $committeeActions[] = ['action' => 'record_legal_review', 'requires_comment' => false];
        }
        $requestRecord->setAttribute('committee_actions', $committeeActions);

        // Decision wizard — sub-project 3. Every other act on this file, from
        // the refusals the endpoints themselves call.
        $requestRecord->setAttribute('acts', app(RequestActs::class)->forRequest($requestRecord, $actor));

        return new RequestDetailResource($requestRecord);
    }

    /**
     * Stage 78 — [D] Appendix 63's مصفوفة الرقابة الداخلية, per request.
     *
     * Since the paper sitting (2026-10-18) the readiness, محضر and Art. 103
     * gates are gone with the in-system sitting, so what is left is gate 1,
     * HR's employment file and gate 4, which is Stage 75's
     * RequestClosureService in full.
     *
     * @return array<string, mixed>
     */
    private function controlGateState(Request $requestRecord, ?User $viewer): array
    {
        $intake = app(IntakeGateService::class);
        $review = app(DocumentReview::class);
        $employmentFile = app(EmploymentFilePreparationService::class);

        return [
            // Workflow designer — slice 4. A custom step's checklist; null at a step without one.
            'step_checklist' => app(StepChecklistService::class)->forViewer($requestRecord, $viewer),

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
                'review_attachments' => $employmentFile->review($requestRecord)->forViewer($requestRecord, $viewer),
                'missing_required' => collect($employmentFile->missingRequired($requestRecord))
                    ->map(fn (array $document, string $key): array => ['key' => $key, ...$document])
                    ->values(),
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
                'review_attachments' => $review->forViewer($requestRecord, $viewer),
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
            // بوابة 4 — قبل الإقفال, already Stage 75's in full.
            'closure' => ['closed_at' => $requestRecord->closed_at?->toIso8601String()],
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
