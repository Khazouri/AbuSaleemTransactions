<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Services\Tasks\MeetingDuties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Decision wizard — sub-project 2. Read-only: what the signed-in member may do
 * on a meeting (MeetingDuties), fetched by the wizard when it opens and after
 * every check.
 */
class MeetingDutiesController extends Controller
{
    public function meeting(Request $request, Meeting $meeting, MeetingDuties $duties): JsonResponse
    {
        return response()->json(['data' => $duties->forMeeting($meeting, $request->user())]);
    }
}
