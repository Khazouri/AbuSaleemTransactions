<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\IndexMeetingRequest;
use App\Http\Requests\Meeting\StoreMeetingRequest;
use App\Http\Requests\Meeting\UpdateMeetingRequest;
use App\Http\Requests\MeetingAgenda\ReorderMeetingAgendaRequest;
use App\Http\Requests\MeetingAgenda\StoreMeetingAgendaRequest;
use App\Http\Requests\MeetingAgenda\UpdateMeetingAgendaItemRequest;
use App\Http\Requests\MeetingAttendee\RespondToMeetingInvitationRequest;
use App\Http\Requests\MeetingAttendee\StoreMeetingGuestRequest;
use App\Http\Resources\MeetingRequestResource;
use App\Http\Resources\MeetingResource;
use App\Models\Appeal;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\Setting;
use App\Models\User;
use App\Services\AgendaOrderingService;
use App\Services\ArtifactNumberGenerator;
use App\Services\CommitteeStatusService;
use App\Services\DocumentCompletenessService;
use App\Services\Lifecycle\DocumentConflictService;
use App\Services\Lifecycle\UrgencyRules;
use App\Services\MeetingVisibility;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stage 20 — scheduling meetings and building their agenda/attendance.
 *
 * Everything here rides the `meetings` screen's own permissions (view/add/edit
 * from ScreenRolePermissionSeeder), the same as CommitteeController.
 */
