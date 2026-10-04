<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommitteeRole\AssignCommitteeRoleRequest;
use App\Models\CommitteeMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;

/**
 * Stage 103 — «أدوار اللجنة» (user decision 2026-10-04). Every account starts
 * as an employee; R08 names the مقرر, and the مقرر names the other four seat
 * holders. Each seat role has one holder in the system, and every committee
 * seats that holder (Committee::seatRoleHolders()), so naming someone here is
 * the only way onto a committee.
 *
 * The seat follows the role when a committee is formed or its next meeting is
 * scheduled, not here: reseating now would pull the former holder out of a
 * sitting already under way, which the new holder was never invited to. Until
 * then the former holder is a stand-in (SeatDutyGrant).
 */
class CommitteeRoleController extends Controller
{
    public function index(HttpRequest $request): JsonResponse
    {
        $actor = $request->user();
        $roles = Role::query()->whereIn('code', CommitteeMember::SEAT_ROLES)->get()->keyBy('code');

        $seats = collect(CommitteeMember::SEAT_ROLES)->map(function (string $code, string $seat) use ($actor, $roles) {
            $holder = User::activeSeatHolder($seat);

            return [
                'seat' => $seat,
                'role' => $roles[$code]->only(['code', 'name_ar', 'name_en']),
                'holder' => $holder?->only(['id', 'name']),
                'can_assign' => $this->assignRefusal($actor, $seat) === null,
            ];
        })->values();

        return response()->json(['data' => [
            'seats' => $seats,
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    /**
     * Name the holder of one seat's role. It is taken from everyone else who
     * holds it, active or not, so the page always shows one person per seat
     * and a former holder cannot be re-enabled into a second one.
     */
    public function assign(AssignCommitteeRoleRequest $request, string $seat): JsonResponse
    {
        $refusal = $this->assignRefusal($request->user(), $seat);
        abort_if($refusal !== null, 422, $refusal);

        $roleId = Role::query()->where('code', CommitteeMember::SEAT_ROLES[$seat])->value('id');
        $userId = $request->validated('user_id');

        DB::transaction(function () use ($roleId, $userId) {
            DB::table('role_user')->where('role_id', $roleId)->where('user_id', '!=', $userId)->delete();
            User::query()->findOrFail($userId)->roles()->syncWithoutDetaching([$roleId]);
        });

        return $this->index($request);
    }

    private function assignRefusal(User $actor, string $seat): ?string
    {
        if ($seat === 'rapporteur') {
            return $actor->hasRole('R08') ? null : 'تعيين المقرر من صلاحية مدير النظام وحده.';
        }

        return User::activeRapporteur()?->id === $actor->id ? null : 'تعيين أعضاء اللجنة من صلاحية المقرر وحده.';
    }
}
