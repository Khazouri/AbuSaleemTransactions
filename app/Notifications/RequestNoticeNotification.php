<?php

namespace App\Notifications;

use App\Models\Request;
use App\Services\EmployeeNoticeService;

/**
 * Stage 79 — the notice [D] Art. 101 requires the employee to receive at each
 * of its twelve moments, worded within Art. 102's limits.
 *
 * One class for all twelve, for the same reason Stage 71's
 * RequestDelayEscalationNotification covers three delay rungs: the moment is
 * the datum, not the class, and twelve near-identical classes would put the
 * one thing that varies (the wording) in twelve places.
 *
 * Art. 102 is a rule about CONTENT, so it is enforced by what this class can
 * see: it holds a tracking number, a moment, and at most three quoted facts —
 * the meeting number and the deferral's required completion, both of which
 * النموذج 16 itself prints, plus the recorded reason for the two moments where
 * the employee has to act. It has no access to a vote tally, a member's
 * opinion, an internal memo or another employee's record, so it cannot leak
 * one. The committee's own tally stays in DecisionRecordedNotification, which
 * after this stage goes to the committee and not to the employee.
 */
class RequestNoticeNotification extends SystemNotification
{
    private readonly int $requestId;

    private readonly string $reference;

    private readonly ?string $meetingNumber;

    private readonly ?string $requiredCompletion;

    private readonly ?string $detail;

    /**
     * @param  array{meeting_number: ?string, required_completion: ?string, detail: ?string}  $context
     */
    public function __construct(
        Request $requestRecord,
        private readonly string $moment,
        array $context = [],
        // Stage 100 — المقرر who issued it by hand, null for the automatic send.
        private readonly ?string $issuedBy = null,
    ) {
        $this->requestId = $requestRecord->id;
        $this->reference = (string) $requestRecord->trackingNumber();
        $this->meetingNumber = $context['meeting_number'] ?? null;
        $this->requiredCompletion = $context['required_completion'] ?? null;
        $this->detail = $context['detail'] ?? null;
    }

    public function eventType(): string
    {
        return 'request_notice';
    }

    protected function payload(): array
    {
        $moment = EmployeeNoticeService::MOMENTS[$this->moment] ?? null;

        [$bodyAr, $bodyEn] = $this->body();

        return [
            'request_id' => $this->requestId,
            'reference_number' => $this->reference,
            'moment' => $this->moment,
            'moment_number' => $moment['number'] ?? null,
            'title_ar' => $moment['ar'] ?? 'إشعار بشأن معاملتكم',
            'title_en' => $moment['en'] ?? 'Notice about your request',
            'body_ar' => $bodyAr,
            'body_en' => $bodyEn,
            'issued_by' => $this->issuedBy,
        ];
    }

