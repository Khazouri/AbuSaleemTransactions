<?php

namespace App\Services\Registers;

use App\Models\Request;
use App\Models\User;
use App\Services\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 1 — سجل المعاملات الواردة.
 *
 * Carries both of Stage 70's numbers side by side, which is what makes Art.
 * 99's rule ("يكون لكل معاملة رقم واحد طوال دورة حياتها") visible as the fact
 * it is rather than something a reader has to take on trust: the `PM-RCV`
 * intake receipt is not a قيد (Art. 15 says so outright) and the `PM-COM`
 * reference is the one number that never changes afterwards.
 */
class IncomingRequestsRegister extends Register
{
    public function code(): string
    {
        return 'incoming';
    }

    public function nameAr(): string
    {
        return 'سجل المعاملات الواردة';
    }

    public function nameEn(): string
    {
        return 'Incoming Requests Register';
    }

    public function columns(): array
    {
        return [
            'intake_receipt_number' => ['ar' => 'إيصال الاستلام', 'en' => 'Intake receipt'],
            'reference_number' => ['ar' => 'الرقم المرجعي', 'en' => 'Reference'],
            'title' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'request_type' => ['ar' => 'نوع الطلب', 'en' => 'Type'],
            'employee' => ['ar' => 'الموظف', 'en' => 'Employee'],
            'department' => ['ar' => 'الإدارة', 'en' => 'Department'],
            'submitted_at' => ['ar' => 'تاريخ التقديم', 'en' => 'Submitted'],
            'due_date' => ['ar' => 'تاريخ الاستحقاق', 'en' => 'Due'],
            'status' => ['ar' => 'الحالة', 'en' => 'Status'],
            'current_stage' => ['ar' => 'المرحلة الحالية', 'en' => 'Current stage'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'requests.submitted_at';
    }

    protected function searchColumns(): array
    {
        return ['requests.reference_number', 'requests.intake_receipt_number', 'requests.title'];
    }

    protected function baseQuery(): Builder
    {
        return Request::query()->with([
            'requestType:id,name_ar,name_en',
            'createdBy:id,name',
            'department:id,name_ar,name_en',
            'status:id,name_ar,name_en',
            'currentStage:id,name_ar,name_en',
        ]);
    }

    protected function row(Model $model, string $locale): array
    {
        return [
            'intake_receipt_number' => $model->intake_receipt_number,
            'reference_number' => $model->reference_number,
            'title' => $model->title,
            'request_type' => $this->localName($model->requestType, $locale),
            'employee' => $model->createdBy?->name,
            'department' => $this->localName($model->department, $locale),
            'submitted_at' => $this->date($model->submitted_at),
            'due_date' => $this->date($model->due_date),
            'status' => $this->localName($model->status, $locale),
            'current_stage' => $this->localName($model->currentStage, $locale),
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
