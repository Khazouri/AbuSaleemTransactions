<?php

namespace App\Http\Middleware;

use App\Models\Meeting;
use App\Services\MeetingVisibility;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membership gate — refuses a sitting the caller does not sit on the committee
 * of. Applied per-route as 'meeting.member', alongside the screen permission.
 *
 * A route middleware rather than an abort_unless() in each controller method,
 * because there are ~30 meeting-bound endpoints across eight controllers and
 * copying the check into each would be the exact duplication this work exists
 * to remove — the three membership checks that predate it were copy-pasted
 * inline, and two of them had already drifted apart.
 *
 * 404, not 403, and the split is deliberate: the screen permission answers
 * "may you use this capability at all" and says so with a 403, while this
 * answers "does this row exist for you" — a 403 here would confirm that a
 * meeting with that id exists, which is precisely what the gate conceals.
 * RequestVisibility's eleven call sites already abort 404 for the same reason.
 */
class CheckMeetingMembership
{
    public function __construct(private readonly MeetingVisibility $visibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $meeting = $request->route('meeting');

        // Route-model binding normally ran already (SubstituteBindings sits in
        // the api group, ahead of route middleware), but resolving a raw id
        // too keeps the guard correct if that order ever changes — failing
        // open here would be silent.
        if (! $meeting instanceof Meeting) {
            $meeting = $meeting === null ? null : Meeting::find($meeting);
        }

        abort_unless(
            $meeting !== null && $this->visibility->canView($request->user(), $meeting),
            404,
        );

        // A concluded sitting is a closed record: its agenda, attendance, votes,
        // decisions and minutes describe what happened and must not change
        // afterwards. Every meeting-bound write already passes through here, so
        // one check covers them all. Execution follow-up (outputs/execute) is
        // not behind this middleware and stays open by design.
        if (! $request->isMethodSafe() && $meeting->status === 'completed') {
            throw ValidationException::withMessages([
                'meeting' => ['لا يجوز تعديل اجتماع منتهٍ.'],
            ]);
        }

        return $next($request);
    }
}