    /**
     * The wording, [D]'s own wherever [D] supplies one.
     *
     * Moments 5/6/7/9 are النموذج 16's four formulas verbatim (with the
     * reference number, the meeting number and the deferral's required items
     * filled in where the form leaves dots); moment 2 is النموذج 04's, whose
     * last line — "ولا يعتبر هذا الإشعار رفضًا للطلب أو نتيجة نهائية بشأنه" —
     * is carried because a نواقص notice being mistaken for a refusal is exactly
     * what that line exists to prevent. Moment 6 keeps النموذج 16's own
     * Art. 32-shaped caution that the result is not yet executable, which is
     * the one sentence in this file that must not be trimmed for brevity.
     *
     * @return array{0: string, 1: string}
     */
    private function body(): array
    {
        $ref = $this->reference;
        $suffix = $this->detail !== null ? " التفاصيل: {$this->detail}" : '';
        $suffixEn = $this->detail !== null ? " Details: {$this->detail}" : '';

        return match ($this->moment) {
            'received' => [
                "نفيدكم باستلام معاملتكم رقم {$ref} وقيدها في المسار الرسمي، وسيجري إشعاركم بكل تطور جوهري بشأنها.",
                "Your request {$ref} has been received and registered in the official path; you will be notified of each substantive development.",
            ],
            'documents_missing' => [
                "بالإشارة إلى معاملتكم رقم {$ref}، وبعد مراجعة الملف تبين الحاجة إلى استكمال بعض المستندات، وتبقى المعاملة بحالة (بانتظار استكمال النواقص) إلى حين ورود المطلوب وإعادة فحصها. ولا يعتبر هذا الإشعار رفضًا للطلب أو نتيجة نهائية بشأنه.{$suffix}",
                "Request {$ref} needs supporting documents completed. It stays in \"awaiting completion\" until the required items arrive and it is re-examined. This notice is neither a refusal nor a final result.{$suffixEn}",
            ],
            'documents_completed' => [
                "نفيدكم باكتمال ما طُلب استكماله بشأن معاملتكم رقم {$ref}، وقد استؤنف النظر فيها.",
                "The items requested for request {$ref} have been completed and it is back under consideration.",
            ],
            'placed_on_agenda' => [
                "نفيدكم بإدراج معاملتكم رقم {$ref} في جدول أعمال لجنة شؤون الموظفين.",
                "Request {$ref} has been placed on the Employee Affairs Committee agenda.",
            ],
            'committee_result' => [
                $this->requiredCompletion !== null
                    ? "نفيدكم بأن اللجنة قررت تأجيل البت في معاملتكم رقم {$ref} إلى حين استكمال الآتي: {$this->requiredCompletion} وتظل المعاملة مفتوحة إلى حين استكمال المطلوب."
                    : "نفيدكم بأن لجنة شؤون الموظفين انتهت بشأن معاملتكم رقم {$ref} إلى نتيجة تستوجب إجراءً إضافيًا قبل البت النهائي، وتظل المعاملة مفتوحة.",
                $this->requiredCompletion !== null
                    ? "The committee deferred request {$ref} pending completion of: {$this->requiredCompletion} The request stays open until the required items arrive."
                    : "The committee reached a result on request {$ref} that requires a further step before a final determination; the request stays open.",
            ],
            'referred_for_approval' => [
                $this->meetingNumber !== null
                    ? "نفيدكم بأن لجنة شؤون الموظفين قد انتهت في اجتماعها رقم {$this->meetingNumber} إلى الموافقة بشأن معاملتكم رقم {$ref}، وقد أحيلت النتيجة إلى السلطة المختصة لاستكمال إجراءات الاعتماد. هذه النتيجة لا تعتبر نهائية قابلة للتنفيذ إلا بعد استكمال الاعتماد المطلوب."
                    : "نفيدكم بأن لجنة شؤون الموظفين قد انتهت إلى الموافقة بشأن معاملتكم رقم {$ref}، وقد أحيلت النتيجة إلى السلطة المختصة لاستكمال إجراءات الاعتماد. هذه النتيجة لا تعتبر نهائية قابلة للتنفيذ إلا بعد استكمال الاعتماد المطلوب.",
                "The committee approved request {$ref} and referred the result to the competent authority for approval. This result is not final or executable until the required approval is complete.",
            ],
            'final_approval' => [
                "نفيدكم باستكمال إجراءات اعتماد النتيجة المتعلقة بمعاملتكم رقم {$ref}، وقد أحيل الموضوع إلى الجهة المختصة لاستكمال التنفيذ.",
                "The approval of the result for request {$ref} is complete, and the matter has been referred to the competent body to carry out execution.",
            ],
            'returned_for_completion' => [
                "نفيدكم بإعادة معاملتكم رقم {$ref} لاستكمال ما يلزم قبل استئناف النظر فيها.{$suffix}",
                "Request {$ref} has been returned for completion before consideration resumes.{$suffixEn}",
            ],
            'not_approved' => [
                "نفيدكم بأن لجنة شؤون الموظفين انتهت، بعد دراسة معاملتكم رقم {$ref}، إلى عدم الموافقة على الطلب للأسباب المثبتة في القرار المعتمد. ويتم توجيه صاحب العلاقة إلى مسار التظلم النظامي إن وجد.",
                "After studying request {$ref}, the committee did not approve it, for the reasons recorded in the approved decision. You may pursue the formal grievance path where one applies.",
            ],
            'no_jurisdiction' => [
                "نفيدكم بأن موضوع معاملتكم رقم {$ref} لا يدخل في اختصاص لجنة شؤون الموظفين، وقد تقرر توجيهه إلى الجهة المختصة.",
                "Request {$ref} falls outside the Employee Affairs Committee's jurisdiction and has been directed to the competent body.",
            ],
            'execution_started' => [
                "نفيدكم ببدء إجراءات تنفيذ القرار المتعلق بمعاملتكم رقم {$ref}.",
                "Execution of the decision on request {$ref} has begun.",
            ],
            'closed' => [
                "نفيدكم بإقفال معاملتكم رقم {$ref} بعد استكمال إجراءاتها وتوثيقها.",
                "Request {$ref} has been closed after its procedures were completed and documented.",
            ],
            default => [
                "تحديث بشأن معاملتكم رقم {$ref}.",
                "An update on request {$ref}.",
            ],
        };
    }
}
