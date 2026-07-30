<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\IndexMeetingRequest;
use App\Http\Requests\Meeting\StoreMeetingRequest;
use App\Http\Requests\Meeting\UpdateMeetingRequest;
use App\Http\Requests\MeetingAgenda\ReorderMeetingAgendaRequest;
use App\Http\Requests\MeetingAgenda\StoreMeetingAgendaRequest;
use App\Http\Requests\MeetingAttendee\StoreMeetingAttendeeRequest;
use App\Http\Requests\MeetingAttendee\UpdateMeetingAttendeeRequest;
use App\Http\Resources\MeetingAttendeeResource;
use App\Http\Resources\MeetingResource;
use App\Http\Resources\MeetingTransactionResource;
use App\Models\Committee;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingTransaction;
use Illuminate\Http\JsonResponse;
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
    public function store(StoreMeetingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $meeting = DB::transaction(function () use ($data, $request) {
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

            return $meeting;
        });

        return (new MeetingResource($this->loadDetail($meeting)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Meeting $meeting): MeetingResource
    {
        return new MeetingResource($this->loadDetail($meeting));
    }

    public function update(UpdateMeetingRequest $request, Meeting $meeting): MeetingResource
    {
        $meeting->update($request->validated());

        return new MeetingResource($this->loadDetail($meeting));
    }

    /**
     * Delete a meeting. No "held meetings" style guard is needed here the way
     * committees/departments have one — nothing yet references a meeting from
     * outside this feature (Stage 21 will add decisions tied to agenda items,
     * at which point this may need the same preserve-don't-erase treatment).
     */
    public function destroy(Meeting $meeting): JsonResponse
    {
        $meeting->delete();

        return response()->json(null, 204);
    }

    public function addAgendaItem(StoreMeetingAgendaRequest $request, Meeting $meeting): JsonResponse
    {
        $nextOrder = ($meeting->agendaItems()->max('agenda_order') ?? 0) + 1;

        $item = $meeting->agendaItems()->create([
            'transaction_id' => $request->validated('transaction_id'),
            'agenda_order' => $nextOrder,
        ]);

        return (new MeetingTransactionResource($item->load([
            'transaction:id,reference_number,title,status_id',
            'transaction.status:id,code,name_ar,name_en,color',
        ])))->response()->setStatusCode(201);
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
            ])->get(),
        );
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

    public function markAttendance(UpdateMeetingAttendeeRequest $request, Meeting $meeting, MeetingAttendee $attendee): MeetingAttendeeResource
    {
        abort_unless($attendee->meeting_id === $meeting->id, 404);

        $attendee->update($request->validated());

        return new MeetingAttendeeResource($attendee->load('user:id,name'));
    }

    private function loadDetail(Meeting $meeting): Meeting
    {
        return $meeting->load([
            'committee:id,name_ar,name_en',
            'createdBy:id,name',
            'attendees.user:id,name',
            'agendaItems.transaction:id,reference_number,title,status_id',
            'agendaItems.transaction.status:id,code,name_ar,name_en,color',
        ]);
    }
}
