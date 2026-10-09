<?php

namespace App\Services\Registers;

use App\Models\RequestStatusHistory;
use App\Models\User;
use App\Services\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 2 — سجل المعاملات الناقصة.
 *
 * **Read from `request_status_history`, not from the current status**, and
 * that is the design decision worth stating: a register of نواقص whose rows
 * vanish the moment a file is completed is not a register, it is a worklist.
 * Art. 19's استكمال loop is a thing that *happened* to the file, and Appendix
 * 29 makes recording what was actually required the whole point — so each row
 * here is the moment a shortfall was proven, carrying the reason recorded with
 * it and whether the file has since moved on.
 *
 * Both of [D]'s two shortfall statuses are included, because Art. 27 keeps
 * them deliberately apart and both are نواقص: `incomplete` (Art. 38 code 05,
 * caught at Art. 18's completeness check) and `completion_required` (the
 * committee or the legal member sending the matter back for material).
 */
class IncompleteRequestsRegister extends Register
{
    /** @var list<string> */
    private const SHORTFALL_STATUSES = ['incomplete', 'completion_required'];

    public function code(): string
    {
        return 'incomplete';
    }

    public function nameAr(): string
    {
        return 'سجل المعاملات الناقصة';
    }

    public function nameEn(): string
    {
        return 'Incomplete Requests Register';
    }

    public function columns(): array
    {
        return [
            'reference_number' => ['ar' => 'الرقم المرجعي', 'en' => 'Reference'],
            'title' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'employee' => ['ar' => 'الموظف', 'en' => 'Employee'],
            'shortfall_status' => ['ar' => 'نوع النقص', 'en' => 'Shortfall'],
            'recorded_at' => ['ar' => 'تاريخ إثبات النقص', 'en' => 'Recorded'],
            'reason' => ['ar' => 'المطلوب استكماله', 'en' => 'What is required'],
            'recorded_by' => ['ar' => 'أثبته', 'en' => 'Recorded by'],
            'current_status' => ['ar' => 'الحالة الحالية', 'en' => 'Current status'],
            'still_incomplete' => ['ar' => 'ما زالت ناقصة', 'en' => 'Still incomplete'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'request_status_history.changed_at';
    }

    protected function searchColumns(): array
    {
        return ['request_status_history.reason'];
    }

    protected function baseQuery(): Builder
    {
        return RequestStatusHistory::query()
            ->whereHas('toStatus', fn (Builder $status) => $status->whereIn('code', self::SHORTFALL_STATUSES))
            ->with([
                'toStatus:id,code,name_ar,name_en',
                'changedBy:id,name',
                'request:id,reference_number,title,status_id,created_by_user_id,subject_user_id',
                'request.status:id,code,name_ar,name_en',
                'request.subject:id,name',
            ]);
    }

    protected function row(Model $model, string $locale): array
    {
        $stillIncomplete = in_array((string) $model->request?->status?->code, self::SHORTFALL_STATUSES, true);

        return [
            'reference_number' => $model->request?->reference_number,
            'title' => $model->request?->title,
            'employee' => $model->request?->subject?->name,
            'shortfall_status' => $this->localName($model->toStatus, $locale),
            'recorded_at' => $this->date($model->changed_at),
            'reason' => $model->reason,
            'recorded_by' => $model->changedBy?->name,
            'current_status' => $this->localName($model->request?->status, $locale),
            'still_incomplete' => $stillIncomplete
                ? ($locale === 'ar' ? 'نعم' : 'Yes')
                : ($locale === 'ar' ? 'لا' : 'No'),
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
        return $query->whereHas('request', fn (Builder $r) => app(RequestVisibility::class)->apply($r, $actor));
    }
}
