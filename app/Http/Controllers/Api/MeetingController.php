<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\IndexMeetingRequest;
use App\Http\Requests\Meeting\StoreMeetingRequest;
use App\Http\Requests\Meeting\UpdateMeetingRequest;
use App\Http\Requests\MeetingAgenda\ReorderMeetingAgendaRequest;
use App\Http\Requests\MeetingAgenda\StoreMeetingAgendaRequest;
use App\Http\Requests\MeetingAgenda\UpdateMeetingAgendaItemRequest;
use App\Http\Requests\MeetingAgenda\UpdateMeetingAgendaItemStateRequest;
use App\Http\Requests\MeetingAttendee\StoreMeetingAttendeeRequest;
use App\Http\Requests\MeetingAttendee\UpdateMeetingAttendeeRequest;
use App\Http\Resources\MeetingAttendeeResource;
use App\Http\Resources\MeetingRequestResource;
use App\Http\Resources\MeetingResource;
use App\Models\Appeal;
use App\Models\Attachment;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingMinutes;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStageLog;
use App\Services\ArtifactNumberGenerator;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stage 20 — scheduling meetings and building their agenda/attendance.
 *
 * Everything here rides the `meetings` screen's own permissions (view/add/edit
 * from ScreenRolePermissionSeeder), the same as CommitteeController.
 */
