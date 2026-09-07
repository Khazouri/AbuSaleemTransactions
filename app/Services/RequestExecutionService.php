<?php

namespace App\Services;

use App\Models\Request;

/**
 * Stage 76 — [D] Appendix 70's التحقق النهائي من التنفيذ and النموذج 17's
 * أمر تنفيذ قرار وظيفي.
 *
 * Appendix 70 is the whole stage in one sentence: "لا يكفي أن تقول الجهة
 * المنفذة: (تم التنفيذ) **بل يجب إرفاق دليل التنفيذ**." Until now Art. 38's
 * code 19 was reachable on an unsupported claim — `MeetingOutputService::
 * markExecuted()` asked for nothing but a decision and the right status — so
 * this class holds the rules that turn that claim into evidence.
 *
 * It owns the rules only. `MeetingOutputService` remains the sole writer of
 * code 19 and keeps its own structural guards (persisted item, active actor,
 * a recorded decision, the right stage and status); this is the
 * DecisionStructureRules/CommitteeVotingRules split, and it is what keeps the
 * screen's "why not" and the endpoint's refusal from disagreeing.
 */
class RequestExecutionService
{
    /** Art. 38 code 18 — markExecuted()'s only legal origin, named once. */
    public const EXECUTABLE_STATUS = 'in_execution';

    /**
     * النموذج 17's متابعة التنفيذ list, verbatim and in the form's own order.
     *
     * Seven, and the seventh is not a question this system asks: Appendix 70
     * refuses to let "تم إرفاق مستند التنفيذ" be an attestation, so it is
     * derived from real `attachments.execution_evidence_type` rows instead —
     * the same split Stage 60 established for appeal formal verification and
     * Stage 75 for Appendix 47 (ask a human only what the system cannot
     * answer, because asking and then overriding is worse than not asking).
     *
     * @var array<string, string>
     */
    public const TRACKING_CHECKS = [
        'administrative_decision_issued' => 'هل تم إصدار القرار الإداري؟',
        'employee_file_updated' => 'هل تم تحديث ملف الموظف؟',
        'system_updated' => 'هل تم تحديث النظام؟',
        'financial_effect_referred' => 'هل تمت إحالة الأثر المالي؟',
        'organizational_unit_notified' => 'هل تم إخطار التقسيم التنظيمي؟',
        'employee_notified' => 'هل تم إخطار الموظف؟',
        'execution_document_attached' => 'هل تم إرفاق مستند التنفيذ؟',
    ];

    /** The six the executing officer answers; the seventh is derived. */
    public const EXECUTOR_CHECKS = [
        'administrative_decision_issued',
        'employee_file_updated',
        'system_updated',
        'financial_effect_referred',
        'organizational_unit_notified',
        'employee_notified',
    ];

    /**
     * Tri-state for Stage 75's own reasons: not every executed decision issues
     * an administrative قرار, touches an organisational unit, or carries a
     * financial effect, and demanding "نعم" where the source itself does not
     * would have the system forbid what [D] permits.
     *
     * `not_applicable` is not a loophole — Appendix 70's evidence requirement
     * and Art. 97's financial referral below are read from real state and
     * cannot be ticked away.
     */
    public const ANSWERS = ['yes', 'no', 'not_applicable'];

    /**
     * Appendix 70's own list of what دليل التنفيذ may be, plus its closing
     * "أي وثيقة تثبت تحقق الأثر المطلوب" as `other` — the appendix offers the
     * seven by example ("وقد يكون"), so an exhaustive enum would close a list
     * the source deliberately leaves open, the reasoning Stage 74 used for
     * Appendix 28's refusal codes.
     *
     * @var array<string, string>
     */
    public const EVIDENCE_TYPES = [
        'administrative_decision' => 'قرار إداري',
        'employee_record_update' => 'تحديث في سجل الموظف',
        'transfer_decision' => 'قرار نقل',
        'secondment_decision' => 'قرار ندب',
        'grade_amendment' => 'تعديل درجة',
        'financial_statement' => 'إفادة مالية',
        'commencement_document' => 'مستند مباشرة',
        'other' => 'وثيقة أخرى تثبت تحقق الأثر المطلوب',
    ];

    /**
     * Appendix 52's قواعد تحديث الملف الوظيفي بعد التنفيذ, carried so the
     * screen can state what "تم تحديث ملف الموظف" actually means.
     *
     * Guidance, not columns: the Track K intro's scope decision (1) puts ملف
     * الخدمة outside this application, so these are *evidenced* here (an
     * Appendix 70 attachment of kind `employee_record_update`) rather than
     * modelled.
     *
     * @var list<string>
     */
    public const SERVICE_FILE_ITEMS = [
        'القرار النهائي',
        'تاريخ النفاذ',
        'أثر القرار',
        'أي تعديل على الدرجة',
        'أي تعديل على الوظيفة',
        'أي تعديل على الجهة',
        'أي تعديل على الوضع المالي',
        'أي تاريخ جديد للأقدمية عند الحاجة',
    ];

