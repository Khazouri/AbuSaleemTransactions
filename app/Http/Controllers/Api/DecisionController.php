<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decision\StoreDecisionRequest;
use App\Http\Requests\Vote\StoreVoteRequest;
use App\Http\Resources\DecisionResource;
use App\Http\Resources\VoteResource;
use App\Models\CommitteeMember;
use App\Models\Decision;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingTransaction;
use App\Models\Vote;
use App\Services\ApprovalSignatureStorage;
use App\Services\NotificationDispatcher;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Stage 21 — committee members vote on an agenda item, then the committee
 * head records the outcome, which drives WorkflowService::transition()
 * directly (STAGE_PLAN's "no manual intervention"). Rides the `decisions`
 * screen's own permissions: `add` (R03/R04) to cast a vote, `approve` (R03)
 * to record the binding decision — the same view/write split MeetingController
 * uses for edit vs. delete on the `meetings` screen.
 */
class DecisionController extends Controller
{
    /**
     * How a vote outcome maps onto the workflow_transitions row seeded at
     * stage 7 (`receive_from_committee`). `reject` reuses the existing R03
     * self-loop `cancel` exception rather than inventing a second terminal
     * outcome; `defer` is the new Stage 21 self-loop.
     */
    private const ACTIONS = [
        'approve' => 'approve',
        'reject' => 'cancel',
        'defer' => 'defer',
    ];

    public function vote(StoreVoteRequest $request, Meeting $meeting, MeetingTransaction $agendaItem): JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->decision()->exists()) {
            return response()->json([
                'message' => 'تم تسجيل قرار هذا البند بالفعل، لا يمكن التصويت بعد الآن.',
            ], 422);
        }

        $actor = $request->user();

        $isMember = CommitteeMember::query()
            ->where('committee_id', $meeting->committee_id)
            ->where('user_id', $actor->id)
            ->exists();
        if (! $isMember) {
            return response()->json([
                'message' => 'التصويت مقصور على أعضاء هذه اللجنة.',
            ], 422);
        }

        $attended = MeetingAttendee::query()
            ->where('meeting_id', $meeting->id)
            ->where('user_id', $actor->id)
            ->where('attended', true)
            ->exists();
        if (! $attended) {
            return response()->json([
                'message' => 'التصويت مقصور على الأعضاء المسجل حضورهم في هذا الاجتماع.',
            ], 422);
        }

        $vote = Vote::updateOrCreate(
            ['meeting_transaction_id' => $agendaItem->id, 'user_id' => $actor->id],
            [
                'vote' => $request->validated('vote'),
                'comment' => $request->validated('comment'),
                'voted_at' => now(),
            ],
        );

        return (new VoteResource($vote->load('user:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Tally the votes by simple plurality and let the winning outcome drive
     * the workflow transition directly. Ties and zero-vote tallies are
     * rejected rather than guessed at — the head can wait for more votes or
     * ask for a re-vote instead.
     *
     * WorkflowService re-checks the actor's role and the transition's own
     * requires_comment/signature rules under its row lock, so this method
     * does not duplicate that validation.
     */
    public function record(
        StoreDecisionRequest $request,
        Meeting $meeting,
        MeetingTransaction $agendaItem,
        WorkflowService $workflow,
        ApprovalSignatureStorage $signatureStorage,
        NotificationDispatcher $notifications,
    ): JsonResponse {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->decision()->exists()) {
            return response()->json([
                'message' => 'تم تسجيل قرار هذا البند بالفعل.',
            ], 422);
        }

        $counts = Vote::query()
            ->where('meeting_transaction_id', $agendaItem->id)
            ->selectRaw('vote, count(*) as total')
            ->groupBy('vote')
            ->pluck('total', 'vote');

        $tally = collect(array_keys(self::ACTIONS))
            ->mapWithKeys(fn (string $outcome) => [$outcome => (int) ($counts[$outcome] ?? 0)]);

        $max = $tally->max();
        if ($max === 0) {
            return response()->json([
                'message' => 'لا توجد أصوات مسجلة على هذا البند بعد.',
            ], 422);
        }

        $leaders = $tally->filter(fn (int $count) => $count === $max);
        if ($leaders->count() > 1) {
            return response()->json([
                'message' => 'التصويت متعادل، لا يمكن حسم القرار تلقائياً.',
            ], 422);
        }

        $outcome = $leaders->keys()->first();
        $action = self::ACTIONS[$outcome];
        $comment = $request->validated('comment');
        $actor = $request->user();

        $signaturePath = $action === 'approve' && $request->hasFile('signature')
            ? $signatureStorage->store($request->file('signature'), $agendaItem->transaction)
            : null;

        try {
            $decision = DB::transaction(function () use (
                $workflow, $agendaItem, $action, $actor, $comment, $signaturePath, $outcome, $tally,
            ) {
                $workflow->transition($agendaItem->transaction, $action, $actor, $comment, $signaturePath);

                return Decision::create([
                    'meeting_transaction_id' => $agendaItem->id,
                    'outcome' => $outcome,
                    'votes_approve_count' => $tally['approve'],
                    'votes_reject_count' => $tally['reject'],
                    'votes_defer_count' => $tally['defer'],
                    'comment' => $comment,
                    'decided_by_user_id' => $actor->id,
                    'decided_at' => now(),
                ]);
            });
        } catch (WorkflowTransitionException $exception) {
            $signatureStorage->delete($signaturePath);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // Stage 23 — separate from the stage-change notification the same
        // transition raises: this one names the outcome and the tally, which
        // is the part the requester and the committee actually ask about.
        $notifications->decisionRecorded($agendaItem->transaction, $decision, $meeting, $actor);

        return (new DecisionResource($decision->load('decidedBy:id,name')))
            ->response()
            ->setStatusCode(201);
    }
}