class MeetingController extends Controller
{
    public function index(IndexMeetingRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $meetings = Meeting::query()
            ->with('committee:id,name_ar,name_en')
            ->withCount(['attendees', 'agendaItems'])
            ->when($filters['committee_id'] ?? null, fn ($query, int $committeeId) => $query->where('committee_id', $committeeId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, string $dateFrom) => $query->whereDate('scheduled_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn ($query, string $dateTo) => $query->whereDate('scheduled_at', '<=', $dateTo))
            ->orderByDesc('scheduled_at')
            ->get();

        return MeetingResource::collection($meetings);
    }

    /**
     * Schedule a meeting and invite the committee's current active members.
     *
     * Attendance rows are seeded here rather than computed on the fly, since
     * committee membership can change later and the meeting's attendee list
     * for a given sitting must stay fixed to who was actually invited to it.
     */
    public function store(
        StoreMeetingRequest $request,
        NotificationDispatcher $notifications,
        ArtifactNumberGenerator $numbers,
    ): JsonResponse {
        $data = $request->validated();

        [$meeting, $invitedUserIds] = DB::transaction(function () use ($data, $request, $numbers) {
            $committee = Committee::query()->findOrFail($data['committee_id']);

            $meeting = Meeting::create([
                ...$data,
                // Stage 70 — [D] Appendix 15 numbers the meeting itself
                // (PM-MTG/YEAR/NN), and Appendix 8 refuses to send a محضر for
                // approval without "تطابق رقم الاجتماع". Server-minted rather
                // than typed into the scheduling wizard as it was from Stage
                // 30: a number a human retypes can neither be guaranteed
                // unique nor guaranteed to match.
                'meeting_number' => $numbers->nextMeetingNumber(),
                'created_by_user_id' => $request->user()->id,
            ]);

            $memberUserIds = $committee->activeMembers()->pluck('user_id');
            foreach ($memberUserIds as $userId) {
                MeetingAttendee::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => $userId,
                ]);
            }

            return [$meeting, $memberUserIds];
        });

        // Stage 23 — the invitation follows the attendee rows just written,
        // not the committee roster, so the two can never disagree.
        $notifications->meetingScheduled($meeting, $invitedUserIds, $request->user());

        return (new MeetingResource($this->loadDetail($meeting)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Meeting $meeting): MeetingResource
    {
        return new MeetingResource($this->loadDetail($meeting));
    }

    /**
     * Stage 34 — closing a meeting rides this same generic status update
     * (the pre-existing `MeetingDetailView` status dropdown already PUTs
     * here), so the gate protects that dropdown and the live runner from one
     * code path rather than adding a second "close" action either could
     * still bypass. Blocked (422) while any agenda item is unresolved —
     * see MeetingRequest::isResolved().
     *
     * Stage 36 adds a second, independent gate right after: closing also
     * requires the meeting's minutes to have completed their own
     * generate → review → sign lifecycle. No exemption for an
     * empty/attendee-less meeting — MeetingMinutesController::review()
     * itself resolves that case by auto-approving when there is nothing to
     * sign, so `generate` then `review` is still the required path.
     */
    public function update(UpdateMeetingRequest $request, Meeting $meeting): MeetingResource|JsonResponse
    {
        $data = $request->validated();

        if (($data['status'] ?? null) === 'completed') {
            $unresolved = $meeting->agendaItems()->with('decision')->get()
                ->reject(fn (MeetingRequest $item) => $item->isResolved());

            if ($unresolved->isNotEmpty()) {
                return response()->json([
                    'message' => 'لا يمكن إغلاق الاجتماع قبل استكمال جميع بنود جدول الأعمال (تصويت وقرار، أو إنهاء يدوي للبنود الإدارية).',
                ], 422);
            }

            $minutes = $meeting->meetingMinutes()->first();
            if ($minutes === null || $minutes->status !== MeetingMinutes::STATUS_APPROVED) {
                return response()->json([
                    'message' => 'لا يمكن إغلاق الاجتماع قبل اعتماد محضر الاجتماع (إنشاء، مراجعة، وتوقيع الحضور).',
                ], 422);
            }
        }

        $meeting->update($data);

        return new MeetingResource($this->loadDetail($meeting));
    }

    /**
     * Delete a meeting. Blocked (422) once any of its agenda items has a
     * recorded decision — that history must stay attached to a resolvable
     * meeting, the same "preserve, don't erase" rule CommitteeController's
     * own destroy() applies to a committee that has held meetings.
     */
    public function destroy(Meeting $meeting): JsonResponse
    {
        $hasDecisions = MeetingRequest::query()
            ->where('meeting_id', $meeting->id)
            ->whereHas('decision')
            ->exists();

        if ($hasDecisions) {
            return response()->json([
                'message' => 'لا يمكن حذف اجتماع تم تسجيل قرارات فيه. لا يمكن حذف السجل التاريخي المرتبط به.',
            ], 422);
        }

        $meeting->delete();

        return response()->json(null, 204);
    }

    /** Every agenda-item read that needs enough of a request/appeal to render one row. */
    private const AGENDA_ITEM_WITH = [
        'request:id,reference_number,title,status_id',
        'request.status:id,code,name_ar,name_en,color',
        // Stage 74 — the recording panel pre-selects Art. 90's instrument
        // from Appendix 22's own answer; see MeetingRequestResource.
        // NOTE: no column list. A latestOfMany relation's generated
        // subquery join collides with a restricted select and fails with
        // "ambiguous column name: request_id" — the same gotcha Stage 52
        // hit eager-loading latestStageLog.
        'request.latestLegalReview',
        'appeal:id,appellant_user_id,original_request_id,appeal_status_id',
        'appeal.appellant:id,name',
        'appeal.originalRequest:id,reference_number,title',
        'appeal.status:id,code,name_ar,name_en,color',
        'department:id,name_ar,name_en',
    ];

    /**
     * Stage 31 — an item is either an `employee_request` riding a request
     * (the only kind before this stage), a standalone `administrative`/
     * `emerging` item, or (Stage 63) an `appeal`; the FormRequest's
     * conditional rules already picked which of request_id/appeal_id/subject
     * is present, so this just stores whichever validated shape arrived.
     *
     * The one business rule the FormRequest can't express: an appeal may
     * only be nominated once it has passed legal review (Stage 62's own
     * "cannot reach Stage 63 without both records present" done-when, read
     * as a nomination-time gate).
     */
    public function addAgendaItem(StoreMeetingAgendaRequest $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validated();

        $itemType = $validated['item_type'] ?? 'employee_request';

        if ($itemType === 'appeal') {
            $appeal = Appeal::query()->with('status:id,code')->findOrFail($validated['appeal_id']);

            if ($appeal->status?->code !== 'legal_review') {
                throw ValidationException::withMessages([
                    'appeal_id' => ['لا يمكن عرض التظلم على اللجنة إلا بعد اجتياز المراجعة القانونية.'],
                ]);
            }
        }

        // Stage 68 — [D] Art. 21 / Appendix 2's file-readiness rule ("مراجعًا
        // قانونيًا") and Appendix 7's own checklist question ("هل تمت المراجعة
        // القانونية المطلوبة؟"). Art. 24 lists المراجعة أو الرأي القانوني as
        // component 7 of the ملف العرض, so a file with no completed review is
        // simply not presentable.
        //
        // The gate lives here rather than in CommitteeStatusService's
        // place_on_agenda because Stage 44 established that an item can be
        // inserted without that action ever firing — gating the service alone
        // would be trivially bypassable through this very endpoint.
        //
        // Two of Art. 21's five verdicts permit insertion: سليم قانونيًا, and
        // مسألة قانونية تستوجب العرض مع بيانها (whose whole point is that the
        // matter IS presented, with the issue stated). See
        // RequestLegalReview::PERMITTING_VERDICTS.
        if ($itemType === 'employee_request') {
            $subject = Request::query()
                ->with('latestLegalReview')
                ->findOrFail($validated['request_id']);

            if (! $subject->latestLegalReview?->permitsAgenda()) {
                throw ValidationException::withMessages([
                    'request_id' => ['لا يمكن إدراج الطلب في جدول الأعمال قبل استكمال المراجعة القانونية بنتيجة تجيز العرض.'],
                ]);
            }
        }

        $nextOrder = ($meeting->agendaItems()->max('agenda_order') ?? 0) + 1;

        $item = $meeting->agendaItems()->create([
            ...$validated,
            'item_type' => $validated['item_type'] ?? 'employee_request',
            'agenda_order' => $nextOrder,
        ]);

        return (new MeetingRequestResource($item->load(self::AGENDA_ITEM_WITH)))
            ->response()->setStatusCode(201);
    }

    /** Stage 31 — priority/time/subject/department only; see the FormRequest for why item_type/request_id/appeal_id stay fixed. */
    public function updateAgendaItem(UpdateMeetingAgendaItemRequest $request, Meeting $meeting, MeetingRequest $agendaItem): MeetingRequestResource
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $agendaItem->update($request->validated());

        return new MeetingRequestResource($agendaItem->load(self::AGENDA_ITEM_WITH));
    }

    /**
     * Stage 34 — the live runner advancing (or reopening) one agenda item's
     * state. A resolved item (already has a decision) refuses any further
     * change, mirroring DecisionEligibility's "voting closes once decided"
     * rule. `complete` is refused outright for a request or appeal item —
     * that state is only ever reached as a side effect of
     * DecisionController::record()/recordAppealDecision(), so a `complete`
     * item of either kind always means a real recorded decision.
     */
    public function updateItemState(UpdateMeetingAgendaItemStateRequest $request, Meeting $meeting, MeetingRequest $agendaItem): MeetingRequestResource|JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->decision()->exists()) {
            return response()->json([
                'message' => 'تم إنهاء هذا البند بالفعل، لا يمكن تغيير حالته.',
            ], 422);
        }

