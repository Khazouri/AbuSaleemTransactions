<?php

namespace App\Services\Lifecycle;

use App\Models\Request;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowTransition;
use App\Services\WorkflowService;
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
        // Workflow designer — slice 3: a custom step names a role, not one of
        // Appendix 17's bodies; `names` carries which.
        'role' => ['ar' => 'الجهة المكلّفة بالمرحلة', 'en' => "The step's assigned role"],
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
        'update_employee_file' => ['ar' => 'تحديث ملف الموظف', 'en' => 'Update the employee file'],
        'close_request' => ['ar' => 'إقفال المعاملة', 'en' => 'Close the request'],
        // Named beyond the appendix's ten examples — see the docblock above.
        'manager_review' => ['ar' => 'مراجعة الرئيس المباشر وإحالة الطلب', 'en' => 'Direct-manager review and referral'],
        'administrative_routing' => ['ar' => 'إحالة الطلب للجهة المعنية', 'en' => 'Route the request to the relevant body'],
        'register_request' => ['ar' => 'تجهيز الملف الإداري وقيده', 'en' => 'Prepare and register the file'],
        'completeness_check' => ['ar' => 'فحص اكتمال الملف', 'en' => 'Check the file for completeness'],
        // 2026-10-18 — no execution leg: the committee file and the service
        // file are archived, and the second archive closes the file.
        'archive_file' => ['ar' => 'أرشفة ملف اللجنة والملف الوظيفي', 'en' => 'Archive the committee and service files'],
        'reprocess_approval_return' => ['ar' => 'إثبات إجراء إعادة المعالجة', 'en' => 'Record the re-processing action'],
        // 2026-10-25 — the مقرر confirms جهة الاعتماد's decision or returns it.
        'review_authority_decision' => ['ar' => 'تدقيق قرار جهة الاعتماد', 'en' => "Review the authority's decision"],
        'closed' => ['ar' => 'لا إجراء مطلوب — المعاملة مقفلة', 'en' => 'No action required — the file is closed'],
        // Workflow designer — slice 3: whatever a custom step asks; the stage name says what.
        'complete_step' => ['ar' => 'إنجاز إجراء المرحلة', 'en' => "Complete this step's action"],
    ];

    /**
     * Appendix 17's own vocabulary, reached from this system's roles.
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
        // Stage 96 left this mapping in place although R09 holds no outbound
        // rule any more: the row costs nothing, and a stage that ever re-seats
        // R09 would need it back. R10's went with the role (merged into R03,
        // 2026-10-04); Appendix 17's `diwan_deputy` party stays in PARTIES
        // because the appendix's vocabulary is fixed.
        'R09' => 'staff_affairs',
        'R11' => 'legal_member',
        // R12 holds the `register` row at receive_and_register (Stage 96 left
        // it the only registering party); without this the stage fell through
        // to «قسم شؤون الموظفين», a desk that no longer handles the file.
        'R12' => 'hr_department',
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
        'authority_approved' => 'committee_rapporteur',
        'authority_rejected' => 'committee_rapporteur',
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
        'authority_approved' => 'review_authority_decision',
        'authority_rejected' => 'review_authority_decision',
        'final_approved' => 'archive_file',
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
        'receive_from_committee' => 'await_meeting',
        'approval_by_authority' => 'send_for_approval',
        'local_governance_ministry' => 'await_ministry_reply',
        'rapporteur_decision_review' => 'review_authority_decision',
        'final_approval_archiving' => 'archive_file',
    ];

    /** @var Collection<int, WorkflowTransition>|null */
    private ?Collection $rules = null;

    /** @var array<int, string>|null role id => role code */
    private ?array $roleCodes = null;

    /** @var array<int, string>|null role id => Arabic name */
    private ?array $roleNames = null;

    /** @var list<string>|null */
    private ?array $hrDirectorNames = null;

    /**
     * Appendix 17's المسؤول الحالي and Appendix 18's الإجراء التالي for one
     * request, both always answered.
     *
     * The request must carry `status` and `currentStage`; both are eager-loaded
     * everywhere this is read.
     *
     * @return array{
     *     responsible: array{code: string, ar: string, en: string, names: list<string>},
     *     next_action: array{code: string, ar: string, en: string}
     * }
     */
    public function for(Request $requestRecord): array
    {
        $statusCode = $requestRecord->status?->code;
        $stageCode = $requestRecord->currentStage?->code;

        // Workflow designer — slice 3. A custom step is its own answer: the
        // status a file carries into it (e.g. `routed_to_hr`) names the desk
        // it came from, not the one it waits on. A closed file still reads
        // as closed.
        if ($requestRecord->currentStage?->isCustom() && (self::STATUS_TO_NEXT_ACTION[$statusCode] ?? null) !== 'closed') {
            return $this->forCustomStage($requestRecord);
        }

        [$party, $roleCode] = isset(self::STATUS_TO_PARTY[$statusCode])
            ? [self::STATUS_TO_PARTY[$statusCode], null]
            : $this->partyFromStage($requestRecord);
        $action = self::STATUS_TO_NEXT_ACTION[$statusCode]
            ?? self::STAGE_TO_NEXT_ACTION[$stageCode]
            // Never empty: a request with neither a mapped status nor a known
            // stage is still waiting on somebody to look at it, and "قيد
            // الإجراء" is exactly the answer Appendix 18 refuses.
            ?? 'completeness_check';

        return [
            'responsible' => ['code' => $party] + self::PARTIES[$party]
                + ['names' => $this->names($requestRecord, $party, $roleCode)],
            'next_action' => ['code' => $action] + self::NEXT_ACTIONS[$action],
        ];
    }

    /**
     * The people behind the label, where the label alone leaves صاحب العلاقة
     * asking "who?": their own manager at the manager-gated hop, and the HR
     * director when an R12 rule owns the stage. Every other party is a body
     * (a committee, a ministry) rather than one account, so it names nobody.
     *
     * The manager is read through WorkflowService's own live-manager rule, so
     * the name shown is always the person the gate will actually let act.
     *
     * @return list<string>
     */
    private function names(Request $requestRecord, string $party, ?string $roleCode): array
    {
        if ($party === 'direct_manager') {
            // ponytail: two queries per row at direct_manager_review on a list
            // page; batch the managers per page if lists ever get slow.
            $managerId = app(WorkflowService::class)->subjectsLiveManagerId($requestRecord);

            return $managerId === null ? [] : [User::query()->whereKey($managerId)->value('name')];
        }

        return $roleCode === 'R12' ? $this->hrDirectorNames() : [];
    }

    /** @return list<string> */
    private function hrDirectorNames(): array
    {
        return $this->hrDirectorNames ??= User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('roles.code', 'R12'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
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
     *
     * Returns the party with the role code that decided it, since two roles
     * share a party (R05 and R12 are both hr_department) and only R12's
     * holders are the people to name.
     *
     * @return array{0: string, 1: ?string}
     */
    private function partyFromStage(Request $requestRecord): array
    {
        $stage = $requestRecord->currentStage;

        if ($stage === null) {
            return ['staff_affairs', null];
        }

        $applicable = $this->rules()->filter(
            fn (WorkflowTransition $rule) => $rule->from_stage_id === $stage->getKey()
                && ($rule->request_type_id === null || $rule->request_type_id === $requestRecord->request_type_id)
        );

        // Exception rows count here and nowhere else: the retired
        // administrative_routing stage is left only through the manager-gated
        // `route_to_hr` exception, so a file parked there is still the
        // manager's, while R08's exception overrides name nobody's duty.
        if ($applicable->contains(fn (WorkflowTransition $rule) => (bool) $rule->requires_submitter_manager)) {
            return ['direct_manager', null];
        }

        $applicable = $applicable->reject(fn (WorkflowTransition $rule) => (bool) $rule->is_exception);

        // `submit` after a manager's return is the filer's own move.
        if ($applicable->contains(fn (WorkflowTransition $rule) => (bool) $rule->requires_creator)) {
            return ['employee', null];
        }

        foreach ($applicable as $rule) {
            $code = $this->roleCode($rule->required_role_id);

            if (isset(self::ROLE_TO_PARTY[$code])) {
                return [self::ROLE_TO_PARTY[$code], $code];
            }
        }

        // The stage's own seeded owner, rather than reporting nobody — which
        // is the one answer Appendix 17 rules out.
        $code = $this->roleCode($stage->responsible_role_id);

        return [self::ROLE_TO_PARTY[$code] ?? 'staff_affairs', $code];
    }

    /**
     * @return array{
     *     responsible: array{code: string, ar: string, en: string, names: list<string>},
     *     next_action: array{code: string, ar: string, en: string}
     * }
     */
    private function forCustomStage(Request $requestRecord): array
    {
        [$party, $roleCode] = $this->partyFromStage($requestRecord);
        $names = $this->names($requestRecord, $party, $roleCode);

        if (! in_array($party, ['direct_manager', 'employee'], true)) {
            $rule = $this->rules()->first(fn (WorkflowTransition $rule) => $rule->from_stage_id === $requestRecord->current_stage_id
                && ! $rule->is_exception && $rule->required_role_id !== null);
            $party = 'role';
            $names = array_values(array_filter([$rule ? ($this->roleNames()[$rule->required_role_id] ?? null) : null]));
        }

        return [
            'responsible' => ['code' => $party] + self::PARTIES[$party] + ['names' => $names],
            'next_action' => ['code' => 'complete_step'] + self::NEXT_ACTIONS['complete_step'],
        ];
    }

    /** @return array<int, string> */
    private function roleNames(): array
    {
        return $this->roleNames ??= Role::query()->pluck('name_ar', 'id')->all();
    }

    private function roleCode(?int $roleId): ?string
    {
        return $roleId === null ? null : ($this->roleCodes()[$roleId] ?? null);
    }

    /** @return Collection<int, WorkflowTransition> */
    private function rules(): Collection
    {
        return $this->rules ??= WorkflowTransition::query()
            ->get(['from_stage_id', 'request_type_id', 'required_role_id', 'requires_submitter_manager', 'requires_creator', 'is_exception']);
    }

    /** @return array<int, string> */
    private function roleCodes(): array
    {
        return $this->roleCodes ??= Role::query()->pluck('code', 'id')->all();
    }
}
