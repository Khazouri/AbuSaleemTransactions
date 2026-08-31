<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CommitteeStatusTransitionException;
use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommitteeCandidate\CommitteeCandidateActionRequest;
use App\Http\Requests\CommitteeCandidate\IndexCommitteeCandidateRequest;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use App\Services\CommitteeStatusService;
use App\Services\WorkflowService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Stage 32 — the candidate-requests worklist: requests sitting with the
 * committee, not yet placed on a meeting's agenda
 * (CommitteeStatusService::CANDIDATE_STATUSES). Replaces the ad-hoc
 * request search that used to be the only way a request reached an
 * agenda (see MeetingAgendaBuilderView) with a first-class linking screen.
 *
 * nominate() and requestCompletion() are status-only committee bookkeeping,
 * so they run through CommitteeStatusService and never touch
 * current_stage_id. defer() and returnToStudy() both change what stage the
 * request is officially at (defer is Stage 21's existing 7→7 exception;
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

        $requests = $committeeStatus->candidatesQuery()
            ->with([
                'department:id,name_ar,name_en,code',
                'requestType:id,code,name_ar,name_en,decision_grade_threshold',
                'status:id,code,name_ar,name_en,color',
                'currentStage:id,order_no,code,name_ar,name_en',
                // Stage 44 — closes the [C] §2 fidelity gap: الموظف, اكتمال
                // الملف, الاجتماع المقترح (and its priority, standing in for
                // §2's own الأولوية column, which otherwise has no meaning
                // before a candidate is placed on any agenda).
                'createdBy:id,name',
                'meetingRequests.meeting:id,title,scheduled_at,status',
            ])
            ->withCount('attachments')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query
                ->whereHas('status', fn ($statusQuery) => $statusQuery->where('code', $status)))
            ->when($filters['department_id'] ?? null, fn ($query, int $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['type_id'] ?? null, fn ($query, int $typeId) => $query->where('request_type_id', $typeId))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($inner) => $inner
                    ->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%"),
            ))
            ->orderBy('submitted_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return RequestResource::collection($requests);
    }

    public function nominate(CommitteeCandidateActionRequest $request, Request $requestRecord, CommitteeStatusService $committeeStatus): RequestResource
    {
        return $this->moveStatus($request, $requestRecord, $committeeStatus, 'nominate');
    }

    public function requestCompletion(CommitteeCandidateActionRequest $request, Request $requestRecord, CommitteeStatusService $committeeStatus): RequestResource
    {
        return $this->moveStatus($request, $requestRecord, $committeeStatus, 'require_completion');
    }

    public function defer(CommitteeCandidateActionRequest $request, Request $requestRecord, WorkflowService $workflow): RequestResource
    {
        return $this->moveStage($request, $requestRecord, $workflow, 'defer');
    }

    public function returnToStudy(CommitteeCandidateActionRequest $request, Request $requestRecord, WorkflowService $workflow): RequestResource
    {
        return $this->moveStage($request, $requestRecord, $workflow, 'return_to_study');
    }

    private function moveStatus(
        CommitteeCandidateActionRequest $request,
        Request $requestRecord,
        CommitteeStatusService $committeeStatus,
        string $action,
    ): RequestResource {
        try {
            $requestRecord = $committeeStatus->move($requestRecord, $action, $request->user(), $request->validated('comment'));
        } catch (CommitteeStatusTransitionException $exception) {
            throw ValidationException::withMessages(['action' => [$exception->getMessage()]]);
        }

        return new RequestResource($requestRecord->load(['department', 'requestType', 'status', 'currentStage']));
    }

    private function moveStage(
        CommitteeCandidateActionRequest $request,
        Request $requestRecord,
        WorkflowService $workflow,
        string $action,
    ): RequestResource {
        try {
            $requestRecord = $workflow->transition($requestRecord, $action, $request->user(), $request->validated('comment'));
        } catch (WorkflowTransitionException $exception) {
            throw ValidationException::withMessages(['action' => [$exception->getMessage()]]);
        }

        return new RequestResource($requestRecord->load(['department', 'requestType', 'status', 'currentStage']));
    }
}
