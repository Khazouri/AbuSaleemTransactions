<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeetingDiscussionNote\StoreMeetingDiscussionNoteRequest;
use App\Http\Resources\MeetingDiscussionNoteResource;
use App\Models\Meeting;
use App\Models\MeetingTransaction;
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
    public function index(Meeting $meeting, MeetingTransaction $agendaItem): AnonymousResourceCollection
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        return MeetingDiscussionNoteResource::collection(
            $agendaItem->notes()->with('createdBy:id,name')->get(),
        );
    }

    public function store(StoreMeetingDiscussionNoteRequest $request, Meeting $meeting, MeetingTransaction $agendaItem): JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $note = $agendaItem->notes()->create([
            'note' => $request->validated('note'),
            'created_by_user_id' => $request->user()->id,
        ]);

        return (new MeetingDiscussionNoteResource($note->load('createdBy:id,name')))
            ->response()
            ->setStatusCode(201);
    }
}
