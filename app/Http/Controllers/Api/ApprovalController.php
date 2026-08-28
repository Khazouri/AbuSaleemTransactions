<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\StoreApprovalRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\ApprovalSignatureStorage;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stage 18 approval work queues.
 *
 * Route middleware binds every level to its own screen permission. This map
 * then limits the queue to the exact workflow checkpoint represented by that
 * screen; WorkflowService remains the locked, role-aware write authority.
 */
class ApprovalController extends Controller
{
    private const LEVELS = [
        'reviewer' => ['stage' => 'requirements_check', 'role' => 'R02'],
        'committee-head' => ['stage' => 'receive_from_committee', 'role' => 'R03'],
        'admin-manager' => ['stage' => 'approval_by_authority', 'role' => 'R05'],
        'ministry' => ['stage' => 'local_governance_ministry', 'role' => 'R06'],
        'authority' => ['stage' => 'competent_authority', 'role' => 'R07'],
        'final' => ['stage' => 'final_approval_archiving', 'role' => 'R07'],
    ];

    // Stage 18 — role-specific pending approval queues.
    public function index(Request $request, string $level): AnonymousResourceCollection
    {
        $configuration = $this->configuration($level);

        $transactions = Transaction::query()
            ->with([
                'department:id,name_ar,name_en,code',
                'transactionType:id,code,name_ar,name_en,decision_grade_threshold',
                'status:id,code,name_ar,name_en,color',
                'currentStage:id,order_no,code,name_ar,name_en',
            ])
            ->whereHas('currentStage', fn ($query) => $query->where('code', $configuration['stage']))
            // Stage 37: final approval moves to in_execution; keeping that
            // status out prevents the stage-11 self-loop being approved twice.
            ->whereDoesntHave('status', fn ($query) => $query->whereIn(
                'code',
                ['cancelled', 'archived', 'in_execution', 'completed_closed'],
            ))
            ->latest('submitted_at')
            ->paginate(20)
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    // Stage 18 — approve only the checkpoint named by the current queue.
    public function store(
        StoreApprovalRequest $request,
        Transaction $transaction,
        string $level,
        WorkflowService $workflow,
        ApprovalSignatureStorage $signatureStorage,
    ): TransactionResource {
        $configuration = $this->configuration($level);
        $currentStageCode = $transaction->currentStage()->value('code');

        if ($currentStageCode !== $configuration['stage']) {
            throw ValidationException::withMessages([
                'transaction' => ['لا توجد المعاملة في مستوى الاعتماد المطلوب.'],
            ]);
        }

        if (! $request->user()->roles()->where('code', $configuration['role'])->exists()) {
            throw ValidationException::withMessages([
                'transaction' => ['لا يملك المستخدم الدور المطلوب لهذا المستوى من الاعتماد.'],
            ]);
        }

        $signaturePath = $signatureStorage->store($request->file('signature'), $transaction);

        try {
            $transaction = $workflow->transition(
                $transaction,
                'approve',
                $request->user(),
                $request->validated('comment'),
                $signaturePath,
            );
        } catch (WorkflowTransitionException $exception) {
            $signatureStorage->delete($signaturePath);

            throw ValidationException::withMessages([
                'transaction' => [$exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            $signatureStorage->delete($signaturePath);

            throw $exception;
        }

        return new TransactionResource($transaction->load([
            'department:id,name_ar,name_en,code',
            'transactionType:id,code,name_ar,name_en,decision_grade_threshold',
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
