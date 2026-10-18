<?php

namespace App\Services\Registers;

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Art. 98's register 3 — سجل الاجتماعات. */
class MeetingsRegister extends Register
{
    public function code(): string
    {
        return 'meetings';
    }

    public function nameAr(): string
    {
        return 'سجل الاجتماعات';
    }

    public function nameEn(): string
    {
        return 'Meetings Register';
    }

    public function columns(): array
    {
        return [
            'meeting_number' => ['ar' => 'رقم الاجتماع', 'en' => 'Meeting no.'],
            'committee' => ['ar' => 'اللجنة', 'en' => 'Committee'],
            'title' => ['ar' => 'العنوان', 'en' => 'Title'],
            'meeting_type' => ['ar' => 'نوع الاجتماع', 'en' => 'Type'],
            'scheduled_at' => ['ar' => 'التاريخ', 'en' => 'Date'],
            'location' => ['ar' => 'المكان', 'en' => 'Location'],
            'chairman' => ['ar' => 'رئيس الجلسة', 'en' => 'Chair'],
            'rapporteur' => ['ar' => 'المقرر', 'en' => 'Rapporteur'],
            'status' => ['ar' => 'الحالة', 'en' => 'Status'],
            'results_recorded_at' => ['ar' => 'تاريخ إدخال النتائج', 'en' => 'Results entered'],
            'agenda_items' => ['ar' => 'عدد البنود', 'en' => 'Agenda items'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'meetings.scheduled_at';
    }

    protected function searchColumns(): array
    {
        return ['meetings.meeting_number', 'meetings.title'];
    }

    protected function baseQuery(): Builder
    {
        return Meeting::query()
            ->with([
                'committee:id,name_ar,name_en',
                'chairman:id,name',
                'rapporteur:id,name',
            ])
            ->withCount('agendaItems');
    }

    protected function row(Model $model, string $locale): array
    {
        return [
            'meeting_number' => $model->meeting_number,
            'committee' => $this->localName($model->committee, $locale),
            'title' => $model->title,
            'meeting_type' => $model->meeting_type,
            'scheduled_at' => $this->date($model->scheduled_at),
            'location' => $model->location,
            'chairman' => $model->chairman?->name,
            'rapporteur' => $model->rapporteur?->name,
            'status' => $model->status,
            'results_recorded_at' => $this->date($model->results_recorded_at),
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
