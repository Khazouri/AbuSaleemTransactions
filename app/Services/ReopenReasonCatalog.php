<?php

namespace App\Services;

/**
 * Stage 66, Track J — [D] Arts. 78–79's own enumerated list of legitimate
 * reopen reasons, the only reasons a closed Appeal or a concluded Request
 * may be reopened for. "I disagree with the outcome" names none of these
 * and is refused by the Rule::in on both ReopenAppealRequest and
 * ReopenRequestRequest.
 *
 * Shared by both mechanisms deliberately: Arts. 34–37's matching عدم
 * الموافقة non-re-presentation rule for the original Request and Arts.
 * 78–79's appeal-reopening rule name the identical list (see
 * AGENT_NOTES.md's Stage 66 entry) — one catalog, not two copies that could
 * drift apart.
 */
class ReopenReasonCatalog
{
    public const CODES = [
        'new_document',
        'external_reply_received',
        'material_error_correction',
        'legal_status_change',
        'returned_by_approving_body',
        'competent_authority_restudy',
    ];

    private const LABELS_AR = [
        'new_document' => 'ظهور مستند جديد',
        'external_reply_received' => 'ورود رد من جهة خارجية',
        'material_error_correction' => 'تصحيح خطأ جوهري',
        'legal_status_change' => 'تغيّر الحالة القانونية',
        'returned_by_approving_body' => 'إعادتها من الجهة المعتمِدة',
        'competent_authority_restudy' => 'توجيه جهة مختصة بإعادة الدراسة',
    ];

    /**
     * The Arabic label for a reason code — used to build the free-text
     * comment WorkflowService::reopenAtStage() writes into the Request's own
     * stage-log/status-history rows (its only place to carry a reason). The
     * Appeal side has no equivalent free-text sink, so it stores the raw
     * code directly instead — see the appeals.reopen_reason_code column.
     */
    public static function label(string $code): string
    {
        return self::LABELS_AR[$code] ?? $code;
    }
}
