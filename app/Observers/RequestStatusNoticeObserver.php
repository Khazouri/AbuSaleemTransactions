<?php

namespace App\Observers;

use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Services\EmployeeNoticeService;
use App\Services\NotificationDispatcher;

/**
 * Stage 79 — turns every recorded status change into [D] Art. 101's notice.
 *
 * Art. 101 is a statement about states ("يتم إشعار الموظف، **بحسب مرحلة
 * المعاملة**، عند" + twelve of them), and `request_status_history` is this
 * system's append-only record that a request reached a state. Every one of the
 * eight services that can move a status writes a row here, so hooking the row
 * rather than the eight callers is both the faithful reading of the article and
 * the thing that makes the Stage 79 complaint — "status-only moves fire
 * nothing" — structurally unrepeatable: a future status writer cannot forget to
 * notify, because the row it must write is the trigger.
 *
 * Registered in AppServiceProvider::boot(), alongside AuditObserver, which
 * exists for the same reason and covers the same kind of cross-cutting record.
 */
class RequestStatusNoticeObserver
{
    public function __construct(
        private readonly EmployeeNoticeService $notices,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function created(RequestStatusHistory $history): void
    {
        // WorkflowService stamps a status row on EVERY move, "even when
        // adjacent stages share a broad status such as `in_review`" (its own
        // comment) — so an unguarded observer would announce a state the file
        // never actually left.
        if ($history->from_status_id !== null && $history->from_status_id === $history->to_status_id) {
            return;
        }

        $requestRecord = Request::query()->find($history->request_id);

        if ($requestRecord === null) {
            return;
        }

        $codes = RequestStatus::query()
            ->whereKey(array_filter([$history->from_status_id, $history->to_status_id]))
            ->pluck('code', 'id');

        $toCode = $codes[$history->to_status_id] ?? null;

        if ($toCode === null) {
            return;
        }

        $moment = $this->notices->momentFor(
            $requestRecord,
            $history->from_status_id === null ? null : ($codes[$history->from_status_id] ?? null),
            $toCode,
        );

        if ($moment === null) {
            return;
        }

        $this->dispatcher->requestNotice(
            $requestRecord,
            $moment,
            $this->notices->contextFor($requestRecord, $moment, $history->reason),
            $history->changed_by_user_id,
        );
    }
}
