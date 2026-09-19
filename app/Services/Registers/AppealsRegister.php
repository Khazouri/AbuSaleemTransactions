<?php

namespace App\Services\Registers;

use App\Models\Appeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 10 — سجل التظلمات, over Track J's `appeals`.
 *
 * An appeal has no reference number of its own by design (Stage 58: it is a
 * contest against an already-decided Request, never a Request itself), so the
 * register is keyed by the original file's number and carries the appeal's own
 * id beside it — which is what makes two appeals against the same decision
 * distinguishable in a printed register.
 */
class AppealsRegister extends Register
{
    public function code(): string
    {
        return 'appeals';
    }

    public function nameAr(): string
    {
        return 'سجل التظلمات';
    }

    public function nameEn(): string
    {
        return 'Appeals Register';
    }

    public function columns(): array
    {
        return [
            'id' => ['ar' => 'رقم التظلم', 'en' => 'Appeal no.'],
            'appellant' => ['ar' => 'المتظلم', 'en' => 'Appellant'],
            'original_reference' => ['ar' => 'رقم المعاملة الأصلية', 'en' => 'Original reference'],
            'original_decision_date' => ['ar' => 'تاريخ القرار المتظلم منه', 'en' => 'Decision date'],
            'known_at' => ['ar' => 'تاريخ العلم', 'en' => 'Known on'],
            'filed_at' => ['ar' => 'تاريخ التظلم', 'en' => 'Filed on'],
            'status' => ['ar' => 'الحالة', 'en' => 'Status'],
            'committee_outcome' => ['ar' => 'نتيجة اللجنة', 'en' => 'Committee outcome'],
            'outcome_executed_at' => ['ar' => 'تاريخ تنفيذ النتيجة', 'en' => 'Outcome executed'],
            'closed_at' => ['ar' => 'تاريخ الإقفال', 'en' => 'Closed on'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'appeals.created_at';
    }

    protected function searchColumns(): array
    {
        return ['appeals.original_decision_reference', 'appeals.appeal_reasons'];
    }

    protected function baseQuery(): Builder
    {
        return Appeal::query()->with([
            'appellant:id,name',
            'originalRequest:id,reference_number',
            'status:id,name_ar,name_en',
            'committeeAgendaItem:id,appeal_id',
            'committeeAgendaItem.decision:id,meeting_request_id,outcome',
        ]);
    }

    protected function row(Model $model, string $locale): array
    {
        return [
            'id' => $model->id,
            'appellant' => $model->appellant?->name,
            'original_reference' => $model->originalRequest?->reference_number
                ?? $model->original_decision_reference,
            'original_decision_date' => $this->date($model->original_decision_date),
            'known_at' => $this->date($model->known_at),
            'filed_at' => $this->date($model->created_at),
            'status' => $this->localName($model->status, $locale),
            'committee_outcome' => $model->committeeAgendaItem?->decision?->outcome,
            'outcome_executed_at' => $this->date($model->outcome_executed_at),
            'closed_at' => $this->date($model->closed_at),
        ];
    }

    /**
     * Membership gate — see Register::scopeToActor().
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function scopeToActor(Builder $query, User $actor): Builder
    {
        // An appeal is its appellant's own, or readable by whoever may act on
        // appeals at all — exactly Appeal::isVisibleTo()'s rule, as a scope.
        if ($actor->hasScreenPermission('appeals', 'can_edit')) {
            return $query;
        }

        return $query->where('appellant_user_id', $actor->id);
    }
}
