<?php

namespace App\Services;

use App\Models\Role;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workflow designer — slice 3. Applies a whole draft graph (PUT
 * workflow-versions/{v}). Upserts rather than replacing, so stage and rule
 * ids — and the type-specific rows the designer does not draw — survive.
 *
 * This slice's locks, all enforced here because they depend on the draft's
 * current rows: every built-in stage stays, unedited and in its relative
 * order; every built-in rule stays, and only its destination may change
 * (that is how a custom step is put in the path). Custom steps and outcomes
 * are free, and the server mints their codes, so the `custom_` prefix is
 * never a client's claim.
 */
class WorkflowDraftWriter
{
    /** @param  array{stages: list<array<string, mixed>>, transitions: list<array<string, mixed>>}  $data */
    public function save(WorkflowVersion $draft, array $data): void
    {
        DB::transaction(function () use ($draft, $data) {
            // Two admins saving at once: the second waits, then overwrites.
            // The status is re-read under the lock: a publish that committed
            // after the controller's own check must not be written into.
            if (WorkflowVersion::query()->lockForUpdate()->findOrFail($draft->id)->status !== 'draft') {
                throw ValidationException::withMessages(['version' => [WorkflowVersion::NOT_A_DRAFT]]);
            }

            $stages = $draft->stages()->get()->keyBy('code');
            $rules = WorkflowTransition::query()->whereIn('from_stage_id', $stages->pluck('id'))->get()->keyBy('id');
            $sent = collect($data['stages'])->values();
            $sentCodes = $sent->pluck('code')->filter()->values();

            $this->checkStages($stages, $sentCodes);
            $this->checkRules($rules, $stages, $data['transitions'], $sentCodes->all());

            $checklists = $this->checklists($stages, $sent);

            // Everything checked; nothing below can refuse half-way.
            $removed = $stages->filter(fn (WorkflowStage $stage) => $stage->isCustom() && ! $sentCodes->contains($stage->code))->pluck('id');
            WorkflowTransition::query()->whereIn('from_stage_id', $removed)->orWhereIn('to_stage_id', $removed)->delete();
            WorkflowStage::query()->whereKey($removed)->delete();

            // Park every survivor clear of unique(version, order_no) before
            // renumbering, so the loop below never collides with a row it
            // has not reached yet.
            $draft->stages()->update(['order_no' => DB::raw('order_no + 1000')]);

            foreach ($sent as $index => $item) {
                $orderNo = $index + 1;
                $fields = [
                    ...collect($item)->only(['name_ar', 'name_en', 'responsible_role_id', 'target_days_min', 'target_days_max'])->all(),
                    'checklist' => $checklists[$index],
                ];

                if ($item['code'] === null) {
                    WorkflowStage::create([
                        ...$fields,
                        'workflow_version_id' => $draft->id,
                        'order_no' => $orderNo,
                        'code' => WorkflowTransition::CUSTOM_PREFIX.Str::lower((string) Str::ulid()),
                    ]);

                    continue;
                }

                // A query update, not $model->update(): the models still hold
                // the pre-park order_no, so an unmoved stage would look clean
                // and be left parked at +1000.
                $stage = $stages[$item['code']];
                WorkflowStage::query()->whereKey($stage->id)->update(
                    $stage->isCustom() ? [...$fields, 'order_no' => $orderNo] : ['order_no' => $orderNo],
                );
            }

            $this->writeRules($draft, $rules, $data['transitions']);
        });
    }

