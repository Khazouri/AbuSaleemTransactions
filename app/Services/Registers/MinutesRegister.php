<?php

namespace App\Services\Registers;

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 5 — سجل المحاضر.
 *
 * Since the paper sitting (2026-10-18) a محضر is the scanned document the مقرر
 * attaches with the results, so the register lists the meetings that carry
 * one: when it was entered and by whom.
 */
class MinutesRegister extends Register
{
    public function code(): string
    {
        return 'minutes';
    }

    public function nameAr(): string
    {
        return 'سجل المحاضر';
    }

    public function nameEn(): string
    {
        return 'Minutes Register';
    }

    public function columns(): array
    {
        return [
            'meeting_number' => ['ar' => 'رقم الاجتماع', 'en' => 'Meeting no.'],
            'meeting_date' => ['ar' => 'تاريخ الاجتماع', 'en' => 'Meeting date'],
            'committee' => ['ar' => 'اللجنة', 'en' => 'Committee'],
            'recorded_at' => ['ar' => 'تاريخ إدخال المحضر', 'en' => 'Entered'],
            'recorded_by' => ['ar' => 'أدخله', 'en' => 'Entered by'],
            'agenda_items' => ['ar' => 'عدد البنود', 'en' => 'Agenda items'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'meetings.results_recorded_at';
    }

    protected function searchColumns(): array
    {
        return ['meetings.meeting_number', 'meetings.title'];
    }

    protected function baseQuery(): Builder
    {
        return Meeting::query()
            // The results always carry the محضر, as one file or (since
            // 2026-10-25) as scanned pages, so this covers both.
            ->whereNotNull('results_recorded_at')
            ->with(['committee:id,name_ar,name_en', 'resultsRecordedBy:id,name'])
            ->withCount('agendaItems');
    }

    protected function row(Model $model, string $locale): array
    {
        return [
            'meeting_number' => $model->meeting_number,
            'meeting_date' => $this->date($model->scheduled_at),
            'committee' => $this->localName($model->committee, $locale),
            'recorded_at' => $this->date($model->results_recorded_at),
            'recorded_by' => $model->resultsRecordedBy?->name,
            'agenda_items' => $model->agenda_items_count,
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
        return app(MeetingVisibility::class)->apply($query, $actor);
    }
}
