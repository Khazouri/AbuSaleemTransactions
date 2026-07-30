<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\IndexTransactionRequest;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Requests\Transaction\TransitionTransactionRequest;
use App\Http\Resources\TransactionDetailResource;
use App\Http\Resources\TransactionResource;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\Transaction;
use App\Models\TransactionStageLog;
use App\Models\TransactionStatus;
use App\Models\TransactionStatusHistory;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalSignatureStorage;
use App\Services\TransactionDeadlineService;
use App\Services\TransactionReferenceGenerator;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stage 11 read model for transaction work queues.
 *
 * Stage 13 adds the controlled intake write path; workflow actions remain a
 * later concern, leaving this controller responsible only for entering work.
 */
class TransactionController extends Controller
{
    public function index(IndexTransactionRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $transactions = Transaction::query()
            ->with([
                'department:id,name_ar,name_en,code',
                'transactionType:id,code,name_ar,name_en,decision_grade_threshold',
                'status:id,code,name_ar,name_en,color',
                'currentStage:id,order_no,code,name_ar,name_en',
            ])
            ->when($filters['status'] ?? null, function ($query, string $status) {
                $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('code', $status));
            })
            ->when($filters['department_id'] ?? null, fn ($query, int $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['type_id'] ?? null, fn ($query, int $typeId) => $query->where('transaction_type_id', $typeId))
            ->when($filters['date_from'] ?? null, fn ($query, string $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn ($query, string $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    /** Lookup values travel separately so filters are useful even with no rows. */
    public function filters(): JsonResponse
    {
        return response()->json([
            'data' => [
                'statuses' => TransactionStatus::query()
                    ->orderBy('name_ar')
                    ->get(['code', 'name_ar', 'name_en', 'color']),
                'departments' => Department::query()
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar', 'name_en', 'code']),
                'types' => TransactionType::query()
                    ->orderBy('name_ar')
                    ->get(['id', 'code', 'name_ar', 'name_en']),
            ],
        ]);
    }

    /** Lookup values for intake are separate from work-queue access rights. */
    public function intakeOptions(): JsonResponse
    {
        return response()->json([
            'data' => [
                'departments' => Department::query()
                    ->where('is_active', true)
                    ->whereNotNull('code')
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar', 'name_en', 'code']),
                'types' => TransactionType::query()
                    ->where('is_active', true)
                    ->orderBy('name_ar')
                    ->get(['id', 'code', 'name_ar', 'name_en', 'decision_grade_threshold']),
            ],
        ]);
    }

    public function store(
        StoreTransactionRequest $request,
        TransactionReferenceGenerator $references,
        TransactionDeadlineService $deadlines,
    ): JsonResponse {
        $data = $request->validated();
        $storedPaths = [];

        try {
            $transaction = DB::transaction(function () use ($data, $request, $references, $deadlines, &$storedPaths) {
                $department = Department::query()->findOrFail($data['department_id']);
                $type = TransactionType::query()->findOrFail($data['transaction_type_id']);
                $newStatus = TransactionStatus::query()->where('code', 'new')->firstOrFail();
                $firstStage = WorkflowStage::query()->where('order_no', 1)->firstOrFail();
                $submittedAt = now();

                $transaction = Transaction::create([
                    'reference_number' => $references->nextFor($department),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'department_id' => $department->id,
                    'transaction_type_id' => $data['transaction_type_id'],
                    'status_id' => $newStatus->id,
                    'current_stage_id' => $firstStage->id,
                    'created_by_user_id' => $request->user()->id,
                    'submitted_at' => $submittedAt,
                    'due_date' => $deadlines->dueDateFor($type, $submittedAt),
                    'decision_grade' => $data['decision_grade'] ?? null,
                ]);

                foreach ($request->file('attachments', []) as $index => $attachmentInput) {
                    $file = $attachmentInput['file'];
                    $path = $file->store("attachments/{$transaction->id}", 'local');
                    $storedPaths[] = $path;

                    Attachment::create([
                        'transaction_id' => $transaction->id,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                        'label' => $data['attachments'][$index]['label'] ?? null,
                        'uploaded_by_user_id' => $request->user()->id,
                    ]);
                }

                // Intake is the first observable state, so later timelines
                // have a truthful origin rather than starting at stage two.
                TransactionStageLog::create([
                    'transaction_id' => $transaction->id,
                    'to_stage_id' => $firstStage->id,
                    'action' => 'intake',
                    'acted_by_user_id' => $request->user()->id,
                    'acted_at' => now(),
                ]);
                TransactionStatusHistory::create([
                    'transaction_id' => $transaction->id,
                    'to_status_id' => $newStatus->id,
                    'changed_by_user_id' => $request->user()->id,
                    'changed_at' => now(),
                ]);

                return $transaction->load([
                    'department:id,name_ar,name_en,code',
                    'transactionType:id,code,name_ar,name_en,decision_grade_threshold',
                    'status:id,code,name_ar,name_en,color',
                    'currentStage:id,order_no,code,name_ar,name_en',
                ]);
            });
        } catch (Throwable $exception) {
            // DB rollback cannot roll back private disk writes; remove only
            // paths made by this request so failed intake leaves no residue.
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

        return (new TransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    /** Stage 15 — one complete transaction workspace, including its audit timeline. */
    public function show(Request $request, Transaction $transaction, WorkflowService $workflow): TransactionDetailResource
    {
        return $this->detailResource($transaction, $workflow, $request->user());
    }

    /** Stage 15 — adapt the state-machine failure into the SPA's normal 422 shape. */
    public function transition(
        TransitionTransactionRequest $request,
        Transaction $transaction,
        WorkflowService $workflow,
        ApprovalSignatureStorage $signatureStorage,
    ): TransactionDetailResource {
        $action = $request->validated('action');

        // Stage 18 — the older generic workspace endpoint must not become a
        // back door around a revoked approval-screen capability.
        if ($action === 'approve' && ! $this->actorCanApproveCurrentLevel($transaction, $request->user())) {
            throw ValidationException::withMessages([
                'action' => ['لا تملك صلاحية الاعتماد في هذه المرحلة.'],
            ]);
        }

        // Stage 19 — only an approval writes signature evidence; ordinary
        // forwards and exception commands remain compact JSON/form commands.
        $signaturePath = $action === 'approve'
            ? $signatureStorage->store($request->file('signature'), $transaction)
            : null;

        try {
            $transaction = $workflow->transition(
                $transaction,
                $action,
                $request->user(),
                $request->validated('comment'),
                $signaturePath,
            );
        } catch (WorkflowTransitionException $exception) {
            $signatureStorage->delete($signaturePath);

            throw ValidationException::withMessages([
                'action' => [$exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            $signatureStorage->delete($signaturePath);

            throw $exception;
        }

        return $this->detailResource($transaction, $workflow, $request->user());
    }

    private function detailResource(Transaction $transaction, WorkflowService $workflow, $actor): TransactionDetailResource
    {
        $transaction->load([
            'department:id,name_ar,name_en,code',
            'transactionType:id,code,name_ar,name_en,decision_grade_threshold',
            'status:id,code,name_ar,name_en,color',
            'currentStage:id,order_no,code,name_ar,name_en',
            'createdBy:id,name',
            'attachments:id,transaction_id,original_name,mime_type,size_bytes,label,uploaded_by_user_id,created_at',
            'stageLogs' => fn ($query) => $query->orderBy('acted_at')->orderBy('id'),
            'stageLogs.fromStage:id,order_no,code,name_ar,name_en',
            'stageLogs.toStage:id,order_no,code,name_ar,name_en',
            'stageLogs.actedBy:id,name',
            'approvals' => fn ($query) => $query
                ->select([
                    'id',
                    'transaction_id',
                    'level',
                    'role_id',
                    'approved_by_user_id',
                    'action',
                    'comment',
                    'signature_path',
                    'approved_at',
                ])
                ->orderBy('approved_at')
                ->orderBy('id'),
            'approvals.role:id,code,name_ar,name_en',
            'approvals.approvedBy:id,name',
        ]);
        $availableTransitions = $workflow->availableTransitions($transaction, $actor)
            ->filter(fn ($rule) => $rule->action !== 'approve'
                || $this->actorCanApproveCurrentLevel($transaction, $actor))
            ->values();
        $transaction->setAttribute('available_actions', $availableTransitions->pluck('action')->all());
        $transaction->setAttribute('available_transitions', $availableTransitions->map(fn ($rule) => [
            'action' => $rule->action,
            'is_exception' => $rule->is_exception,
            'requires_comment' => $rule->requires_comment,
        ])->values()->all());

        return new TransactionDetailResource($transaction);
    }

    /** Approval screen paired with each of Stage 18's six checkpoints. */
    private function actorCanApproveCurrentLevel(Transaction $transaction, User $actor): bool
    {
        $screenByStage = [
            2 => 'reviewer_approval',
            7 => 'committee_head_approval',
            8 => 'admin_manager_approval',
            9 => 'ministry_approval',
            10 => 'authority_approval',
            11 => 'final_approval',
        ];
        $stageOrder = $transaction->currentStage()->value('order_no');
        $screenCode = $screenByStage[$stageOrder] ?? null;

        return $screenCode === null || $actor->hasScreenPermission($screenCode, 'can_approve');
    }
}
