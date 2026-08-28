<?php

namespace Database\Seeders;

use App\Models\RequestStatus;
use App\Models\Role;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Database\Seeder;

/**
 * Seeds the normal path and its corrective/terminal exception branches.
 *
 * These rows apply to every request type. A later type-specific row for
 * the same stage/action takes precedence in WorkflowService, so a specialized
 * flow can override one step without copying the entire map.
 */
class WorkflowTransitionSeeder extends Seeder
{
    public function run(): void
    {
        $stages = WorkflowStage::query()->get()->keyBy('code');
        $roles = Role::query()->get()->keyBy('code');
        $statuses = RequestStatus::query()->get()->keyBy('code');

        // Stage 18 changes several checkpoint verbs/roles. Remove only the
        // seeded generic happy path first so old rows cannot survive a re-seed
        // beside their replacement and create an ambiguous route.
        WorkflowTransition::query()
            ->whereNull('request_type_id')
            ->where('is_exception', false)
            ->delete();

        // [from, to, action, required role, resulting status]
        //
        // Diagram-alignment redesign: the old first row
        // (receive_from_municipality -> requirements_check, action `forward`)
        // is replaced by a one-hop system handoff into the new
        // direct_manager_review stage — see AGENT_NOTES.md. An R08 override
        // sibling for the same (from_stage, action) is seeded separately
        // below via seedException(), which is why the upsert key here is
        // widened to include required_role_id: without that, this row and
        // the R08 sibling would collide on (from_stage_id, action) alone and
        // one would silently overwrite the other on every re-seed.
        $transitions = [
            ['receive_from_municipality', 'direct_manager_review', 'submit', 'R01', 'in_review'],
            ['requirements_check', 'reviewer_review', 'approve', 'R02', 'in_review'],
            ['reviewer_review', 'observations', 'forward', 'R02', 'in_review'],
            ['observations', 'ministry_endorsement', 'forward', 'R02', 'ready'],
            ['ministry_endorsement', 'forward_to_committee', 'forward', 'R05', 'ready'],
            ['forward_to_committee', 'receive_from_committee', 'forward', 'R05', 'in_meeting'],
            ['receive_from_committee', 'approval_by_authority', 'approve', 'R03', 'decided'],
            ['approval_by_authority', 'local_governance_ministry', 'approve', 'R05', 'approved'],
            ['local_governance_ministry', 'competent_authority', 'approve', 'R06', 'approved'],
            ['competent_authority', 'final_approval_archiving', 'approve', 'R07', 'final_approved'],
            // Stage 37 — final approval hands the request to execution. The
            // meeting outputs tracker performs the later status-only close.
            ['final_approval_archiving', 'final_approval_archiving', 'approve', 'R07', 'in_execution'],
        ];

        foreach ($transitions as $order => [$from, $to, $action, $role, $status]) {
            WorkflowTransition::updateOrCreate(
                [
                    'request_type_id' => null,
                    'from_stage_id' => $stages[$from]->id,
                    'action' => $action,
                    // Widened so a same-stage/same-action sibling seeded
                    // through seedException() (e.g. the R08 `submit`
                    // override below) can never collide with this row.
                    'required_role_id' => $roles[$role]->id,
                ],
                [
                    'to_stage_id' => $stages[$to]->id,
                    'required_role_id' => $roles[$role]->id,
                    // Neither the direct-manager gate nor the status gate is
                    // used by the happy path yet — set explicitly rather than
                    // relying on the column defaults, since an upsert's UPDATE
                    // branch does not re-apply a schema default.
                    'requires_submitter_manager' => false,
                    'required_status_id' => null,
                    'set_status_id' => $statuses[$status]->id,
                    'is_exception' => false,
                    'requires_comment' => false,
                    'order_no' => $order + 1,
                ],
            );
        }

        // R08 override sibling for the new intake handoff: any active R01
        // submitter's row is the normal path, but an admin can always push a
        // stuck submission forward too. Modelled as an exception (matching
        // every other R08 bypass already in this file — cancel, deadline
        // escalation), not as a second happy-path row.
        $this->seedException(
            $stages['receive_from_municipality']->id,
            $stages['direct_manager_review']->id,
            'submit',
            $roles['R08']->id,
            $statuses['in_review']->id,
            25,
        );

        // Diagram-alignment redesign: the manager's own review, moving the
        // request on to the routing decision. This is the deterministic
        // continuation of the normal path (there is exactly one way
        // forward from direct_manager_review that isn't a rejection or a
        // cancellation), so — unlike the routing/registration rows below —
        // it is written directly rather than through seedException(), with
        // is_exception=false, matching the main $transitions array's
        // semantics. It is manager-gated rather than role-gated, so it
        // can't live in that array's single-role tuple shape; no separate
        // R08 sibling is needed here (unlike `submit` above) because
        // actorMayUse() already lets R08 through any
        // requires_submitter_manager row automatically.
        WorkflowTransition::updateOrCreate(
            [
                'request_type_id' => null,
                'from_stage_id' => $stages['direct_manager_review']->id,
                'action' => 'forward',
            ],
            [
                'to_stage_id' => $stages['administrative_routing']->id,
                'required_role_id' => null,
                'requires_submitter_manager' => true,
                'required_status_id' => null,
                'set_status_id' => $statuses['in_review']->id,
                'is_exception' => false,
                'requires_comment' => false,
                'order_no' => 2,
            ],
        );

        // Diagram-alignment redesign: 3-way administrative routing out of the
        // new administrative_routing stage. Modelled as exceptions (not the
        // single-row happy path), matching the closest existing precedent —
        // conditional_approve / request_legal_opinion / refer_to_another_body
        // — three distinct named outcomes branching from one stage, chosen by
        // the actor rather than a single deterministic next step. Gated on
        // the submitter's manager (same actor as direct_manager_review, since
        // routing is that manager's decision), not a fixed role. The diagram
        // does not ask for a reason on a routing choice, so requiresComment
        // is explicitly false here — unlike every other exception in this
        // file, which defaults to requiring one.
        $routingActions = [
            'route_to_hr' => 'routed_to_hr',
            'route_to_diwan' => 'routed_to_diwan',
            'route_to_committee_secretary' => 'routed_to_committee_secretary',
        ];
        $routingOrder = 26;
        foreach ($routingActions as $action => $statusCode) {
            $this->seedException(
                $stages['administrative_routing']->id,
                $stages['receive_and_register']->id,
                $action,
                null,
                $statuses[$statusCode]->id,
                $routingOrder++,
                requiresSubmitterManager: true,
                requiresComment: false,
            );
        }

        // Diagram-alignment redesign: registration convergence. Three
        // `register` rows share the SAME action out of receive_and_register,
        // one per legitimate receiving role (R05/HR, R10/Diwan,
        // R09/Committee Secretary), each gated on BOTH its role and the
        // matching routed_to_* status the routing step above stamped —
        // required_status_id is what makes routing enforceable rather than
        // decorative: role alone can't tell which of the three routes a file
        // actually took, so R10 could otherwise register an HR-routed file.
        // Unlike the routing branch above, this is NOT modelled as an
        // exception: exactly one legitimate actor exists for any given file
        // (the status disambiguates it), so this is the deterministic
        // continuation of the normal path, not a corrective/alternate
        // outcome — it just has three role-shaped faces instead of one.
        // Written directly (not through seedException(), which hardcodes
        // is_exception=true) with an upsert key widened to include both
        // required_role_id and required_status_id: without both, all three
        // rows collapse onto the same (from_stage, action) key and only the
        // last one seeded would survive.
        $registrations = [
            ['R05', 'routed_to_hr'],
            ['R10', 'routed_to_diwan'],
            ['R09', 'routed_to_committee_secretary'],
        ];
        foreach ($registrations as $index => [$role, $routedStatusCode]) {
            WorkflowTransition::updateOrCreate(
                [
                    'request_type_id' => null,
                    'from_stage_id' => $stages['receive_and_register']->id,
                    'action' => 'register',
                    'required_role_id' => $roles[$role]->id,
                    'required_status_id' => $statuses[$routedStatusCode]->id,
                ],
                [
                    'to_stage_id' => $stages['requirements_check']->id,
                    'requires_submitter_manager' => false,
                    'set_status_id' => $statuses['registered']->id,
                    'is_exception' => false,
                    'requires_comment' => false,
                    'order_no' => 12 + $index,
                ],
            );
        }

        // Stage 16 — backward exception paths always preserve an actor's
        // reason, while cancellation keeps the last active stage as context.
        $exceptions = [
            ['requirements_check', 'receive_from_municipality', 'return_missing_docs', 'R02', 'incomplete', 20],
            ['reviewer_review', 'requirements_check', 'reject_review', 'R02', 'rejected', 20],
            ['observations', 'reviewer_review', 'request_edit', 'R02', 'returned', 20],
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

        // Diagram-alignment redesign: the manager's reject path out of the
        // new direct_manager_review stage, sending the request all the way
        // back to intake — mirrors the shape of the return_missing_docs /
        // reject_review / request_edit rows above, but manager-gated instead
        // of role-gated, and reuses the existing `returned` status.
        $this->seedException(
            $stages['direct_manager_review']->id,
            $stages['receive_from_municipality']->id,
            'return_to_employee',
            null,
            $statuses['returned']->id,
            21,
            requiresSubmitterManager: true,
        );

        // Cancellation is available to the role responsible for moving each
        // open stage. The self-loop records where work stopped without falsely
        // presenting cancellation as progress to an approval/archive stage.
        $cancellationRoles = [
            'receive_from_municipality' => 'R02',
            'requirements_check' => 'R02',
            'reviewer_review' => 'R02',
            'observations' => 'R02',
            'ministry_endorsement' => 'R05',
            'forward_to_committee' => 'R05',
            'receive_from_committee' => 'R03',
            'approval_by_authority' => 'R05',
            'local_governance_ministry' => 'R06',
            'competent_authority' => 'R07',
            'final_approval_archiving' => 'R07',
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

        // Diagram-alignment redesign: cancel at the two manager-gated new
        // stages follows the same manager-or-R08 actor rule as advancing
        // them, rather than a fixed role — only the manager currently
        // reviewing (or an admin) should be able to cancel at that point.
        foreach (['direct_manager_review', 'administrative_routing'] as $managerGatedStage) {
            $this->seedException(
                $stages[$managerGatedStage]->id,
                $stages[$managerGatedStage]->id,
                'cancel',
                null,
                $statuses['cancelled']->id,
                99,
                requiresSubmitterManager: true,
            );
        }

        // Diagram-alignment redesign: receive_and_register can be cancelled
        // by any of the three roles that could legitimately be holding the
        // file there, matching the plan's "simpler" fallback rather than the
        // register rows' per-route status gate — cancelling doesn't need to
        // prove which route was taken, only that the actor is one of the
        // three receiving roles. Three rows (not one shared row) because
        // required_role_id must differ per role; the widened seedException()
        // key keeps them from colliding with each other.
        foreach (['R05', 'R09', 'R10'] as $registrarRole) {
            $this->seedException(
                $stages['receive_and_register']->id,
                $stages['receive_and_register']->id,
                'cancel',
                $roles[$registrarRole]->id,
                $statuses['cancelled']->id,
                99,
            );
        }

        // Stage 21 — a committee vote to defer keeps the request at the
        // committee stage, status `deferred`, ready to be placed on a future
        // meeting's agenda instead of advancing to stage 8. See
        // DecisionController::record for the vote tally that triggers this.
        $this->seedException(
            $stages['receive_from_committee']->id,
            $stages['receive_from_committee']->id,
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
            $stages['receive_from_committee']->id,
            $stages['approval_by_authority']->id,
            'conditional_approve',
            $roles['R03']->id,
            $statuses['approved_with_conditions']->id,
            51,
        );
        $this->seedException(
            $stages['receive_from_committee']->id,
            $stages['receive_from_committee']->id,
            'request_legal_opinion',
            $roles['R03']->id,
            $statuses['legal_opinion_requested']->id,
            52,
        );
        $this->seedException(
            $stages['receive_from_committee']->id,
            $stages['receive_from_committee']->id,
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
            $stages['receive_from_committee']->id,
            $stages['observations']->id,
            'return_to_study',
            $roles['R03']->id,
            $statuses['returned']->id,
            55,
        );

        // Stage 17 — once the SLA sweep marks a breach, an administrator can
        // route the case to ministry oversight. The deadline is a separate
        // flag, so this does not disguise the actual workflow state as a
        // reporting-only "overdue" status.
        // Ordered by order_no rather than a numeric range, since stage codes
        // (not literal integers) are now the identity key throughout.
        //
        // Diagram-alignment redesign: extended to also cover the three new
        // front-half stages — any open stage before the final archiving step
        // can breach its SLA and escalate, which was already this loop's
        // intent for the original ten stages.
        $openStageCodesForDeadlineEscalation = [
            'receive_from_municipality',
            'direct_manager_review',
            'administrative_routing',
            'receive_and_register',
            'requirements_check',
            'reviewer_review',
            'observations',
            'ministry_endorsement',
            'forward_to_committee',
            'receive_from_committee',
            'approval_by_authority',
            'local_governance_ministry',
            'competent_authority',
        ];

        foreach ($openStageCodesForDeadlineEscalation as $stageCode) {
            $this->seedException(
                $stages[$stageCode]->id,
                $stages['local_governance_ministry']->id,
                'deadline_expired',
                $roles['R08']->id,
                $statuses['in_review']->id,
                90,
            );
        }
    }

    /**
     * $requiredRoleId is nullable because a manager-gated row (Phase 3 of the
     * direct-manager redesign, AGENT_NOTES.md) has no single fixed role — the
     * actor is resolved dynamically against the request creator's
     * manager, with R08 as the fallback (see WorkflowService::actorMayUse).
     *
     * The upsert key includes required_role_id and required_status_id (not
     * just from_stage_id/action) because several diagram-alignment rows
     * deliberately share one action name from one stage with siblings that
     * differ only by role and/or required status (the `register` rows'
     * closest exception-side counterpart: the three `cancel` rows at
     * receive_and_register, and the `submit` R08 override sitting alongside
     * the R01 happy-path row). Without the wider key, those siblings would
     * collapse onto the same row and only the last one seeded would survive
     * a re-seed. This is additive-safe for every pre-existing call site:
     * each already has exactly one row per (from_stage, action), so widening
     * the key does not change which row any of them resolves to.
     */
    private function seedException(
        int $fromStageId,
        int $toStageId,
        string $action,
        ?int $requiredRoleId,
        int $statusId,
        int $order,
        bool $requiresSubmitterManager = false,
        ?int $requiredStatusId = null,
        bool $requiresComment = true,
    ): void {
        WorkflowTransition::updateOrCreate(
            [
                'request_type_id' => null,
                'from_stage_id' => $fromStageId,
                'action' => $action,
                'required_role_id' => $requiredRoleId,
                'required_status_id' => $requiredStatusId,
            ],
            [
                'to_stage_id' => $toStageId,
                'requires_submitter_manager' => $requiresSubmitterManager,
                'set_status_id' => $statusId,
                'is_exception' => true,
                'requires_comment' => $requiresComment,
                'order_no' => $order,
            ],
        );
    }
}