class MeetingController extends Controller
{
    // Membership gate — the list half of the same rule CheckMeetingMembership
    // enforces per row, so a sitting this omits is never one whose detail
    // endpoint would have opened.
    public function index(IndexMeetingRequest $request, MeetingVisibility $visibility): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $meetings = $visibility->apply(Meeting::query(), $request->user(), withGuests: true)
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
     *
     * Only the committee's مقرر may schedule (user decision 2026-10-02):
     * Appendix 45 gives إنشاء الاجتماع to the مقرر, and the date is theirs to
     * propose. That replaces Stage 84's "a committee you sit on, or R08"
     * check — the مقرر is seated on every committee — and R08 no longer
     * schedules at all.
     */
    public function store(
        StoreMeetingRequest $request,
        NotificationDispatcher $notifications,
        ArtifactNumberGenerator $numbers,
    ): JsonResponse {
        $data = $request->validated();
        $actor = $request->user();

        // One holder per seat role (user decisions 2026-10-02/04), seated here
        // as well as at the committee's creation: a committee formed before
        // the rule, or before a role changed hands, still invites today's.
        $committee = Committee::query()->findOrFail($data['committee_id']);
        $committee->seatRoleHolders();
        $rapporteur = $committee->seatRapporteur();
        if ($rapporteur === null) {
            throw ValidationException::withMessages([
                'committee_id' => ['لا يمكن جدولة اجتماع قبل تعيين مقرر نشط في النظام.'],
            ]);
        }
        $this->refuseUnlessRapporteur($rapporteur, $actor);

        // Stage 102 — the committee is its five Art. 10 (أ) seats, and the
        // meeting invites exactly them: no sitting until every seat is held by
        // an active user. The chair and rapporteur of the sitting are whoever
        // holds those seats, never picked per meeting.
        $seats = $committee->activeMembers()->whereNotNull('seat')->get()->keyBy('seat');
        if ($seats->count() < count(CommitteeMember::SEATS)) {
            throw ValidationException::withMessages([
                'committee_id' => ['لا يمكن جدولة اجتماع قبل شغل مقاعد اللجنة الخمسة (الرئيس، العضو القانوني، مدير الموارد البشرية، مندوب الخدمة المدنية، المقرر).'],
            ]);
        }

        $guests = collect($data['guests'] ?? []);
        unset($data['guests']);
        if ($guests->pluck('user_id')->intersect($seats->pluck('user_id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['guests' => ['المدعو الإضافي لا يكون من شاغلي مقاعد اللجنة الخمسة.']]);
        }

        // User decision 2026-10-25 — Settings can answer the date for everyone.
        $autoAccept = Setting::enabled(Setting::MEETING_AUTO_ACCEPT);
        $accepted = ['invitation_status' => 'confirmed', 'responded_at' => now()];

        [$meeting, $invitedUserIds] = DB::transaction(function () use ($data, $request, $numbers, $seats, $guests, $autoAccept, $accepted) {
            $rapporteurId = $seats['rapporteur']->user_id;

            $meeting = Meeting::create([
                ...$data,
                'meeting_type' => 'regular',
                'chairman_user_id' => $seats['chair']->user_id,
                'rapporteur_user_id' => $rapporteurId,
                // Stage 102 — a proposed date, approved only once enough
                // invited members accept it (respond()).
                'status' => $autoAccept ? 'scheduled' : Meeting::STATUS_PENDING_CONFIRMATION,
                // Stage 70 — [D] Appendix 15 numbers the meeting itself
                // (PM-MTG/YEAR/NN), and Appendix 8 refuses to send a محضر for
                // approval without "تطابق رقم الاجتماع". Server-minted rather
                // than typed into the scheduling wizard as it was from Stage
                // 30: a number a human retypes can neither be guaranteed
                // unique nor guaranteed to match.
                'meeting_number' => $numbers->nextMeetingNumber(),
                'created_by_user_id' => $request->user()->id,
            ]);

            // The مقرر proposes the date, so their own answer is already yes.
            $memberUserIds = $seats->pluck('user_id')->values();
            foreach ($memberUserIds as $userId) {
                MeetingAttendee::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => $userId,
                    ...($autoAccept || $userId === $rapporteurId ? $accepted : []),
                ]);
            }
            foreach ($guests as $guest) {
                $meeting->attendees()->create([...$guest, ...($autoAccept ? $accepted : [])]);
            }

            return [$meeting, $memberUserIds->merge($guests->pluck('user_id'))];
        });

        // Stage 23 — the invitation follows the attendee rows just written,
        // not the committee roster, so the two can never disagree.
        $notifications->meetingScheduled($meeting, $invitedUserIds, $request->user());

        return (new MeetingResource($this->loadDetail($meeting)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(HttpRequest $request, Meeting $meeting, MeetingVisibility $visibility): MeetingResource
    {
        $meeting = $this->loadDetail($meeting);

        // A guest answers the date; the files on the agenda are the committee's.
        if (! $visibility->canView($request->user(), $meeting)) {
            $meeting->unsetRelation('agendaItems');
        }

        return new MeetingResource($meeting);
    }

    public function update(UpdateMeetingRequest $request, Meeting $meeting, NotificationDispatcher $notifications): MeetingResource|JsonResponse
    {
        $data = $request->validated();

        // Stage 102 — a new date is a new proposal: every member answers it
        // again, and the meeting is not approved until enough accept — unless
        // Settings' auto-accept answers it for everyone (2026-10-25).
        $dateChanged = isset($data['scheduled_at'])
            && ! Carbon::parse($data['scheduled_at'])->equalTo($meeting->scheduled_at);
        if ($dateChanged) {
            if ($meeting->results_recorded_at !== null || ! in_array($meeting->status, [Meeting::STATUS_PENDING_CONFIRMATION, 'scheduled'], true)) {
                return response()->json(['message' => 'لا يمكن تغيير موعد اجتماع انعقد أو أُغلق أو أُلغي.'], 422);
            }
            $this->refuseUnlessRapporteur($meeting->committee->seatRapporteur(), $request->user());
        }

        DB::transaction(function () use ($meeting, $data, $dateChanged) {
            if ($dateChanged && Setting::enabled(Setting::MEETING_AUTO_ACCEPT)) {
                $meeting->update([...$data, 'status' => 'scheduled']);
                $meeting->attendees()->update(['invitation_status' => 'confirmed', 'responded_at' => now()]);

                return;
            }

            $meeting->update($dateChanged ? [...$data, 'status' => Meeting::STATUS_PENDING_CONFIRMATION] : $data);

            if ($dateChanged) {
                $meeting->attendees()->update(['invitation_status' => 'pending', 'responded_at' => null]);
                $meeting->attendees()->where('user_id', $meeting->rapporteur_user_id)
                    ->update(['invitation_status' => 'confirmed', 'responded_at' => now()]);
            }
        });

        if ($dateChanged) {
            $notifications->meetingScheduled($meeting, $meeting->attendees()->pluck('user_id'), $request->user());
        }

        return new MeetingResource($this->loadDetail($meeting));
    }

    /**
     * Only the system's مقرر sets a meeting's date, first or proposed again
     * (user decision 2026-10-02); the chair keeps the rest of `meetings,edit`.
     */
    private function refuseUnlessRapporteur(?User $rapporteur, User $actor): void
    {
        if ($rapporteur?->id !== $actor->id) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['تحديد موعد الاجتماع من صلاحية المقرر وحده.'],
            ]);
        }
    }

