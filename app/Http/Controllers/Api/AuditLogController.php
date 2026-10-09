<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ExportAuditLogRequest;
use App\Http\Requests\Audit\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Reports\ReportDocument;
use App\Services\Reports\ReportExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 22 — the audit log is READ ONLY over HTTP.
 *
 * There is deliberately no store/update/destroy here: rows are written only by
 * App\Observers\AuditObserver, and a trail an operator can edit is not a trail.
 * Retention/pruning, if it is ever needed, belongs in a console command where
 * it leaves its own footprint.
 *
 * Stage 24 adds the export the seeded `audit_log,export` grant always implied.
 */
class AuditLogController extends Controller
{
    /**
     * Column headings for the exported file, rendered server-side and so kept
     * out of the SPA's locale files.
     */
    private const LABELS = [
        'ar' => [
            'title' => 'سجل التدقيق',
            'generated' => 'تاريخ الإصدار',
            'entries' => 'عدد السجلات',
            'columns' => ['التاريخ والوقت', 'المستخدم', 'الإجراء', 'نوع السجل', 'رقم السجل', 'الحقول المتغيرة', 'عنوان IP'],
            'system' => 'النظام',
            'none' => '—',
        ],
        'en' => [
            'title' => 'Audit Log',
            'generated' => 'Generated',
            'entries' => 'Entries',
            'columns' => ['Timestamp', 'User', 'Action', 'Record type', 'Record id', 'Changed fields', 'IP address'],
            'system' => 'System',
            'none' => '—',
        ],
    ];

    public function index(IndexAuditLogRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $logs = $this->filtered($filters)
            ->with('user:id,name,email')
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
                'formats' => ReportExporter::FORMATS,
            ],
        ]);
    }

    /** Stage 24 — the filtered trail as .xlsx or PDF. */
    public function export(ExportAuditLogRequest $request, ReportExporter $exporter): Response
    {
        $locale = $request->exportLocale();
        $labels = self::LABELS[$locale];
        $modelLabels = array_flip(AuditLog::modelKeys());

        // Chunked rather than get(): the audit table is the largest in the
        // schema and an unfiltered export would otherwise hydrate all of it at
        // once. The rows array still holds everything, but only one page of
        // Eloquent models is alive at a time. Descending, to match the newest
        // -first order the viewer shows.
        $rows = [];
        $this->filtered($request->validated())
            ->with('user:id,name')
            ->chunkByIdDesc(500, function ($logs) use (&$rows, $labels, $modelLabels) {
                foreach ($logs as $log) {
                    // A delete records what was lost in old_values and leaves
                    // new_values empty, so neither alone lists every field.
                    $changed = array_keys($log->new_values ?: ($log->old_values ?: []));

                    $rows[] = [
                        $log->created_at?->format('Y-m-d H:i:s') ?? $labels['none'],
                        $log->user?->name ?? $labels['system'],
                        $log->action,
                        $modelLabels[$log->auditable_type] ?? $log->auditable_type,
                        $log->auditable_id,
                        implode(', ', $changed) ?: $labels['none'],
                        $log->ip_address ?? $labels['none'],
                    ];
                }
            });

        $document = new ReportDocument(
            slug: 'audit-log',
            title: $labels['title'],
            columns: $labels['columns'],
            rows: $rows,
            meta: [
                $labels['generated'].': '.now()->format('Y-m-d H:i'),
                $labels['entries'].': '.count($rows),
            ],
            rtl: $locale === 'ar',
        );

        return $exporter->download($document, $request->exportFormat());
    }

    /**
     * The one filter implementation the viewer and the export share, so an
     * exported file can never cover a different set of rows than the screen.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function filtered(array $filters): Builder
    {
        $modelKeys = AuditLog::modelKeys();

        return AuditLog::query()
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            // The validated key is translated back to a class here, so the
            // query never sees a caller-supplied class string.
            ->when(
                $filters['model'] ?? null,
                fn (Builder $query, string $model) => $query->where('auditable_type', $modelKeys[$model]),
            )
            ->when($filters['record_id'] ?? null, fn (Builder $query, int $recordId) => $query->where('auditable_id', $recordId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('id');
    }
}
