<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Committee\StoreCommitteeRequest;
use App\Http\Requests\Committee\UpdateCommitteeRequest;
use App\Http\Resources\CommitteeResource;
use App\Models\Committee;
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
     * index() lists only committees you sit on. Only the system's مقرر forms a
     * committee (user decision 2026-10-02), and seatRoleHolders() puts them in
     * the rapporteur seat, so that one row is the creator's seat too. The other
     * four seats come from «المناصب الفردية» (Stage 103), never picked here.
     */
    public function store(StoreCommitteeRequest $request): JsonResponse
    {
        $this->refuseUnlessRapporteur($request->user());

        $committee = DB::transaction(function () use ($request) {
            $committee = Committee::create($request->validated());
            $committee->seatRoleHolders();

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

    /**
     * The committee is a group the مقرر picks, not a standing body (user
     * decision 2026-10-02): forming one is theirs alone. The chair keeps
     * `meetings,edit` for renaming and retiring it.
     */
    private function refuseUnlessRapporteur(User $actor): void
    {
        abort_unless(User::activeRapporteur()?->id === $actor->id, 422, 'تشكيل اللجنة من صلاحية المقرر وحده.');
    }

    /**
     * Active users, kept separate from GET /users: that endpoint sits behind
     * the R08-only `users` screen.
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
