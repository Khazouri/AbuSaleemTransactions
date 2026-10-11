<?php

namespace App\Services;

use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Workflow designer — slice 3. The checks a draft must pass before it is
 * published (spec §5). Only what the engine or the code depends on is an
 * error: by user decision a flow that departs from the procedures manual is
 * not reported at all.
 *
 * Slice 5 unlocked built-in steps and rules, so the checks also guard what
 * the code depends on: the anchors stay reachable (E1), the three rules the
 * code resolves by name exist once (E8, E9, E11 — REQUIRED_RULES), and a
 * file reaches each stage with a status its exits accept (status_unmet).
 *
 * Slice 4 adds E6 (an outcome waiting on a checklist its step lacks),
 * checklist_no_way_out (a checklist step every exit of which waits on it) and
 * W2 (an attachment item naming a document a bound request type does not list).
 */
class WorkflowVersionValidator
{
    private const ENTRY = 'receive_from_municipality';

    /** Past this stage a file's life is status-only (WorkflowService's terminal statuses). */
    private const FINISH = 'final_approval_archiving';

    private const COMMITTEE = 'receive_from_committee';

    /**
     * Workflow designer — slice 5 (spec §5 E11, E8, E9). Rules the code
     * resolves by stage and action rather than a person choosing them, so
     * their absence is a PHP exception at runtime, not a refusal: intake's
     * system hop, the dean's skip past his own manager, and the ministry
     * bypass. Checked only where the stage is present: a version without the
     * manager step never lands a dean's file there.
     */
    private const REQUIRED_RULES = [
        'receive_from_municipality' => ['submit', 'تقديم الطلب', 'بها يُحال كل طلب جديد'],
        'direct_manager_review' => ['forward', 'موافقة وإحالة', 'بها يتجاوز طلب العميد المدير المباشر'],
        'approval_by_authority' => ['approve', 'اعتماد', 'بها يُحال الطلب إلى الوزارة أو الاعتماد النهائي'],
    ];

    /** @return array{errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} */
    public function report(WorkflowVersion $version): array
    {
        $stages = $version->stages()->get()->keyBy('id');
        $rules = $version->transitions()->with(['setStatus:id,code', 'requiredRole:id,name_ar'])->get();
        $entryId = $stages->firstWhere('code', self::ENTRY)?->id;
        $reachable = $this->reach([$entryId], $rules);
        $errors = [];

        // Built-in stages nothing reaches are kept for history (6–8,
        // administrative_routing); a step the admin added, or an anchor the
        // code sends files to (slice 5: a deleted rule can cut one off), is a
        // mistake.
        foreach ($stages as $stage) {
            if (($stage->isCustom() || $stage->isAnchor()) && ! $reachable->contains($stage->id)) {
                $errors[] = $this->entry('unreachable', sprintf('لا يصل أي مسار إلى المرحلة «%s».', $stage->name_ar), $stage);
            }
        }

        // A file can end by arriving at the final stage, or through any rule
        // that stamps a terminal status (every `cancel` does).
        $ends = $rules->filter(fn (WorkflowTransition $rule) => in_array($rule->setStatus?->code, WorkflowService::TERMINAL_STATUS_CODES, true))
            ->pluck('from_stage_id')
            ->push($stages->firstWhere('code', self::FINISH)?->id)
            ->filter()
            ->values()
            ->all();
        $canFinish = $this->reach($ends, $rules, backwards: true);

        foreach ($stages as $stage) {
            if ($reachable->contains($stage->id) && ! $canFinish->contains($stage->id)) {
                $errors[] = $this->entry('stranded', sprintf('لا يخرج الطلب من المرحلة «%s» إلى نهاية المسار.', $stage->name_ar), $stage);
            }
        }

        // The committee works only on registered files (the قيد mints the
        // reference number), so every path there must pass a rule that
        // stamps `registered`.
        $unregistered = $this->reach([$entryId], $rules->reject(fn (WorkflowTransition $rule) => $rule->setStatus?->code === 'registered'));
        $committee = $stages->firstWhere('code', self::COMMITTEE);
        if ($committee && $unregistered->contains($committee->id)) {
            $errors[] = $this->entry('committee_unregistered', 'يمكن أن يبلغ الطلب اللجنة دون قيده: لا يمر بنتيجة تمنحه حالة «تم التسجيل».', $committee);
        }

        // Workflow designer — slice 4 (E6). An outcome that waits on a checklist
        // its step does not have would be refused forever (the gate fails closed).
        foreach ($rules->where('requires_checks', true) as $rule) {
            $from = $stages->get($rule->from_stage_id);
            if (empty($from?->checklist)) {
                $errors[] = $this->entry('checks_without_checklist', sprintf('النتيجة «%s» تنتظر قائمة تحقق، والمرحلة «%s» لا قائمة لها.', $rule->label_ar, $from?->name_ar), $from, $rule->id);
            }
        }

        return [
            'errors' => [...$errors, ...$this->noWayOut($stages, $rules), ...$this->requiredRules($stages, $rules), ...$this->unmetStatuses($stages, $rules, $entryId)],
            'warnings' => $this->warnings($version, $stages, $rules),
        ];
    }