    /**
     * Why this request's execution may not be recorded, or null if it may.
     *
     * Returns the message rather than a boolean so a refusal reads as an
     * explanation — the DecisionEligibility/RequestClosureService convention —
     * and so the outputs screen's "why not" and the endpoint's refusal are the
     * same sentence, computed once. One message at a time, in the order the
     * sources present the requirements.
     *
     * @param  array<string, string>|null  $checklist  the executing officer's answers, when one is being submitted
     * @param  list<int>|null  $evidenceAttachmentIds  the attachments nominated as دليل التنفيذ
     */
    public function refusalReason(
        Request $requestRecord,
        ?array $checklist = null,
        ?array $evidenceAttachmentIds = null,
    ): ?string {
        if ($requestRecord->executed_at !== null) {
            return 'سبق إثبات تنفيذ هذه المعاملة.';
        }

        if ($requestRecord->status?->code !== self::EXECUTABLE_STATUS) {
            return 'لا يثبت التنفيذ إلا لمعاملة تحت التنفيذ.';
        }

        if ($evidenceAttachmentIds !== null && ($reason = $this->evidenceRefusal($requestRecord, $evidenceAttachmentIds)) !== null) {
            return $reason;
        }

        if ($checklist === null) {
            return null;
        }

        return $this->checklistRefusal($requestRecord, $checklist);
    }

    /**
     * Appendix 70, enforced rather than attested.
     *
     * @param  list<int>  $attachmentIds
     */
    private function evidenceRefusal(Request $requestRecord, array $attachmentIds): ?string
    {
        if ($attachmentIds === []) {
            return 'لا يكفي أن تقول الجهة المنفذة (تم التنفيذ)؛ يجب إرفاق دليل التنفيذ.';
        }

        // Read from the request's own documents, so a nominated id cannot
        // point at a file belonging to some other file's execution.
        $owned = $requestRecord->attachments()
            ->whereIn('id', $attachmentIds)
            ->count();

        if ($owned !== count(array_unique($attachmentIds))) {
            return 'لا ينتمي مستند دليل التنفيذ المحدد إلى هذه المعاملة.';
        }

        return null;
    }

    /**
     * النموذج 17's own rule, plus Art. 97's — the one check with a system fact
     * behind it rather than an attestation.
     *
     * @param  array<string, string>  $checklist
     */
    private function checklistRefusal(Request $requestRecord, array $checklist): ?string
    {
        foreach (self::EXECUTOR_CHECKS as $key) {
            if (($checklist[$key] ?? null) === 'no') {
                return 'لا يثبت التنفيذ قبل استكمال متابعة التنفيذ: '.self::TRACKING_CHECKS[$key];
            }
        }

        // Art. 97 — "إذا ترتب على القرار أثر مالي، يحال إلى الجهة المالية أو
        // قسم المرتبات والمهايا بعد اكتمال الاعتماد". Stage 47 already records
        // whether this request carries a financial effect, so unlike the other
        // five this answer binds against real state and "لا ينطبق" is refused
        // when the flag is set. The inverse is deliberately not enforced: the
        // flag is a per-type default the executing body may know better than.
        if ($requestRecord->has_financial_impact && ($checklist['financial_effect_referred'] ?? null) !== 'yes') {
            return 'لا يثبت تنفيذ معاملة ذات أثر مالي قبل إحالة الأثر المالي إلى قسم المرتبات والمهايا.';
        }

        return null;
    }

    /**
     * The stored checklist is all seven of النموذج 17's checks — the officer's
     * six answers plus the derived seventh — so the record reads as the form's
     * own list rather than a subset a reader has to reassemble. Stage 75's
     * `auditRecord()` precedent.
     *
     * @param  array<string, string>  $checklist
     * @param  list<int>  $evidenceAttachmentIds
     * @return array<string, string>
     */
    public function checklistRecord(array $checklist, array $evidenceAttachmentIds): array
    {
        $record = [];

        foreach (array_keys(self::TRACKING_CHECKS) as $key) {
            $record[$key] = $key === 'execution_document_attached'
                // Derived from the evidence actually nominated above, never
                // asked for — Appendix 70's whole point.
                ? ($evidenceAttachmentIds === [] ? 'no' : 'yes')
                : $checklist[$key];
        }

        return $record;
    }
}
