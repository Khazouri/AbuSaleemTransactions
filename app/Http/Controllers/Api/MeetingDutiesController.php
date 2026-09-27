<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Services\Tasks\MeetingDuties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Decision wizard — sub-project 2. Read-only: what the signed-in member may do
 * on a meeting or one of its agenda items (MeetingDuties), fetched by the
 * wizards when they open and after every check.
 */
class MeetingDutiesController extends Controller
{
    public function item(Request $request, Meeting $meeting, MeetingRequest $agendaItem, MeetingDuties $duties): JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        return response()->json(['data' => $duties->forItem($agendaItem, $request->user())]);
    }
}
