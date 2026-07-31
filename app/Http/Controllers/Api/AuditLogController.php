<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stage 22 — the audit log is READ ONLY over HTTP.
 *
 * There is deliberately no store/update/destroy here: rows are written only by
 * App\Observers\AuditObserver, and a trail an operator can edit is not a trail.
 * Retention/pruning, if it is ever needed, belongs in a console command where
 * it leaves its own footprint.
 */
class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $modelKeys = AuditLog::modelKeys();

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($filters['user_id'] ?? null, fn ($query, int $userId) => $query->where('user_id', $userId))
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            // The validated key is translated back to a class here, so the
            // query never sees a caller-supplied class string.
            ->when(
                $filters['model'] ?? null,
                fn ($query, string $model) => $query->where('auditable_type', $modelKeys[$model]),
            )
            ->when($filters['record_id'] ?? null, fn ($query, int $recordId) => $query->where('auditable_id', $recordId))
            ->when($filters['date_from'] ?? null, fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }

    /**
     * Filter lookups. The model list comes from the audit registry rather than
     * from DISTINCT over the table, so a filter for something that hasn't
     * happened yet still exists — an empty result is itself an answer.
     */
    public function filters(): JsonResponse
    {
        return response()->json([
            'data' => [
                'actions' => AuditLog::ACTIONS,
                'models' => array_keys(AuditLog::modelKeys()),
                'users' => User::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'email']),
            ],
        ]);
    }
}
