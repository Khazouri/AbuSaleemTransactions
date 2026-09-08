<?php

namespace App\Services\Registers;

use App\Models\MeetingRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Art. 98's register 4 — سجل جدول الأعمال.
 *
 * One row per agenda item across every meeting, i.e. Stage 31's typed agenda
 * rows. The subject column falls back through the three kinds of item the
 * agenda can hold — an employee request, an appeal (Stage 63), or an
 * administrative/emerging item that carries its own `subject` and no parent at
 * all — so no row in this register is ever blank.
 */
class AgendaRegister extends Register
{
    public function code(): string
    {
        return 'agenda';
    }

    public function nameAr(): string
    {
        return 'سجل جدول الأعمال';
    }

    public function nameEn(): string
    {
        return 'Agenda Register';
    }

    public function columns(): array
    {
        return [
            'meeting_number' => ['ar' => 'رقم الاجتماع', 'en' => 'Meeting no.'],
            'meeting_date' => ['ar' => 'تاريخ الاجتماع', 'en' => 'Meeting date'],
            'agenda_order' => ['ar' => 'رقم البند', 'en' => 'Item no.'],
            'item_type' => ['ar' => 'نوع البند', 'en' => 'Item type'],
            'reference_number' => ['ar' => 'رقم المعاملة', 'en' => 'Reference'],
            'subject' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'priority' => ['ar' => 'الأولوية', 'en' => 'Priority'],
            'estimated_minutes' => ['ar' => 'الزمن المقدر', 'en' => 'Est. minutes'],
            'item_state' => ['ar' => 'حالة البند', 'en' => 'Item state'],
            'outcome' => ['ar' => 'النتيجة', 'en' => 'Outcome'],
        ];
    }

    protected function dateColumn(): string
    {
        return 'meeting_requests.created_at';
    }

    protected function searchColumns(): array
    {
        return ['meeting_requests.subject'];
    }

    protected function baseQuery(): Builder
    {
        return MeetingRequest::query()->with([
            'meeting:id,meeting_number,scheduled_at',
            'request:id,reference_number,title',
            'appeal:id,original_request_id',
            'appeal.originalRequest:id,reference_number,title',
            'decision:id,meeting_request_id,outcome',
        ]);
    }

    protected function row(Model $model, string $locale): array
    {
        return [
            'meeting_number' => $model->meeting?->meeting_number,
            'meeting_date' => $this->date($model->meeting?->scheduled_at),
            'agenda_order' => $model->agenda_order,
            'item_type' => $model->item_type,
            'reference_number' => $model->request?->reference_number
                ?? $model->appeal?->originalRequest?->reference_number,
            'subject' => $model->request?->title
                ?? $model->subject
                ?? $model->appeal?->originalRequest?->title,
            'priority' => $model->priority,
            'estimated_minutes' => $model->estimated_minutes,
            'item_state' => $model->item_state,
            'outcome' => $model->decision?->outcome,
        ];
    }
}
