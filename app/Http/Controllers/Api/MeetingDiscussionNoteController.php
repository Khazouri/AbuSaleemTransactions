<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeetingDiscussionNote\StoreMeetingDiscussionNoteRequest;
use App\Http\Resources\MeetingDiscussionNoteResource;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Services\DecisionEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stage 34 — the live runner's discussion feed for one agenda item. An
 * append-only log (no update/delete) — closer in spirit to `votes`' "who
 * said what" than to `notes`' editable body, since a discussion feed is a
 * transcript, not a document.
 */
class MeetingDiscussionNoteController extends Controller
{
    public function index(Meeting $meeting, MeetingRequest $agendaItem): AnonymousResourceCollection
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        return MeetingDiscussionNoteResource::collection(
            $agendaItem->notes()->with('createdBy:id,name')->get(),
        );
    }

    public function store(
        StoreMeetingDiscussionNoteRequest $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        DecisionEligibility $eligibility,
    ): JsonResponse {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        // Stage 48 — a disclosed conflict of interest blocks deliberation, not
        // only the vote; the discussion feed is exactly the deliberation this
        // guards, per [D] Art. 11/15/18.
        if ($agendaItem->item_type === 'employee_request' && $eligibility->isRecused($agendaItem, $request->user())) {
            return response()->json([
                'message' => 'تم إعلان تعارض مصالح على هذا البند، لا يجوز المشاركة في مداولته.',
            ], 422);
        }

        $note = $agendaItem->notes()->create([
            'note' => $request->validated('note'),
            'created_by_user_id' => $request->user()->id,
        ]);

        return (new MeetingDiscussionNoteResource($note->load('createdBy:id,name')))
            ->response()
            ->setStatusCode(201);
    }
}
