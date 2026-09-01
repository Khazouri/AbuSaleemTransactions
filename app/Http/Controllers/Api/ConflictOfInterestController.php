<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConflictOfInterest\StoreConflictOfInterestRequest;
use App\Http\Resources\ConflictOfInterestDeclarationResource;
use App\Models\CommitteeMember;
use App\Models\ConflictOfInterestDeclaration;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stage 48 — [D] Art. 11/15/18: a committee member with a stake in an agenda
 * item must formally disclose it before deliberation. Rides `decisions,add`
 * (the same grant vote-casting uses) rather than a new screen: declaring a
 * conflict is a self-service action any voting member already has reach for.
 *
 * The declaration IS the recusal — there is no separate "recuse" step, and no
 * withdrawal endpoint: a disclosed conflict is a standing fact about this
 * item, not a toggle. DecisionEligibility::isRecused() is what both
 * DecisionController::vote() and MeetingDiscussionNoteController::store()
 * check against what this controller writes.
 */
class ConflictOfInterestController extends Controller
{
    public function index(Meeting $meeting, MeetingRequest $agendaItem): AnonymousResourceCollection
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        return ConflictOfInterestDeclarationResource::collection(
            $agendaItem->conflictDeclarations()->with('user:id,name')->get(),
        );
    }

    public function store(StoreConflictOfInterestRequest $request, Meeting $meeting, MeetingRequest $agendaItem): JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->item_type !== 'employee_request') {
            return response()->json([
                'message' => 'الإعلان عن تعارض المصالح مقصور على بنود الطلبات المرتبطة بطلب.',
            ], 422);
        }

        if ($agendaItem->decision()->exists()) {
            return response()->json([
                'message' => 'تم تسجيل قرار هذا البند بالفعل، لا يمكن الإعلان عن تعارض مصالح بعد الآن.',
            ], 422);
        }

        $actor = $request->user();

        $isMember = CommitteeMember::query()
            ->where('committee_id', $meeting->committee_id)
            ->where('user_id', $actor->id)
            ->exists();

        if (! $isMember) {
            return response()->json([
                'message' => 'الإعلان عن تعارض المصالح مقصور على أعضاء هذه اللجنة.',
            ], 422);
        }

        $declaration = ConflictOfInterestDeclaration::updateOrCreate(
            ['meeting_request_id' => $agendaItem->id, 'user_id' => $actor->id],
            ['reason' => $request->validated('reason'), 'declared_at' => now()],
        );

        return (new ConflictOfInterestDeclarationResource($declaration->load('user:id,name')))
            ->response()
            ->setStatusCode(201);
    }
}