    /**
     * Workflow designer — slice 4. Nobody may upload at a custom step, so a
     * blocking answer, a missing document or a `not_approved` file can never
     * be cleared there: a step with a checklist needs an outcome that leaves
     * without it. A hold that stays on the step without ending the file is
     * not one.
     *
     * @param  Collection<int, WorkflowStage>  $stages
     * @param  Collection<int, WorkflowTransition>  $rules
     * @return list<array<string, mixed>>
     */
    private function noWayOut(Collection $stages, Collection $rules): array
    {
        $errors = [];

        foreach ($stages->filter(fn (WorkflowStage $stage) => ! empty($stage->checklist)) as $stage) {
            $wayOut = $rules->contains(fn (WorkflowTransition $rule) => (int) $rule->from_stage_id === (int) $stage->id
                && ! $rule->requires_checks
                && ((int) $rule->to_stage_id !== (int) $stage->id || in_array($rule->setStatus?->code, WorkflowService::TERMINAL_STATUS_CODES, true)));
            if (! $wayOut) {
                $errors[] = $this->entry('checklist_no_way_out', sprintf('لا يخرج الطلب من المرحلة «%s» إلا بعد قائمة التحقق؛ أضف نتيجة لا تنتظرها (إعادة أو إلغاء)، فالإجابة المانعة توقف الطلب.', $stage->name_ar), $stage);
            }
        }

        return $errors;
    }

    /**
     * Workflow designer — slice 5. See REQUIRED_RULES. Generic rows only: a
     * type-specific override replaces the generic rule for its own type, and
     * every other type still needs the generic one.
     *
     * @param  Collection<int, WorkflowStage>  $stages
     * @param  Collection<int, WorkflowTransition>  $rules
     * @return list<array<string, mixed>>
     */
    private function requiredRules(Collection $stages, Collection $rules): array
    {
        $errors = [];

        foreach (self::REQUIRED_RULES as $code => [$action, $label, $why]) {
            $stage = $stages->firstWhere('code', $code);
            $count = $stage === null ? 1 : $rules->filter(fn (WorkflowTransition $rule) => (int) $rule->from_stage_id === (int) $stage->id
                && $rule->action === $action && ! $rule->is_exception && $rule->request_type_id === null)->count();
            if ($count !== 1) {
                $errors[] = $this->entry('required_rule_missing', sprintf('تحتاج المرحلة «%s» نتيجة «%s» واحدة غير استثنائية؛ %s.', $stage->name_ar, $label, $why), $stage);
            }
        }

        return $errors;
    }

    /**
     * Workflow designer — slice 5. A rule that names a required status (HR's
     * `register` takes only `routed_to_hr`) is usable only by a file that
     * arrives holding it, and outcomes can now change the status they stamp.
     * So: walk (stage, status) from intake's `new`, following every rule the
     * file may take with the status it holds, and report a stage it reaches
     * holding a status that none of its normal exits accept — there it could
     * only be cancelled. A closed file (terminal status) is not followed.
     *
     * ponytail: only statuses the rules stamp are modelled. The committee's
     * moves and the decision change a status outside workflow_transitions;
     * no rule leaving those stages requires one today. If one ever does, feed
     * those statuses in as extra arrivals at that stage.
     *
     * @param  Collection<int, WorkflowStage>  $stages
     * @param  Collection<int, WorkflowTransition>  $rules
     * @return list<array<string, mixed>>
     */
    private function unmetStatuses(Collection $stages, Collection $rules, ?int $entryId): array
    {
        $terminal = RequestStatus::query()->whereIn('code', WorkflowService::TERMINAL_STATUS_CODES)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $out = $rules->groupBy('from_stage_id');
        $accepts = fn (WorkflowTransition $rule, int $statusId) => $rule->required_status_id === null || (int) $rule->required_status_id === $statusId;
        $queue = $entryId === null ? [] : [[$entryId, (int) RequestStatus::query()->where('code', 'new')->value('id')]];
        $seen = [];
        $errors = [];

        while ($queue !== []) {
            [$stageId, $statusId] = array_shift($queue);
            if (isset($seen["{$stageId}:{$statusId}"]) || in_array($statusId, $terminal, true)) {
                continue;
            }
            $seen["{$stageId}:{$statusId}"] = true;
            $leaving = $out->get($stageId, collect());

            $exits = $leaving->reject(fn (WorkflowTransition $rule) => $rule->is_exception || (int) $rule->to_stage_id === $stageId);
            if ($exits->isNotEmpty() && ! $exits->contains(fn (WorkflowTransition $rule) => $accepts($rule, $statusId)) && ! isset($errors[$stageId])) {
                $stage = $stages->get($stageId);
                $errors[$stageId] = $this->entry('status_unmet', sprintf(
                    'يصل الطلب إلى المرحلة «%s» بحالة «%s»، ولا تقبلها أي نتيجة تنقله منها؛ غيّر الحالة التي تمنحها النتيجة السابقة.',
                    $stage?->name_ar,
                    RequestStatus::query()->whereKey($statusId)->value('name_ar'),
                ), $stage);
            }

            foreach ($leaving->filter(fn (WorkflowTransition $rule) => $accepts($rule, $statusId)) as $rule) {
                $queue[] = [(int) $rule->to_stage_id, (int) ($rule->set_status_id ?? $statusId)];
            }
        }

        return array_values($errors);
    }

