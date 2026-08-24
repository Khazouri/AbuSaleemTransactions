<?php

namespace App\Services;

use App\Exceptions\CommitteeStatusTransitionException;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Guarded, status-only moves for a transaction sitting with the committee.
 *
 * WorkflowService stays the sole authority for stage changes (workflow_transitions
 * is admin-configurable and drives current_stage_id). The moves here are a fixed,
 * hardcoded state machine on purpose — nomination, agenda placement, discussion
 * and recommendation-approval are internal committee bookkeeping, not workflow
 * stages, so they must never appear in workflow_transitions or touch
 * current_stage_id / transaction_stage_logs. Every move is confined to a
 * transaction currently at the `receive_from_committee` stage, which is the
 * only stage these sub-statuses are meaningful at.
 */
class CommitteeStatusService
{
    /**
     * action => [from statuses, to status, whether a comment is required].
     *
     * `nominate`'s origin list covers both the design doc's "ready" prose and
     * `in_meeting`, the status the existing 6→7 `forward` rule actually sets
     * on arrival at this stage (see WorkflowTransitionSeeder) — the two names
     * describe the same real-world moment.
     */
    private const ACTIONS = [
        'nominate' => [
            'from' => ['ready', 'in_meeting'],
            'to' => 'nominated_for_committee',
            'requires_comment' => false,
        ],
        'place_on_agenda' => [
            'from' => ['nominated_for_committee'],
            'to' => 'on_agenda',
            'requires_comment' => false,
        ],
        'remove_from_agenda' => [
            'from' => ['on_agenda'],
            'to' => 'nominated_for_committee',
            'requires_comment' => true,
        ],
        'start_discussion' => [
            'from' => ['on_agenda'],
            'to' => 'under_discussion',
            'requires_comment' => false,
        ],
        'send_for_recommendation_approval' => [
            'from' => ['under_discussion'],
            'to' => 'awaiting_recommendation_approval',
            'requires_comment' => false,
        ],
        'require_completion' => [
            'from' => ['under_discussion', 'awaiting_recommendation_approval'],
            'to' => 'completion_required',
            'requires_comment' => true,
        ],
        'resume_discussion' => [
            'from' => ['completion_required'],
            'to' => 'under_discussion',
            'requires_comment' => false,
        ],
    ];

    private const COMMITTEE_STAGE_CODE = 'receive_from_committee';

    /**
     * @throws CommitteeStatusTransitionException
     */
    public function move(
        Transaction $transaction,
        string $action,
        User $actor,
        ?string $comment = null,
    ): Transaction {
        if (! $transaction->exists) {
            throw CommitteeStatusTransitionException::transactionNotPersisted();
        }

        if (! $actor->exists || ! $actor->is_active) {
            throw CommitteeStatusTransitionException::actorNotActive();
        }

        $action = trim($action);
        $comment = filled($comment) ? trim($comment) : null;

        $rule = self::ACTIONS[$action] ?? null;
        if ($rule === null) {
            throw CommitteeStatusTransitionException::actionNotConfigured();
        }

        if ($rule['requires_comment'] && $comment === null) {
            throw CommitteeStatusTransitionException::commentRequired();
        }

        return DB::transaction(function () use ($transaction, $rule, $actor, $comment) {
            $locked = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            if ($this->hasTerminalStatus($locked)) {
                throw CommitteeStatusTransitionException::transactionClosed();
            }

            if ($locked->currentStage?->code !== self::COMMITTEE_STAGE_CODE) {
                throw CommitteeStatusTransitionException::wrongStage();
            }

            $currentStatusCode = $locked->status?->code;
            if (! in_array($currentStatusCode, $rule['from'], strict: true)) {
                throw CommitteeStatusTransitionException::transitionNotAllowedFromCurrentStatus();
            }

            $toStatus = TransactionStatus::where('code', $rule['to'])->firstOrFail();
            $fromStatusId = $locked->status_id;

            $locked->status_id = $toStatus->id;
            $locked->save();

            TransactionStatusHistory::create([
                'transaction_id' => $locked->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatus->id,
                'reason' => $comment,
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    private function hasTerminalStatus(Transaction $transaction): bool
    {
        return $transaction->status()
            ->whereIn('code', ['cancelled', 'archived'])
            ->exists();
    }
}
