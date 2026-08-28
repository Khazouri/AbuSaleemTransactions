<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MeetingOutputTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingOutputsResource;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Services\MeetingOutputService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Stage 37 — meeting-to-request outputs tracking through execution and close. */
class MeetingOutputsController extends Controller
{
    public function show(Meeting $meeting): MeetingOutputsResource
    {
        return new MeetingOutputsResource($this->loadOutputs($meeting));
    }

    public function complete(
        Request $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        MeetingOutputService $outputs,
    ): MeetingOutputsResource {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        try {
            $outputs->complete($agendaItem, $request->user());
        } catch (MeetingOutputTransitionException $exception) {
            throw ValidationException::withMessages([
                'action' => [$exception->getMessage()],
            ]);
        }

        return new MeetingOutputsResource($this->loadOutputs($meeting));
    }

    private function loadOutputs(Meeting $meeting): Meeting
    {
        return $meeting->load([
            'committee:id,name_ar,name_en',
            'agendaItems.decision.decidedBy:id,name',
            'agendaItems.request.createdBy:id,name',
            'agendaItems.request.department:id,code,name_ar,name_en',
            'agendaItems.request.status:id,code,name_ar,name_en,color',
            'agendaItems.request.currentStage:id,order_no,code,name_ar,name_en,responsible_role_id',
            'agendaItems.request.currentStage.responsibleRole:id,code,name_ar,name_en',
        ]);
    }
}
