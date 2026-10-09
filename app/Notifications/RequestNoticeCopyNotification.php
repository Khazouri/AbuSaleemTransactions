<?php

namespace App\Notifications;

use App\Models\Request;
use App\Services\EmployeeNoticeService;

/**
 * Stage 101 — [D] Appendix 6 row 14: while المقرر answers for the employee's
 * notice, الرئيس المباشر is «مطلع» and الموارد البشرية «مشارك».
 *
 * A copy, not a forward. It names the moment and the file and nothing else:
 * Art. 102 limits a notice to what صاحب العلاقة needs, and this one is not
 * addressed to them, so repeating the body — which quotes the employee's own
 * reference, meeting number and required completion — would spread it further
 * than the article allows. Anyone who needs the wording reads it on the
 * request's notices register.
 */
class RequestNoticeCopyNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    public function __construct(Request $requestRecord, private readonly string $moment)
    {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
    }

    public function eventType(): string
    {
        return 'request_notice_copy';
    }

    protected function payload(): array
    {
        $moment = EmployeeNoticeService::MOMENTS[$this->moment] ?? null;
        $titleAr = $moment['ar'] ?? 'إشعار بشأن المعاملة';
        $titleEn = $moment['en'] ?? 'Notice about the request';

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'moment' => $this->moment,
            'moment_number' => $moment['number'] ?? null,
            'title_ar' => 'نسخة من إشعار الموظف',
            'title_en' => 'Copy of the employee notice',
            'body_ar' => "أُرسل إلى الموظف إشعار بشأن الطلب {$this->reference}: {$titleAr}.",
            'body_en' => "The employee was sent a notice on request {$this->reference}: {$titleEn}.",
        ];
    }
}
