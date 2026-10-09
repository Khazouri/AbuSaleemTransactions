<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\StoreApprovalRequest;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use App\Services\Tasks\PendingTaskCollector;
use App\Services\WorkflowService;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Stage 18 approval work queues.
 *
 * Route middleware binds every level to its own screen permission. This map
 * then limits the queue to the exact workflow checkpoint represented by that
 * screen; WorkflowService remains the locked, role-aware write authority.
 */
class ApprovalController extends Controller
{
    // Stage 57 — the `authority` checkpoint (competent_authority) is gone: no
    // standard document names a fourth post-committee approving party. R07
    // keeps exactly one approval screen now, `final`.
    /**
     * The five approval checkpoints: stage, the one role that may approve it,
     * and the screen whose `approve` flag carries that capability.
     *
     * Public because it is one fact with three readers — this queue, the
     * pending-task inbox, and RequestController::actorCanApproveCurrentLevel()
     * — which used to spell the stage-to-screen half out separately. The
     * `screen` entries are not menu entries: those five screens carry no route
     * any more, and exist purely as the per-checkpoint segregation of duties
     * the whole approval chain rests on.
     */
    public const LEVELS = [
        'reviewer' => ['stage' => 'requirements_check', 'role' => 'R02', 'screen' => 'reviewer_approval'],
        'committee-head' => ['stage' => 'receive_from_committee', 'role' => 'R03', 'screen' => 'committee_head_approval'],
        'admin-manager' => ['stage' => 'approval_by_authority', 'role' => 'R05', 'screen' => 'admin_manager_approval'],
        'ministry' => ['stage' => 'local_governance_ministry', 'role' => 'R06', 'screen' => 'ministry_approval'],
        'final' => ['stage' => 'final_approval_archiving', 'role' => 'R07', 'screen' => 'final_approval'],
    ];

    // Stage 18 — role-specific pending approval queues.
    public function index(HttpRequest $request, string $level, PendingTaskCollector $tasks): AnonymousResourceCollection
    {
        $configuration = $this->configuration($level);

        // One definition of "pending approval", shared with the task inbox:
        // the self-created exclusion and the terminal-status list live there
        // now. This narrows it to the single checkpoint this queue is for.
        $requests = $tasks->approvalsQuery($request->user(), [$configuration['stage']])
            ->with([
                'department:id,name_ar,name_en,code',
                'requestType:id,code,name_ar,name_en,decision_grade_threshold',
                'status:id,code,name_ar,name_en,color',
                'currentStage:id,order_no,code,name_ar,name_en',
            ])
            ->latest('submitted_at')
            ->paginate(20)
            ->withQueryString();

        return RequestResource::collection($requests);
    }

    // Stage 18 — approve only the checkpoint named by the current queue.
    public function store(
        StoreApprovalRequest $request,
        Request $requestRecord,
        string $level,
        WorkflowService $workflow,
    ): RequestResource {
        $configuration = $this->configuration($level);
        $currentStageCode = $requestRecord->currentStage()->value('code');

        if ($currentStageCode !== $configuration['stage']) {
            throw ValidationException::withMessages([
                'request' => ['لا توجد الطلب في مستوى الاعتماد المطلوب.'],
            ]);
        }

        if (! $request->user()->roles()->where('code', $configuration['role'])->exists()) {
            throw ValidationException::withMessages([
                'request' => ['لا يملك المستخدم الدور المطلوب لهذا المستوى من الاعتماد.'],
            ]);
        }

        // Stage 54 — the reviewer queue is the primary way `requirements_check`
        // gets approved; this must refuse the same way RequestController's
        // generic transition endpoint does, or the jurisdiction test would be
        // trivially bypassable through this parallel entry point.
        if ($configuration['stage'] === 'requirements_check' && $requestRecord->jurisdiction_test === null) {
            throw ValidationException::withMessages([
                'request' => ['يجب إكمال اختبار الاختصاص (المادة 45) قبل اتخاذ هذا الإجراء.'],
            ]);
        }

        // Stage 77 — [D] Art. 94: a محضر the approving body sent back is not
        // approved onward until the re-processing action has been recorded.
        // The queue is the primary way the two approving-body checkpoints get
        // approved, so this must refuse exactly the way RequestController's
        // generic transition endpoint does — the same reason the jurisdiction
        // gate above is repeated here.
        if ($requestRecord->openApprovalReturn()->exists()) {
            throw ValidationException::withMessages([
                'request' => [RequestController::APPROVAL_RETURN_BLOCK_MESSAGE],
            ]);
        }

        // Stage 78 — [D] Appendix 63's control gates and Arts. 103/105, read
        // from the one predicate RequestController::transition() and the
        // detail screen's preview filter also read. The queue is the primary
        // way `requirements_check` and `final_approval_archiving` get
        // approved, so gating only the generic endpoint would leave both this
        // stage's new gates wide open here — the same reason the jurisdiction
        // and approval-return checks above are repeated.
        if (($gateRefusal = RequestController::controlGateRefusal($requestRecord, 'approve')) !== null) {
            throw ValidationException::withMessages(['request' => [$gateRefusal]]);
        }

        try {
            $requestRecord = $workflow->transition(
                $requestRecord,
                'approve',
                $request->user(),
                $request->validated('comment'),
            );
        } catch (WorkflowTransitionException $exception) {
            throw ValidationException::withMessages([
                'request' => [$exception->getMessage()],
            ]);
        }

        return new RequestResource($requestRecord->load([
            'department:id,name_ar,name_en,code',
            'requestType:id,code,name_ar,name_en,decision_grade_threshold',
            'status:id,code,name_ar,name_en,color',
            'currentStage:id,order_no,code,name_ar,name_en',
        ]));
    }

    /**
     * Routes only supply known constants, but fail closed if one is ever
     * miswired instead of silently exposing a broader queue.
     *
     * @return array{stage: string, role: string}
     */
    private function configuration(string $level): array
    {
        abort_unless(isset(self::LEVELS[$level]), 404);

        return self::LEVELS[$level];
    }
}
