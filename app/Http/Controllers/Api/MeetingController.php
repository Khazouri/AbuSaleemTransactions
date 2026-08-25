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
use App\Http\Resources\MeetingResource;
use App\Http\Resources\MeetingTransactionResource;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingTransaction;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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
    public function store(StoreMeetingRequest $request, NotificationDispatcher $notifications): JsonResponse
    {
        $data = $request->validated();

        [$meeting, $invitedUserIds] = DB::transaction(function () use ($data, $request) {
            $committee = Committee::query()->findOrFail($data['committee_id']);

            $meeting = Meeting::create([
                ...$data,
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
     * see MeetingTransaction::isResolved().
     */
    public function update(UpdateMeetingRequest $request, Meeting $meeting): MeetingResource|JsonResponse
    {
        $data = $request->validated();

        if (($data['status'] ?? null) === 'completed') {
            $unresolved = $meeting->agendaItems()->with('decision')->get()
                ->reject(fn (MeetingTransaction $item) => $item->isResolved());

            if ($unresolved->isNotEmpty()) {
                return response()->json([
                    'message' => 'لا يمكن إغلاق الاجتماع قبل استكمال جميع بنود جدول الأعمال (تصويت وقرار، أو إنهاء يدوي للبنود الإدارية).',
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
        $hasDecisions = MeetingTransaction::query()
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

    /**
     * Stage 31 — an item is either an `employee_request` riding a transaction
     * (the only kind before this stage) or a standalone `administrative`/
     * `emerging` item; the FormRequest's conditional rules already picked
     * which of transaction_id/subject is present, so this just stores
     * whichever validated shape arrived.
     */
    public function addAgendaItem(StoreMeetingAgendaRequest $request, Meeting $meeting): JsonResponse
    {
        $nextOrder = ($meeting->agendaItems()->max('agenda_order') ?? 0) + 1;

        $item = $meeting->agendaItems()->create([
            ...$request->validated(),
            'item_type' => $request->validated('item_type') ?? 'employee_request',
            'agenda_order' => $nextOrder,
        ]);

        return (new MeetingTransactionResource($item->load([
            'transaction:id,reference_number,title,status_id',
            'transaction.status:id,code,name_ar,name_en,color',
            'department:id,name_ar,name_en',
        ])))->response()->setStatusCode(201);
    }

    /** Stage 31 — priority/time/subject/department only; see the FormRequest for why item_type/transaction_id stay fixed. */
    public function updateAgendaItem(UpdateMeetingAgendaItemRequest $request, Meeting $meeting, MeetingTransaction $agendaItem): MeetingTransactionResource
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $agendaItem->update($request->validated());

        return new MeetingTransactionResource($agendaItem->load([
            'transaction:id,reference_number,title,status_id',
            'transaction.status:id,code,name_ar,name_en,color',
            'department:id,name_ar,name_en',
        ]));
    }

    /**
     * Stage 34 — the live runner advancing (or reopening) one agenda item's
     * state. A resolved item (already has a decision) refuses any further
     * change, mirroring DecisionEligibility's "voting closes once decided"
     * rule. `complete` is refused outright for a request item — that state
     * is only ever reached as a side effect of DecisionController::record(),
     * so a `complete` request item always means a real recorded decision.
     */
    public function updateItemState(UpdateMeetingAgendaItemStateRequest $request, Meeting $meeting, MeetingTransaction $agendaItem): MeetingTransactionResource|JsonResponse
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        if ($agendaItem->decision()->exists()) {
            return response()->json([
                'message' => 'تم إنهاء هذا البند بالفعل، لا يمكن تغيير حالته.',
            ], 422);
        }

        $newState = $request->validated('item_state');

        if ($newState === 'complete' && $agendaItem->item_type === 'employee_request') {
            return response()->json([
                'message' => 'بنود الطلبات تُستكمل تلقائياً عند تسجيل القرار، لا يمكن إنهاؤها يدوياً.',
            ], 422);
        }

        if ($newState !== $agendaItem->item_state) {
            $agendaItem->update(['item_state' => $newState, 'state_changed_at' => now()]);
        }

        return new MeetingTransactionResource($agendaItem->load([
            'transaction:id,reference_number,title,status_id',
            'transaction.status:id,code,name_ar,name_en,color',
            'department:id,name_ar,name_en',
        ]));
    }

    public function removeAgendaItem(Meeting $meeting, MeetingTransaction $agendaItem): JsonResponse
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
                MeetingTransaction::query()->where('id', $id)->update(['agenda_order' => $index + 1]);
            }
        });

        return MeetingTransactionResource::collection(
            $meeting->agendaItems()->with([
                'transaction:id,reference_number,title,status_id',
                'transaction.status:id,code,name_ar,name_en,color',
                'department:id,name_ar,name_en',
            ])->get(),
        );
    }

    /**
     * Stage 31 — totals and a "group similar" view over the agenda, so the
     * builder screen can show a computed total time and cluster items by
     * effective department (the item's own for an admin item, its
     * transaction's for a request — there's no free-text similarity match
     * here, department is the one dimension both item shapes share).
     */
    public function agendaStats(Meeting $meeting): JsonResponse
    {
        $items = $meeting->agendaItems()->with([
            'transaction:id,reference_number,title,department_id',
            'transaction.department:id,name_ar,name_en',
            'department:id,name_ar,name_en',
        ])->get();

        $byPriority = ['high' => 0, 'medium' => 0, 'low' => 0, 'none' => 0];
        $byType = ['employee_request' => 0, 'administrative' => 0, 'emerging' => 0];
        $groups = [];

        foreach ($items as $item) {
            $byPriority[$item->priority ?? 'none']++;
            $byType[$item->item_type]++;

            $department = $item->department ?? $item->transaction?->department;
            $key = $department?->id ?? 0;

            $groups[$key]['department'] ??= $department ? [
                'id' => $department->id,
                'name_ar' => $department->name_ar,
                'name_en' => $department->name_en,
            ] : null;
            $groups[$key]['items'][] = [
                'id' => $item->id,
                'label' => $item->transaction?->title ?? $item->subject,
            ];
        }

        return response()->json(['data' => [
            'total_items' => $items->count(),
            'total_estimated_minutes' => (int) $items->sum('estimated_minutes'),
            'by_priority' => $byPriority,
            'by_type' => $byType,
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
    public function sendInvitations(Meeting $meeting, NotificationDispatcher $notifications, Request $request): MeetingResource
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
            'convenedBy:id,name',
            'attendees.user:id,name',
            'agendaItems.transaction:id,reference_number,title,status_id',
            'agendaItems.transaction.status:id,code,name_ar,name_en,color',
            'agendaItems.department:id,name_ar,name_en',
            'agendaItems.votes.user:id,name',
            'agendaItems.decision.decidedBy:id,name',
            'agendaItems.notes.createdBy:id,name',
        ]);
    }
}
