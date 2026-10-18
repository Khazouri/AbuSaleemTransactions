<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\AuthorityDecisionRequest;
use App\Http\Requests\Meeting\RecordMeetingResultsRequest;
use App\Models\AppealStatus;
use App\Models\Attachment;
use App\Models\Decision;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Services\ArtifactNumberGenerator;
use App\Services\MeetingVisibility;
use App\Services\Tasks\MeetingDuties;
use App\Services\WorkflowService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The paper sitting (user decision 2026-10-18). The committee meets on paper;
 * afterwards the مقرر enters every item's توصية and attaches the محضر in one
 * submit, which concludes the meeting. The committee only recommends, so
 * جهة الاعتماد then decides each موافقة or عدم موافقة from this same meeting.
 */
class MeetingResultsController extends Controller
{
    /** The مقرر's three choices, as the Decision outcome each one records on a request and on an appeal. */
    private const OUTCOMES = [
        'employee_request' => ['approve' => 'approve', 'decline' => 'reject', 'defer' => 'defer'],
        'appeal' => ['approve' => 'appeal_accept', 'decline' => 'appeal_reject', 'defer' => 'defer'],
    ];

    // 2026-10-18 — the مقرر enters the paper sitting's results.
    public function store(
        RecordMeetingResultsRequest $request,
        Meeting $meeting,
        MeetingDuties $duties,
        WorkflowService $workflow,
        ArtifactNumberGenerator $numbers,
    ): JsonResponse {
        $actor = $request->user();
        if ($refusal = $duties->recordResultsRefusal($meeting, $actor)) {
            return response()->json(['message' => $refusal], 422);
        }

        $rows = $request->validated('items');
        $items = $meeting->agendaItems()->get();
        if ($items->pluck('id')->sort()->values()->all() !== collect(array_keys($rows))->map(fn ($id) => (int) $id)->sort()->values()->all()) {
            return response()->json(['message' => 'يجب إدخال نتيجة كل بند في جدول الأعمال، دون غيره.'], 422);
        }
        foreach ($items as $item) {
            if ($problem = $this->rowProblem($item, $rows[$item->id])) {
                return response()->json(['message' => $problem], 422);
            }
        }

        $upload = $request->file('minutes_file');
        $path = $upload->store("meeting-minutes/{$meeting->id}", 'local');

        try {
            DB::transaction(function () use ($meeting, $items, $rows, $actor, $workflow, $numbers, $upload, $path) {
                // A second submit racing the first finds the results already in.
                $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
                if ($locked->results_recorded_at !== null) {
                    throw new DomainException('تم إدخال نتائج هذا الاجتماع بالفعل.');
                }

                foreach ($items as $item) {
                    $row = $rows[$item->id];
                    $outcome = self::OUTCOMES[$item->item_type][$row['recommendation']];
                    $note = filled($row['note'] ?? null) ? trim($row['note']) : null;

                    if ($item->item_type === 'appeal') {
                        // The committee decides an appeal itself; a deferred
                        // one stays at legal review for a later agenda.
                        if ($outcome !== 'defer') {
                            $item->appeal->update(['appeal_status_id' => AppealStatus::where('code', 'committee_presentation')->value('id')]);
                        }
                    } else {
                        $file = Request::query()->lockForUpdate()->findOrFail($item->request_id);
                        // Read by WorkflowService to send a minister file to the ministry.
                        $file->update(['approving_authority' => $outcome === 'defer' ? null : $row['approving_authority']]);
                        $workflow->applyCommitteeRecommendation($file, DecisionController::ACTIONS[$outcome], $actor, $note);
                    }

                    $item->update(['item_state' => 'complete', 'state_changed_at' => now()]);
                    Decision::create([
                        'meeting_request_id' => $item->id,
                        'decision_number' => $numbers->nextDecisionNumber(),
                        'outcome' => $outcome,
                        // Art. 90 — what the committee issues is a توصية; the
                        // authority's decision is the قرار.
                        'instrument' => 'recommendation',
                        'comment' => $note,
                        'decided_by_user_id' => $actor->id,
                        'decided_at' => now(),
                    ]);
                }

                $locked->update([
                    'status' => 'completed',
                    'minutes_file_path' => $path,
                    'minutes_file_name' => $upload->getClientOriginalName(),
                    'minutes_file_mime' => $upload->getMimeType(),
                    'results_recorded_at' => now(),
                    'results_recorded_by_user_id' => $actor->id,
                ]);
            });
        } catch (WorkflowTransitionException|DomainException $exception) {
            Storage::disk('local')->delete($path);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'تم إدخال نتائج الاجتماع.']);
    }

    /**
     * A deferred file goes back to the pending list, so it has no جهة الاعتماد
     * yet, and an appeal never has one. Art. 91 refuses an unreasoned
     * «عدم موافقة», a تأجيل states what it waits for (Art. 34), and every
     * appeal outcome states the committee's reason.
     *
     * @param  array<string, mixed>  $row
     */
    private function rowProblem(MeetingRequest $item, array $row): ?string
    {
        $needsReason = $item->item_type === 'appeal' || $row['recommendation'] !== 'approve';
        if ($needsReason && blank($row['note'] ?? null)) {
            return $item->item_type === 'appeal' ? 'يجب بيان سبب قرار اللجنة على التظلم.' : 'يجب بيان سبب عدم الموافقة أو التأجيل.';
        }

        if ($item->item_type !== 'appeal' && $row['recommendation'] !== 'defer' && blank($row['approving_authority'] ?? null)) {
            return 'يجب تحديد جهة الاعتماد: العميد أو الوزير.';
        }

        return null;
    }

    /** The scanned محضر the مقرر attached with the results. */
    public function minutesFile(Meeting $meeting): StreamedResponse
    {
        abort_unless($meeting->minutes_file_path !== null && Storage::disk('local')->exists($meeting->minutes_file_path), 404);

        return Storage::disk('local')->response($meeting->minutes_file_path, $meeting->minutes_file_name, [
            'Content-Type' => $meeting->minutes_file_mime,
        ]);
    }

    public function authorityDuties(HttpRequest $request, Meeting $meeting, MeetingRequest $agendaItem, MeetingDuties $duties): JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        return response()->json(['data' => $duties->forAuthority($agendaItem, $request->user())]);
    }

    /**
     * 2026-10-18 — العميد decides a dean file; مكتب العميد records the
     * minister's paper قرار, attached, on a minister file. Not behind
     * `meeting.member`, which refuses every write on a concluded meeting, so
     * the visibility check is here.
     */
    public function authorityDecision(
        AuthorityDecisionRequest $request,
        Meeting $meeting,
        MeetingRequest $agendaItem,
        MeetingDuties $duties,
        MeetingVisibility $visibility,
        WorkflowService $workflow,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($agendaItem->meeting_id === $meeting->id && $visibility->canView($actor, $meeting), 404);

        if ($refusal = $duties->authorityDecisionRefusal($agendaItem, $actor)) {
            return response()->json(['message' => $refusal], 422);
        }

        $file = $agendaItem->request;
        if ($file->currentStage()->value('code') === 'local_governance_ministry' && ! $request->hasFile('decision_file')) {
            return response()->json(['message' => 'يجب إرفاق قرار الوزير.'], 422);
        }

        $upload = $request->file('decision_file');
        $path = $upload?->store("attachments/{$file->id}", 'local');

        try {
            DB::transaction(function () use ($request, $file, $upload, $path, $actor, $workflow) {
                if ($upload !== null) {
                    Attachment::create([
                        'request_id' => $file->id,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => 'قرار الوزير - '.$file->reference_number.'.'.$upload->guessExtension(),
                        'mime_type' => $upload->getMimeType(),
                        'size_bytes' => $upload->getSize(),
                        'label' => 'قرار الوزير',
                        'file_section' => 'approval',
                        'uploaded_by_user_id' => $actor->id,
                    ]);
                }

                $workflow->transition($file, $request->validated('decision'), $actor, $request->validated('note'));
            });
        } catch (WorkflowTransitionException $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'تم تسجيل قرار جهة الاعتماد.']);
    }
}
