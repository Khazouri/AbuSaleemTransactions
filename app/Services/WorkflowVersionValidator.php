<?php

namespace App\Services;

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
 * This slice's writer locks every built-in step and rule except where a
 * built-in rule leads, so the checks here are the ones an admin can break
 * with custom steps and redirected rules. Slice 5 adds the rest once
 * built-ins can be removed.
 */
class WorkflowVersionValidator
{
    private const ENTRY = 'receive_from_municipality';

    /** Past this stage a file's life is status-only (WorkflowService's terminal statuses). */
    private const FINISH = 'final_approval_archiving';

    private const COMMITTEE = 'receive_from_committee';

    /** @return array{errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} */
    public function report(WorkflowVersion $version): array
    {
        $stages = $version->stages()->get()->keyBy('id');
        $rules = $version->transitions()->with(['setStatus:id,code', 'requiredRole:id,name_ar'])->get();
        $entryId = $stages->firstWhere('code', self::ENTRY)?->id;
        $reachable = $this->reach([$entryId], $rules);
        $errors = [];

        // Built-in stages nothing reaches are kept for history (6–8,
        // administrative_routing); only a step the admin added is a mistake.
        foreach ($stages as $stage) {
            if ($stage->isCustom() && ! $reachable->contains($stage->id)) {
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

        return ['errors' => $errors, 'warnings' => $this->warnings($version, $stages, $rules)];
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
