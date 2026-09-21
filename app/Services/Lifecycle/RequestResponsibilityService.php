<?php

namespace App\Services\Lifecycle;

use App\Models\Request;
use App\Models\Role;
use App\Models\WorkflowTransition;
use Illuminate\Support\Collection;

/**
 * Stage 83 — [D] Appendices 17 (المسؤول الحالي) and 18 (الإجراء التالي).
 *
 * **Both are derived, never stored, and that is the design decision not to
 * re-litigate.** Appendix 17 requires the field to be *visible on every
 * request and never empty* ("يجب أن تظهر في كل معاملة خانة إلزامية باسم:
 * المسؤول الحالي. **ولا يجوز تركها فارغة**"); it does not ask for a second
 * record of a fact the state machine already determines. Storing one would
 * hand the same question two answers free to disagree — the failure this
 * codebase's one-predicate-two-consumers discipline exists to prevent, and the
 * reason Stage 81's early-warning list already read the live rule and
 * explicitly reserved this appendix for a shared derivation rather than a
 * column. `Performance\EarlyWarningService` now reads this service, so an
 * alert and the request screen can never name different people.
 *
 * The two appendices are one question with two halves — Appendix 18 opens
 * "**إلى جانب حالة المعاملة**، يجب تحديد الإجراء التالي المطلوب" — so one
 * service answers both.
 *
 * **The responsible party comes from the outbound `workflow_transitions` rows
 * WorkflowService itself enforces**, mapped onto Appendix 17's own fourteen
 * values rather than reported as a raw role name. A stage with no outbound
 * rule falls back to its own seeded `responsible_role_id`, and a request whose
 * status names its owner outright (a file waiting on the employee to complete
 * documents) beats both — because the status is the more specific fact.
 *
 * **The next action is status-first, stage-fallback**, so it always answers:
 * Appendix 18's whole point is "**وهذا يمنع وجود معاملة بحالة عامة مثل (قيد
 * الإجراء) دون معرفة ما المطلوب فعليًا**", and a broad status such as
 * `in_review` is exactly that — which is why it falls through to the stage,
 * whose own name says what is being done. A concluded file reports مقفلة
 * rather than a fabricated action; the appendix's rule is against a live file
 * with a generic status, not against a closed one.
 *
 * The seed data both halves read (57 transitions, 11 roles, 12 stages) is
 * memoised on first use, so a paginated list costs two queries regardless of
 * how many rows it holds — Stage 81's batching discipline, applied to a
 * per-row field.
 */
class RequestResponsibilityService
{
    /**
     * Appendix 17's own fourteen values, in the appendix's own order.
     *
     * @var array<string, array{ar: string, en: string}>
     */
    public const PARTIES = [
        'employee' => ['ar' => 'الموظف', 'en' => 'The employee'],
        'direct_manager' => ['ar' => 'الرئيس المباشر', 'en' => 'Direct manager'],
        'diwan_deputy' => ['ar' => 'وكيل الديوان', 'en' => 'Diwan deputy'],
        'hr_department' => ['ar' => 'إدارة الموارد البشرية', 'en' => 'Human resources department'],
        'staff_affairs' => ['ar' => 'قسم شؤون الموظفين', 'en' => 'Staff affairs section'],
        'committee_rapporteur' => ['ar' => 'مقرر اللجنة', 'en' => 'Committee rapporteur'],
        'legal_member' => ['ar' => 'العضو القانوني', 'en' => 'Legal member'],
        'committee' => ['ar' => 'لجنة شؤون الموظفين', 'en' => 'Staff affairs committee'],
        'mayor' => ['ar' => 'عميد البلدية', 'en' => 'Municipality mayor'],
        'ministry' => ['ar' => 'وزارة الحكم المحلي', 'en' => 'Ministry of Local Governance'],
        'other_central' => ['ar' => 'جهة مركزية أخرى', 'en' => 'Another central body'],
        'payroll' => ['ar' => 'قسم المرتبات', 'en' => 'Payroll section'],
        'execution_body' => ['ar' => 'جهة التنفيذ', 'en' => 'Executing body'],
        'archive' => ['ar' => 'الأرشيف', 'en' => 'Archive'],
    ];

