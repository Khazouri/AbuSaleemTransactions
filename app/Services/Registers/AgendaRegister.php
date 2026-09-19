<?php

namespace App\Services\Registers;

use App\Models\MeetingRequest;
use App\Models\User;
use App\Services\AgendaOrderingService;
use App\Services\MeetingVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Art. 98's register 4 — سجل جدول الأعمال.
 *
 * One row per agenda item across every meeting, i.e. Stage 31's typed agenda
 * rows. The subject column falls back through the three kinds of item the
 * agenda can hold — an employee request, an appeal (Stage 63), or an
 * administrative/emerging item that carries its own `subject` and no parent at
 * all — so no row in this register is ever blank.
 *
 * **Stage 82 makes this register النموذج 08 plus Appendix 24.** That form's own
 * six columns include حالة الجاهزية, which was missing; Appendix 24 then adds
 * the six that turn an agenda from "مجرد قائمة أسماء" into "وثيقة إدارة قرار"
 * — الرأي القانوني، نوع القرار المطلوب، جهة الاعتماد المتوقعة، درجة الأولوية،
 * هل سبق عرضه، رقم الاجتماع السابق. Three of those are Stage 68's own recorded
 * legal card and two are derived from the item's earlier appearances, all
 * computed once for the whole page by AgendaOrderingService rather than per
 * row.
 *
 * It also answers Stage 80's own open item (3): the register now reads in the
 * order an agenda is actually held — by sitting, then by item number — instead
 * of by the row's creation timestamp.
 */
