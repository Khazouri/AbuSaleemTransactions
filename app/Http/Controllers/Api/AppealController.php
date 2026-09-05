<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appeal\RecordAppealJurisdictionTestRequest;
use App\Http\Requests\Appeal\RecordAppealLegalReviewRequest;
use App\Http\Requests\Appeal\StoreAppealRequest;
use App\Http\Requests\Appeal\VerifyAppealRequest;
use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Request as RequestRecord;
use App\Services\AppealEligibility;
use App\Services\AppealFileCompiler;
use App\Services\AppealVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Stage 59/60/61/62, Track J — the `appeals` screen with its real intake
 * rules (App\Services\AppealEligibility), the formal-verification gate
 * (App\Services\AppealVerificationService), the assembled original-matter
 * dossier (App\Services\AppealFileCompiler), and the jurisdiction test +
 * legal review that gate Stage 63's committee presentation. Still no outcome
 * execution (Stage 64). See STAGE_PLAN.md Track J and AGENT_NOTES.md for the
 * scope decisions this stage rests on.
 */
class AppealController extends Controller
{
    private const WITH = [
        'appellant:id,name',
        'originalRequest:id,reference_number,title',
        'status:id,code,name_ar,name_en,color',
        'formalVerifiedBy:id,name',
        'jurisdictionTestedBy:id,name',
        'legalReviewedBy:id,name',
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
}
