<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API-side half of Stage 9's enforcement.
 *
 * Route middleware, applied per-endpoint with the screen code and action it
 * guards, e.g. ->middleware('screen.permission:users,view'). It reads the
 * same screen_role_permissions table the Vue router guard and v-can
 * directive read, so a hidden button and a blocked route always agree with
 * what the API actually allows — a crafted request can't get further than
 * the UI does.
 */
class CheckScreenPermission
{
    public function handle(Request $request, Closure $next, string $screenCode, string $action): Response
    {
        if (! $request->user()?->hasScreenPermission($screenCode, "can_{$action}")) {
            return response()->json([
                'message' => 'لا تملك صلاحية الوصول إلى هذا القسم.',
            ], 403);
        }

        return $next($request);
    }
}