    /**
     * Stage 102 — each invited member answers the proposed date themselves;
     * nobody records it for them. The meeting is approved (`scheduled`) the
     * moment Settings' number of members have accepted (2026-10-16; the
     * مقرر's own yes counts), and answers close then. Until that, a decline
     * leaves it awaiting confirmation, or the مقرر proposes another date (update()).
     */
    public function respond(RespondToMeetingInvitationRequest $request, Meeting $meeting, NotificationDispatcher $notifications): MeetingResource|JsonResponse
    {
        $refusal = DB::transaction(function () use ($request, $meeting) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);

            if ($locked->status !== Meeting::STATUS_PENDING_CONFIRMATION) {
                return 'لا يمكن الرد على الدعوة إلا والاجتماع بانتظار تأكيد موعده.';
            }

            $attendee = $locked->attendees()->where('user_id', $request->user()->id)->first();
            if ($attendee === null) {
                return 'لست من المدعوين إلى هذا الاجتماع.';
            }

            $attendee->update([
                'invitation_status' => $request->validated('response') === 'accept' ? 'confirmed' : 'declined',
                'responded_at' => now(),
            ]);

            // A guest's yes is for the مقرر's information; only the seats set the date.
            if ($locked->attendees()->whereNull('guest_title')->where('invitation_status', 'confirmed')->count() >= Setting::meetingAcceptancesRequired()) {
                $locked->update(['status' => 'scheduled']);
            }

            return null;
        });

        if ($refusal !== null) {
            return response()->json(['message' => $refusal], 422);
        }

        $meeting->refresh();
        $notifications->invitationAnswered($meeting, $request->user(), $request->validated('response'));

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
     * An item is either an `employee_request` riding a request or (Stage 63)
     * an `appeal`. Stage 102 removed Stage 31's standalone `administrative`/
     * `emerging` items: the agenda is the pending list's requests, plus appeals.
     *
     * The one business rule the FormRequest can't express: an appeal may
     * only be nominated once it has passed legal review (Stage 62's own
     * "cannot reach Stage 63 without both records present" done-when, read
     * as a nomination-time gate), and only once per lap
     * (Appeal::awaitsNomination()).
     */
    public function addAgendaItem(
        StoreMeetingAgendaRequest $request,
        Meeting $meeting,
        NotificationDispatcher $notifications,
    ): JsonResponse {
        $validated = $request->validated();
        $subject = null;

        $itemType = $validated['item_type'] ?? 'employee_request';

        // Stage 102 — a request reaches a meeting only from the committee's
        // pending list (the مقرر's approve puts it there), never by picking
        // any file the actor happens to be able to see.
        if ($itemType === 'employee_request'
            && ! app(CommitteeStatusService::class)->candidatesQuery()->whereKey($validated['request_id'])->exists()) {
            throw ValidationException::withMessages([
                'request_id' => ['لا يُدرج في جدول الأعمال إلا طلب من قائمة الطلبات المعلقة للجنة.'],
            ]);
        }

        if ($itemType === 'appeal') {
            $appeal = Appeal::query()->with('status:id,code')->findOrFail($validated['appeal_id']);

            if ($appeal->status?->code !== 'legal_review') {
                throw ValidationException::withMessages([
                    'appeal_id' => ['لا يمكن عرض التظلم على اللجنة إلا بعد اجتياز المراجعة القانونية.'],
                ]);
            }

            if (! $appeal->awaitsNomination()) {
                throw ValidationException::withMessages([
                    'appeal_id' => ['هذا التظلم مدرج بالفعل في جدول أعمال اجتماع.'],
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
            // Reused below for the Art. 101 notice, so the gate and the
            // notice speak about the same loaded row.
            $subject = Request::query()
                ->with('latestLegalReview')
                ->findOrFail($validated['request_id']);

            // R08's Settings checkbox (user decision 2026-10-16) lets the
            // مقرر present a file the legal member has not cleared.
            if (! Setting::enabled(Setting::LEGAL_REVIEW_BYPASS) && ! $subject->latestLegalReview?->permitsAgenda()) {
                throw ValidationException::withMessages([
                    'request_id' => ['لا يمكن إدراج الطلب في جدول الأعمال قبل استكمال المراجعة القانونية بنتيجة تجيز العرض.'],
                ]);
            }

            // Stage 83 — [D] Appendix 30: "**فلا تعرض المعاملة قبل معالجة
            // التعارض**". Here rather than in CommitteeStatusService for the
            // same reason the legal-review gate above is — an item is inserted
            // through this endpoint without `place_on_agenda` ever firing.
            if (app(DocumentConflictService::class)->hasOpenConflict($subject)) {
                throw ValidationException::withMessages([
                    'request_id' => [DocumentConflictService::AGENDA_BLOCK_MESSAGE],
                ]);
            }

            // Stage 85 — [F]'s own footer rule 2, enforced where it is named:
            // "لا يُعرض أي طلب على اللجنة قبل استكمال المستندات المطلوبة".
            //
            // The file's documents were already checked once, at
            // `requirements_check → approve` — but that check is recorded and
            // never re-verified, so completeness that regressed afterwards
            // (a row added to the type's matrix through the Request Types
            // screen, or a file that predates Stage 85's submission rule)
            // reaches the agenda unnoticed. This reads live coverage rather
            // than the frozen gate record, because the record is an
            // attestation and cannot regress while the documents can.
            //
            // Here rather than in CommitteeStatusService for the third time
            // and the same documented reason as the two gates above: an item
            // is inserted through this endpoint without `place_on_agenda`
            // ever firing.
            if (($incomplete = app(DocumentCompletenessService::class)->refusalForRequest($subject)) !== null) {
                throw ValidationException::withMessages(['request_id' => [$incomplete]]);
            }
        }

        // Stage 83 — [D] Appendix 33: "لا تعتبر المعاملة مستعجلة لمجرد طلب
        // صاحبها ذلك" plus "ويثبت سبب الاستعجال في النظام". A declared عالية
        // needs one of the appendix's five grounds AND the recorded مبرر.
        if (($urgency = app(UrgencyRules::class)->refusalReason($validated)) !== null) {
            throw ValidationException::withMessages(['priority' => [$urgency]]);
        }

        $nextOrder = ($meeting->agendaItems()->max('agenda_order') ?? 0) + 1;

        $item = $meeting->agendaItems()->create([
            ...$validated,
            'item_type' => $validated['item_type'] ?? 'employee_request',
            'agenda_order' => $nextOrder,
        ]);

        // Stage 79 — [D] Art. 101's fourth moment (إدراج الطلب بجدول
        // الأعمال). This is the one moment with no status behind it:
        // CommitteeStatusService's `place_on_agenda` (which would set
        // `on_agenda`) has no caller anywhere, because Stage 44 established
        // that an item is inserted through this endpoint without that action
        // ever firing — the same reason Stage 68 put its legal-review gate
        // here rather than on the service. Only a request item has an
        // employee to tell; an administrative or emerging item has none, and
        // an appeal has its own Track J notice.
        if ($item->item_type === 'employee_request' && $item->request_id !== null) {
            $subject = $subject ?? Request::query()->find($item->request_id);

            if ($subject !== null) {
                $notifications->requestNotice($subject, 'placed_on_agenda', [], $request->user()?->id);
            }
        }

        return (new MeetingRequestResource($item->load(self::AGENDA_ITEM_WITH)))
            ->response()->setStatusCode(201);
    }

    /** Stage 31 — priority/time/subject/department only; see the FormRequest for why item_type/request_id/appeal_id stay fixed. */
    public function updateAgendaItem(UpdateMeetingAgendaItemRequest $request, Meeting $meeting, MeetingRequest $agendaItem): MeetingRequestResource
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $validated = $request->validated();

        // Stage 83 — Appendix 33, checked against the values the write would
        // leave behind rather than the payload alone: an update may raise the
        // level without restating the ground, or clear the ground while the
        // level stays عالية, and either would slip past a payload-only check.
        $merged = [
            'priority' => array_key_exists('priority', $validated) ? $validated['priority'] : $agendaItem->priority,
            'priority_reason_code' => array_key_exists('priority_reason_code', $validated) ? $validated['priority_reason_code'] : $agendaItem->priority_reason_code,
            'priority_reason' => array_key_exists('priority_reason', $validated) ? $validated['priority_reason'] : $agendaItem->priority_reason,
        ];

        if (($urgency = app(UrgencyRules::class)->refusalReason($merged)) !== null) {
            throw ValidationException::withMessages(['priority' => [$urgency]]);
        }

        $agendaItem->update($validated);

        return new MeetingRequestResource($agendaItem->load(self::AGENDA_ITEM_WITH));
    }

    public function removeAgendaItem(Meeting $meeting, MeetingRequest $agendaItem): JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $agendaItem->delete();

        return response()->json(null, 204);
    }

    /** Rewrites agenda_order 1..n to match the submitted order — see the FormRequest for the exact-set guard. */
    public function reorderAgenda(ReorderMeetingAgendaRequest $request, Meeting $meeting): AnonymousResourceCollection|JsonResponse
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
     * Stage 82 — [D] Art. 83's ordering and Appendix 24's per-item profile,
     * read-only. The builder screen shows each item's rank and the twelve
     * fields the appendix requires of an agenda ("يجب ألا يكون جدول الأعمال
     * مجرد قائمة أسماء، بل وثيقة إدارة قرار"), plus whether the agenda as it
     * stands already follows the article's own order.
     */
    public function agendaOrdering(Meeting $meeting, AgendaOrderingService $ordering): JsonResponse
    {
        return response()->json(['data' => $ordering->forMeeting($meeting)]);
    }

    /**
     * Stage 82 — rewrite `agenda_order` to Art. 83's computed sequence.
     *
     * Offered, never imposed: the article's fifth rule leaves the ordering to
     * the chair "بما لا يخل بالمساواة وسلامة الإجراءات", so Stage 39's manual
     * reorder stays exactly as it was and this is one more way to arrive at an
     * order. Applying the rule clears any recorded departure justification —
     * it justified a departure that no longer exists.
     */
    public function applyAgendaOrder(Meeting $meeting, AgendaOrderingService $ordering): AnonymousResourceCollection|JsonResponse
    {
        $items = $meeting->agendaItems()->orderBy('agenda_order')->get();
        $sequence = $ordering->orderedIds($items, $ordering->profiles($items));

        DB::transaction(function () use ($meeting, $sequence) {
            foreach ($sequence as $index => $id) {
                MeetingRequest::query()->where('id', $id)->update(['agenda_order' => $index + 1]);
            }

            $meeting->update(['agenda_order_justification' => null]);
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

        // Stage 82 — Appendix 24's two levels replace Stage 31's invented
        // three; `none` is an item whose level has not been declared yet.
        $byPriority = ['high' => 0, 'normal' => 0, 'none' => 0];
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
     * An extra member the مقرر invites beyond the five seats (user decision
     * 2026-10-16). Only while the date is still open: once enough seats have
     * accepted, answers close, and a late guest could never answer.
     */
    public function addGuest(StoreMeetingGuestRequest $request, Meeting $meeting, NotificationDispatcher $notifications): MeetingResource
    {
        $this->refuseGuestChange($meeting, $request->user());

        $userId = $request->validated('user_id');
        if ($meeting->attendees()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => ['هذا الشخص مدعو إلى الاجتماع بالفعل.']]);
        }

        $meeting->attendees()->create($request->validated());
        $notifications->meetingScheduled($meeting, collect([$userId]), $request->user());

        return new MeetingResource($this->loadDetail($meeting));
    }

    public function removeGuest(HttpRequest $request, Meeting $meeting, MeetingAttendee $attendee): MeetingResource
    {
        abort_unless($attendee->meeting_id === $meeting->id && $attendee->guest_title !== null, 404);
        $this->refuseGuestChange($meeting, $request->user());

        $attendee->delete();

        return new MeetingResource($this->loadDetail($meeting));
    }

    private function refuseGuestChange(Meeting $meeting, User $actor): void
    {
        if ($meeting->committee->seatRapporteur()?->id !== $actor->id) {
            throw ValidationException::withMessages(['guests' => ['دعوة الأعضاء الإضافيين من صلاحية المقرر وحده.']]);
        }
        if ($meeting->status !== Meeting::STATUS_PENDING_CONFIRMATION) {
            throw ValidationException::withMessages(['guests' => ['لا تُعدَّل قائمة المدعوين الإضافيين إلا والاجتماع بانتظار تأكيد موعده.']]);
        }
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
            'committee:id,name_ar,name_en',
            'createdBy:id,name',
            'chairman:id,name',
            'rapporteur:id,name',
            'resultsRecordedBy:id,name',
            'attendees.user:id,name',
            'agendaItems.request:id,reference_number,title,status_id,approving_authority,current_stage_id,subject_user_id,request_type_id,decision_grade',
            'agendaItems.request.currentStage:id,code',
            'agendaItems.request.subject:id,name',
            'agendaItems.request.requestType:id,name_ar,name_en,decision_grade_threshold',
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
            'agendaItems.decision.decidedBy:id,name',
        ]);
    }
}
