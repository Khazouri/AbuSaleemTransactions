<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appeal\CloseAppealRequest;
use App\Http\Requests\Appeal\ExecuteAppealOutcomeRequest;
use App\Http\Requests\Appeal\RecordAppealJurisdictionTestRequest;
use App\Http\Requests\Appeal\RecordAppealLegalReviewRequest;
use App\Http\Requests\Appeal\ReopenAppealRequest;
use App\Http\Requests\Appeal\StoreAppealRequest;
use App\Http\Requests\Appeal\VerifyAppealRequest;
use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Request as RequestRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\AppealEligibility;
use App\Services\AppealFileCompiler;
use App\Services\AppealOutcomeExecutor;
use App\Services\AppealVerificationService;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stage 59/60/61/62/64/65, Track J — the `appeals` screen with its real
 * intake rules (App\Services\AppealEligibility), the formal-verification
 * gate (App\Services\AppealVerificationService), the assembled
 * original-matter dossier (App\Services\AppealFileCompiler), the
 * jurisdiction test + legal review that gate Stage 63's committee
 * presentation, Stage 64's execution of that committee decision's real
 * effect on the original Request (App\Services\AppealOutcomeExecutor),
 * Stage 65's notification + closure record, and Stage 66's enumerated-
 * reason-only reopen of a closed appeal. See STAGE_PLAN.md Track J and
 * AGENT_NOTES.md for the scope decisions this stage rests on.
 */
class AppealController extends Controller
{
    /**
     * Stages this list refuses to name as an `appeal_redo` target — see
     * WorkflowService::REDO_EXCLUDED_STAGE_CODES for why. Kept here too,
     * as the literal set redoStageOptions() excludes from the picker, so
     * the UI never offers a stage executeOutcome() would refuse.
     */
    private const REDO_EXCLUDED_STAGE_CODES = [
        'receive_from_municipality', 'direct_manager_review', 'administrative_routing', 'receive_and_register',
        // Stage 102 — off the path: nothing leaves them, so a file reopened
        // there would be stranded.
        'reviewer_review', 'observations', 'forward_to_committee',
    ];

    private const WITH = [
        'appellant:id,name',
        'originalRequest:id,reference_number,title',
        'status:id,code,name_ar,name_en,color',
        'formalVerifiedBy:id,name',
        'jurisdictionTestedBy:id,name',
        'legalReviewedBy:id,name',
        'outcomeExecutedBy:id,name',
        'outcomeRedoStage:id,code,name_ar,name_en',
        'committeeAgendaItem.decision:id,meeting_request_id,outcome,comment,decided_at',
        'closedBy:id,name',
        'reopenedBy:id,name',
    ];

    /**
     * Every appeal is visible on this screen, but scoped: an appellant sees
     * only their own filings, matching the "employee-facing action" framing
     * in the Track J intro. R08, and — since Stage 60 — anyone holding this
     * screen's `edit` grant (the formal-verification action), see
     * everything: a verifier has to be able to find appeals filed by other
     * people to act on them, so visibility has to agree with write-capability
     * here. `status` narrows the list to one AppealStatus code, so a
     * verifier can pull up exactly the `submitted` worklist.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        $appeals = Appeal::query()
            ->with(self::WITH)
            ->withCount('attachments')
            ->when(
                ! $actor->roles()->where('code', 'R08')->exists() && ! $actor->hasScreenPermission('appeals', 'can_edit'),
                fn ($query) => $query->where('appellant_user_id', $actor->id),
            )
            ->when(
                $request->query('status'),
                fn ($query, $status) => $query->whereHas('status', fn ($q) => $q->where('code', $status)),
            )
            ->latest()
            ->paginate(20);

        return AppealResource::collection($appeals);
    }

    /**
     * The filer is always the acting user — never a client-supplied id.
     * `original_decision_id` is likewise never client-supplied: it is
     * derived here from the target's latest committee appearance.
     */
    public function store(StoreAppealRequest $request, AppealEligibility $eligibility): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validated();

