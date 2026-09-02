<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appeal\StoreAppealRequest;
use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use App\Models\AppealStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stage 58, Track J — the foundational `appeals` screen: an Appeal row can
 * be created against an existing decided Request and is visible on its own
 * screen. No workflow logic yet — no ownership/decided-status/duplication
 * validation (Stage 59), no jurisdiction gate (Stage 60), no outcome
 * execution (Stage 64). See STAGE_PLAN.md Track J and AGENT_NOTES.md for the
 * scope decisions this stage rests on.
 */
class AppealController extends Controller
{
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
            ->with([
                'appellant:id,name',
                'originalRequest:id,reference_number,title',
                'status:id,code,name_ar,name_en,color',
            ])
            ->when(
                ! $actor->roles()->where('code', 'R08')->exists(),
                fn ($query) => $query->where('appellant_user_id', $actor->id),
            )
            ->latest()
            ->paginate(20);

        return AppealResource::collection($appeals);
    }

    /** The filer is always the acting user — never a client-supplied id. */
    public function store(StoreAppealRequest $request): JsonResponse
    {
        $appeal = Appeal::create([
            ...$request->validated(),
            'appellant_user_id' => $request->user()->id,
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
        ]);

        return (new AppealResource(
            $appeal->load(['appellant:id,name', 'originalRequest:id,reference_number,title', 'status:id,code,name_ar,name_en,color']),
        ))->response()->setStatusCode(201);
    }
}
