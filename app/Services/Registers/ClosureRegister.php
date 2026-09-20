<?php

namespace App\Services\Registers;

use App\Models\Request;
use App\Models\User;
use App\Services\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 12 — سجل الإقفال والأرشفة, over Stage 75's closure record.
 *
 * Its columns are Art. 37's eight closure fields as that stage stored them,
 * plus who closed the file. `file_storage_location` is carried deliberately:
 * this register is the أرشفة half of its own name, and a closure record with
 * no archive location would not say where the file went.
 */
class ClosureRegister extends Register
{
    public function code(): string
    {
        return 'closure';
    }

    public function nameAr(): string
    {
        return 'سجل الإقفال والأرشفة';
    }

    public function nameEn(): string
    {
        return 'Closure & Archiving Register';
    }

    public function columns(): array
    {
        return [
            'reference_number' => ['ar' => 'رقم المعاملة', 'en' => 'Reference'],
            'title' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'employee' => ['ar' => 'الموظف', 'en' => 'Employee'],
            'closed_at' => ['ar' => 'تاريخ الإقفال', 'en' => 'Closed on'],
            'final_result' => ['ar' => 'النتيجة النهائية', 'en' => 'Final result'],
            'final_decision_number' => ['ar' => 'رقم القرار النهائي', 'en' => 'Final decision no.'],
            'approving_body' => ['ar' => 'جهة الاعتماد', 'en' => 'Approving body'],
            'execution_date' => ['ar' => 'تاريخ التنفيذ', 'en' => 'Execution date'],
            'executing_body' => ['ar' => 'الجهة المنفذة', 'en' => 'Executing body'],
            'notice_status' => ['ar' => 'حالة الإشعار', 'en' => 'Notice status'],
            'file_storage_location' => ['ar' => 'موقع حفظ الملف', 'en' => 'File location'],
            'closed_by' => ['ar' => 'أقفلها', 'en' => 'Closed by'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'requests.closed_at';
    }

    protected function searchColumns(): array
    {
        return ['requests.reference_number', 'requests.title'];
    }

    protected function baseQuery(): Builder
    {
        return Request::query()
            ->whereNotNull('closed_at')
            ->with([
                'createdBy:id,name',
                'subject:id,name',
                'closedBy:id,name',
            ]);
    }

    protected function row(Model $model, string $locale): array
    {
        $closure = $model->closure ?? [];

        return [
            'reference_number' => $model->reference_number,
            'title' => $model->title,
            'employee' => $model->subject?->name,
            'closed_at' => $this->date($model->closed_at),
            'final_result' => $closure['final_result_code'] ?? null,
            'final_decision_number' => $closure['final_decision_number'] ?? null,
            'approving_body' => $closure['approving_body'] ?? null,
            'execution_date' => $closure['execution_date'] ?? null,
            'executing_body' => $closure['executing_body'] ?? null,
            'notice_status' => $closure['notice_status'] ?? null,
            'file_storage_location' => $closure['file_storage_location'] ?? null,
            'closed_by' => $model->closedBy?->name,
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
        return app(RequestVisibility::class)->apply($query, $actor);
    }
}
