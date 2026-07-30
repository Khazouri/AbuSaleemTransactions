<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\IndexTransactionRequest;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\Transaction;
use App\Models\TransactionStageLog;
use App\Models\TransactionStatus;
use App\Models\TransactionStatusHistory;
use App\Models\TransactionType;
use App\Models\WorkflowStage;
use App\Services\TransactionReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
                'transactionType:id,code,name_ar,name_en',
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
                    ->get(['id', 'code', 'name_ar', 'name_en']),
            ],
        ]);
    }

    public function store(StoreTransactionRequest $request, TransactionReferenceGenerator $references): JsonResponse
    {
        $data = $request->validated();
        $storedPaths = [];

        try {
            $transaction = DB::transaction(function () use ($data, $request, $references, &$storedPaths) {
                $department = Department::query()->findOrFail($data['department_id']);
                $newStatus = TransactionStatus::query()->where('code', 'new')->firstOrFail();
                $firstStage = WorkflowStage::query()->where('order_no', 1)->firstOrFail();

                $transaction = Transaction::create([
                    'reference_number' => $references->nextFor($department),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'department_id' => $department->id,
                    'transaction_type_id' => $data['transaction_type_id'],
                    'status_id' => $newStatus->id,
                    'current_stage_id' => $firstStage->id,
                    'created_by_user_id' => $request->user()->id,
                    'submitted_at' => now(),
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
                    'transactionType:id,code,name_ar,name_en',
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
}
