<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Decision\ExportDecisionRequest;
use App\Http\Requests\Decision\IndexDecisionRequest;
use App\Http\Resources\DecisionResource;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\User;
use App\Services\MeetingVisibility;
use App\Services\Reports\ReportDocument;
use App\Services\Reports\ReportExporter;
use App\Services\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 25 — the register of every recorded decision (`view`) and the same
 * thing as a file (`export`). Since 2026-10-18 decisions are written only by
 * the paper sitting's results (MeetingResultsController); the in-system votes
 * and the head's recording went with the sitting.
 */
class DecisionController extends Controller
{
    public function __construct(
        private readonly MeetingVisibility $meetingVisibility,
        private readonly RequestVisibility $requestVisibility,
    ) {}

    /**
     * Column headings and outcome labels for the exported register.
     *
     * Server-side strings, deliberately not in the SPA's locale files: the
     * file is rendered here and the browser never sees them.
     */
    private const LABELS = [
        'ar' => [
            'title' => 'سجل القرارات والتوصيات',
            'generated' => 'تاريخ الإصدار',
            'filters' => 'عوامل التصفية',
            'no_filters' => 'بدون تصفية — كل القرارات',
            'outcome' => 'النتيجة',
            'committee' => 'اللجنة',
            'date_from' => 'من تاريخ',
            'date_to' => 'إلى تاريخ',
            'search' => 'بحث',
            'total' => 'إجمالي القرارات',
            'outcomes' => [
                'approve' => 'تمت الموافقة',
                'reject' => 'تم الرفض',
                'defer' => 'تم التأجيل',
                'conditional_approval' => 'اعتماد مشروط',
                'legal_opinion' => 'طلب رأي قانوني',
                'refer_other_body' => 'إحالة لجهة أخرى',
                'no_jurisdiction' => 'عدم اختصاص',
                // Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary.
                'appeal_accept' => 'قبول التظلم',
                'appeal_partial_accept' => 'قبول جزئي',
                'appeal_reject' => 'رفض التظلم',
                'appeal_refer' => 'إحالة التظلم',
                'appeal_redo' => 'إعادة الإجراءات',
            ],
            // Stage 74 — Art. 90's three instruments and Appendix 28's seven
            // refusal reasons, transcribed from the appendix's own wording.
            'instruments' => [
                'decision' => 'قرار',
                'recommendation' => 'توصية',
                'opinion' => 'رأي',
            ],
            'refusal_reasons' => [
                'period_condition_unmet' => 'عدم استيفاء شرط المدة',
                'position_unavailable' => 'عدم توفر الوظيفة المطلوبة',
                'legal_text_inapplicable' => 'عدم انطباق النص القانوني',
                'essential_condition_missing' => 'نقص شرط أساسي لا يمكن استكماله',
                'outside_jurisdiction' => 'عدم اختصاص اللجنة',
                'document_invalid' => 'عدم صحة المستند المؤثر',
                'legal_impediment' => 'وجود مانع قانوني',
                'other' => 'سبب آخر مبين في الملف',
            ],
            'columns' => [
                // Stage 70 — Appendix 12's سجل القرارات opens with
                // الرقم المتسلسل للقرار · رقم الاجتماع · رقم المعاملة.
                'رقم القرار', 'رقم الاجتماع',
                'الرقم المرجعي', 'الموضوع', 'اللجنة', 'الاجتماع', 'تاريخ الاجتماع',
                // Stage 74 — Art. 90's instrument, Appendix 27's منطوق and
                // Appendix 28's reason sit beside the outcome they qualify.
                'النتيجة', 'نوع الإصدار', 'المنطوق', 'سبب عدم الموافقة',
                'موافق', 'رافض', 'مؤجل', 'مشروط', 'رأي قانوني', 'إحالة', 'عدم اختصاص',
                'قبول التظلم', 'قبول جزئي', 'رفض التظلم', 'إحالة التظلم', 'إعادة الإجراءات', 'ممتنع',
                'صاحب القرار', 'تاريخ القرار', 'الملاحظات',
            ],
            'none' => '—',
        ],
        'en' => [
            'title' => 'Decisions & Recommendations Register',
            'generated' => 'Generated',
            'filters' => 'Filters',
            'no_filters' => 'No filters — all decisions',
            'outcome' => 'Outcome',
            'committee' => 'Committee',
            'date_from' => 'From',
            'date_to' => 'To',
            'search' => 'Search',
            'total' => 'Total decisions',
            'outcomes' => [
                'approve' => 'Approved',
                'reject' => 'Rejected',
                'defer' => 'Deferred',
                'conditional_approval' => 'Conditionally Approved',
                'legal_opinion' => 'Legal Opinion Requested',
                'refer_other_body' => 'Referred to Another Body',
                'no_jurisdiction' => 'Outside Jurisdiction',
                // Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary.
                'appeal_accept' => 'Appeal Accepted',
                'appeal_partial_accept' => 'Partially Accepted',
                'appeal_reject' => 'Appeal Rejected',
                'appeal_refer' => 'Appeal Referred',
                'appeal_redo' => 'Procedures Redone',
            ],
            // Stage 74 — Art. 90's three instruments and Appendix 28's seven
            // refusal reasons.
            'instruments' => [
                'decision' => 'Decision',
                'recommendation' => 'Recommendation',
                'opinion' => 'Opinion',
            ],
            'refusal_reasons' => [
                'period_condition_unmet' => 'Service-period condition not met',
                'position_unavailable' => 'Required position unavailable',
                'legal_text_inapplicable' => 'Legal text not applicable',
                'essential_condition_missing' => 'Essential condition missing and uncompletable',
                'outside_jurisdiction' => "Outside the committee's jurisdiction",
                'document_invalid' => 'Governing document invalid',
                'legal_impediment' => 'Legal impediment',
                'other' => 'Other reason stated in the file',
            ],
            'columns' => [
                'Decision no.', 'Meeting no.',
                'Reference', 'Subject', 'Committee', 'Meeting', 'Meeting date',
                'Outcome', 'Instrument', 'Operative clause', 'Refusal reason',
                'Approve', 'Reject', 'Defer', 'Conditional', 'Legal opinion', 'Referred', 'No jurisdiction',
                'Appeal accepted', 'Appeal partial', 'Appeal rejected', 'Appeal referred', 'Appeal redo', 'Abstain',
                'Decided by', 'Decided at', 'Comment',
            ],
            'none' => '—',
        ],
    ];

