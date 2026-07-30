<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\TransactionStatus;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Database\Seeder;

/**
 * Stage 14 — seeds the normal forward path through all eleven stages.
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

        // [from, to, action, required role, resulting status]
        $transitions = [
            [1, 2, 'forward', 'R02', 'in_review'],
            [2, 3, 'approve', 'R02', 'in_review'],
            [3, 4, 'forward', 'R02', 'in_review'],
            [4, 5, 'forward', 'R02', 'ready'],
            [5, 6, 'approve', 'R05', 'ready'],
            [6, 7, 'forward', 'R05', 'in_meeting'],
            [7, 8, 'forward', 'R03', 'decided'],
            [8, 9, 'approve', 'R03', 'approved'],
            [9, 10, 'approve', 'R06', 'final_approved'],
            [10, 11, 'approve', 'R07', 'archived'],
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
    }
}