    /**
     * Appendix 18's own ten examples, plus the steps this system's own
     * pipeline needs to name a live file's next move.
     *
     * The appendix introduces its list with "**مثال**", so it is an open list
     * by its own wording — the additions below (the intake half of the chain,
     * which [D] describes in Arts. 15–20 rather than in this appendix, and the
     * re-processing of a returned محضر, which is Art. 94's) are named in the
     * same register rather than being folded into a generic bucket, since a
     * generic bucket is precisely what the appendix forbids.
     *
     * @var array<string, array{ar: string, en: string}>
     */
    public const NEXT_ACTIONS = [
        'complete_documents' => ['ar' => 'استكمال المستندات المطلوبة', 'en' => 'Complete the required documents'],
        'legal_review' => ['ar' => 'مراجعة قانونية', 'en' => 'Legal review'],
        'place_on_agenda' => ['ar' => 'إدراج في جدول الأعمال', 'en' => 'Place on the agenda'],
        'await_meeting' => ['ar' => 'انتظار اجتماع', 'en' => 'Await the meeting'],
        'prepare_minutes' => ['ar' => 'إعداد محضر', 'en' => 'Prepare the minutes'],
        'send_for_approval' => ['ar' => 'إرسال للاعتماد', 'en' => 'Send for approval'],
        'await_ministry_reply' => ['ar' => 'انتظار رد الوزارة', 'en' => 'Await the ministry reply'],
        'issue_execution_order' => ['ar' => 'إصدار قرار تنفيذ', 'en' => 'Issue the execution order'],
        'update_employee_file' => ['ar' => 'تحديث ملف الموظف', 'en' => 'Update the employee file'],
        'close_request' => ['ar' => 'إقفال المعاملة', 'en' => 'Close the request'],
        // Named beyond the appendix's ten examples — see the docblock above.
        'manager_review' => ['ar' => 'مراجعة الرئيس المباشر وإحالة الطلب', 'en' => 'Direct-manager review and referral'],
        'administrative_routing' => ['ar' => 'إحالة الطلب للجهة المعنية', 'en' => 'Route the request to the relevant body'],
        'register_request' => ['ar' => 'تجهيز الملف الإداري وقيده', 'en' => 'Prepare and register the file'],
        'completeness_check' => ['ar' => 'فحص اكتمال الملف', 'en' => 'Check the file for completeness'],
        'regulatory_review' => ['ar' => 'مراجعة المقرر وفق اللوائح', 'en' => 'Rapporteur review against the regulations'],
        'prepare_presentation_memo' => ['ar' => 'إعداد مذكرة العرض', 'en' => 'Prepare the presentation memo'],
        'reprocess_approval_return' => ['ar' => 'إثبات إجراء إعادة المعالجة', 'en' => 'Record the re-processing action'],
        'closed' => ['ar' => 'لا إجراء مطلوب — المعاملة مقفلة', 'en' => 'No action required — the file is closed'],
    ];

    /**
     * Appendix 17's own vocabulary, reached from this system's eleven roles.
     *
     * R05 (مدير إدارة الشؤون الإدارية) maps onto إدارة الموارد البشرية and R07
     * (المدير العام / العميد) onto عميد البلدية: neither role name is the
     * appendix's word for it, but each is the local body the appendix's own
     * value names — the same judgment Stage 71 recorded when it routed
     * Appendix 38's escalation targets onto this system's roles.
     *
     * @var array<string, string>
     */
    private const ROLE_TO_PARTY = [
        'R01' => 'employee',
        'R02' => 'committee_rapporteur',
        'R03' => 'committee',
        'R04' => 'committee',
        'R05' => 'hr_department',
        'R06' => 'ministry',
        'R07' => 'mayor',
        'R08' => 'staff_affairs',
        // Stage 96 left these two mappings in place although neither role
        // holds an outbound rule any more: Appendix 17's party vocabulary is
        // fixed, the rows cost nothing, and a stage that ever re-seats R09 or
        // R10 would need them back.
        'R09' => 'staff_affairs',
        'R10' => 'diwan_deputy',
        'R11' => 'legal_member',
    ];