        /** @var RequestRecord $originalRequest */
        $originalRequest = RequestRecord::query()
            ->with('status:id,code')
            ->findOrFail($validated['original_request_id']);

        $reason = $eligibility->reasonBlockingAppeal($originalRequest, $actor, $validated['new_facts_declaration'] ?? null);

        if ($reason !== null) {
            throw ValidationException::withMessages(['original_request_id' => [$reason]]);
        }

        $decision = $eligibility->latestDecisionFor($originalRequest);

        if ($decision === null && trim((string) ($validated['original_decision_reference'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'original_decision_reference' => ['هذا الطلب لا يرتبط بقرار مسجّل؛ يجب إدخال مرجع القرار يدوياً.'],
            ]);
        }

        $appeal = Appeal::create([
            ...$validated,
            'original_decision_id' => $decision?->id,
            'appellant_user_id' => $actor->id,
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
        ]);

        return (new AppealResource(
            $appeal->loadCount('attachments')->load(self::WITH),
        ))->response()->setStatusCode(201);
    }

    /**
     * Stage 60 — the formal-verification gate. A one-shot action: only an
     * appeal still at `submitted` can be verified, and the appellant may not
     * verify their own filing (same self-action caution as WorkflowService's
     * existing block on a creator approving their own request). Three of the
     * four checks are the verifier's own attestation; the fourth (the
     * statutory deadline) is computed, never client-supplied — see
     * AppealVerificationService.
     */
    public function verify(VerifyAppealRequest $request, Appeal $appeal, AppealVerificationService $verification): AppealResource|JsonResponse
    {
        $actor = $request->user();

        if ($appeal->appellant_user_id === $actor->id) {
            return response()->json([
                'message' => 'لا يجوز للمتظلم التحقق من تظلمه بنفسه.',
            ], 422);
        }

        if ($appeal->status?->code !== 'submitted') {
            return response()->json([
                'message' => 'لا يمكن إجراء التحقق الشكلي إلا لتظلم في حالة تقديم التظلم.',
            ], 422);
        }

        $validated = $request->validated();
        $deadlineMet = $verification->deadlineMet($appeal);

        $checks = [
            'appellant_standing' => (bool) $validated['appellant_standing'],
            'valid_target_decision' => (bool) $validated['valid_target_decision'],
            'deadline_met' => $deadlineMet,
            'non_duplication' => (bool) $validated['non_duplication'],
        ];

        $passed = $checks['appellant_standing']
            && $checks['valid_target_decision']
            && $checks['non_duplication']
            && $checks['deadline_met'] !== false;

        $reason = trim((string) ($validated['reason'] ?? ''));

        if (! $passed && $reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['يجب بيان سبب رفض التظلم عند عدم اجتياز أي شرط من شروط التحقق الشكلي.'],
            ]);
        }

        $appeal->update([
            'formal_verification_checks' => $checks,
            'formal_verification_reason' => $reason !== '' ? $reason : null,
            'formal_verified_by_user_id' => $actor->id,
            'formal_verified_at' => now(),
            'appeal_status_id' => AppealStatus::where('code', $passed ? 'formal_verification' : 'rejected')->value('id'),
        ]);

        return new AppealResource(
            $appeal->fresh()->loadCount('attachments')->load(self::WITH),
        );
    }

    /**
     * Stage 62 — Art. 77's jurisdiction test. One-shot, only from
     * `formal_verification` (the appeal has passed admissibility but not yet
     * had its jurisdiction tested), same self-action block as verify(). Any
     * answer other than `committee` terminates the appeal immediately as
     * `outside_jurisdiction` — Art. 77 is explicit about the
     * disciplinary/court case, and the same "the committee is not the right
     * venue" logic is generalized to the other three named alternatives,
     * since none of them are the committee either.
     */
    public function recordJurisdictionTest(
        RecordAppealJurisdictionTestRequest $request,
        Appeal $appeal,
    ): AppealResource|JsonResponse {
        $actor = $request->user();

        if ($appeal->appellant_user_id === $actor->id) {
            return response()->json([
                'message' => 'لا يجوز للمتظلم إجراء اختبار الاختصاص على تظلمه بنفسه.',
            ], 422);
        }

        if ($appeal->status?->code !== 'formal_verification') {
            return response()->json([
                'message' => 'لا يمكن إجراء اختبار الاختصاص إلا لتظلم اجتاز التحقق الشكلي.',
            ], 422);
        }

        $competentBody = $request->validated('competent_body');

        $appeal->update([
            'jurisdiction_test' => ['competent_body' => $competentBody],
            'jurisdiction_tested_by_user_id' => $actor->id,
            'jurisdiction_tested_at' => now(),
            'appeal_status_id' => AppealStatus::where(
                'code',
                $competentBody === 'committee' ? 'file_assembly' : 'outside_jurisdiction',
            )->value('id'),
        ]);

        return new AppealResource(
            $appeal->fresh()->loadCount('attachments')->load(self::WITH),
        );
    }

    /**
     * Stage 62 — Art. 75 point 4's legal-review checklist. One-shot, only
     * from `file_assembly` (jurisdiction already confirmed with the
     * committee), same self-action block as verify()/recordJurisdictionTest().
     * The 5 answers are informational for Stage 63/64's later outcome
     * selection — only the record's presence (and the status it advances to)
     * gates progression to committee presentation.
     */
    public function recordLegalReview(
        RecordAppealLegalReviewRequest $request,
        Appeal $appeal,
    ): AppealResource|JsonResponse {
        $actor = $request->user();

        if ($appeal->appellant_user_id === $actor->id) {
            return response()->json([
                'message' => 'لا يجوز للمتظلم إجراء المراجعة القانونية على تظلمه بنفسه.',
            ], 422);
        }

        if ($appeal->status?->code !== 'file_assembly') {
            return response()->json([
                'message' => 'لا يمكن إجراء المراجعة القانونية إلا بعد اجتياز اختبار الاختصاص.',
            ], 422);
        }

        $appeal->update([
            'legal_review' => $request->validated(),
            'legal_reviewed_by_user_id' => $actor->id,
            'legal_reviewed_at' => now(),
            'appeal_status_id' => AppealStatus::where('code', 'legal_review')->value('id'),
        ]);

        return new AppealResource(
            $appeal->fresh()->loadCount('attachments')->load(self::WITH),
        );
    }

    /**
     * Stage 61 — the assembled original-matter dossier: the original
     * request, its presentation memo, its meeting-minutes excerpt, its
     * decision, whatever notification evidence genuinely exists, and the
     * appeal's own documents, all in one place. Visibility is the same
     * per-instance predicate index() already applies as a query condition
     * (Appeal::isVisibleTo) — a stranger gets 404, not an empty payload.
     */
    public function file(Request $request, Appeal $appeal, AppealFileCompiler $compiler): JsonResponse
    {
        abort_unless($appeal->isVisibleTo($request->user()), 404);

        return response()->json(['data' => $compiler->compile($appeal)]);
    }

    /**
     * Stage 64 — the stages an `appeal_redo` outcome may target, narrower
     * than the full 12-stage catalogue. A narrow lookup, not the full
     * resource's own visibility rule — same "picker, not a general list"
     * precedent MeetingController::appealOptions()/departmentOptions()
     * already set.
     */
    public function redoStageOptions(): JsonResponse
    {
        return response()->json([
            'data' => WorkflowStage::query()
                ->whereNotIn('code', self::REDO_EXCLUDED_STAGE_CODES)
                ->orderBy('order_no')
                ->get(['id', 'code', 'order_no', 'name_ar', 'name_en']),
        ]);
    }

    /**
     * Stage 64 — executes Stage 63's already-recorded committee decision:
     * قبول/قبول جزئي/رفض/إحالة are a direct status mutation on the original
     * Request (App\Services\AppealOutcomeExecutor); إعادة الإجراءات is the
     * one outcome that re-enters WorkflowService, at a stage this actor
     * names explicitly since Stage 62's legal-review checklist never
     * recorded one. One-shot, same self-action block as every other Track
     * J action on this model.
     */
    public function executeOutcome(
        ExecuteAppealOutcomeRequest $request,
        Appeal $appeal,
        AppealOutcomeExecutor $executor,
    ): AppealResource|JsonResponse {
        $actor = $request->user();

        if ($appeal->appellant_user_id === $actor->id) {
            return response()->json([
                'message' => 'لا يجوز للمتظلم تنفيذ نتيجة تظلمه بنفسه.',
            ], 422);
        }

        if ($appeal->status?->code !== 'committee_presentation') {
            return response()->json([
                'message' => 'لا يمكن تنفيذ نتيجة التظلم إلا بعد صدور قرار اللجنة بشأنه.',
            ], 422);
        }

        if ($appeal->outcome_executed_at !== null) {
            return response()->json([
                'message' => 'تم تنفيذ نتيجة هذا التظلم بالفعل.',
            ], 422);
        }

        $decision = $appeal->committeeAgendaItem?->decision;

        if ($decision === null) {
            return response()->json([
                'message' => 'لا يوجد قرار مسجل لهذا التظلم بعد.',
            ], 422);
        }

        $redoStage = null;

        if ($decision->outcome === 'appeal_redo') {
            $redoStageId = $request->validated('redo_stage_id');

            if ($redoStageId === null) {
                throw ValidationException::withMessages([
                    'redo_stage_id' => ['يجب تحديد المرحلة التي وقع فيها العيب لإعادة الإجراءات إليها.'],
                ]);
            }

            $redoStage = WorkflowStage::find($redoStageId);

            if ($redoStage !== null && in_array($redoStage->code, self::REDO_EXCLUDED_STAGE_CODES, true)) {
                throw ValidationException::withMessages([
                    'redo_stage_id' => ['لا يمكن إعادة الإجراءات إلى هذه المرحلة.'],
                ]);
            }
        }

        try {
            DB::transaction(function () use ($appeal, $decision, $actor, $redoStage, $executor) {
                $executor->execute($appeal, $decision->outcome, $actor, $redoStage);

                $appeal->update([
                    'outcome_executed_by_user_id' => $actor->id,
                    'outcome_executed_at' => now(),
                    'outcome_redo_stage_id' => $redoStage?->id,
                ]);
            });
        } catch (WorkflowTransitionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return new AppealResource(
            $appeal->fresh()->loadCount('attachments')->load(self::WITH),
        );
    }

    /**
     * Stage 65 — Art. 75 point 6: written notice of the final result, plus
     * [D] Arts. 34–37's closure-field list. One-shot, same self-action block
     * as every other Track J action. Reachable from either terminal branch
     * (`rejected` at Stage 60, `outside_jurisdiction` at Stage 62 — neither
     * of which ever reaches a committee vote) or from `committee_presentation`
     * once Stage 64 has already executed the outcome — an appeal cannot be
     * closed on a decision that hasn't taken effect yet.
     *
     * `final_result_code` and `notice_status` are derived here, never
     * client-supplied: the former is already known (the branch status, or
     * the committee's own recorded outcome), and the latter is a plain fact
     * about whether the appellant's account can currently receive anything.
     * Setting `appeal_status_id` to `notified_closed` is what releases
     * Appeal::openAgainst()'s hold on the original request's own closure
     * (Track J intro, scope decision (3)).
     */
    public function close(
        CloseAppealRequest $request,
        Appeal $appeal,
        NotificationDispatcher $notifications,
    ): AppealResource|JsonResponse {
        $actor = $request->user();

        if ($appeal->appellant_user_id === $actor->id) {
            return response()->json([
                'message' => 'لا يجوز للمتظلم إغلاق تظلمه بنفسه.',
            ], 422);
        }

        $statusCode = $appeal->status?->code;
        $terminalBranch = in_array($statusCode, ['rejected', 'outside_jurisdiction'], true);
        $decidedAndExecuted = $statusCode === 'committee_presentation' && $appeal->outcome_executed_at !== null;

        if (! $terminalBranch && ! $decidedAndExecuted) {
            return response()->json([
                'message' => 'لا يمكن إغلاق التظلم إلا بعد انتهاء إجراءاته: رفض شكلي، أو عدم اختصاص، أو تنفيذ قرار اللجنة، ولم يُغلق بعد.',
            ], 422);
        }

        $finalResultCode = $terminalBranch ? $statusCode : $appeal->committeeAgendaItem?->decision?->outcome;

        $appellantActive = $appeal->appellant_user_id !== null
            && User::query()->whereKey($appeal->appellant_user_id)->where('is_active', true)->exists();

        $validated = $request->validated();

        $appeal->update([
            'closure' => [
                'final_result_code' => $finalResultCode,
                'final_decision_number' => $validated['final_decision_number'] ?? null,
                'approving_body' => $validated['approving_body'],
                'execution_date' => $validated['execution_date'] ?? null,
                'executing_body' => $validated['executing_body'] ?? null,
                'file_storage_location' => $validated['file_storage_location'],
                'notice_status' => $appellantActive ? 'notified' : 'appellant_unreachable',
            ],
            'closed_by_user_id' => $actor->id,
            'closed_at' => now(),
            'appeal_status_id' => AppealStatus::where('code', 'notified_closed')->value('id'),
        ]);

        $notifications->appealDecided($appeal->fresh()->load('originalRequest:id,reference_number'), $actor);

        return new AppealResource(
            $appeal->fresh()->loadCount('attachments')->load(self::WITH),
        );
    }

    /**
     * Stage 66 — [D] Arts. 78–79's non-reopening rule: only an appeal that
     * has actually concluded (`notified_closed` — Stage 65's own definition
     * of "closed", per Appeal::openAgainst()) may be reopened, and only for
     * one of App\Services\ReopenReasonCatalog's enumerated reasons. Same
     * self-action block as every other Track J action.
     *
     * Resumes at `formal_verification`, not `submitted`: Stage 60's
     * standing/deadline checks already happened and a new document/legal-
     * status change doesn't put those back in question, so reopening skips
     * straight to the next real checkpoint (Stage 62's jurisdiction test)
     * rather than re-running the whole pipeline. Clears the prior lap's
     * terminal bookkeeping (outcome execution, closure) so Stage 64/65's
     * one-shot gates don't stay stuck refusing a fresh pass through
     * committee_presentation.
     */
    public function reopen(ReopenAppealRequest $request, Appeal $appeal): AppealResource|JsonResponse
    {
        $actor = $request->user();

        if ($appeal->appellant_user_id === $actor->id) {
            return response()->json([
                'message' => 'لا يجوز للمتظلم إعادة فتح تظلمه بنفسه.',
            ], 422);
        }

        if ($appeal->status?->code !== 'notified_closed') {
            return response()->json([
                'message' => 'لا يمكن إعادة فتح تظلم لم يُغلق بعد.',
            ], 422);
        }

        $validated = $request->validated();

        $appeal->update([
            'appeal_status_id' => AppealStatus::where('code', 'formal_verification')->value('id'),
            'reopened_at' => now(),
            'reopened_by_user_id' => $actor->id,
            'reopen_reason_code' => $validated['reason_code'],
            'reopen_reason_note' => $validated['note'] ?? null,
            'outcome_executed_at' => null,
            'outcome_executed_by_user_id' => null,
            'outcome_redo_stage_id' => null,
            'closure' => null,
            'closed_by_user_id' => null,
            'closed_at' => null,
        ]);

        return new AppealResource(
            $appeal->fresh()->loadCount('attachments')->load(self::WITH),
        );
    }
}