class AgendaRegister extends Register
{
    public function __construct(private readonly AgendaOrderingService $ordering) {}

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
            'employee_name' => ['ar' => 'اسم الموظف', 'en' => 'Employee'],
            'request_type' => ['ar' => 'نوع المعاملة', 'en' => 'Request type'],
            'subject' => ['ar' => 'الموضوع', 'en' => 'Subject'],
            'readiness_status' => ['ar' => 'حالة الجاهزية', 'en' => 'Readiness'],
            'legal_opinion' => ['ar' => 'الرأي القانوني', 'en' => 'Legal opinion'],
            'required_instrument' => ['ar' => 'نوع القرار المطلوب', 'en' => 'Instrument sought'],
            'expected_approving_body' => ['ar' => 'جهة الاعتماد المتوقعة', 'en' => 'Expected approving body'],
            'priority' => ['ar' => 'درجة الأولوية', 'en' => 'Priority'],
            'previously_presented' => ['ar' => 'هل سبق عرضه', 'en' => 'Previously presented'],
            'previous_meeting' => ['ar' => 'رقم الاجتماع السابق', 'en' => 'Previous meeting'],
            'estimated_minutes' => ['ar' => 'الزمن المقدر', 'en' => 'Est. minutes'],
            'item_state' => ['ar' => 'حالة البند', 'en' => 'Item state'],
            'study_sequence' => ['ar' => 'تسلسل الدراسة', 'en' => 'Study sequence'],
            'outcome' => ['ar' => 'النتيجة', 'en' => 'Outcome'],
        ];
    }

    /** @var array<string, array{ar: string, en: string}> */
    private const ITEM_TYPES = [
        'employee_request' => ['ar' => 'طلب موظف', 'en' => 'Employee request'],
        'administrative' => ['ar' => 'موضوع إداري', 'en' => 'Administrative'],
        'emerging' => ['ar' => 'موضوع طارئ', 'en' => 'Emerging'],
        'appeal' => ['ar' => 'تظلم', 'en' => 'Appeal'],
    ];

    /** @var array<string, array{ar: string, en: string}> */
    private const ITEM_STATES = [
        'presented' => ['ar' => 'معروض', 'en' => 'Presented'],
        'discussion' => ['ar' => 'قيد المناقشة', 'en' => 'In discussion'],
        'voting' => ['ar' => 'قيد التصويت', 'en' => 'Voting'],
        'deciding' => ['ar' => 'قيد إثبات النتيجة', 'en' => 'Deciding'],
        'complete' => ['ar' => 'مكتمل', 'en' => 'Complete'],
    ];

    /** Appendix 22's اختصاص اللجنة, which is Appendix 24's "نوع القرار المطلوب". */
    private const INSTRUMENTS = [
        'decision' => ['ar' => 'قرار', 'en' => 'Decision'],
        'recommendation' => ['ar' => 'توصية', 'en' => 'Recommendation'],
        'opinion' => ['ar' => 'رأي', 'en' => 'Opinion'],
        'study_only' => ['ar' => 'دراسة فقط', 'en' => 'Study only'],
    ];

    /** Appendix 24's own two levels. */
    private const PRIORITIES = [
        'high' => ['ar' => 'أولوية عالية', 'en' => 'High priority'],
        'normal' => ['ar' => 'أولوية عادية', 'en' => 'Normal priority'],
    ];

    protected function dateColumn(): string
    {
        return 'meetings.scheduled_at';
    }

    protected function searchColumns(): array
    {
        return ['meeting_requests.subject'];
    }

    protected function baseQuery(): Builder
    {
        // Joined rather than filtered through the relation so the sitting's own
        // date can be both the register's date filter and its ordering key.
        return MeetingRequest::query()
            ->join('meetings', 'meetings.id', '=', 'meeting_requests.meeting_id')
            ->select('meeting_requests.*')
            ->with([
                'meeting:id,meeting_number,scheduled_at',
                'request:id,reference_number,title,status_id,request_type_id,created_by_user_id',
                'appeal:id,appellant_user_id,original_request_id',
                'appeal.originalRequest:id,reference_number,title',
                'decision:id,meeting_request_id,outcome',
            ]);
    }

    protected function applyOrdering(Builder $query): Builder
    {
        return $query
            ->orderByDesc('meetings.scheduled_at')
            ->orderBy('meeting_requests.agenda_order');
    }

    /**
     * Overridden so Appendix 24's derived fields are computed once for the
     * whole page rather than per row — the same batching discipline Stage 81
     * established for the time cards.
     */
    public function rows(Collection $models, string $locale): array
    {
        $profiles = $this->ordering->profiles($models);

        return $models
            ->map(fn (Model $model) => $this->rowWithProfile($model, $locale, $profiles[$model->id]['fields'] ?? []))
            ->values()
            ->all();
    }

    protected function row(Model $model, string $locale): array
    {
        return $this->rowWithProfile($model, $locale, []);
    }

    /** @param array<string, mixed> $fields */
    private function rowWithProfile(Model $model, string $locale, array $fields): array
    {
        $previous = $fields['previous_meeting'] ?? null;
        $legal = $fields['legal_opinion'] ?? null;

        return [
            'meeting_number' => $model->meeting?->meeting_number,
            'meeting_date' => $this->date($model->meeting?->scheduled_at),
            'agenda_order' => $model->agenda_order,
            'item_type' => $this->label(self::ITEM_TYPES, $model->item_type, $locale),
            'reference_number' => $fields['reference_number']
                ?? $model->request?->reference_number
                ?? $model->appeal?->originalRequest?->reference_number,
            'employee_name' => $fields['employee_name'] ?? null,
            'request_type' => $this->pair($fields['request_type'] ?? null, $locale),
            'subject' => $model->request?->title
                ?? $model->subject
                ?? $model->appeal?->originalRequest?->title,
            'readiness_status' => $this->pair($fields['readiness_status'] ?? null, $locale),
            'legal_opinion' => $legal === null ? null : $this->legalVerdict($legal, $locale),
            'required_instrument' => $this->label(self::INSTRUMENTS, $fields['required_instrument'] ?? null, $locale),
            'expected_approving_body' => $fields['expected_approving_body'] ?? null,
            'priority' => $this->label(self::PRIORITIES, $fields['priority_level'] ?? $model->priority, $locale),
            'previously_presented' => $this->yesNo((bool) ($fields['previously_presented'] ?? false), $locale),
            'previous_meeting' => $previous['meeting_number'] ?? null,
            'estimated_minutes' => $model->estimated_minutes,
            'item_state' => $this->label(self::ITEM_STATES, $model->item_state, $locale),
            'study_sequence' => $this->yesNo($model->study_sequence_completed_at !== null, $locale),
            'outcome' => $model->decision?->outcome,
        ];
    }

    /** @param array<string, array{ar: string, en: string}> $map */
    private function label(array $map, ?string $code, string $locale): ?string
    {
        if ($code === null || ! isset($map[$code])) {
            return $code;
        }

        return $map[$code][$locale] ?? $map[$code]['ar'];
    }

    /** @param array{name_ar?: string|null, name_en?: string|null, code?: string, ar?: string, en?: string}|null $pair */
    private function pair(?array $pair, string $locale): ?string
    {
        if ($pair === null) {
            return null;
        }

        $preferred = $locale === 'ar' ? ($pair['name_ar'] ?? null) : ($pair['name_en'] ?? null);
        $fallback = $locale === 'ar' ? ($pair['name_en'] ?? null) : ($pair['name_ar'] ?? null);

        return ($preferred ?: $fallback) ?: null;
    }

    /** @param array{verdict: string|null, note: string|null, legal_deadline: string|null} $legal */
    private function legalVerdict(array $legal, string $locale): ?string
    {
        $verdicts = [
            'sound_ready' => ['ar' => 'سليم قانونياً وجاهز للعرض', 'en' => 'Legally sound, ready'],
            'needs_document' => ['ar' => 'يحتاج إلى استكمال مستند', 'en' => 'Needs a document'],
            'needs_clarification' => ['ar' => 'يحتاج إلى إيضاح', 'en' => 'Needs clarification'],
            'jurisdiction_note' => ['ar' => 'ملاحظة بشأن الاختصاص', 'en' => 'Jurisdiction note'],
            'present_with_note' => ['ar' => 'يعرض مع بيان المسألة القانونية', 'en' => 'Present with the issue stated'],
        ];

        return $this->label($verdicts, $legal['verdict'] ?? null, $locale);
    }

    private function yesNo(bool $value, string $locale): string
    {
        if ($locale === 'ar') {
            return $value ? 'نعم' : 'لا';
        }

        return $value ? 'Yes' : 'No';
    }

    /**
     * Membership gate — see Register::scopeToActor().
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function scopeToActor(Builder $query, User $actor): Builder
    {
        return $query->whereHas('meeting', fn (Builder $m) => app(MeetingVisibility::class)->apply($m, $actor));
    }
}
