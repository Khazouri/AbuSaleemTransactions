<?php

namespace Tests;

/**
 * Stage 74 — the structured payload [D] now requires of a recorded decision,
 * for the many existing tests whose subject is something else entirely (the
 * vote tally, the workflow move, the محضر, an appeal's own status machine)
 * but which have to get past DecisionStructureRules to reach it.
 *
 * Kept in one place so those tests say "a properly drafted decision" once
 * rather than restating Appendix 27's four parts thirty-five times, and so a
 * later stage that changes what a decision must carry updates one helper
 * instead of hunting call sites. Tests whose subject IS the structure build
 * their own deliberately incomplete payloads instead — see
 * DecisionStructureTest.
 */
trait RecordsStructuredDecisions
{
    /**
     * A complete, valid structure for $outcome.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function decisionPayload(string $outcome, array $overrides = []): array
    {
        $payload = [
            'instrument' => 'decision',
            'decision_subject' => 'طلب ترقية الموظف إلى الدرجة التالية',
            'decision_operative' => 'تقرر اللجنة ما انتهت إليه أعلاه وإحالة الملف إلى السلطة المختصة لاستكمال إجراءات الاعتماد والتنفيذ.',
        ];

        // Appendix 27's other two parts are required only of the outcomes that
        // dispose of the matter; supplying them everywhere would be harmless
        // but would stop these fixtures from reflecting what the rules
        // actually ask for.
        if (in_array($outcome, [
            'approve', 'conditional_approval', 'reject', 'no_jurisdiction',
            'appeal_accept', 'appeal_partial_accept', 'appeal_reject',
        ], true)) {
            $payload['decision_facts'] = 'أكمل الموظف مدة الثلاث سنوات المقررة في درجته الحالية وفق كشف الخدمة المرفق.';
            $payload['decision_basis'] = 'المادة 135 من قانون علاقات العمل رقم 12 لسنة 2010.';
        }

        if (in_array($outcome, ['reject', 'no_jurisdiction', 'appeal_reject'], true)) {
            $payload['refusal_reason_code'] = 'period_condition_unmet';
        }

        if ($outcome === 'defer') {
            $payload['deferral_reason'] = 'نقص مستند مؤثر في تحديد المركز الوظيفي.';
            $payload['deferral_required_completion'] = 'كشف الخدمة معتمداً من إدارة الموارد البشرية.';
            $payload['deferral_responsible_body'] = 'إدارة الموارد البشرية';
            $payload['deferral_required_document'] = 'كشف خدمة معتمد وموقع';
        }

        return array_merge($payload, $overrides);
    }
}
