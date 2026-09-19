<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Committee\StoreCommitteeRequest;
use App\Http\Requests\Committee\UpdateCommitteeRequest;
use App\Http\Requests\CommitteeMember\StoreCommitteeMemberRequest;
use App\Http\Resources\CommitteeMemberResource;
use App\Http\Resources\CommitteeResource;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\User;
use App\Services\MeetingVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Stage 20 CRUD for committees (اللجان) and their membership.
 *
 * Committees ride the `meetings` screen's permissions rather than a screen of
 * their own — the 22/23-screen sheet has no separate "Committees" row, and
 * managing who sits on a committee is a prerequisite of scheduling that
 * committee's meetings, not a distinct capability.
 */
class CommitteeController extends Controller
{
    public function __construct(private readonly MeetingVisibility $visibility) {}

    /**
     * Membership gate — committees the caller sits on.
     *
     * Unscoped this returned every committee WITH its full roster eager-loaded,
     * to anyone holding `meetings,view` — which is seeded '*'. Narrowing it
     * also makes the picker honest: store() has refused to schedule for a
     * committee you do not sit on since Stage 84, so an unscoped list was
     * offering choices the very next endpoint rejects.
     */
    public function index(HttpRequest $request): AnonymousResourceCollection
    {
        $committees = $this->visibility->applyToCommittees(Committee::query(), $request->user())
            ->withCount(['members', 'meetings'])
            ->with(['members.user:id,name'])
            ->orderBy('name_ar')
            ->get();

        return CommitteeResource::collection($committees);
    }

    /**
     * Membership gate — forming a committee seats you on it.
     *
     * Without this the creator immediately loses sight of what they just made:
     * index() now lists only committees you sit on, and MeetingController
     * ::store() has refused to schedule for a committee you do not sit on
     * since Stage 84 — so an unseated creator could produce a committee they
     * could neither find nor convene. Seated as a plain member, not as head:
     * `meetings,add` is held by R02 (المقرر) as well as R03, and making the
     * rapporteur the chair would be a governance claim this action has no
     * business making. addMember() still assigns the chair seat explicitly.
     */
    public function store(StoreCommitteeRequest $request): JsonResponse
    {
        $committee = DB::transaction(function () use ($request) {
            $committee = Committee::create($request->validated());
            $committee->members()->create(['user_id' => $request->user()->id]);

            return $committee;
        });

        return (new CommitteeResource($committee->loadCount(['members', 'meetings'])->load('members.user:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCommitteeRequest $request, Committee $committee): CommitteeResource
    {
        $this->authorizeCommittee($request->user(), $committee);

        $committee->update($request->validated());

        return new CommitteeResource($committee->loadCount(['members', 'meetings'])->load('members.user:id,name'));
    }

    /** Flip is_active, the same soft-disable pattern DepartmentController uses. */
    public function toggleActive(HttpRequest $request, Committee $committee): CommitteeResource
    {
        $this->authorizeCommittee($request->user(), $committee);

        $committee->update(['is_active' => ! $committee->is_active]);

        return new CommitteeResource($committee->loadCount(['members', 'meetings'])->load('members.user:id,name'));
    }

    /**
     * Soft-delete a committee. Blocked while it has held any meetings — that
     * history (agenda, attendance, minutes) must stay attached to a resolvable
     * committee, mirroring DepartmentController::destroy's "empty it first" rule.
     */
    public function destroy(HttpRequest $request, Committee $committee): JsonResponse
    {
        $this->authorizeCommittee($request->user(), $committee);

        if ($committee->meetings()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف لجنة عقدت اجتماعات. لا يمكن حذف السجل التاريخي المرتبط بها.',
            ], 422);
        }

        $committee->delete();

        return response()->json(null, 204);
    }

    /**
     * Add one member. Marking them as head (is_head) demotes any existing
     * head of this committee in the same request — a committee has at
     * most one head at a time, and the alternative (two "the" heads) would be
     * ambiguous everywhere the UI shows one.
     *
     * Stage 45 — filling the `chair` seat (Art. 10's رئيس اللجنة = وكيل ديوان
     * البلدية) is also always the head, so it forces is_head the same way an
     * explicit is_head=true does, rather than leaving the two concepts able
     * to disagree about who chairs the committee. The other 4 named seats
     * carry no such implication — a `seat` is purely an identity slot, and
     * uniqueness per (committee, seat) is enforced by the request's own
     * validation plus a DB-level unique index as a second line of defence.
     */
    public function addMember(StoreCommitteeMemberRequest $request, Committee $committee): JsonResponse
    {
        $this->authorizeCommittee($request->user(), $committee);

        $data = $request->validated();

        if (($data['seat'] ?? null) === 'chair') {
            $data['is_head'] = true;
        }

        $member = DB::transaction(function () use ($committee, $data) {
            if ($data['is_head'] ?? false) {
                $committee->members()->where('is_head', true)->update(['is_head' => false]);
            }

            // updateOrCreate, not create: there is no unique index on
            // (committee_id, user_id), and store() now always seats the
            // creator — so promoting that same person to a named seat would
            // otherwise leave the committee holding two rows for one person.
            return $committee->members()->updateOrCreate(['user_id' => $data['user_id']], $data);
        });

        return (new CommitteeMemberResource($member->load('user:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function removeMember(HttpRequest $request, Committee $committee, CommitteeMember $member): JsonResponse
    {
        $this->authorizeCommittee($request->user(), $committee);

        abort_unless($member->committee_id === $committee->id, 404);

        $member->delete();

        return response()->json(null, 204);
    }

    /**
     * Active users, for the committee-member and meeting-attendee pickers.
     *
     * Kept separate from GET /users: that endpoint sits behind the `users`
     * screen, which defaults to R08 only, while committee heads (R03) need to
     * pick people here without being handed the broader Users administration
     * screen just to do it.
     */
    /**
     * Membership gate — you may only act on a committee you can see.
     *
     * 404 rather than 403, matching the meeting gate and RequestVisibility:
     * refusing by name would confirm the committee exists. Without this,
     * index() would hide a committee that every write endpoint below still
     * accepted from anyone holding `meetings,edit` and an id to guess.
     */
    private function authorizeCommittee(User $actor, Committee $committee): void
    {
        abort_unless($this->visibility->canViewCommittee($actor, $committee), 404);
    }

    public function userOptions(): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
