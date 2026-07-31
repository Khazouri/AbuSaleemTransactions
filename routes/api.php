<?php

use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\ApprovalSignatureController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommitteeController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ScreenController;
use App\Http\Controllers\Api\ScreenRolePermissionController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\TransactionController;
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

    /*
     * Stage 11 — transaction work queue. Static transaction paths must precede
     * /transactions/{transaction}, otherwise the model wildcard would consume
     * their literal path and make their lookup data unreachable.
     */
    Route::middleware('screen.permission:transactions,view')
        ->get('transactions/filters', [TransactionController::class, 'filters']);
    Route::middleware('screen.permission:transactions,view')
        ->get('transactions', [TransactionController::class, 'index']);

    // Stage 13 — controlled transaction intake with locked reference allocation.
    Route::middleware('screen.permission:transaction_intake,view')
        ->get('transactions/intake-options', [TransactionController::class, 'intakeOptions']);
    Route::middleware('screen.permission:transaction_intake,add')
        ->post('transactions', [TransactionController::class, 'store']);

    // Stage 19 — private signature images use the same transaction-detail
    // visibility gate as the approval trail that renders them.
    Route::middleware('screen.permission:transaction_details,view')
        ->get(
            'transactions/{transaction}/approvals/{approval}/signature',
            [ApprovalSignatureController::class, 'show'],
        )
        ->name('transactions.approvals.signature');

    // Stage 15 — the detail screen is read by its own capability. The action
    // endpoint uses that same view gate, then WorkflowService enforces the
    // transition's configured role under lock (not one broad screen flag).
    Route::middleware('screen.permission:transaction_details,view')
        ->get('transactions/{transaction}', [TransactionController::class, 'show']);
    Route::middleware('screen.permission:transaction_details,view')
        ->post('transactions/{transaction}/transition', [TransactionController::class, 'transition']);

    /*
     * Stage 18 — one queue and write gate per approval authority. Literal
     * routes keep a caller from swapping a level slug under a permission
     * granted for a different screen.
     */
    $approvalScreens = [
        'reviewer' => 'reviewer_approval',
        'committee-head' => 'committee_head_approval',
        'admin-manager' => 'admin_manager_approval',
        'ministry' => 'ministry_approval',
        'authority' => 'authority_approval',
        'final' => 'final_approval',
    ];

    foreach ($approvalScreens as $level => $screenCode) {
        Route::middleware("screen.permission:{$screenCode},view")
            ->get("approvals/{$level}", [ApprovalController::class, 'index'])
            ->defaults('level', $level);
        Route::middleware("screen.permission:{$screenCode},approve")
            ->post("approvals/{$level}/{transaction}", [ApprovalController::class, 'store'])
            ->defaults('level', $level);
    }

    // Stage 12 — private attachments are written through the dedicated
    // Notes & Attachments capability, not a broad transaction-list privilege.
    Route::middleware('screen.permission:notes_attachments,add')
        ->post('transactions/{transaction}/attachments', [AttachmentController::class, 'store']);

    // Stage 13 — conversation notes are independent from changing workflow state.
    Route::middleware('screen.permission:notes_attachments,view')
        ->get('transactions/{transaction}/notes', [NoteController::class, 'index']);
    Route::middleware('screen.permission:notes_attachments,add')
        ->post('transactions/{transaction}/notes', [NoteController::class, 'store']);

    /*
     * Stage 20 — committees & meetings. Neither has a screen of its own on the
     * 22/23-screen sheet, so both ride the `meetings` screen's permissions
     * (committee management is a prerequisite of scheduling that committee's
     * meetings, not a distinct capability). Literal paths (user-options,
     * reorder) are declared before their sibling wildcard routes so the model
     * binding can't swallow them.
     */
    Route::middleware('screen.permission:meetings,view')
        ->get('committees/user-options', [CommitteeController::class, 'userOptions']);
    Route::middleware('screen.permission:meetings,view')
        ->get('committees', [CommitteeController::class, 'index']);
    Route::middleware('screen.permission:meetings,add')
        ->post('committees', [CommitteeController::class, 'store']);
    Route::middleware('screen.permission:meetings,edit')->group(function () {
        Route::patch('committees/{committee}/toggle-active', [CommitteeController::class, 'toggleActive']);
        Route::put('committees/{committee}', [CommitteeController::class, 'update']);
        Route::post('committees/{committee}/members', [CommitteeController::class, 'addMember']);
        Route::delete('committees/{committee}/members/{member}', [CommitteeController::class, 'removeMember']);
    });
    Route::middleware('screen.permission:meetings,delete')
        ->delete('committees/{committee}', [CommitteeController::class, 'destroy']);

    Route::middleware('screen.permission:meetings,view')
        ->get('meetings', [MeetingController::class, 'index']);
    Route::middleware('screen.permission:meetings,add')
        ->post('meetings', [MeetingController::class, 'store']);
    Route::middleware('screen.permission:meetings,view')
        ->get('meetings/{meeting}', [MeetingController::class, 'show']);
    Route::middleware('screen.permission:meetings,edit')->group(function () {
        Route::put('meetings/{meeting}', [MeetingController::class, 'update']);
        Route::post('meetings/{meeting}/agenda', [MeetingController::class, 'addAgendaItem']);
        Route::put('meetings/{meeting}/agenda/reorder', [MeetingController::class, 'reorderAgenda']);
        Route::delete('meetings/{meeting}/agenda/{agendaItem}', [MeetingController::class, 'removeAgendaItem']);
        Route::post('meetings/{meeting}/attendees', [MeetingController::class, 'addAttendee']);
        Route::patch('meetings/{meeting}/attendees/{attendee}', [MeetingController::class, 'markAttendance']);
        Route::delete('meetings/{meeting}/attendees/{attendee}', [MeetingController::class, 'removeAttendee']);
    });
    Route::middleware('screen.permission:meetings,delete')
        ->delete('meetings/{meeting}', [MeetingController::class, 'destroy']);

    /*
     * Stage 21 — committee voting and decision recording. These ride the
     * `decisions` screen's own permissions (view=*, add=[R03,R04],
     * approve=[R03]) rather than `meetings`: casting a vote is open to any
     * committee member, while only the head can record the binding outcome
     * that drives WorkflowService::transition().
     */
    Route::middleware('screen.permission:decisions,add')
        ->post('meetings/{meeting}/agenda/{agendaItem}/votes', [DecisionController::class, 'vote']);
    Route::middleware('screen.permission:decisions,approve')
        ->post('meetings/{meeting}/agenda/{agendaItem}/decision', [DecisionController::class, 'record']);

    /*
     * Stage 22 — audit log. Read only by design (see AuditLogController), so
     * only the `view` action is wired; there is no write endpoint to gate.
     * `filters` is declared first purely for readability — no wildcard route
     * shares this prefix to shadow it.
     */
    Route::middleware('screen.permission:audit_log,view')->group(function () {
        Route::get('audit-logs/filters', [AuditLogController::class, 'filters']);
        Route::get('audit-logs', [AuditLogController::class, 'index']);
    });
});