    /**
     * Statuses that name their own owner outright, beating whatever the stage
     * would otherwise say. A file returned for missing documents is waiting on
     * the employee no matter which desk it is formally parked at.
     *
     * @var array<string, string>
     */
    private const STATUS_TO_PARTY = [
        'incomplete' => 'employee',
        'completion_required' => 'employee',
        'returned' => 'employee',
        'under_legal_review' => 'legal_member',
        'execution_suspended' => 'legal_member',
        'nominated_for_committee' => 'committee_rapporteur',
        'on_agenda' => 'committee',
        'in_meeting' => 'committee',
        'under_discussion' => 'committee',
        'awaiting_recommendation_approval' => 'committee',
        'deferred' => 'committee',
        'legal_opinion_requested' => 'legal_member',
        'awaiting_municipal_approval' => 'mayor',
        'approved' => 'mayor',
        'awaiting_central_approval' => 'ministry',
        'returned_by_approving_body' => 'committee_rapporteur',
        'in_execution' => 'execution_body',
        'executed' => 'committee_rapporteur',
        'not_approved' => 'committee_rapporteur',
        'outside_jurisdiction' => 'committee_rapporteur',
        'completed_closed' => 'archive',
        'archived' => 'archive',
        'cancelled' => 'archive',
    ];

    /** @var array<string, string> */
    private const STATUS_TO_NEXT_ACTION = [
        'incomplete' => 'complete_documents',
        'completion_required' => 'complete_documents',
        'returned' => 'complete_documents',
        'under_legal_review' => 'legal_review',
        'legal_opinion_requested' => 'legal_review',
        'execution_suspended' => 'legal_review',
        'ready' => 'place_on_agenda',
        'nominated_for_committee' => 'place_on_agenda',
        'on_agenda' => 'await_meeting',
        'in_meeting' => 'await_meeting',
        'deferred' => 'await_meeting',
        'under_discussion' => 'prepare_minutes',
        'awaiting_recommendation_approval' => 'prepare_minutes',
        'decided' => 'prepare_minutes',
        'approved_with_conditions' => 'send_for_approval',
        'awaiting_municipal_approval' => 'send_for_approval',
        'approved' => 'send_for_approval',
        'awaiting_central_approval' => 'await_ministry_reply',
        'returned_by_approving_body' => 'reprocess_approval_return',
        'final_approved' => 'issue_execution_order',
        'in_execution' => 'update_employee_file',
        'executed' => 'close_request',
        'not_approved' => 'close_request',
        'outside_jurisdiction' => 'close_request',
        'referred_to_other_body' => 'await_meeting',
        'completed_closed' => 'closed',
        'archived' => 'closed',
        'cancelled' => 'closed',
    ];

    /** @var array<string, string> */
    private const STAGE_TO_NEXT_ACTION = [
        'receive_from_municipality' => 'manager_review',
        'direct_manager_review' => 'manager_review',
        'administrative_routing' => 'administrative_routing',
        'receive_and_register' => 'register_request',
        'requirements_check' => 'completeness_check',
        'reviewer_review' => 'regulatory_review',
        'observations' => 'prepare_presentation_memo',
        'forward_to_committee' => 'place_on_agenda',
        'receive_from_committee' => 'await_meeting',
        'approval_by_authority' => 'send_for_approval',
        'local_governance_ministry' => 'await_ministry_reply',
        'final_approval_archiving' => 'issue_execution_order',
    ];

