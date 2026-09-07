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

    /** Stage 69 — Art. 38 code 18 → 19: the effect has been carried out. */
    public function execute(
        Request $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        MeetingOutputService $outputs,
    ): MeetingOutputsResource {
        return $this->apply($meeting, $agendaItem, fn () => $outputs->markExecuted($agendaItem, $request->user()));
    }

    /**
     * Stage 69 — Art. 38 code 19 → 20. Kept a separate call from execute()
     * because Appendix 5 refuses to let the two collapse: "منفذة … لكنها لا
     * تصبح مغلقة إلا بعد التحقق من اكتمال التوثيق".
     */
    public function close(
        Request $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        MeetingOutputService $outputs,
    ): MeetingOutputsResource {
        return $this->apply($meeting, $agendaItem, fn () => $outputs->close($agendaItem, $request->user()));
    }

    private function apply(Meeting $meeting, MeetingRequest $agendaItem, callable $move): MeetingOutputsResource
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        try {
            $move();
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