    /**
     * Workflow designer — slice 4. Each sent stage's checklist, normalised to
     * the fields its item type uses (spec §3) — so switching an item's type
     * in the editor leaves no stale field behind — with every new item given
     * a key. Keys are minted here, like stage codes, and a sent key must
     * already be on that stage: an answer card is keyed by them, so they must
     * never change under it or be invented by a client. The `c` prefix keeps
     * a key from being all digits (the JSON re-index gotcha).
     *
     * @param  Collection<string, WorkflowStage>  $stages
     * @param  Collection<int, array<string, mixed>>  $sent
     * @return array<int, list<array<string, mixed>>|null> by sent index
     */
    private function checklists(Collection $stages, Collection $sent): array
    {
        $errors = [];
        $result = [];

        foreach ($sent as $index => $item) {
            $known = collect($item['code'] === null ? [] : ($stages[$item['code']]->checklist ?? []))->pluck('key');
            $keys = collect($item['checklist'] ?? [])->pluck('key')->filter();

            if ($keys->diff($known)->isNotEmpty() || $keys->duplicates()->isNotEmpty()) {
                $errors["stages.{$index}.checklist"][] = 'بند في قائمة التحقق لا ينتمي إلى هذه المرحلة.';

                continue;
            }

            $items = collect($item['checklist'] ?? [])->map(fn (array $check) => [
                'key' => $check['key'] ?? 'c'.Str::lower(Str::random(10)),
                'type' => $check['type'],
                'label_ar' => $check['label_ar'],
                'label_en' => $check['label_en'] ?? null,
                ...match ($check['type']) {
                    'yes_no' => ['blocking_answer' => $check['blocking_answer']],
                    'attachment' => ['document_key' => $check['document_key']],
                    default => [],
                },
            ])->values()->all();

            $result[$index] = $items === [] ? null : $items;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    /**
     * @param  Collection<string, WorkflowStage>  $stages
     * @param  Collection<int, string>  $sentCodes
     */
    private function checkStages(Collection $stages, Collection $sentCodes): void
    {
        if ($sentCodes->contains(fn (string $code) => ! $stages->has($code))) {
            throw ValidationException::withMessages(['stages' => ['مرحلة غير موجودة في هذه المسودة.']]);
        }

        $builtIns = $stages->reject(fn (WorkflowStage $stage) => $stage->isCustom())->sortBy('order_no')->keys()->values()->all();
        $sentBuiltIns = $sentCodes->reject(fn (string $code) => $stages[$code]->isCustom())->values()->all();
        if ($sentBuiltIns !== $builtIns) {
            throw ValidationException::withMessages(['stages' => ['المراحل الأساسية لا تُحذف ولا يتغير ترتيبها فيما بينها في هذا الإصدار من المصمم.']]);
        }
    }

    /**
     * @param  Collection<int, WorkflowTransition>  $rules
     * @param  Collection<string, WorkflowStage>  $stages
     * @param  list<array<string, mixed>>  $sent
     * @param  list<string>  $kept  stage codes that survive the save
     */
    private function checkRules(Collection $rules, Collection $stages, array $sent, array $kept): void
    {
        $errors = [];
        $sentIds = collect($sent)->pluck('id')->filter();
        $everyone = Role::query()->where('code', 'R01')->value('id');

        if ($sentIds->contains(fn (int $id) => ! $rules->has($id))) {
            $errors['transitions'][] = 'نتيجة لا تنتمي إلى هذه المسودة.';
        }
        if ($rules->reject(fn (WorkflowTransition $rule) => $rule->isCustom())->keys()->diff($sentIds)->isNotEmpty()) {
            $errors['transitions'][] = 'القواعد الأساسية لا تُحذف في هذا الإصدار من المصمم.';
        }

        foreach ($sent as $index => $item) {
            $existing = $item['id'] !== null ? $rules->get($item['id']) : null;

            if (! in_array($item['from'], $kept, true) || ! in_array($item['to'], $kept, true)) {
                $errors['transitions'][] = $this->danglingRuleMessage($item, $existing, $stages);

                continue;
            }
            if ($existing && ! $existing->isCustom()) {
                if ($stages[$item['from']]->id !== $existing->from_stage_id) {
                    $errors["transitions.{$index}.from"][] = 'لا يمكن نقل قاعدة أساسية إلى مرحلة أخرى؛ تتغير وجهتها فقط.';
                }

                continue;
            }

            $errors = array_merge_recursive($errors, $this->customRuleErrors($index, $item, $everyone));
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A rule whose end is a step this save removes (or never had). A built-in
     * rule cannot be deleted in this slice, so the admin is told which one to
     * point elsewhere first; both are named by stage, never by raw code.
     *
     * @param  array<string, mixed>  $item
     * @param  Collection<string, WorkflowStage>  $stages
     */
    private function danglingRuleMessage(array $item, ?WorkflowTransition $existing, Collection $stages): string
    {
        $name = fn (string $code) => $stages->get($code)?->name_ar ?? $code;

        if ($existing && ! $existing->isCustom()) {
            return sprintf('قاعدة أساسية في المرحلة «%s» تنقل الطلب إلى «%s»؛ غيّر وجهتها قبل حذف تلك المرحلة.', $name($item['from']), $name($item['to']));
        }

        return sprintf('النتيجة «%s» تشير إلى مرحلة محذوفة أو غير موجودة.', $item['label_ar'] ?? $name($item['to']));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, list<string>>
     */
    private function customRuleErrors(int $index, array $item, ?int $everyone): array
    {
        $errors = [];

        if (blank($item['label_ar'] ?? null)) {
            $errors["transitions.{$index}.label_ar"][] = 'اسم النتيجة بالعربية مطلوب.';
        }
        // A rule with no actor at all would let — and show the file to —
        // every account (RequestVisibility reads a null role as "anyone").
        if (($item['required_role_id'] ?? null) === null && empty($item['requires_submitter_manager']) && empty($item['requires_creator'])) {
            $errors["transitions.{$index}.required_role_id"][] = 'حدد من يتخذ هذه النتيجة: دورًا أو الرئيس المباشر أو مقدم الطلب.';
        }
        // Every account holds R01, so naming it would show the file to — and
        // let it be moved by — everyone (the 2026-09-21 `submit` leak). The
        // filer is `requires_creator`.
        if ($everyone !== null && ($item['required_role_id'] ?? null) === $everyone) {
            $errors["transitions.{$index}.required_role_id"][] = 'كل حساب يحمل دور «موظف»، فلا يحدد من يتخذ النتيجة؛ اختر «مقدم الطلب» إن كان المقصود صاحب الطلب.';
        }

        // Workflow designer — slice 4. The filer may never complete a check on
        // their own file (actorMayUse()), so a checked outcome only the filer
        // may take could never be taken by anyone.
        if (! empty($item['requires_checks']) && ! empty($item['requires_creator'])) {
            $errors["transitions.{$index}.requires_checks"][] = 'لا تُربط قائمة التحقق بنتيجة يتخذها مقدم الطلب، فهو لا يستكمل التحقق على طلبه.';
        }

        return $errors;
    }

    /**
     * @param  Collection<int, WorkflowTransition>  $rules
     * @param  list<array<string, mixed>>  $sent
     */
    private function writeRules(WorkflowVersion $draft, Collection $rules, array $sent): void
    {
        $ids = $draft->stages()->pluck('id', 'code');

        foreach ($sent as $item) {
            $existing = $item['id'] !== null ? $rules->get($item['id']) : null;

            if ($existing && ! $existing->isCustom()) {
                $existing->update(['to_stage_id' => $ids[$item['to']]]);

                continue;
            }

            $fields = [
                'from_stage_id' => $ids[$item['from']],
                'to_stage_id' => $ids[$item['to']],
                ...collect($item)->only(['label_ar', 'label_en', 'required_role_id', 'set_status_id', 'required_status_id'])->all(),
                'is_exception' => (bool) ($item['is_exception'] ?? false),
                'requires_comment' => (bool) ($item['requires_comment'] ?? false),
                'requires_submitter_manager' => (bool) ($item['requires_submitter_manager'] ?? false),
                'requires_creator' => (bool) ($item['requires_creator'] ?? false),
                'requires_checks' => (bool) ($item['requires_checks'] ?? false),
            ];

            if ($existing) {
                $existing->update($fields);
            } else {
                WorkflowTransition::create([...$fields, 'action' => WorkflowTransition::CUSTOM_PREFIX.Str::lower((string) Str::ulid())]);
            }
        }

        $sentIds = collect($sent)->pluck('id')->filter();
        WorkflowTransition::query()
            ->whereKey($rules->filter(fn (WorkflowTransition $rule) => $rule->isCustom())->keys()->diff($sentIds))
            ->delete();
    }
}
