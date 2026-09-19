<?php

namespace App\Services\Registers;

use App\Models\ApprovalReturn;
use App\Models\User;
use App\Services\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 8 — سجل القرارات المعادة من جهة الاعتماد, over Stage 77's
 * `approval_returns`.
 *
 * That table was built as a history rather than a column precisely because
 * this register exists: Art. 94's loop has no limit, and an overwritten column
 * could not supply a register of returns. Both of Art. 94's halves are
 * columns here — سبب الإعادة and الإجراء الذي اتخذ بشأنها — so an unresolved
 * round is visible as one.
 */
class ApprovalReturnsRegister extends Register
{
    public function code(): string
    {
        return 'approval_returns';
    }

    public function nameAr(): string
    {
        return 'سجل القرارات المعادة من جهة الاعتماد';
    }

    public function nameEn(): string
    {
        return 'Returns from Approving Body Register';
    }

    public function columns(): array
    {
        return [
            'reference_number' => ['ar' => 'رقم المعاملة', 'en' => 'Reference'],
            'title' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'received_at' => ['ar' => 'تاريخ ورود الإعادة', 'en' => 'Returned on'],
            'letter_number' => ['ar' => 'رقم الكتاب', 'en' => 'Letter no.'],
            'return_kind' => ['ar' => 'تصنيف الإعادة', 'en' => 'Classification'],
            'return_reason' => ['ar' => 'سبب الإعادة', 'en' => 'Reason'],
            'return_note' => ['ar' => 'ملاحظات جهة الاعتماد', 'en' => 'Approving body notes'],
            'returned_from_stage' => ['ar' => 'مرحلة الإعادة', 'en' => 'Returned from'],
            'recorded_by' => ['ar' => 'أثبتها', 'en' => 'Recorded by'],
            'resolution_action' => ['ar' => 'الإجراء المتخذ', 'en' => 'Action taken'],
            'resolved_at' => ['ar' => 'تاريخ المعالجة', 'en' => 'Resolved on'],
            'resolved_by' => ['ar' => 'عالجها', 'en' => 'Resolved by'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'approval_returns.received_at';
    }

    protected function searchColumns(): array
    {
        return ['approval_returns.letter_number', 'approval_returns.return_note'];
    }

    protected function baseQuery(): Builder
    {
        return ApprovalReturn::query()->with([
            // Stage 77 named this relation `requestRecord`, per AGENTS.md's
            // rule about the App\Models\Request name collision.
            'requestRecord:id,reference_number,title',
            'returnedFromStage:id,name_ar,name_en',
            'recordedBy:id,name',
            'resolvedBy:id,name',
        ]);
    }

    protected function row(Model $model, string $locale): array
    {
        return [
            'reference_number' => $model->requestRecord?->reference_number,
            'title' => $model->requestRecord?->title,
            'received_at' => $this->date($model->received_at),
            'letter_number' => $model->letter_number,
            'return_kind' => $model->return_kind === ApprovalReturn::KIND_FORMAL
                ? ($locale === 'ar' ? 'شكلية' : 'Formal')
                : ($locale === 'ar' ? 'موضوعية' : 'Substantive'),
            'return_reason' => ApprovalReturn::reasonLabel($model->return_reason_code),
            'return_note' => $model->return_note,
            'returned_from_stage' => $this->localName($model->returnedFromStage, $locale),
            'recorded_by' => $model->recordedBy?->name,
            'resolution_action' => $model->resolution_action,
            'resolved_at' => $this->date($model->resolved_at),
            'resolved_by' => $model->resolvedBy?->name,
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
        return $query->whereHas('requestRecord', fn (Builder $r) => app(RequestVisibility::class)->apply($r, $actor));
    }
}
