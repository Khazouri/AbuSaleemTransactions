<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appeal\StoreAppealRequest;
use App\Http\Requests\Appeal\VerifyAppealRequest;
use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Request as RequestRecord;
use App\Services\AppealEligibility;
use App\Services\AppealVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Stage 59/60, Track J — the `appeals` screen with its real intake rules
 * (App\Services\AppealEligibility) and, since Stage 60, the formal-
 * verification gate (App\Services\AppealVerificationService). Still no
 * outcome execution (Stage 64). See STAGE_PLAN.md Track J and
 * AGENT_NOTES.md for the scope decisions this stage rests on.
 */
class AppealController extends Controller
{
    private const WITH = [
        'appellant:id,name',
        'originalRequest:id,reference_number,title',
        'status:id,code,name_ar,name_en,color',
        'formalVerifiedBy:id,name',
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
}
