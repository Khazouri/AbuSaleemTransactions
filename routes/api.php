<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ScreenController;
use App\Http\Controllers\Api\ScreenRolePermissionController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| API routes
|------------------------------------------------------------------------------
| Everything here is automatically prefixed with /api and is stateless — the
| Vue SPA is the only client. Authentication is by Sanctum bearer token.
|
| CORS (which origins may call these routes) is configured in config/cors.php
| and currently allows the Vite dev server at localhost:5173.
*/

/**
 * Connectivity check — no auth, no database. Used to confirm the SPA can reach
 * Laravel through Homestead's nginx.
 */
Route::get('/ping', fn () => response()->json([
    'message' => 'pong',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

Route::prefix('auth')->group(function () {
    /*
     * Public: this is where a user gets their token, so it can't require one.
     *
     * throttle:6,1 caps it at 6 attempts per minute per IP. Login is the one
     * endpoint worth guessing at, and without a limit an attacker could try
     * passwords as fast as the server responds. Exceeding it returns 429,
     * which the login form reports as "too many attempts".
     */
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1');

    /*
     * Protected: auth:sanctum rejects anything without a valid bearer token
     * with a 401, which the SPA's axios interceptor turns into a logout.
     */
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
 * Signed-in application routes.
 */
Route::middleware('auth:sanctum')->group(function () {
    // Sidebar navigation, filtered to the screens this user may view. Menu
    // filtering only — see ScreenController's docblock — so it stays
    // unguarded by screen.permission itself.
    Route::get('/screens', [ScreenController::class, 'index']);

    /*
     * Departments (الإدارات).
     *
     * No `show` route — the SPA already holds the full list from index(), so a
     * single-department endpoint would be dead weight.
     *
     * Stage 9 — each verb is gated by screen.permission against the
     * `departments` screen's matching can_* flag, split out of a plain
     * apiResource() so index/store/update/destroy can each require a
     * different action. toggle-active is grouped with update under `edit`
     * since it's a state change, not a deletion; it stays declared before the
     * {department} wildcard route so it can't be shadowed.
     */
    Route::middleware('screen.permission:departments,view')
        ->get('departments', [DepartmentController::class, 'index']);
    Route::middleware('screen.permission:departments,add')
        ->post('departments', [DepartmentController::class, 'store']);
    Route::middleware('screen.permission:departments,edit')->group(function () {
        Route::patch('departments/{department}/toggle-active', [DepartmentController::class, 'toggleActive']);
        Route::put('departments/{department}', [DepartmentController::class, 'update']);
    });
    Route::middleware('screen.permission:departments,delete')
        ->delete('departments/{department}', [DepartmentController::class, 'destroy']);

    /*
     * Read-only role list (Stage 7) — the Users screen needs it to offer
     * roles as checkboxes, and the Roles & Permissions screen needs it for
     * its role tabs. It doesn't map to a single screen, and role
     * codes/names aren't sensitive on their own, so it stays behind
     * auth:sanctum only rather than a screen.permission check.
     */
    Route::get('/roles', [RoleController::class, 'index']);

    /*
     * Roles & permissions matrix (Stage 8) — screens x roles x the seven
     * can_* actions. index() returns screens, roles and the matrix together;
     * update() bulk-upserts the whole grid in one request. Stage 9 gates both
     * behind the `roles_permissions` screen itself.
     */
    Route::middleware('screen.permission:roles_permissions,view')
        ->get('/screen-role-permissions', [ScreenRolePermissionController::class, 'index']);
    Route::middleware('screen.permission:roles_permissions,edit')
        ->put('/screen-role-permissions', [ScreenRolePermissionController::class, 'update']);

    /*
     * Users (المستخدمون) — Stage 7. Same shape as departments: verbs gated
     * individually against the `users` screen (Stage 9), toggle-active grouped
     * under `edit` and declared before the {user} wildcard, no `show` since
     * the SPA already holds the full list from index().
     */
    Route::middleware('screen.permission:users,view')
        ->get('users', [UserController::class, 'index']);
    Route::middleware('screen.permission:users,add')
        ->post('users', [UserController::class, 'store']);
    Route::middleware('screen.permission:users,edit')->group(function () {
        Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive']);
        Route::put('users/{user}', [UserController::class, 'update']);
    });
    Route::middleware('screen.permission:users,delete')
        ->delete('users/{user}', [UserController::class, 'destroy']);

    /*
     * Stage 10 — settings and template administration. Each verb is bound to
     * the matching screen permission, keeping these low-risk CRUD screens in
     * step with the server-side enforcement introduced in Stage 9.
     */
    Route::middleware('screen.permission:settings,view')
        ->get('settings', [SettingController::class, 'index']);
    Route::middleware('screen.permission:settings,add')
        ->post('settings', [SettingController::class, 'store']);
    Route::middleware('screen.permission:settings,edit')
        ->put('settings/{setting}', [SettingController::class, 'update']);
    Route::middleware('screen.permission:settings,delete')
        ->delete('settings/{setting}', [SettingController::class, 'destroy']);

    Route::middleware('screen.permission:templates,view')
        ->get('templates', [TemplateController::class, 'index']);
    Route::middleware('screen.permission:templates,add')
        ->post('templates', [TemplateController::class, 'store']);
    Route::middleware('screen.permission:templates,edit')
        ->put('templates/{template}', [TemplateController::class, 'update']);
    Route::middleware('screen.permission:templates,delete')
        ->delete('templates/{template}', [TemplateController::class, 'destroy']);
});
