<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PresentationMemo\UpdatePresentationMemoRequest;
use App\Http\Resources\PresentationMemoResource;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\PresentationMemo;
use App\Services\PresentationMemoCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stage 46 — [D] Art. 22's compiled pre-meeting memo. Rides the
 * `meeting_agenda` screen's existing grants (view=*, add=[R03,R04],
 * edit=[R03]) rather than a new screen: drafting a memo sits in agenda-prep
 * territory (between Art. 21's legal review and Art. 23's agenda insertion),
 * and `add` already covers both the chair (R03) and a member acting as
 * مقرر (R04) — `edit`'s R03-only tier would wrongly shut the rapporteur out
 * of drafting their own memo.
 */
class PresentationMemoController extends Controller
{
    public function show(Meeting $meeting, MeetingRequest $agendaItem): PresentationMemoResource|JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->item_type !== 'employee_request') {
            return response()->json([
                'message' => 'لا تتوفر مذكرة عرض لبند غير مرتبط بطلب.',
            ], 422);
        }

        $memo = $agendaItem->presentationMemo()->with(['generatedBy:id,name', 'updatedBy:id,name'])->first();

        if ($memo === null) {
            return response()->json(['data' => null]);
        }

        return new PresentationMemoResource($memo);
    }

    /**
     * (Re)compiles the derived half from the item's current data and
     * preserves the authored half — except the very first call, which seeds
     * facts_summary from the request's own description as an editable
     * starting draft (mirroring DecisionDraftComposer's Stage 42 pattern).
     */
    public function generate(Meeting $meeting, MeetingRequest $agendaItem, PresentationMemoCompiler $compiler, Request $request): PresentationMemoResource|JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->item_type !== 'employee_request') {
            return response()->json([
                'message' => 'لا يمكن إنشاء مذكرة عرض لبند غير مرتبط بطلب.',
            ], 422);
        }

        $existing = $agendaItem->presentationMemo()->first();

        $authored = $existing?->content['authored'] ?? [
            'facts_summary' => $agendaItem->request?->description ?? '',
            'employment_status_notes' => '',
            'legal_opinion' => '',
            'committee_question' => '',
        ];

        $memo = PresentationMemo::updateOrCreate(
            ['meeting_request_id' => $agendaItem->id],
            [
                'content' => [
                    'derived' => $compiler->compile($agendaItem),
                    'authored' => $authored,
                ],
                'generated_by_user_id' => $request->user()->id,
                'generated_at' => now(),
            ],
        );

        // Explicit 200 — same reasoning as MeetingMinutesController::generate():
        // updateOrCreate answers 201 on the first call and 200 after, and an
        // idempotent-feeling "recompile" action shouldn't visibly differ.
        return (new PresentationMemoResource($memo->load(['generatedBy:id,name'])))
            ->response()
            ->setStatusCode(200);
    }

    public function update(UpdatePresentationMemoRequest $request, Meeting $meeting, MeetingRequest $agendaItem): PresentationMemoResource|JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $memo = $agendaItem->presentationMemo()->first();
        if ($memo === null) {
            return response()->json(['message' => 'لم يتم إنشاء مذكرة عرض لهذا البند بعد.'], 404);
        }

        $content = $memo->content;
        $content['authored'] = array_merge($content['authored'] ?? [], $request->validated());

        $memo->update([
            'content' => $content,
            'updated_by_user_id' => $request->user()->id,
        ]);

        return new PresentationMemoResource($memo->load(['generatedBy:id,name', 'updatedBy:id,name']));
    }
}