    /**
     * @param  Collection<int, WorkflowStage>  $stages
     * @param  Collection<int, WorkflowTransition>  $rules
     * @return list<array<string, mixed>>
     */
    private function warnings(WorkflowVersion $version, Collection $stages, Collection $rules): array
    {
        $warnings = [];

        // Only roles a custom outcome names: the seeded rules name the
        // organisation's own posts, and a fresh install has holders for few.
        foreach ($rules->filter(fn (WorkflowTransition $rule) => $rule->isCustom() && $rule->required_role_id !== null) as $rule) {
            $held = User::query()->where('is_active', true)
                ->whereHas('roles', fn ($roles) => $roles->whereKey($rule->required_role_id))
                ->exists();
            if (! $held) {
                $warnings[] = $this->entry(
                    'role_without_holder',
                    sprintf('لا يوجد مستخدم نشط يحمل الدور «%s» الذي تطلبه النتيجة «%s».', $rule->requiredRole?->name_ar, $rule->label_ar),
                    $stages->get($rule->from_stage_id),
                    $rule->id,
                );
            }
        }

        // A re-seed fixes Standard v1 in place; a draft cloned before that
        // does not get the fix.
        $source = $version->cloned_from_version_id ? WorkflowVersion::find($version->cloned_from_version_id) : null;
        if ($source && $this->lastChange($source)?->gt($version->created_at)) {
            $warnings[] = $this->entry('source_changed', sprintf('تغيّر الإصدار %d بعد إنشاء هذه المسودة، فقد لا تتضمن آخر تعديلاته.', $source->number));
        }

        // Workflow designer — slice 4 (W2). A file of that type can never carry
        // the document, so the item would hold its requests forever. A warning,
        // not an error: the admin may be about to add the row to the type.
        $types = RequestType::query()->where('workflow_id', $version->workflow_id)->get();
        foreach ($stages as $stage) {
            foreach (collect($stage->checklist ?? [])->where('type', 'attachment') as $item) {
                foreach ($types->reject(fn (RequestType $type) => array_key_exists($item['document_key'], $type->documentOptions())) as $type) {
                    $warnings[] = $this->entry('document_missing_for_type', sprintf('البند «%s» في المرحلة «%s» يراجع مستندًا لا يرد في نوع الطلب «%s».', $item['label_ar'], $stage->name_ar, $type->name_ar), $stage);
                }
            }
        }

        return $warnings;
    }

    private function lastChange(WorkflowVersion $version): ?Carbon
    {
        $latest = max(
            (string) $version->stages()->max('updated_at'),
            (string) $version->transitions()->max('workflow_transitions.updated_at'),
        );

        return $latest === '' ? null : Carbon::parse($latest);
    }

    /**
     * Stage ids reachable from $start along $rules (or, backwards, the stages
     * that can reach $start).
     *
     * @param  list<int|null>  $start
     * @param  Collection<int, WorkflowTransition>  $rules
     * @return Collection<int, int>
     */
    private function reach(array $start, Collection $rules, bool $backwards = false): Collection
    {
        [$from, $to] = $backwards ? ['to_stage_id', 'from_stage_id'] : ['from_stage_id', 'to_stage_id'];
        $next = $rules->groupBy($from)->map(fn (Collection $group) => $group->pluck($to));
        $seen = collect(array_filter($start));
        $queue = $seen->all();

        while ($queue !== []) {
            foreach ($next->get(array_shift($queue), []) as $id) {
                if (! $seen->contains($id)) {
                    $seen->push($id);
                    $queue[] = $id;
                }
            }
        }

        return $seen;
    }

    /** @return array<string, mixed> */
    private function entry(string $code, string $message, ?WorkflowStage $stage = null, ?int $transitionId = null): array
    {
        return ['code' => $code, 'message_ar' => $message, 'stage_code' => $stage?->code, 'transition_id' => $transitionId];
    }
}