    /**
     * The paper sitting's three توصيات (2026-10-18), as the workflow action
     * each one takes at `receive_from_committee`. Stage 69 — `reject` has its
     * own `reject_by_committee` row, so a reasoned committee non-approval
     * stays distinct from a plain administrative withdrawal.
     */
    public const ACTIONS = [
        'approve' => 'approve',
        'reject' => 'reject_by_committee',
        'defer' => 'defer',
    ];

    /**
     * Stage 25 — the register: every recorded decision, filterable, away from
     * the meeting that produced it.
     */
    public function index(IndexDecisionRequest $request): AnonymousResourceCollection
    {
        $rows = $this->registerQuery($request->filters(), $request->user())
            ->paginate($request->validated('per_page') ?? 25)
            ->withQueryString();

        return DecisionResource::collection($rows);
    }

    /** Lookups travel separately so the filter bar works before any rows do. */
    public function filters(): JsonResponse
    {
        return response()->json([
            'data' => [
                'committees' => Committee::query()
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar', 'name_en']),
                'outcomes' => IndexDecisionRequest::OUTCOMES,
                'formats' => ReportExporter::FORMATS,
            ],
        ]);
    }

    /**
     * Stage 25 — the register as .xlsx or PDF, reusing Stage 24's writers. A
     * new document, not a new writer: that split is exactly what ReportDocument
     * and ReportExporter were built for.
     */
    public function export(ExportDecisionRequest $request, ReportExporter $exporter): Response
    {
        $filters = $request->filters();
        $locale = $request->exportLocale();
        $labels = self::LABELS[$locale];

        // Not paginated: an export that silently stopped at page one would be
        // worse than no export. The filters bound the size.
        $rows = $this->registerQuery($filters, $request->user())->get();

        $document = new ReportDocument(
            slug: 'decisions-register',
            title: $labels['title'],
            columns: $labels['columns'],
            rows: $rows->map(fn (Decision $decision) => $this->exportRow($decision, $locale, $labels))->all(),
            meta: $this->metaLines($filters, $labels, $locale),
            summary: $this->summaryPairs($rows, $labels),
            rtl: $locale === 'ar',
        );

        return $exporter->download($document, $request->exportFormat());
    }

