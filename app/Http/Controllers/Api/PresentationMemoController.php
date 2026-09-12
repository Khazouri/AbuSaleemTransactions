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
 * `meeting_agenda` screen's existing grants rather than a new screen:
 * drafting a memo sits in agenda-prep territory, between Art. 21's legal
 * review and Art. 23's agenda insertion.
 *
 * Stage 84 corrected who that means. The memo rode `add` so that a member
 * acting as مقرر (R04) could draft one — but Appendix 6's RACI makes إعداد
 * مذكرة العرض مقرر اللجنة's own responsibility, and Art. 15 (أ) أولًا 11
 * lists تجهيز ملفات العرض ومذكرات العرض among the rapporteur's pre-meeting
 * duties. So the drafting audience is now R02 (المقرر), R03 (chair, who
 * reviews and approves the agenda under Art. 12 (أ) 3) and R09 (this
 * system's own agenda secretary); an ordinary member no longer drafts.
 * Read the seeder for the current tiers rather than trusting this comment.
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

        if ($problem = $this->frozenByVoting($agendaItem)) {
            return $problem;
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

        if ($problem = $this->frozenByVoting($agendaItem)) {
            return $problem;
        }

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

    /**
     * Stage 82 — [D] Appendix 25's fifth stage closes with "ولا يجوز استمرار
     * تعديل الوقائع أو المستندات بعد بدء التصويت", and the memo's authored
     * half IS the الوقائع the committee is voting on. Bounded to an item whose
     * vote is still open: once the decision is recorded the item is finished,
     * and Art. 94's own rule about not quietly editing an approved محضر takes
     * over from there.
     */
    private function frozenByVoting(MeetingRequest $agendaItem): ?JsonResponse
    {
        if (! $agendaItem->votes()->exists() || $agendaItem->decision()->exists()) {
            return null;
        }

        return response()->json([
            'message' => 'بدأ التصويت على هذا البند، ولا يجوز تعديل وقائع مذكرة العرض قبل إثبات النتيجة.',
        ], 422);
    }
}
