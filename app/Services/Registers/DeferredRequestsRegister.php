<?php

namespace App\Services\Registers;

use App\Models\Decision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 11 — سجل المعاملات المؤجلة.
 *
 * **Built over Stage 74's `defer` decisions, not over the `deferred` status**,
 * and that is the design point. The status says a file is deferred; the
 * decision says *why* and *what must be completed*, which is exactly Art. 34's
 * own five recorded fields and exactly what Appendix 29 refuses to let a
 * deferral omit ("يمنع استخدام عبارة (تأجيل للمراجعة) دون بيان المطلوب"). A
 * register built on the status alone would print a list of files with a blank
 * column where the appendix's whole requirement goes.
 *
 * It also means a deferral stays in the register after the file has resumed,
 * which is what a register is for — the same reasoning the نواقص register
 * reads status history rather than the current status.
 */
class DeferredRequestsRegister extends Register
{
    public function code(): string
    {
        return 'deferred';
    }

    public function nameAr(): string
    {
        return 'سجل المعاملات المؤجلة';
    }

    public function nameEn(): string
    {
        return 'Deferred Requests Register';
    }

    public function columns(): array
    {
        return [
            'reference_number' => ['ar' => 'رقم المعاملة', 'en' => 'Reference'],
            'title' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'employee' => ['ar' => 'الموظف', 'en' => 'Employee'],
            'meeting_number' => ['ar' => 'رقم الاجتماع', 'en' => 'Meeting no.'],
            'deferred_at' => ['ar' => 'تاريخ التأجيل', 'en' => 'Deferred on'],
            'deferral_reason' => ['ar' => 'سبب التأجيل', 'en' => 'Reason'],
            'required_completion' => ['ar' => 'المطلوب استكماله', 'en' => 'Required completion'],
            'responsible_body' => ['ar' => 'الجهة المسؤولة', 'en' => 'Responsible body'],
            'required_document' => ['ar' => 'المستند المطلوب', 'en' => 'Required document'],
            'legal_period' => ['ar' => 'المدة القانونية', 'en' => 'Legal period'],
            'current_status' => ['ar' => 'الحالة الحالية', 'en' => 'Current status'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'decisions.decided_at';
    }

    protected function searchColumns(): array
    {
        return ['decisions.deferral_reason', 'decisions.deferral_required_completion'];
    }

    protected function baseQuery(): Builder
    {
        return Decision::query()
            ->where('outcome', 'defer')
            ->with([
                'meetingRequest:id,meeting_id,request_id',
                'meetingRequest.meeting:id,meeting_number',
                'meetingRequest.request:id,reference_number,title,status_id,created_by_user_id',
                'meetingRequest.request.status:id,name_ar,name_en',
                'meetingRequest.request.createdBy:id,name',
            ]);
    }

    protected function row(Model $model, string $locale): array
    {
        $requestRecord = $model->meetingRequest?->request;

        return [
            'reference_number' => $requestRecord?->reference_number,
            'title' => $requestRecord?->title,
            'employee' => $requestRecord?->createdBy?->name,
            'meeting_number' => $model->meetingRequest?->meeting?->meeting_number,
            'deferred_at' => $this->date($model->decided_at),
            'deferral_reason' => $model->deferral_reason,
            'required_completion' => $model->deferral_required_completion,
            'responsible_body' => $model->deferral_responsible_body,
            'required_document' => $model->deferral_required_document,
            'legal_period' => $model->deferral_legal_period,
            'current_status' => $this->localName($requestRecord?->status, $locale),
        ];
    }
}
