<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\RecordAuthorityDecisionsRequest;
use App\Http\Requests\Meeting\RecordMeetingResultsRequest;
use App\Models\AppealStatus;
use App\Models\AuthorityDecision;
use App\Models\Decision;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Services\ArtifactNumberGenerator;
use App\Services\MeetingVisibility;
use App\Services\RequestVisibility;
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

        $pages = $this->storePages("meeting-minutes/{$meeting->id}", $meeting->id, $request->file('minutes_pages'));

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
     * Scanned page n is saved as «<id>-<n>» in `$directory`, n from 1 in the
     * order the user arranged them, which is also the name it downloads under:
     * the محضر under its meeting's id, جهة الاعتماد's قرار under its own. The
     * extension comes from the checked MIME type, never the client's file name.
     *
     * @param  list<UploadedFile>  $uploads
     * @return list<string>
     */
    private function storePages(string $directory, int $id, array $uploads): array
    {
        $paths = [];
        foreach (array_values($uploads) as $index => $upload) {
            $extension = $upload->getMimeType() === 'image/png' ? 'png' : 'jpg';
            $path = $upload->storeAs($directory, "{$id}-".($index + 1).".{$extension}", 'local');
            if ($path === false) {
                Storage::disk('local')->delete($paths);
                abort(500, 'تعذّر حفظ الصفحات المرفقة.');
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

    // 2026-10-25 — what جهة الاعتماد may tick on this meeting's page.
    public function authorityDuties(HttpRequest $request, Meeting $meeting, MeetingDuties $duties): JsonResponse
    {
        return response()->json(['items' => $duties->authorityItems($meeting, $request->user())]);
    }

    /**
     * 2026-10-25 — جهة الاعتماد decides several files of this meeting with one
     * paper قرار: one اشاري, one date and one set of scanned pages, and its own
     * اعتماد or عدم اعتماد per file. All or nothing: one refused file holds
     * the whole submit back and is named, so a قرار is never half recorded.
     *
     * Not behind `meeting.member`, which refuses every write on a concluded
     * meeting, nor `request.visible`, which cannot see the ticked items, so
     * both visibility checks are here.
     */
    public function recordAuthorityDecisions(
        RecordAuthorityDecisionsRequest $request,
        Meeting $meeting,
        MeetingDuties $duties,
        MeetingVisibility $meetings,
        RequestVisibility $requests,
        WorkflowService $workflow,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($meetings->canView($actor, $meeting), 404);

        $rows = $request->validated('items');
        $items = $meeting->agendaItems()->whereKey(array_keys($rows))->with(['request', 'decision', 'meeting'])->get();
        abort_unless($items->count() === count($rows), 404);

        foreach ($items as $item) {
            abort_if($item->request !== null && ! $requests->canView($actor, $item->request), 404);
            if ($refusal = $duties->authorityDecisionRefusal($item, $actor)) {
                return response()->json(['message' => ($item->request?->reference_number ?? "#{$item->id}").': '.$refusal], 422);
            }
        }

        $directory = null;
        try {
            DB::transaction(function () use ($request, $items, $rows, $actor, $workflow, &$directory) {
                $decision = AuthorityDecision::create([
                    'reference' => trim($request->validated('reference')),
                    'decided_on' => $request->validated('decided_on'),
                    'pages' => [],
                    'decided_by_user_id' => $actor->id,
                ]);
                $directory = "authority-decisions/{$decision->id}";
                $decision->update(['pages' => $this->storePages($directory, $decision->id, $request->file('pages'))]);

                foreach ($items as $item) {
                    $note = filled($rows[$item->id]['note'] ?? null) ? trim($rows[$item->id]['note']) : null;
                    $workflow->transition($item->request, $rows[$item->id]['decision'], $actor, $note);
                    // A re-decision after the مقرر's return points at the new قرار.
                    $item->update(['authority_decision_id' => $decision->id]);
                }
            });
        } catch (WorkflowTransitionException $exception) {
            if ($directory !== null) {
                Storage::disk('local')->deleteDirectory($directory);
            }

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'تم تسجيل قرار جهة الاعتماد على '.$items->count().' من الطلبات.']);
    }

    /**
     * 2026-10-25 — one page of جهة الاعتماد's قرار on this file, for whoever
     * may open the request, its creator among them. The same قرار the request
     * page shows, so none while the file is back before the authority.
     */
    public function authorityDecisionPage(Request $requestRecord, int $page): StreamedResponse
    {
        $path = AuthorityDecision::shownOn($requestRecord)?->pages[$page - 1] ?? null;
        abort_unless($page >= 1 && $path !== null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, basename($path));
    }
}
