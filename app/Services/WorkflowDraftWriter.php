<?php

namespace App\Services;

use App\Models\Role;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workflow designer — slice 3. Applies a whole draft graph (PUT
 * workflow-versions/{v}). Upserts rather than replacing, so stage and rule
 * ids — and the type-specific rows the designer does not draw — survive.
 *
 * What stays fixed (slice 5 unlocked the rest): the three anchor stages
 * (WorkflowStage::ANCHORS) are never removed; built-in stages keep their
 * relative order, because order_no is read as "past the committee" and by
 * appeal redo's backward-only check; a built-in stage keeps its code and
 * takes no checklist; a built-in rule keeps its from-stage, action and
 * label, since the code and the locale files name it by them. Custom steps
 * and outcomes are free, and the server mints their codes, so the
 * `custom_` prefix is never a client's claim.
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
            // Workflow designer — slice 5. Any stage left out goes, built-in
            // or custom; checkStages() has made sure no anchor is among them.
            $removed = $stages->reject(fn (WorkflowStage $stage) => $sentCodes->contains($stage->code))->pluck('id');

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
                WorkflowStage::query()->whereKey($stage->id)->update([
                    ...($stage->isCustom() ? $fields : Arr::except($fields, 'checklist')),
                    'order_no' => $orderNo,
                ]);
            }

            $this->writeRules($draft, $rules, $data['transitions']);
            // Removed only after the rules are written: a rule redirected away from a step this save
            // removes still points at it until writeRules(), and to_stage_id cascades on delete.
            WorkflowTransition::query()->whereIn('from_stage_id', $removed)->orWhereIn('to_stage_id', $removed)->delete();
            WorkflowStage::query()->whereKey($removed)->delete();
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

        // Workflow designer — slice 5. A non-anchor built-in may go; an anchor
        // never (see WorkflowStage::ANCHORS).
        $anchor = $stages->first(fn (WorkflowStage $stage) => $stage->isAnchor() && ! $sentCodes->contains($stage->code));
        if ($anchor) {
            throw ValidationException::withMessages(['stages' => [sprintf('المرحلة «%s» ثابتة في كل مسار عمل، فلا تُحذف.', $anchor->name_ar)]]);
        }

        // The built-ins that remain keep their order among themselves; custom
        // steps may sit anywhere between them.
        $builtIns = $stages->reject(fn (WorkflowStage $stage) => $stage->isCustom())->sortBy('order_no')->keys()
            ->intersect($sentCodes)->values()->all();
        $sentBuiltIns = $sentCodes->reject(fn (string $code) => $stages[$code]->isCustom())->values()->all();
        if ($sentBuiltIns !== $builtIns) {
            throw ValidationException::withMessages(['stages' => ['لا يتغير ترتيب المراحل الأساسية فيما بينها؛ تُضاف المراحل المخصصة بينها.']]);
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

        foreach ($sent as $index => $item) {
            $existing = $item['id'] !== null ? $rules->get($item['id']) : null;

            if (! in_array($item['from'], $kept, true) || ! in_array($item['to'], $kept, true)) {
                $errors['transitions'][] = $this->danglingRuleMessage($item, $existing, $stages);

                continue;
            }
            $builtIn = $existing && ! $existing->isCustom();
            if ($builtIn && $stages[$item['from']]->id !== $existing->from_stage_id) {
                $errors["transitions.{$index}.from"][] = 'لا تنتقل قاعدة أساسية إلى مرحلة أخرى؛ تتغير وجهتها فقط.';

                continue;
            }

            $errors = array_merge_recursive($errors, $this->ruleErrors($index, $item, $everyone, custom: ! $builtIn));
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A rule whose end is a step this save removes (or never had). A deleted
     * built-in rule cannot be re-made in a draft (new outcomes are always
     * custom_…), so it is never dropped silently: the admin is told which one
     * to point elsewhere first. Both are named by stage, never by raw code.
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
     * Workflow designer — slice 5: the actor checks hold for an edited
     * built-in rule too — they are what keeps a rule from opening the file
     * to every account.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, list<string>>
     */
    private function ruleErrors(int $index, array $item, ?int $everyone, bool $custom): array
    {
        $errors = [];

        if ($custom && blank($item['label_ar'] ?? null)) {
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
            // Workflow designer — slice 5: everything but from, action and
            // label is now editable on a built-in rule too. request_type_id is
            // never written, so a type-specific override keeps its type.
            $fields = [
                'to_stage_id' => $ids[$item['to']],
                ...collect($item)->only(['required_role_id', 'set_status_id', 'required_status_id'])->all(),
                'is_exception' => (bool) ($item['is_exception'] ?? false),
                'requires_comment' => (bool) ($item['requires_comment'] ?? false),
                'requires_submitter_manager' => (bool) ($item['requires_submitter_manager'] ?? false),
                'requires_creator' => (bool) ($item['requires_creator'] ?? false),
                'requires_checks' => (bool) ($item['requires_checks'] ?? false),
            ];

            if ($existing && ! $existing->isCustom()) {
                $existing->update($fields);

                continue;
            }

            $fields = [...$fields, 'from_stage_id' => $ids[$item['from']], ...collect($item)->only(['label_ar', 'label_en'])->all()];

            if ($existing) {
                $existing->update($fields);
            } else {
                WorkflowTransition::create([...$fields, 'action' => WorkflowTransition::CUSTOM_PREFIX.Str::lower((string) Str::ulid())]);
            }
        }

        // Any rule left out goes, a built-in one too (slice 5).
        WorkflowTransition::query()->whereKey($rules->keys()->diff(collect($sent)->pluck('id')->filter()))->delete();
    }
}