    /**
     * The register's one query. Shared by the screen and the export so a
     * forwarded file can never describe a different population than the table
     * it was exported from.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Decision>
     */
    /**
     * Membership gate — the register, scoped in TWO branches.
     *
     * `decisions` was deliberately pulled out of the meetings group as a
     * shared system-wide record, so hiding the screen would contradict that
     * sourced decision. Its ROWS are narrowed instead, and by a union rather
     * than a single rule, because two different people legitimately read this
     * register for two different reasons: a committee member looking at what
     * their own committee decided, and anyone who can already open the
     * underlying request looking at the decision on a file they hold. An
     * employee therefore still finds the decision on their own request, while
     * they stop reading the whole municipality's decisions.
     *
     * Both halves reuse the existing services rather than restating either
     * rule, so this cannot drift from the screens those rules already govern.
     */
    private function registerQuery(array $filters, User $actor): Builder
    {
        $committeeIds = $this->meetingVisibility->visibleCommitteeIds($actor);

        return Decision::query()
            ->when($committeeIds !== null, fn (Builder $query) => $query->where(
                fn (Builder $mine) => $mine
                    ->whereHas(
                        'meetingRequest.meeting',
                        fn (Builder $meeting) => $meeting->whereIn('committee_id', $committeeIds),
                    )
                    ->orWhereHas(
                        'meetingRequest.request',
                        fn (Builder $requestRecord) => $this->requestVisibility->apply($requestRecord, $actor),
                    ),
            ))
            ->with([
                'decidedBy:id,name',
                'meetingRequest:id,meeting_id,request_id,appeal_id',
                'meetingRequest.request:id,reference_number,title',
                // Stage 63 — the appeal riding an `appeal` item, mirroring the
                // request eager-load above.
                'meetingRequest.appeal:id,appellant_user_id,original_request_id,appeal_status_id',
                'meetingRequest.appeal.appellant:id,name',
                'meetingRequest.appeal.originalRequest:id,reference_number,title',
                'meetingRequest.meeting:id,title,scheduled_at,committee_id',
                'meetingRequest.meeting.committee:id,name_ar,name_en',
            ])
            ->when(
                $filters['outcome'] ?? null,
                fn (Builder $query, string $outcome) => $query->where('outcome', $outcome),
            )
            ->when(
                $filters['committee_id'] ?? null,
                fn (Builder $query, $committeeId) => $query->whereHas(
                    'meetingRequest.meeting',
                    fn (Builder $meeting) => $meeting->where('committee_id', $committeeId),
                ),
            )
            ->when(
                $filters['date_from'] ?? null,
                fn (Builder $query, string $from) => $query->whereDate('decided_at', '>=', $from),
            )
            ->when(
                $filters['date_to'] ?? null,
                fn (Builder $query, string $to) => $query->whereDate('decided_at', '<=', $to),
            )
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $term) => $query->where(fn (Builder $matches) => $matches
                    ->whereHas(
                        'meetingRequest.request',
                        fn (Builder $requestRecord) => $requestRecord
                            ->where('reference_number', 'like', "%{$term}%")
                            ->orWhere('title', 'like', "%{$term}%"),
                    )
                    // Stage 63 — an appeal decision's own "reference/subject"
                    // is its original request's, since the appeal itself has
                    // no reference number or title of its own.
                    ->orWhereHas(
                        'meetingRequest.appeal.originalRequest',
                        fn (Builder $requestRecord) => $requestRecord
                            ->where('reference_number', 'like', "%{$term}%")
                            ->orWhere('title', 'like', "%{$term}%"),
                    )),
            )
            ->latest('decided_at');
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<int, string|int|null>
     */
    private function exportRow(Decision $decision, string $locale, array $labels): array
    {
        $agendaItem = $decision->meetingRequest;
        $meeting = $agendaItem?->meeting;
        $committee = $meeting?->committee;
        // Stage 63 — an appeal item's own "reference/subject" is its
        // original request's, since the appeal has no reference number or
        // title of its own.
        $originalRequest = $agendaItem?->request ?? $agendaItem?->appeal?->originalRequest;

        return [
            $decision->decision_number ?? $labels['none'],
            $meeting?->meeting_number ?? $labels['none'],
            $originalRequest?->reference_number ?? $labels['none'],
            $originalRequest?->title ?? $labels['none'],
            $this->localName($committee, $locale, $labels),
            $meeting?->title ?? $labels['none'],
            $meeting?->scheduled_at?->format('Y-m-d') ?? $labels['none'],
            $labels['outcomes'][$decision->outcome] ?? $decision->outcome,
            // Stage 74 — Appendix 12's register is the file a reader takes
            // away, so it carries the instrument and the منطوق rather than
            // only the outcome word, which Art. 90 says is not enough on its
            // own to know what the committee actually issued.
            $labels['instruments'][$decision->instrument] ?? $decision->instrument ?? $labels['none'],
            $decision->decision_operative ?? $labels['none'],
            $labels['refusal_reasons'][$decision->refusal_reason_code] ?? $decision->refusal_reason_code ?? $labels['none'],
            $decision->votes_approve_count,
            $decision->votes_reject_count,
            $decision->votes_defer_count,
            $decision->votes_conditional_approval_count,
            $decision->votes_legal_opinion_count,
            $decision->votes_refer_other_body_count,
            $decision->votes_no_jurisdiction_count,
            $decision->votes_appeal_accept_count,
            $decision->votes_appeal_partial_accept_count,
            $decision->votes_appeal_reject_count,
            $decision->votes_appeal_refer_count,
            $decision->votes_appeal_redo_count,
            $decision->votes_abstain_count,
            $decision->decidedBy?->name ?? $labels['none'],
            $decision->decided_at?->format('Y-m-d H:i') ?? $labels['none'],
            $decision->comment ?? $labels['none'],
        ];
    }

    /**
     * Context lines printed under the title, so a forwarded file still says
     * what it is a register of.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $labels
     * @return array<int, string>
     */
    private function metaLines(array $filters, array $labels, string $locale): array
    {
        $applied = [];

        if ($outcome = $filters['outcome'] ?? null) {
            $applied[] = $labels['outcome'].': '.($labels['outcomes'][$outcome] ?? $outcome);
        }
        if ($id = $filters['committee_id'] ?? null) {
            $applied[] = $labels['committee'].': '.$this->localName(Committee::find($id), $locale, $labels);
        }
        if ($from = $filters['date_from'] ?? null) {
            $applied[] = $labels['date_from'].': '.$from;
        }
        if ($to = $filters['date_to'] ?? null) {
            $applied[] = $labels['date_to'].': '.$to;
        }
        if ($search = $filters['search'] ?? null) {
            $applied[] = $labels['search'].': '.$search;
        }

        return [
            $labels['generated'].': '.now()->format('Y-m-d H:i'),
            $applied === [] ? $labels['no_filters'] : $labels['filters'].' — '.implode(' | ', $applied),
        ];
    }

    /**
     * @param  Collection<int, Decision>  $rows
     * @param  array<string, mixed>  $labels
     * @return array<int, array{label: string, value: string}>
     */
    private function summaryPairs(Collection $rows, array $labels): array
    {
        return [
            ['label' => $labels['total'], 'value' => (string) $rows->count()],
            // Every outcome the register can hold, past sittings' included.
            ...collect(array_keys($labels['outcomes']))
                ->map(fn (string $outcome) => [
                    'label' => $labels['outcomes'][$outcome],
                    'value' => (string) $rows->where('outcome', $outcome)->count(),
                ])
                ->all(),
        ];
    }

    /** @param  array<string, mixed>  $labels */
    private function localName(mixed $model, string $locale, array $labels): string
    {
        if ($model === null) {
            return $labels['none'];
        }

        $preferred = $locale === 'ar' ? $model->name_ar : $model->name_en;
        $fallback = $locale === 'ar' ? $model->name_en : $model->name_ar;

        return $preferred ?: ($fallback ?: $labels['none']);
    }
}
