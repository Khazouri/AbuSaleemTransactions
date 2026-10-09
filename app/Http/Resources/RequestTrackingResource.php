<?php

namespace App\Http\Resources;

use App\Services\ReportMetricsService;
use Illuminate\Http\Request;

/**
 * Stage 89 — one of my own requests, said in the employee's terms.
 *
 * The same file RequestDetailResource describes, minus the committee's own
 * machinery. It extends RequestResource for the identity/status/stage block and
 * Appendix 17/18's responsibility pair, then adds exactly the three registers
 * «متابعة طلباتي» is built on: Art. 100's timeline, Art. 101's notices, and
 * Appendix 71's time card.
 *
 * What it deliberately does NOT carry is the point. Appendix 63's control-gate
 * matrix, Art. 45's jurisdiction test, Appendix 47's closure audit, the
 * approval-return and referral registers and the available-actions preview are
 * all on the workspace payload and all describe how the committee is handling
 * the file rather than where the file is — the distinction [D] Art. 102 draws
 * when it limits a notice to صاحب العلاقة to "ما يحتاجه لمعرفة حالة معاملته
 * ونتيجتها والإجراء المطلوب منه".
 *
 * This narrows nothing that exists: /requests/{id} is unchanged and still shows
 * its creator everything it showed before. This is a second, smaller window on
 * the same file, not a restriction of the first.
 */
class RequestTrackingResource extends RequestResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'reasons' => $this->reasons,
            // Stage 51 — [A] §7's own employee-facing question: is my file
            // still missing something? Derived from the status, so it needs no
            // eager load of its own.
            'documents_complete' => $this->documentsComplete(),
            // Whether this file still has a next step at all. Derived from the
            // status here rather than set by the controller so the LIST rows
            // carry it too: a concluded file's current stage may still hold a
            // target duration nothing is counting against any more, and a
            // tracking row that answered "expected by ⟨date⟩" for a closed
            // request would be confidently wrong.
            'is_concluded' => in_array($this->status?->code, ReportMetricsService::CONCLUDED_STATUSES, true),
            // The three registers below are the panel's, not the list's — each
            // is present exactly when the controller computed it, the same
            // "did the caller ask for this?" idiom RequestResource already uses
            // for `attachments_count`. A list page has no room for ten
            // durations and a full timeline per row, and computing them per row
            // is the N+1 TimeCardCompiler exists to avoid.

            // Stage 80 — [D] Art. 100's السجل الزمني: التاريخ — الإجراء —
            // المسؤول — الجهة — الملاحظة — المستند المرتبط.
            'timeline' => $this->when(
                array_key_exists('art_100_timeline', $this->getAttributes()),
                fn () => $this->art_100_timeline,
            ),
            // Stage 79 — [D] Art. 101's register for this file: which of its
            // twelve moments the employee was actually told about, and when.
            'employee_notices' => $this->when(
                array_key_exists('employee_notices', $this->getAttributes()),
                fn () => $this->employee_notices,
            ),
            // Stage 81 — [D] Appendix 71's ten measured segments. The
            // appendix's own purpose is locating where a delay actually sits
            // rather than blaming the committee for the whole elapsed time,
            // which is exactly the question an employee tracking a file asks.
            'time_card' => $this->when(
                array_key_exists('time_card', $this->getAttributes()),
                fn () => $this->time_card,
            ),
        ];
    }
}
