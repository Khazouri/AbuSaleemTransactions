<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\ExecuteMeetingOutputRequest;
use App\Http\Resources\MeetingOutputsResource;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Services\MeetingOutputService;
use DomainException;
use Illuminate\Validation\ValidationException;

/**
 * Stage 37 — meeting-to-request outputs tracking through execution.
 *
 * Stage 75 moved closure off this screen's own routes: Art. 37's الإقفال is a
 * request-level act (two of its four final paths close requests that never
 * reached an agenda), so it lives at PATCH requests/{requestRecord}/close. The
 * outputs screen still offers the button; it posts there.
 *
 * Stage 92 split this screen's write grants in two: `edit` (R02 + R03) still
 * covers the rapporteur/chair certifications elsewhere in the request
 * lifecycle, while `approve` (R02 + R03 + R12) is [F] step 10's own question —
 * who actually executed — so `execute()` rides that instead.
 */
class MeetingOutputsController extends Controller
{
    public function show(Meeting $meeting): MeetingOutputsResource
    {
        return new MeetingOutputsResource($this->loadOutputs($meeting));
    }

    /**
     * Stage 69 — Art. 38 code 18 → 19: the effect has been carried out.
     *
     * Stage 76 made that a substantiated claim rather than a bare one. The
     * request now carries النموذج 17's card, its متابعة التنفيذ answers and
     * Appendix 70's دليل التنفيذ, all written with the status move.
     *
     * Stage 92 — rides `meeting_outputs,approve` (R02 + R03 + R12), not `edit`:
     * [F] step 10's الجهة المنفذة is who this record names, so the acting
     * grant now reaches R12 (HR Manager), the executing body [D] names most
     * often, alongside R02/R03 for whichever file some other body executed.
     */
    public function execute(
        ExecuteMeetingOutputRequest $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        MeetingOutputService $outputs,
    ): MeetingOutputsResource {
        $validated = $request->validated();

        return $this->apply($meeting, $agendaItem, fn () => $outputs->markExecuted(
            $agendaItem,
            $request->user(),
            $validated,
            $validated['checklist'],
            $validated['evidence'],
        ));
    }

    private function apply(Meeting $meeting, MeetingRequest $agendaItem, callable $move): MeetingOutputsResource
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        try {
            $move();
        } catch (DomainException $exception) {
            // MeetingOutputTransitionException extends DomainException, so this
            // one catch covers both the structural refusals that class raises
            // and Stage 76's RequestExecutionService rules.
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
            // Stage 76 — the outputs screen's execute panel picks Appendix 70's
            // دليل التنفيذ from the request's own documents, and shows the
            // recorded card once execution is proven.
            'agendaItems.request.attachments:id,request_id,original_name,mime_type,label,execution_evidence_type',
        ]);
    }
}
