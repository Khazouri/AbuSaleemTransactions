<?php

namespace App\Http\Middleware;

use App\Models\MeetingRequest;
use App\Models\Request as RequestRecord;
use App\Services\RequestVisibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Row gate for the after-decision writes — refuses a file the caller could not
 * open on the request page. Applied per-route as 'request.visible', after the
 * screen permission, so the 403/404 split is CheckMeetingMembership's own.
 *
 * The decision wizard only offers these acts on a page that already passed
 * RequestVisibility, but the endpoints were reachable by id, so a grant holder
 * could close, archive or reopen a file outside their sight. One middleware
 * rather than an abort_unless() per method, so the dozen routes cannot drift.
 *
 * Reads `{requestRecord}`, or `{agendaItem}`'s request for the meeting-scoped
 * execute route. An appeal item has no request: nothing to hide, and
 * MeetingOutputService refuses to execute it on its own.
 */
class CheckRequestVisibility
{
    public function __construct(private readonly RequestVisibility $visibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $record = $request->route('requestRecord');
        $agendaItem = $request->route('agendaItem');

        // Same reason as CheckMeetingMembership: resolve a raw id too, so the
        // gate cannot fail open if binding ever runs after it.
        if ($record !== null && ! $record instanceof RequestRecord) {
            $record = RequestRecord::find($record);
            abort_if($record === null, 404);
        }

        if ($record === null && $agendaItem !== null) {
            $agendaItem = $agendaItem instanceof MeetingRequest ? $agendaItem : MeetingRequest::find($agendaItem);
            abort_if($agendaItem === null, 404);
            $record = $agendaItem->request;
        }

        abort_if($record !== null && ! $this->visibility->canView($request->user(), $record), 404);

        return $next($request);
    }
}
