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
use Illuminate\Http\JsonResponse;
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
    public function index(): AnonymousResourceCollection
    {
        $committees = Committee::query()
            ->withCount(['members', 'meetings'])
            ->with(['members.user:id,name'])
            ->orderBy('name_ar')
            ->get();

        return CommitteeResource::collection($committees);
    }

    public function store(StoreCommitteeRequest $request): JsonResponse
    {
        $committee = Committee::create($request->validated());

        return (new CommitteeResource($committee->loadCount(['members', 'meetings'])->load('members.user:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCommitteeRequest $request, Committee $committee): CommitteeResource
    {
        $committee->update($request->validated());

        return new CommitteeResource($committee->loadCount(['members', 'meetings'])->load('members.user:id,name'));
    }

    /** Flip is_active, the same soft-disable pattern DepartmentController uses. */
    public function toggleActive(Committee $committee): CommitteeResource
    {
        $committee->update(['is_active' => ! $committee->is_active]);

        return new CommitteeResource($committee->loadCount(['members', 'meetings'])->load('members.user:id,name'));
    }

    /**
     * Soft-delete a committee. Blocked while it has held any meetings — that
     * history (agenda, attendance, minutes) must stay attached to a resolvable
     * committee, mirroring DepartmentController::destroy's "empty it first" rule.
     */
    public function destroy(Committee $committee): JsonResponse
    {
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
        $data = $request->validated();

        if (($data['seat'] ?? null) === 'chair') {
            $data['is_head'] = true;
        }

        $member = DB::transaction(function () use ($committee, $data) {
            if ($data['is_head'] ?? false) {
                $committee->members()->where('is_head', true)->update(['is_head' => false]);
            }

            return $committee->members()->create($data);
        });

        return (new CommitteeMemberResource($member->load('user:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function removeMember(Committee $committee, CommitteeMember $member): JsonResponse
    {
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