    /** @var Collection<int, WorkflowTransition>|null */
    private ?Collection $rules = null;

    /** @var array<int, string>|null role id => role code */
    private ?array $roleCodes = null;

    /**
     * Appendix 17's المسؤول الحالي and Appendix 18's الإجراء التالي for one
     * request, both always answered.
     *
     * The request must carry `status` and `currentStage`; both are eager-loaded
     * everywhere this is read.
     *
     * @return array{
     *     responsible: array{code: string, ar: string, en: string},
     *     next_action: array{code: string, ar: string, en: string}
     * }
     */
    public function for(Request $requestRecord): array
    {
        $statusCode = $requestRecord->status?->code;
        $stageCode = $requestRecord->currentStage?->code;

        $party = self::STATUS_TO_PARTY[$statusCode] ?? $this->partyFromStage($requestRecord);
        $action = self::STATUS_TO_NEXT_ACTION[$statusCode]
            ?? self::STAGE_TO_NEXT_ACTION[$stageCode]
            // Never empty: a request with neither a mapped status nor a known
            // stage is still waiting on somebody to look at it, and "قيد
            // الإجراء" is exactly the answer Appendix 18 refuses.
            ?? 'completeness_check';

        return [
            'responsible' => ['code' => $party] + self::PARTIES[$party],
            'next_action' => ['code' => $action] + self::NEXT_ACTIONS[$action],
        ];
    }

    /**
     * The party label alone, in one locale — what Appendix 10's alert rows and
     * Appendix 38's escalation report need.
     */
    public function responsibleLabel(Request $requestRecord, string $locale = 'ar'): string
    {
        $party = $this->for($requestRecord)['responsible'];

        return $locale === 'en' ? $party['en'] : $party['ar'];
    }

    /**
     * The outbound rules for this request's current stage, mapped onto
     * Appendix 17's vocabulary.
     *
     * `requires_submitter_manager` is read as الرئيس المباشر directly rather
     * than through a role, because that gate names a person by relationship
     * (Stage 3's `users.manager_id`) and carries no `required_role_id` at all.
     */
    private function partyFromStage(Request $requestRecord): string
    {
        $stage = $requestRecord->currentStage;

        if ($stage === null) {
            return 'staff_affairs';
        }

        $applicable = $this->rules()->filter(
            fn (WorkflowTransition $rule) => $rule->from_stage_id === $stage->getKey()
                && ($rule->request_type_id === null || $rule->request_type_id === $requestRecord->request_type_id)
        );

        if ($applicable->contains(fn (WorkflowTransition $rule) => (bool) $rule->requires_submitter_manager)) {
            return 'direct_manager';
        }

        // `submit` after a manager's return is the filer's own move.
        if ($applicable->contains(fn (WorkflowTransition $rule) => (bool) $rule->requires_creator)) {
            return 'employee';
        }

        foreach ($applicable as $rule) {
            $party = $this->partyForRoleId($rule->required_role_id);

            if ($party !== null) {
                return $party;
            }
        }

        // The stage's own seeded owner, rather than reporting nobody — which
        // is the one answer Appendix 17 rules out.
        return $this->partyForRoleId($stage->responsible_role_id) ?? 'staff_affairs';
    }

    private function partyForRoleId(?int $roleId): ?string
    {
        if ($roleId === null) {
            return null;
        }

        $code = $this->roleCodes()[$roleId] ?? null;

        return $code === null ? null : (self::ROLE_TO_PARTY[$code] ?? null);
    }

    /** @return Collection<int, WorkflowTransition> */
    private function rules(): Collection
    {
        return $this->rules ??= WorkflowTransition::query()
            ->where('is_exception', false)
            ->get(['from_stage_id', 'request_type_id', 'required_role_id', 'requires_submitter_manager', 'requires_creator']);
    }

    /** @return array<int, string> */
    private function roleCodes(): array
    {
        return $this->roleCodes ??= Role::query()->pluck('code', 'id')->all();
    }
}
