<?php

namespace App\Services\Registers;

use App\Models\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 9 — سجل التنفيذ, over Stage 76's execution card.
 *
 * Only files that actually carry an execution record appear: Appendix 70's
 * whole point is that "لا يكفي أن تقول الجهة المنفذة (تم التنفيذ) بل يجب إرفاق
 * دليل التنفيذ", so a register of التنفيذ that listed files nobody had recorded
 * an execution for would be listing the claim the appendix rejects. The
 * evidence count is carried as its own column for the same reason.
 */
class ExecutionRegister extends Register
{
    public function code(): string
    {
        return 'execution';
    }

    public function nameAr(): string
    {
        return 'سجل التنفيذ';
    }

    public function nameEn(): string
    {
        return 'Execution Register';
    }

    public function columns(): array
    {
        return [
            'reference_number' => ['ar' => 'رقم المعاملة', 'en' => 'Reference'],
            'title' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'employee' => ['ar' => 'الموظف', 'en' => 'Employee'],
            'executing_body' => ['ar' => 'الجهة المنفذة', 'en' => 'Executing body'],
            'action_taken' => ['ar' => 'الإجراء المتخذ', 'en' => 'Action taken'],
            'effective_date' => ['ar' => 'تاريخ النفاذ', 'en' => 'Effective date'],
            'approving_body' => ['ar' => 'جهة الاعتماد', 'en' => 'Approving body'],
            'approval_number' => ['ar' => 'رقم الاعتماد', 'en' => 'Approval no.'],
            'executed_at' => ['ar' => 'تاريخ التنفيذ', 'en' => 'Executed on'],
            'executed_by' => ['ar' => 'نفذها', 'en' => 'Executed by'],
            'evidence_count' => ['ar' => 'عدد مستندات التنفيذ', 'en' => 'Evidence documents'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'requests.executed_at';
    }

    protected function searchColumns(): array
    {
        return ['requests.reference_number', 'requests.title'];
    }

    protected function baseQuery(): Builder
    {
        return Request::query()
            ->whereNotNull('executed_at')
            ->with([
                'createdBy:id,name',
                'executedBy:id,name',
            ])
            ->withCount([
                'attachments as execution_evidence_count' => fn (Builder $query) => $query
                    ->whereNotNull('execution_evidence_type'),
            ]);
    }

    protected function row(Model $model, string $locale): array
    {
        $card = $model->execution ?? [];

        return [
            'reference_number' => $model->reference_number,
            'title' => $model->title,
            'employee' => $model->createdBy?->name,
            'executing_body' => $card['executing_body'] ?? null,
            'action_taken' => $card['action_taken'] ?? null,
            'effective_date' => $card['effective_date'] ?? null,
            'approving_body' => $card['approving_body'] ?? null,
            'approval_number' => $card['approval_number'] ?? null,
            'executed_at' => $this->date($model->executed_at),
            'executed_by' => $model->executedBy?->name,
            'evidence_count' => $model->execution_evidence_count,
        ];
    }
}