        $newState = $request->validated('item_state');

        if ($newState === 'complete' && in_array($agendaItem->item_type, ['employee_request', 'appeal'], true)) {
            return response()->json([
                'message' => 'بنود الطلبات والتظلمات تُستكمل تلقائياً عند تسجيل القرار، لا يمكن إنهاؤها يدوياً.',
            ], 422);
        }

        if ($newState !== $agendaItem->item_state) {
            $agendaItem->update(['item_state' => $newState, 'state_changed_at' => now()]);
        }

        return new MeetingRequestResource($agendaItem->load(self::AGENDA_ITEM_WITH));
    }

    /**
     * Stage 44 — the live runner's quick-info panel: [C] §6's six tabs
     * (ملخص الطلب | بيانات الموظف | الدراسة | المرفقات | الطلبات السابقة |
     * مالحظات اللجنة — the sixth, committee notes, is already served by the
     * existing discussion feed, so this endpoint covers the other five).
     * Deliberately not gated by RequestVisibility (the direct-workspace
     * gate `AttachmentController`/`RequestController` use) — a committee
     * member reading an item during a live meeting isn't necessarily the
     * request's creator or its current actionable assignee (only the R03
     * head's decide-action role passes that gate; R04 members would 404).
     * `meeting_live,view` is the right gate here, same as the discussion
     * feed and the notes list already use.
     */
    public function agendaItemContext(Meeting $meeting, MeetingRequest $agendaItem): JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->item_type !== 'employee_request') {
            return response()->json([
                'message' => 'لا تتوفر بيانات طلب لبند غير مرتبط بطلب.',
            ], 422);
        }

        $agendaItem->loadMissing([
            'request.department:id,name_ar,name_en',
            'request.requestType:id,name_ar,name_en',
            'request.status:id,code,name_ar,name_en,color',
            'request.createdBy:id,name,email,phone,department_id,manager_id',
            'request.createdBy.department:id,name_ar,name_en',
            'request.createdBy.manager:id,name',
            'request.attachments',
        ]);

        $requestRecord = $agendaItem->request;
        $creator = $requestRecord->createdBy;

        // "الدراسة" — [C]'s study stage has no free-standing result field to
        // read; the honest equivalent is the actual stage-log entries
        // recorded while this request sat at the `observations` stage.
        $study = RequestStageLog::query()
            ->where('request_id', $requestRecord->id)
            ->where(function ($query) {
                $query->whereHas('fromStage', fn ($stage) => $stage->where('code', 'observations'))
                    ->orWhereHas('toStage', fn ($stage) => $stage->where('code', 'observations'));
            })
            ->with('actedBy:id,name')
            ->orderBy('acted_at')
            ->get();

        $previousRequests = Request::query()
            ->where('created_by_user_id', $requestRecord->created_by_user_id)
            ->where('id', '!=', $requestRecord->id)
            ->with('status:id,code,name_ar,name_en,color')
            ->orderByDesc('submitted_at')
            ->limit(10)
            ->get();

        return response()->json(['data' => [
            'request' => [
                'id' => $requestRecord->id,
                'reference_number' => $requestRecord->reference_number,
                'title' => $requestRecord->title,
                'description' => $requestRecord->description,
                'department' => $requestRecord->department ? [
                    'id' => $requestRecord->department->id,
                    'name_ar' => $requestRecord->department->name_ar,
                    'name_en' => $requestRecord->department->name_en,
                ] : null,
                'request_type' => $requestRecord->requestType ? [
                    'id' => $requestRecord->requestType->id,
                    'name_ar' => $requestRecord->requestType->name_ar,
                    'name_en' => $requestRecord->requestType->name_en,
                ] : null,
                'status' => $requestRecord->status ? [
                    'code' => $requestRecord->status->code,
                    'name_ar' => $requestRecord->status->name_ar,
                    'name_en' => $requestRecord->status->name_en,
                    'color' => $requestRecord->status->color,
                ] : null,
                'decision_grade' => $requestRecord->decision_grade,
                'submitted_at' => $requestRecord->submitted_at?->toIso8601String(),
                'due_date' => $requestRecord->due_date?->toDateString(),
            ],
            'employee' => $creator ? [
                'id' => $creator->id,
                'name' => $creator->name,
                'email' => $creator->email,
                'phone' => $creator->phone,
                'department' => $creator->department ? [
                    'id' => $creator->department->id,
                    'name_ar' => $creator->department->name_ar,
                    'name_en' => $creator->department->name_en,
                ] : null,
                'manager' => $creator->manager ? [
                    'id' => $creator->manager->id,
                    'name' => $creator->manager->name,
                ] : null,
            ] : null,
            'study' => $study->map(fn (RequestStageLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'comment' => $log->comment,
                'acted_by' => $log->actedBy ? ['id' => $log->actedBy->id, 'name' => $log->actedBy->name] : null,
                'acted_at' => $log->acted_at?->toIso8601String(),
            ])->values(),
            'attachments' => $requestRecord->attachments->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'label' => $attachment->label,
                'preview_url' => route('meetings.agenda-item.attachment', [
                    'meeting' => $meeting->id,
                    'agendaItem' => $agendaItem->id,
                    'attachment' => $attachment->id,
                ]),
            ])->values(),
            'previous_requests' => $previousRequests->map(fn ($previous) => [
                'id' => $previous->id,
                'reference_number' => $previous->reference_number,
                'title' => $previous->title,
                'status' => $previous->status ? [
                    'code' => $previous->status->code,
                    'name_ar' => $previous->status->name_ar,
                    'name_en' => $previous->status->name_en,
                    'color' => $previous->status->color,
                ] : null,
                'submitted_at' => $previous->submitted_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    /**
     * Stage 44 — streams a request's attachment for the live-runner context
     * panel above. A sibling of AttachmentController::preview, deliberately
     * not reusing it: that route is gated by RequestVisibility (creator or
     * current actionable assignee only), which a non-head committee member
     * reading an agenda item during a meeting would fail. Trust here comes
     * from the meeting_live screen grant plus the attachment/agenda-item/
     * meeting chain actually matching, not from RequestVisibility.
     */
    public function agendaItemAttachment(Meeting $meeting, MeetingRequest $agendaItem, Attachment $attachment): StreamedResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);
        abort_unless($agendaItem->item_type === 'employee_request', 404);
        abort_unless($attachment->request_id === $agendaItem->request_id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
            'inline',
        );
    }

    public function removeAgendaItem(Meeting $meeting, MeetingRequest $agendaItem): JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $agendaItem->delete();

        return response()->json(null, 204);
    }

    /** Rewrites agenda_order 1..n to match the submitted order — see the FormRequest for the exact-set guard. */
    public function reorderAgenda(ReorderMeetingAgendaRequest $request, Meeting $meeting): AnonymousResourceCollection
    {
        DB::transaction(function () use ($request) {
            foreach ($request->validated('order') as $index => $id) {
                MeetingRequest::query()->where('id', $id)->update(['agenda_order' => $index + 1]);
            }
        });

        return MeetingRequestResource::collection(
            $meeting->agendaItems()->with(self::AGENDA_ITEM_WITH)->get(),
        );
    }

    /**
     * Stage 31 — totals and a "group similar" view over the agenda, so the
     * builder screen can show a computed total time and cluster items either
     * by effective department (the item's own for an admin item, its
     * request's for a request) or, Stage 40, by the request's type — [C]
     * §4's own example groups by request type ("جميع طلبات الترقية...
     * في مجموعة واحدة"), not department. An admin/emerging/appeal item has
     * no request and therefore no type, so under request_type grouping it
     * always lands in the null-type bucket, mirroring how a department-less
     * item already lands in the null-department bucket today.
     */
    public function agendaStats(HttpRequest $request, Meeting $meeting): JsonResponse
    {
        $groupBy = $request->query('group_by', 'department');
        abort_unless(in_array($groupBy, ['department', 'request_type'], true), 422);

        $items = $meeting->agendaItems()->with([
            'request:id,reference_number,title,department_id,request_type_id',
            'request.department:id,name_ar,name_en',
            'request.requestType:id,name_ar,name_en',
            'department:id,name_ar,name_en',
            'appeal:id,original_request_id',
            'appeal.originalRequest:id,title',
        ])->get();

        $byPriority = ['high' => 0, 'medium' => 0, 'low' => 0, 'none' => 0];
        // Stage 63 — a fourth bucket for appeal items, alongside Stage 31's
        // original three.
        $byType = ['employee_request' => 0, 'administrative' => 0, 'emerging' => 0, 'appeal' => 0];
        $groups = [];

        foreach ($items as $item) {
            $byPriority[$item->priority ?? 'none']++;
            $byType[$item->item_type]++;

            if ($groupBy === 'request_type') {
                $type = $item->request?->requestType;
                $key = $type?->id ?? 0;
                $groups[$key]['type'] ??= $type ? [
                    'id' => $type->id,
                    'name_ar' => $type->name_ar,
                    'name_en' => $type->name_en,
                ] : null;
            } else {
                $department = $item->department ?? $item->request?->department;
                $key = $department?->id ?? 0;
                $groups[$key]['department'] ??= $department ? [
                    'id' => $department->id,
                    'name_ar' => $department->name_ar,
                    'name_en' => $department->name_en,
                ] : null;
            }

            $groups[$key]['items'][] = [
                'id' => $item->id,
                // Stage 63 — an appeal item has neither a title-bearing
                // request nor a subject; fall back to the original request's
                // own title rather than rendering a blank label.
                'label' => $item->request?->title ?? $item->subject ?? $item->appeal?->originalRequest?->title ?? ('#'.$item->id),
            ];
        }

        return response()->json(['data' => [
            'total_items' => $items->count(),
            'total_estimated_minutes' => (int) $items->sum('estimated_minutes'),
            'by_priority' => $byPriority,
            'by_type' => $byType,
            'group_by' => $groupBy,
            'groups' => array_values($groups),
        ]]);
    }

    /** Stage 31 — department picker for the agenda builder's admin-item form; `departments` itself grants no role access. */
    public function departmentOptions(): JsonResponse
    {
        return response()->json([
            'data' => Department::query()
                ->where('is_active', true)
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en']),
        ]);
    }

    /**
     * Stage 63 — the appeal picker for the agenda builder's `appeal` item
     * form. A narrow, purpose-built lookup, same "picker, not the full
     * resource's own visibility rule" precedent as departmentOptions()/
     * CommitteeController::userOptions() — AppealController::index() scopes
     * a non-R08/non-`appeals,edit` actor to their own filings, which the
     * `meeting_agenda,edit` roles (R03/R09) don't hold. Excludes an appeal
     * already nominated on some agenda (Appeal::committeeAgendaItem) — once
     * nominated it stays nominated until decided, never re-offered here.
     */
    public function appealOptions(): JsonResponse
    {
        return response()->json([
            'data' => Appeal::query()
                ->whereHas('status', fn ($query) => $query->where('code', 'legal_review'))
                ->whereDoesntHave('committeeAgendaItem')
                ->with(['appellant:id,name', 'originalRequest:id,reference_number,title'])
                ->latest()
                ->get()
                ->map(fn (Appeal $appeal) => [
                    'id' => $appeal->id,
                    'appellant' => $appeal->appellant ? [
                        'id' => $appeal->appellant->id,
                        'name' => $appeal->appellant->name,
                    ] : null,
                    'original_request' => $appeal->originalRequest ? [
                        'id' => $appeal->originalRequest->id,
                        'reference_number' => $appeal->originalRequest->reference_number,
                        'title' => $appeal->originalRequest->title,
                    ] : null,
                ])
                ->values(),
        ]);
    }

    /** Invites one extra attendee beyond the committee members auto-invited at creation. */
    public function addAttendee(StoreMeetingAttendeeRequest $request, Meeting $meeting): JsonResponse
    {
        $attendee = $meeting->attendees()->create($request->validated());

        return (new MeetingAttendeeResource($attendee->load('user:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function removeAttendee(Meeting $meeting, MeetingAttendee $attendee): JsonResponse
    {
        abort_unless($attendee->meeting_id === $meeting->id, 404);

        $attendee->delete();

        return response()->json(null, 204);
    }

    /**
     * Updates attendance (`attended`) and/or an attendee's RSVP
     * (`invitation_status`) — two independent fields on the same row, so
     * setting the RSVP stamps `responded_at` regardless of whether
     * `attended` was also sent.
     */
    public function markAttendance(UpdateMeetingAttendeeRequest $request, Meeting $meeting, MeetingAttendee $attendee): MeetingAttendeeResource
    {
        abort_unless($attendee->meeting_id === $meeting->id, 404);

        $data = $request->validated();
        if (array_key_exists('invitation_status', $data)) {
            $data['responded_at'] = now();
        }

        $attendee->update($data);

        return new MeetingAttendeeResource($attendee->load('user:id,name'));
    }

    /**
     * Stage 30 — (re)sends the meeting invitation to its current attendees.
     * Decoupled from store() so the scheduling wizard's final step, and a
     * later "resend" from the meeting detail screen, both go through the
     * same explicit action rather than only ever firing once at creation.
     */
    public function sendInvitations(Meeting $meeting, NotificationDispatcher $notifications, HttpRequest $request): MeetingResource
    {
        $attendeeUserIds = $meeting->attendees()->pluck('user_id');

        $notifications->meetingScheduled($meeting, $attendeeUserIds, $request->user());

        return new MeetingResource($this->loadDetail($meeting));
    }

    private function loadDetail(Meeting $meeting): Meeting
    {
        return $meeting->load([
            // Stage 48 — rapporteur_votes travels with the committee so the
            // panel can tell a non-voting rapporteur apart from a voting one.
            'committee:id,name_ar,name_en,rapporteur_votes',
            'createdBy:id,name',
            'chairman:id,name',
            'rapporteur:id,name',
            'convenedBy:id,name',
            'meetingMinutes',
            'attendees.user:id,name',
            'agendaItems.request:id,reference_number,title,status_id',
            'agendaItems.request.status:id,code,name_ar,name_en,color',
            // Stage 74 — see AGENDA_ITEM_WITH (including why there is
            // no column list).
            'agendaItems.request.latestLegalReview',
            // Stage 63 — the appeal riding an `appeal` item, mirroring the
            // request block above.
            'agendaItems.appeal:id,appellant_user_id,original_request_id,appeal_status_id',
            'agendaItems.appeal.appellant:id,name',
            'agendaItems.appeal.originalRequest:id,reference_number,title',
            'agendaItems.appeal.status:id,code,name_ar,name_en,color',
            'agendaItems.department:id,name_ar,name_en',
            'agendaItems.votes.user:id,name',
            'agendaItems.decision.decidedBy:id,name',
            'agendaItems.decision.template:id,code,name_ar,name_en',
            'agendaItems.notes.createdBy:id,name',
            'agendaItems.conflictDeclarations.user:id,name',
        ]);
    }
}
