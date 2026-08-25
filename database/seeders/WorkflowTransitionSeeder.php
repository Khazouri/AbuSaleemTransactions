<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\TransactionStatus;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Database\Seeder;

/**
 * Seeds the normal path and its corrective/terminal exception branches.
 *
 * These rows apply to every transaction type. A later type-specific row for
 * the same stage/action takes precedence in WorkflowService, so a specialized
 * flow can override one step without copying the entire map.
 */
class WorkflowTransitionSeeder extends Seeder
{
    public function run(): void
    {
        $stages = WorkflowStage::query()->get()->keyBy('order_no');
        $roles = Role::query()->get()->keyBy('code');
        $statuses = TransactionStatus::query()->get()->keyBy('code');

        // Stage 18 changes several checkpoint verbs/roles. Remove only the
        // seeded generic happy path first so old rows cannot survive a re-seed
        // beside their replacement and create an ambiguous route.
        WorkflowTransition::query()
            ->whereNull('transaction_type_id')
            ->where('is_exception', false)
            ->delete();

        // [from, to, action, required role, resulting status]
        $transitions = [
            [1, 2, 'forward', 'R02', 'in_review'],
            [2, 3, 'approve', 'R02', 'in_review'],
            [3, 4, 'forward', 'R02', 'in_review'],
            [4, 5, 'forward', 'R02', 'ready'],
            [5, 6, 'forward', 'R05', 'ready'],
            [6, 7, 'forward', 'R05', 'in_meeting'],
            [7, 8, 'approve', 'R03', 'decided'],
            [8, 9, 'approve', 'R05', 'approved'],
            [9, 10, 'approve', 'R06', 'approved'],
            [10, 11, 'approve', 'R07', 'final_approved'],
            [11, 11, 'approve', 'R07', 'archived'],
        ];

        foreach ($transitions as $order => [$from, $to, $action, $role, $status]) {
            WorkflowTransition::updateOrCreate(
                [
                    'transaction_type_id' => null,
                    'from_stage_id' => $stages[$from]->id,
                    'action' => $action,
                ],
                [
                    'to_stage_id' => $stages[$to]->id,
                    'required_role_id' => $roles[$role]->id,
                    'set_status_id' => $statuses[$status]->id,
                    'is_exception' => false,
                    'requires_comment' => false,
                    'order_no' => $order + 1,
                ],
            );
        }

        // Stage 16 — backward exception paths always preserve an actor's
        // reason, while cancellation keeps the last active stage as context.
        $exceptions = [
            [2, 1, 'return_missing_docs', 'R02', 'incomplete', 20],
            [3, 2, 'reject_review', 'R02', 'rejected', 20],
            [4, 3, 'request_edit', 'R02', 'returned', 20],
        ];

        foreach ($exceptions as [$from, $to, $action, $role, $status, $order]) {
            $this->seedException(
                $stages[$from]->id,
                $stages[$to]->id,
                $action,
                $roles[$role]->id,
                $statuses[$status]->id,
                $order,
            );
        }

        // Cancellation is available to the role responsible for moving each
        // open stage. The self-loop records where work stopped without falsely
        // presenting cancellation as progress to an approval/archive stage.
        $cancellationRoles = [
            1 => 'R02',
            2 => 'R02',
            3 => 'R02',
            4 => 'R02',
            5 => 'R05',
            6 => 'R05',
            7 => 'R03',
            8 => 'R05',
            9 => 'R06',
            10 => 'R07',
            11 => 'R07',
        ];

        foreach ($cancellationRoles as $stage => $role) {
            $this->seedException(
                $stages[$stage]->id,
                $stages[$stage]->id,
                'cancel',
                $roles[$role]->id,
                $statuses['cancelled']->id,
                99,
            );
        }

        // Stage 21 — a committee vote to defer keeps the transaction at the
        // committee stage, status `deferred`, ready to be placed on a future
        // meeting's agenda instead of advancing to stage 8. See
        // DecisionController::record for the vote tally that triggers this.
        $this->seedException(
            $stages[7]->id,
            $stages[7]->id,
            'defer',
            $roles['R03']->id,
            $statuses['deferred']->id,
            50,
        );

        // Stage 35 — three more committee-decision outcomes alongside
        // approve/reject(cancel)/defer. `conditional_approve` moves forward
        // like `approve` (same destination stage) but stays an exception row
        // — see DecisionController::ACTIONS and this stage's AGENT_NOTES entry
        // for why none of the three require a signature or write an Approval
        // ledger row, unlike the plain `approve` action at this same stage.
        $this->seedException(
            $stages[7]->id,
            $stages[8]->id,
            'conditional_approve',
            $roles['R03']->id,
            $statuses['approved_with_conditions']->id,
            51,
        );
        $this->seedException(
            $stages[7]->id,
            $stages[7]->id,
            'request_legal_opinion',
            $roles['R03']->id,
            $statuses['legal_opinion_requested']->id,
            52,
        );
        $this->seedException(
            $stages[7]->id,
            $stages[7]->id,
            'refer_to_another_body',
            $roles['R03']->id,
            $statuses['referred_to_other_body']->id,
            53,
        );

        // Stage 32 — the candidate-requests worklist's "return to study": the
        // committee sends a request back to the observations checkpoint for
        // more work before it can be nominated again. Unlike CommitteeStatusService's
        // status-only moves, this crosses back over a stage boundary, so it
        // has to be a WorkflowService exception, not a committee sub-status.
        $this->seedException(
            $stages[7]->id,
            $stages[4]->id,
            'return_to_study',
            $roles['R03']->id,
            $statuses['returned']->id,
            55,
        );

        // Stage 17 — once the SLA sweep marks a breach, an administrator can
        // route the case to ministry oversight. The deadline is a separate
        // flag, so this does not disguise the actual workflow state as a
        // reporting-only "overdue" status.
        foreach (range(1, 10) as $stage) {
            $this->seedException(
                $stages[$stage]->id,
                $stages[9]->id,
                'deadline_expired',
                $roles['R08']->id,
                $statuses['in_review']->id,
                90,
            );
        }
    }

    private function seedException(
        int $fromStageId,
        int $toStageId,
        string $action,
        int $requiredRoleId,
        int $statusId,
        int $order,
    ): void {
        WorkflowTransition::updateOrCreate(
            [
                'transaction_type_id' => null,
                'from_stage_id' => $fromStageId,
                'action' => $action,
            ],
            [
                'to_stage_id' => $toStageId,
                'required_role_id' => $requiredRoleId,
                'set_status_id' => $statusId,
                'is_exception' => true,
                'requires_comment' => true,
                'order_no' => $order,
            ],
        );
    }
}
