<?php

namespace App\Services\Tasks;

use App\Models\Appeal;
use App\Models\User;

/**
 * Decision wizard — sub-project 3. What may be done on one appeal now, and
 * each act's refusal exactly as AppealController answers it — the controller
 * calls refusal() in place of the checks it used to carry inline, so the
 * wizard's list and the endpoints cannot disagree.
 *
 * Every Track J act is one-shot at one status and barred to the appellant, so
 * outside its status an act is "not now" and for the appellant "not yours":
 * both are left out. Only execution can be held back inside its moment — by
 * a committee decision not yet recorded.
 */
class AppealActs
{
    /**
     * Per act: the statuses it belongs to (null = close's own rule), and the
     * controller's two sentences for the appellant and for the wrong status.
     *
     * @var array<string, array{statuses: ?list<string>, self: string, status: string}>
     */
    private const ACTS = [
        'verify' => [
            'statuses' => ['submitted'],
            'self' => 'لا يجوز للمتظلم التحقق من تظلمه بنفسه.',
            'status' => 'لا يمكن إجراء التحقق الشكلي إلا لتظلم في حالة تقديم التظلم.',
        ],
        'jurisdiction_test' => [
            'statuses' => ['formal_verification'],
            'self' => 'لا يجوز للمتظلم إجراء اختبار الاختصاص على تظلمه بنفسه.',
            'status' => 'لا يمكن إجراء اختبار الاختصاص إلا لتظلم اجتاز التحقق الشكلي.',
        ],
        'legal_review' => [
            'statuses' => ['file_assembly'],
            'self' => 'لا يجوز للمتظلم إجراء المراجعة القانونية على تظلمه بنفسه.',
            'status' => 'لا يمكن إجراء المراجعة القانونية إلا بعد اجتياز اختبار الاختصاص.',
        ],
        'execute_outcome' => [
            'statuses' => ['committee_presentation'],
            'self' => 'لا يجوز للمتظلم تنفيذ نتيجة تظلمه بنفسه.',
            'status' => 'لا يمكن تنفيذ نتيجة التظلم إلا بعد صدور قرار اللجنة بشأنه.',
        ],
        'close' => [
            'statuses' => null,
            'self' => 'لا يجوز للمتظلم إغلاق تظلمه بنفسه.',
            'status' => 'لا يمكن إغلاق التظلم إلا بعد انتهاء إجراءاته: رفض شكلي، أو عدم اختصاص، أو تنفيذ قرار اللجنة، ولم يُغلق بعد.',
        ],
        'reopen' => [
            'statuses' => ['notified_closed'],
            'self' => 'لا يجوز للمتظلم إعادة فتح تظلمه بنفسه.',
            'status' => 'لا يمكن إعادة فتح تظلم لم يُغلق بعد.',
        ],
    ];

    /** The endpoint's refusal for an act, in the controller's order. */
    public function refusal(Appeal $appeal, string $action, User $actor): ?string
    {
        if ($appeal->appellant_user_id === $actor->id) {
            return self::ACTS[$action]['self'];
        }

        return $this->statusRefusal($appeal, $action) ?? $this->stateRefusal($appeal, $action);
    }

    /**
     * @return array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>}
     */
    public function forAppeal(Appeal $appeal, User $actor): array
    {
        $appeal->loadMissing(['status:id,code', 'committeeAgendaItem.decision']);
        $acts = ['available' => [], 'blocked' => []];

        if ($appeal->appellant_user_id === $actor->id) {
            return $acts;
        }

        if ($actor->hasScreenPermission('appeals', 'can_edit')) {
            foreach (array_keys(self::ACTS) as $action) {
                // Once executed, the appeal's next act is its closure.
                if ($this->statusRefusal($appeal, $action) !== null
                    || ($action === 'execute_outcome' && $appeal->outcome_executed_at !== null)) {
                    continue;
                }

                $reason = $this->stateRefusal($appeal, $action);
                $reason === null
                    ? $acts['available'][] = ['action' => $action]
                    : $acts['blocked'][] = ['action' => $action, 'reason' => $reason];
            }
        }

        if (Appeal::mayNominate($actor) && $appeal->status?->code === 'legal_review' && $appeal->committeeAgendaItem === null) {
            $acts['available'][] = ['action' => 'nominate'];
        }

        return $acts;
    }

    private function statusRefusal(Appeal $appeal, string $action): ?string
    {
        $code = $appeal->status?->code;
        $fits = $action === 'close'
            ? in_array($code, ['rejected', 'outside_jurisdiction'], true)
                || ($code === 'committee_presentation' && $appeal->outcome_executed_at !== null)
            : in_array($code, self::ACTS[$action]['statuses'], true);

        return $fits ? null : self::ACTS[$action]['status'];
    }

    private function stateRefusal(Appeal $appeal, string $action): ?string
    {
        if ($action !== 'execute_outcome') {
            return null;
        }

        if ($appeal->outcome_executed_at !== null) {
            return 'تم تنفيذ نتيجة هذا التظلم بالفعل.';
        }

        return $appeal->committeeAgendaItem?->decision === null ? 'لا يوجد قرار مسجل لهذا التظلم بعد.' : null;
    }
}
