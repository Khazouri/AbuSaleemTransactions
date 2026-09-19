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
            'convened_at' => ['ar' => 'تاريخ الانعقاد', 'en' => 'Convened'],
            'agenda_items' => ['ar' => 'عدد البنود', 'en' => 'Agenda items'],
            'attendees' => ['ar' => 'عدد الحضور', 'en' => 'Attended'],
            'minutes_status' => ['ar' => 'حالة المحضر', 'en' => 'Minutes'],
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
                'meetingMinutes:id,meeting_id,minutes_number,status',
            ])
            ->withCount([
                'agendaItems',
                // Actual attendance, not the invitation list: Appendix 8 keeps
                // إثبات الحضور and إثبات صحة الانعقاد apart, and this column is
                // the first of the two.
                'attendees as attendees_count' => fn (Builder $query) => $query->where('attended', true),
            ]);
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
            'convened_at' => $this->date($model->convened_at),
            'agenda_items' => $model->agenda_items_count,
            'attendees' => $model->attendees_count,
            'minutes_status' => $model->meetingMinutes?->status,
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
