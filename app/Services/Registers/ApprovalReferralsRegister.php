<?php

namespace App\Services\Registers;

use App\Models\ApprovalReferral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 7 — سجل الإحالات للاعتماد, and the only one of the twelve
 * that needed storage of its own.
 *
 * Its columns are [D] Art. 30's six recorded fields verbatim — "تاريخ الإحالة ·
 * رقم كتاب الإحالة · الجهة المحال إليها · تاريخ ورود النتيجة · رقم قرار
 * الاعتماد أو المستند النهائي · أي ملاحظات أو توجيهات صادرة عن جهة الاعتماد" —
 * plus the request they belong to.
 */
class ApprovalReferralsRegister extends Register
{
    public function code(): string
    {
        return 'approval_referrals';
    }

    public function nameAr(): string
    {
        return 'سجل الإحالات للاعتماد';
    }

    public function nameEn(): string
    {
        return 'Referrals for Approval Register';
    }

    public function columns(): array
    {
        return [
            'reference_number' => ['ar' => 'رقم المعاملة', 'en' => 'Reference'],
            'title' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'referred_at' => ['ar' => 'تاريخ الإحالة', 'en' => 'Referred on'],
            'letter_number' => ['ar' => 'رقم كتاب الإحالة', 'en' => 'Letter no.'],
            'referred_to_body' => ['ar' => 'الجهة المحال إليها', 'en' => 'Referred to'],
            'referred_from_stage' => ['ar' => 'مرحلة الإحالة', 'en' => 'Referred from'],
            'recorded_by' => ['ar' => 'أثبتها', 'en' => 'Recorded by'],
            'result_outcome' => ['ar' => 'النتيجة', 'en' => 'Outcome'],
            'result_received_at' => ['ar' => 'تاريخ ورود النتيجة', 'en' => 'Result received'],
            'approval_decision_number' => ['ar' => 'رقم قرار الاعتماد', 'en' => 'Approval decision no.'],
            'result_note' => ['ar' => 'ملاحظات جهة الاعتماد', 'en' => 'Approving body notes'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'approval_referrals.referred_at';
    }

    protected function searchColumns(): array
    {
        return ['approval_referrals.letter_number', 'approval_referrals.referred_to_body'];
    }

    protected function baseQuery(): Builder
    {
        return ApprovalReferral::query()->with([
            'requestRecord:id,reference_number,title',
            'referredFromStage:id,name_ar,name_en',
            'recordedBy:id,name',
        ]);
    }

    protected function row(Model $model, string $locale): array
    {
        return [
            'reference_number' => $model->requestRecord?->reference_number,
            'title' => $model->requestRecord?->title,
            'referred_at' => $this->date($model->referred_at),
            'letter_number' => $model->letter_number,
            'referred_to_body' => $model->referred_to_body,
            'referred_from_stage' => $this->localName($model->referredFromStage, $locale),
            'recorded_by' => $model->recordedBy?->name,
            'result_outcome' => $model->result_outcome === null
                ? ($locale === 'ar' ? 'بانتظار النتيجة' : 'Awaiting result')
                : ($locale === 'ar'
                    ? (ApprovalReferral::OUTCOMES[$model->result_outcome] ?? $model->result_outcome)
                    : $model->result_outcome),
            'result_received_at' => $this->date($model->result_received_at),
            'approval_decision_number' => $model->approval_decision_number,
            'result_note' => $model->result_note,
        ];
    }
}
