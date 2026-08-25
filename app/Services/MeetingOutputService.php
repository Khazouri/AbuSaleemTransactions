<?php

namespace App\Services;

use App\Exceptions\MeetingOutputTransitionException;
use App\Models\MeetingTransaction;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes the execution loop for a decided meeting request.
 *
 * This boundary intentionally changes status only. WorkflowService remains the
 * sole owner of stage movement; completion records that work at the final stage
 * has actually been carried out, rather than inventing a twelfth stage.
 */
class MeetingOutputService
{
    /**
     * @throws MeetingOutputTransitionException
     */
    // Stage 37 — execution completion without a parallel workflow stage machine.
    public function complete(MeetingTransaction $output, User $actor): Transaction
    {
        if (! $output->exists) {
            throw MeetingOutputTransitionException::outputNotPersisted();
        }

        if (! $actor->exists || ! $actor->is_active) {
            throw MeetingOutputTransitionException::actorNotActive();
        }

        if ($output->transaction_id === null) {
            throw MeetingOutputTransitionException::requestRequired();
        }

        if (! $output->decision()->exists()) {
            throw MeetingOutputTransitionException::decisionRequired();
        }

        return DB::transaction(function () use ($output, $actor) {
            $transaction = Transaction::query()
                ->with(['currentStage:id,code', 'status:id,code'])
                ->lockForUpdate()
                ->findOrFail($output->transaction_id);

            if ($transaction->currentStage?->code !== 'final_approval_archiving') {
                throw MeetingOutputTransitionException::wrongStage();
            }

            if ($transaction->status?->code !== 'in_execution') {
                throw MeetingOutputTransitionException::transitionNotAllowed();
            }

            $completedStatus = TransactionStatus::query()
                ->where('code', 'completed_closed')
                ->firstOrFail();

            $fromStatusId = $transaction->status_id;
            $transaction->update(['status_id' => $completedStatus->id]);

            TransactionStatusHistory::create([
                'transaction_id' => $transaction->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $completedStatus->id,
                'reason' => 'تم توثيق اكتمال تنفيذ مخرج الاجتماع وإغلاق الطلب.',
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $transaction->refresh();
        });
    }
}
