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
use App\Models\User;
use App\Services\ArtifactNumberGenerator;
use App\Services\MeetingVisibility;
use App\Services\Tasks\MeetingDuties;
use App\Services\WorkflowService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
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

        $pages = $this->storeMinutesPages($meeting, $request->file('minutes_pages'));

        try {
            DB::transaction(function () use ($meeting, $items, $rows, $actor, $workflow, $numbers, $pages) {
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
                    'minutes_pages' => $pages,
                    'results_recorded_at' => now(),
                    'results_recorded_by_user_id' => $actor->id,
                ]);
            });
        } catch (WorkflowTransitionException|DomainException $exception) {
            Storage::disk('local')->delete($pages);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'تم إدخال نتائج الاجتماع.']);
    }

    /**
     * Page n is saved as «<meeting id>-<n>», n from 1 in the order the مقرر
     * arranged them, which is also the name it downloads under. The extension
     * comes from the checked MIME type, never from the client's file name.
     *
     * @param  list<UploadedFile>  $uploads
     * @return list<string>
     */
    private function storeMinutesPages(Meeting $meeting, array $uploads): array
    {
        $paths = [];
        foreach (array_values($uploads) as $index => $upload) {
            $extension = $upload->getMimeType() === 'image/png' ? 'png' : 'jpg';
            $path = $upload->storeAs("meeting-minutes/{$meeting->id}", "{$meeting->id}-".($index + 1).".{$extension}", 'local');
            if ($path === false) {
                Storage::disk('local')->delete($paths);
                abort(500, 'تعذّر حفظ صفحات المحضر.');
            }
            $paths[] = $path;
        }

        return $paths;
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

    /**
     * 2026-10-25 — the مقرر takes the paper sitting's whole result back, so
     * it can be entered again, until جهة الاعتماد acts on any of its files
     * (MeetingDuties::rollbackResultsRefusal()). Each file returns to the
     * committee stage with the status it had, through a logged move: history
     * is kept, only what store() created is removed. Not behind
     * `meeting.member`, which refuses every write on a concluded meeting.
     *
     * ponytail: decision numbers are max+1, so a rolled-back number can be
     * issued again; reserve numbers if the register must never repeat one.
     */
    public function rollback(
        HttpRequest $request,
        Meeting $meeting,
        MeetingDuties $duties,
        MeetingVisibility $visibility,
        WorkflowService $workflow,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($visibility->canView($actor, $meeting), 404);

        $minutes = [$meeting->minutes_file_path, ...($meeting->minutes_pages ?? [])];

        try {
            DB::transaction(function () use ($meeting, $actor, $duties, $workflow) {
                $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
                // The files are locked before the check, so an authority
                // decision racing this one waits, then finds the file back
                // at the committee and is refused.
                Request::query()->whereIn('id', $locked->agendaItems()->whereNotNull('request_id')->pluck('request_id'))->lockForUpdate()->get();
                if ($refusal = $duties->rollbackResultsRefusal($locked, $actor)) {
                    throw new DomainException($refusal);
                }

                $legalReview = AppealStatus::where('code', 'legal_review')->value('id');
                foreach ($locked->agendaItems()->with(['decision', 'request', 'appeal'])->get() as $item) {
                    if ($item->item_type === 'appeal') {
                        $item->appeal->update(['appeal_status_id' => $legalReview]);
                    } else {
                        $this->rollBackFile($item, $duties, $workflow, $actor);
                    }

                    // Decision has a unique meeting_request_id, which would refuse the re-entry.
                    $item->decision->delete();
                    $item->update(['item_state' => 'presented', 'state_changed_at' => now()]);
                }

                $locked->update([
                    'status' => 'scheduled',
                    'minutes_file_path' => null,
                    'minutes_file_name' => null,
                    'minutes_file_mime' => null,
                    'minutes_pages' => null,
                    'results_recorded_at' => null,
                    'results_recorded_by_user_id' => null,
                ]);
            });
        } catch (WorkflowTransitionException|DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        Storage::disk('local')->delete(array_filter($minutes));
        Storage::disk('local')->deleteDirectory("meeting-minutes/{$meeting->id}");

        return response()->json(['message' => 'تم التراجع عن نتائج الاجتماع، ويمكن إدخالها من جديد.']);
    }

    /**
     * One file back where the result found it: its earlier status at the
     * committee stage, no جهة الاعتماد, and no committee approval in the
     * ledger. Silent (`announce: false`): nothing was decided, and
     * EmployeeNoticeService keeps the employee notice quiet too.
     */
    private function rollBackFile(MeetingRequest $item, MeetingDuties $duties, WorkflowService $workflow, User $actor): void
    {
        $file = $item->request;
        $priorStatus = $duties->committeeResultHistory($item)->fromStatus()->value('code');

        $file->update(['approving_authority' => null]);
        if ($item->decision->outcome === 'approve') {
            $file->approvals()->where('level', 2)->where('action', 'approve')->latest('id')->first()?->delete();
        }

        $workflow->reopenAtStage(
            $file,
            $file->stageByCode('receive_from_committee'),
            $actor,
            'تراجع المقرر عن نتائج الاجتماع.',
            'rollback_results',
            $priorStatus,
            announce: false,
        );
    }

    /**
     * One page of the scanned محضر (`?page=n`, from 1). A meeting entered
     * before the pages (2026-10-25) has one file instead, served as page 1.
     */
    public function minutesFile(HttpRequest $request, Meeting $meeting): StreamedResponse
    {
        $page = (int) $request->query('page', '1');

        if ($meeting->minutes_pages) {
            $path = $meeting->minutes_pages[$page - 1] ?? null;
            abort_unless($page >= 1 && $path !== null && Storage::disk('local')->exists($path), 404);

            return Storage::disk('local')->response($path, basename($path));
        }

        abort_unless($page === 1 && $meeting->minutes_file_path !== null && Storage::disk('local')->exists($meeting->minutes_file_path), 404);

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
