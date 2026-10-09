<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScreenResource;
use App\Models\Screen;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Supplies the SPA's navigation menu.
 *
 * The sidebar is built from the `screens` table rather than hard-coded in Vue,
 * so adding or renaming a screen is a data change.
 */
class ScreenController extends Controller
{
    /**
     * Active screens the signed-in user is allowed to SEE in the menu.
     *
     * Reads User::screenPermissions() rather than querying
     * screen_role_permissions itself. It used to do the latter, which made this
     * a second, independent implementation of "which screens may you view" —
     * harmless while the answer was a plain role lookup, but a real hazard once
     * the membership gate started suppressing can_view for a user with no
     * committee seat: the sidebar would have kept listing meetings links that
     * every call behind them then refused.
     *
     * Still menu filtering rather than access control — hiding a link does not
     * stop anyone typing the URL. Real enforcement is CheckScreenPermission on
     * every protected endpoint, plus CheckMeetingMembership on the meeting-bound
     * ones. Because all three now read the same resolved map, they agree.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewable = array_keys(array_filter(
            $request->user()->screenPermissions(),
            fn (array $actions) => $actions['can_view'] ?? false,
        ));

        $screens = Screen::query()
            ->whereIn('code', $viewable)
            // The permission map says nothing about whether a screen is still
            // live, so this filter has to survive the rewrite above.
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return ScreenResource::collection($screens);
    }
}
