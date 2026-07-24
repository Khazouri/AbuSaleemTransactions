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
     * ------------------------------------------------------------------------
     * IMPORTANT — this is menu filtering, NOT access control.
     *
     * Hiding a link stops it appearing in the sidebar; it does not stop anyone
     * typing the URL directly, and it does not protect a single API endpoint.
     * Real enforcement — route guards plus middleware on every protected
     * endpoint — is Stage 9. Until then, treat this purely as cosmetics.
     * ------------------------------------------------------------------------
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // The roles this user holds. A user may have several, and their
        // visible menu is the union of what those roles can view.
        $roleIds = $request->user()->roles()->pluck('roles.id');

        $screens = Screen::query()
            ->where('is_active', true)
            // Keep a screen if ANY of the user's roles has can_view on it.
            // whereHas builds one EXISTS subquery, so this stays a single
            // round trip regardless of how many roles the user holds.
            ->whereHas('rolePermissions', fn ($query) => $query
                ->whereIn('role_id', $roleIds)
                ->where('can_view', true))
            ->orderBy('sort_order')
            ->get();

        return ScreenResource::collection($screens);
    }
}
