<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appeal\StoreAppealRequest;
use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Request as RequestRecord;
use App\Services\AppealEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Stage 59, Track J — the `appeals` screen with its real intake rules:
 * ownership, the decided-status restriction, and the non-duplication check
 * (App\Services\AppealEligibility), plus auto-filling the target decision.
 * Still no jurisdiction gate (Stage 60) or outcome execution (Stage 64). See
 * STAGE_PLAN.md Track J and AGENT_NOTES.md for the scope decisions this
 * stage rests on.
 */
class AppealController extends Controller
{
    private const WITH = [
        'appellant:id,name',
        'originalRequest:id,reference_number,title',
        'status:id,code,name_ar,name_en,color',
    ];

    /**
     * Every appeal is visible on this screen, but scoped: an appellant sees
     * only their own filings, matching the "employee-facing action" framing
     * in the Track J intro. R08 sees everything, the same admin bypass every
     * other scoped list in this app uses. Widen this once a later Track J
     * stage gives staff roles their own review queue.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        $appeals = Appeal::query()
            ->with(self::WITH)
            ->withCount('attachments')
            ->when(
                ! $actor->roles()->where('code', 'R08')->exists(),
                fn ($query) => $query->where('appellant_user_id', $actor->id),
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
}
