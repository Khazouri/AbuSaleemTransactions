<?php

namespace App\Services\Registers;

use App\Models\Decision;
use App\Models\Request;
use App\Models\User;
use App\Services\MeetingVisibility;
use App\Services\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 6 — سجل القرارات والتوصيات, in [D] **Appendix 12's own
 * thirteen columns**: "الرقم المتسلسل للقرار · رقم الاجتماع · رقم المعاملة ·
 * اسم الموظف · نوع الموضوع · منطوق النتيجة · تاريخ الاجتماع · جهة الاعتماد ·
 * تاريخ الاعتماد · رقم القرار النهائي · حالة التنفيذ · تاريخ التنفيذ · حالة
 * الإقفال."
 *
 * **Stage 25's `/decisions` screen is deliberately left alone**, and this is
 * not a duplicate of it. Those are two different documents over one table:
 * Stage 25's is the committee's *working* view — the per-outcome vote tally,
 * Art. 90's instrument, Appendix 27's منطوق, Appendix 28's refusal reason, and
 * the "awaiting my vote" worklist — while Appendix 12's is a central reference
 * register whose stated purpose is "**وسيلة للرجوع إلى السوابق الإدارية**" and
 * whose last six columns are the file's *administrative* trail after the vote:
 * who approved it, when, under what number, whether it was executed and
 * whether it was closed. Bolting eight more columns onto an already
 * 27-column operational export would serve neither reader.
 *
 * The approval trio comes from Stage 80's own `approval_referrals` first and
 * falls back to Stage 75's closure card, because those are two honest sources
 * for the same fact recorded at two different moments — a file may be closed
 * without anyone having entered the referral, and vice versa.
 */
class DecisionsRegister extends Register
{
    public function code(): string
    {
        return 'decisions';
    }

    public function nameAr(): string
    {
        return 'سجل القرارات والتوصيات';
    }

    public function nameEn(): string
    {
        return 'Decisions & Recommendations Register';
    }

    public function columns(): array
    {
        return [
            'decision_number' => ['ar' => 'الرقم المتسلسل للقرار', 'en' => 'Decision no.'],
            'meeting_number' => ['ar' => 'رقم الاجتماع', 'en' => 'Meeting no.'],
            'reference_number' => ['ar' => 'رقم المعاملة', 'en' => 'Reference'],
            'employee' => ['ar' => 'اسم الموظف', 'en' => 'Employee'],
            'subject_type' => ['ar' => 'نوع الموضوع', 'en' => 'Subject type'],
            'operative' => ['ar' => 'منطوق النتيجة', 'en' => 'Operative clause'],
            'meeting_date' => ['ar' => 'تاريخ الاجتماع', 'en' => 'Meeting date'],
            'approving_body' => ['ar' => 'جهة الاعتماد', 'en' => 'Approving body'],
            'approved_at' => ['ar' => 'تاريخ الاعتماد', 'en' => 'Approved on'],
            'final_decision_number' => ['ar' => 'رقم القرار النهائي', 'en' => 'Final decision no.'],
            'execution_status' => ['ar' => 'حالة التنفيذ', 'en' => 'Execution'],
            'execution_date' => ['ar' => 'تاريخ التنفيذ', 'en' => 'Executed on'],
            'closure_status' => ['ar' => 'حالة الإقفال', 'en' => 'Closure'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'decisions.decided_at';
    }

    protected function searchColumns(): array
    {
        return ['decisions.decision_number', 'decisions.decision_subject'];
    }

    protected function baseQuery(): Builder
    {
        return Decision::query()->with([
            'meetingRequest:id,meeting_id,request_id',
            'meetingRequest.meeting:id,meeting_number,scheduled_at',
            'meetingRequest.request:id,reference_number,request_type_id,created_by_user_id,status_id,executed_at,closed_at,closure',
            'meetingRequest.request.requestType:id,name_ar,name_en',
            'meetingRequest.request.createdBy:id,name',
            'meetingRequest.request.status:id,code,name_ar,name_en',
            'meetingRequest.request.approvalReferrals',
        ]);
    }

    protected function row(Model $model, string $locale): array
    {
        /** @var Request|null $requestRecord */
        $requestRecord = $model->meetingRequest?->request;
        $closure = $requestRecord?->closure ?? [];

        // The referral that actually came back approved is the one that
        // carries Art. 30's رقم قرار الاعتماد; an open or returned one has no
        // approval to report and must not be read as though it did.
        $approved = $requestRecord?->approvalReferrals
            ->firstWhere('result_outcome', 'approved');

        return [
            'decision_number' => $model->decision_number,
            'meeting_number' => $model->meetingRequest?->meeting?->meeting_number,
            'reference_number' => $requestRecord?->reference_number,
            'employee' => $requestRecord?->createdBy?->name,
            'subject_type' => $this->localName($requestRecord?->requestType, $locale),
            'operative' => $model->decision_operative,
            'meeting_date' => $this->date($model->meetingRequest?->meeting?->scheduled_at),
            'approving_body' => $approved?->referred_to_body ?? ($closure['approving_body'] ?? null),
            'approved_at' => $this->date($approved?->result_received_at),
            'final_decision_number' => $approved?->approval_decision_number
                ?? ($closure['final_decision_number'] ?? null),
            'execution_status' => $requestRecord?->executed_at !== null
                ? ($locale === 'ar' ? 'منفذة' : 'Executed')
                : $this->localName($requestRecord?->status, $locale),
            'execution_date' => $this->date($requestRecord?->executed_at),
            'closure_status' => $requestRecord?->closed_at !== null
                ? ($locale === 'ar' ? 'مقفلة' : 'Closed')
                : ($locale === 'ar' ? 'مفتوحة' : 'Open'),
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
        return $query->where(fn (Builder $either) => $either
            ->whereHas('meetingRequest.meeting', fn (Builder $m) => app(MeetingVisibility::class)->apply($m, $actor))
            ->orWhereHas('meetingRequest.request', fn (Builder $r) => app(RequestVisibility::class)->apply($r, $actor)));
    }
}
