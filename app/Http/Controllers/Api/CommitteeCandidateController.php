<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CommitteeStatusTransitionException;
use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommitteeCandidate\CommitteeCandidateActionRequest;
use App\Http\Requests\CommitteeCandidate\IndexCommitteeCandidateRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\CommitteeStatusService;
use App\Services\WorkflowService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Stage 32 — the candidate-requests worklist: transactions sitting with the
 * committee, not yet placed on a meeting's agenda
 * (CommitteeStatusService::CANDIDATE_STATUSES). Replaces the ad-hoc
 * transaction search that used to be the only way a request reached an
 * agenda (see MeetingAgendaBuilderView) with a first-class linking screen.
 *
 * nominate() and requestCompletion() are status-only committee bookkeeping,
 * so they run through CommitteeStatusService and never touch
 * current_stage_id. defer() and returnToStudy() both change what stage the
 * transaction is officially at (defer is Stage 21's existing 7→7 exception;
 * return_to_study is this stage's new 7→4 exception) — that crosses the
 * exact boundary CommitteeStatusService refuses to cross by design, so both
 * run through WorkflowService instead. See the AGENT_NOTES Stage 32 entry
 * for why the four design-doc verbs split this way.
 */
class CommitteeCandidateController extends Controller
{
    public function index(IndexCommitteeCandidateRequest $request, CommitteeStatusService $committeeStatus): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $transactions = $committeeStatus->candidatesQuery()
            ->with([
                'department:id,name_ar,name_en,code',
                'transactionType:id,code,name_ar,name_en,decision_grade_threshold',
                'status:id,code,name_ar,name_en,color',
                'currentStage:id,order_no,code,name_ar,name_en',
            ])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query
                ->whereHas('status', fn ($statusQuery) => $statusQuery->where('code', $status)))
            ->when($filters['department_id'] ?? null, fn ($query, int $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['type_id'] ?? null, fn ($query, int $typeId) => $query->where('transaction_type_id', $typeId))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($inner) => $inner
                    ->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%"),
            ))
            ->orderBy('submitted_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    public function nominate(CommitteeCandidateActionRequest $request, Transaction $transaction, CommitteeStatusService $committeeStatus): TransactionResource
    {
        return $this->moveStatus($request, $transaction, $committeeStatus, 'nominate');
    }

    public function requestCompletion(CommitteeCandidateActionRequest $request, Transaction $transaction, CommitteeStatusService $committeeStatus): TransactionResource
    {
        return $this->moveStatus($request, $transaction, $committeeStatus, 'require_completion');
    }

    public function defer(CommitteeCandidateActionRequest $request, Transaction $transaction, WorkflowService $workflow): TransactionResource
    {
        return $this->moveStage($request, $transaction, $workflow, 'defer');
    }

    public function returnToStudy(CommitteeCandidateActionRequest $request, Transaction $transaction, WorkflowService $workflow): TransactionResource
    {
        return $this->moveStage($request, $transaction, $workflow, 'return_to_study');
    }

    private function moveStatus(
        CommitteeCandidateActionRequest $request,
        Transaction $transaction,
        CommitteeStatusService $committeeStatus,
        string $action,
    ): TransactionResource {
        try {
            $transaction = $committeeStatus->move($transaction, $action, $request->user(), $request->validated('comment'));
        } catch (CommitteeStatusTransitionException $exception) {
            throw ValidationException::withMessages(['action' => [$exception->getMessage()]]);
        }

        return new TransactionResource($transaction->load(['department', 'transactionType', 'status', 'currentStage']));
    }

    private function moveStage(
        CommitteeCandidateActionRequest $request,
        Transaction $transaction,
        WorkflowService $workflow,
        string $action,
    ): TransactionResource {
        try {
            $transaction = $workflow->transition($transaction, $action, $request->user(), $request->validated('comment'));
        } catch (WorkflowTransitionException $exception) {
            throw ValidationException::withMessages(['action' => [$exception->getMessage()]]);
        }

        return new TransactionResource($transaction->load(['department', 'transactionType', 'status', 'currentStage']));
    }
}
