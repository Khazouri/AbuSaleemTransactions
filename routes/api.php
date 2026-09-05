<?php

use App\Http\Controllers\Api\AppealAttachmentController;
use App\Http\Controllers\Api\AppealController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\ApprovalSignatureController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\CommitteeCandidateController;
use App\Http\Controllers\Api\CommitteeController;
use App\Http\Controllers\Api\ConflictOfInterestController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DevTestUserController;
use App\Http\Controllers\Api\GuideArticleController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\MeetingDiscussionNoteController;
use App\Http\Controllers\Api\MeetingMinutesController;
use App\Http\Controllers\Api\MeetingOutputsController;
use App\Http\Controllers\Api\MeetingReadinessController;
use App\Http\Controllers\Api\MeetingsDashboardController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PresentationMemoController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RequestController;
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

/**
 * The seeded test accounts behind the login screen's one-click picker.
 *
 * Public and unauthenticated by necessity — it is read to draw the login page,
 * before anyone has a token. It is a 404 unless APP_ENV=local; the controller
 * makes that call per request rather than this file making it at boot, so a
 * cached route table can't smuggle a local decision into production.
 */
Route::get('/dev/test-users', [DevTestUserController::class, 'index']);

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
     * Stage 11 — request work queue. Static request paths must precede
     * /requests/{requestRecord}, otherwise the model wildcard would consume
     * their literal path and make their lookup data unreachable.
     */
    Route::middleware('screen.permission:requests,view')
        ->get('requests/filters', [RequestController::class, 'filters']);
    Route::middleware('screen.permission:requests,view')
        ->get('requests', [RequestController::class, 'index']);

    // Stage 13 — controlled request intake with locked reference allocation.
    Route::middleware('screen.permission:request_intake,view')
        ->get('requests/intake-options', [RequestController::class, 'intakeOptions']);
    Route::middleware('screen.permission:request_intake,add')
        ->post('requests', [RequestController::class, 'store']);

    // Stage 19 — private signature images use the same request-detail
    // visibility gate as the approval trail that renders them.
    Route::middleware('screen.permission:request_details,view')
        ->get(
            'requests/{requestRecord}/approvals/{approval}/signature',
            [ApprovalSignatureController::class, 'show'],
        )
        ->name('requests.approvals.signature');

    // Private attachment previews travel through the API so a bearer token,
    // request visibility, and attachment-parent relationship are all
    // checked before a browser receives a byte of the stored file.
    Route::middleware('screen.permission:request_details,view')
        ->get(
            'requests/{requestRecord}/attachments/{attachment}/preview',
            [AttachmentController::class, 'preview'],
        )
        ->name('requests.attachments.preview');

    // Stage 15 — the detail screen is read by its own capability. The action
    // endpoint uses that same view gate, then WorkflowService enforces the
    // transition's configured role under lock (not one broad screen flag).
    Route::middleware('screen.permission:request_details,view')
        ->get('requests/{requestRecord}', [RequestController::class, 'show']);
    Route::middleware('screen.permission:request_details,view')
        ->post('requests/{requestRecord}/transition', [RequestController::class, 'transition']);

    /*
     * Stage 18 — one queue and write gate per approval authority. Literal
     * routes keep a caller from swapping a level slug under a permission
     * granted for a different screen.
     */
    // Stage 57 — 'authority' (competent_authority) is gone; R07 keeps only 'final'.
    $approvalScreens = [
        'reviewer' => 'reviewer_approval',
        'committee-head' => 'committee_head_approval',
        'admin-manager' => 'admin_manager_approval',
        'ministry' => 'ministry_approval',
        'final' => 'final_approval',
    ];

    foreach ($approvalScreens as $level => $screenCode) {
        Route::middleware("screen.permission:{$screenCode},view")
            ->get("approvals/{$level}", [ApprovalController::class, 'index'])
            ->defaults('level', $level);
        Route::middleware("screen.permission:{$screenCode},approve")
            ->post("approvals/{$level}/{requestRecord}", [ApprovalController::class, 'store'])
            ->defaults('level', $level);
    }

    // Stage 12 — private attachments are written through the dedicated
    // Notes & Attachments capability, not a broad request-list privilege.
    Route::middleware('screen.permission:notes_attachments,add')
        ->post('requests/{requestRecord}/attachments', [AttachmentController::class, 'store']);

    // Stage 13 — conversation notes are independent from changing workflow state.
    Route::middleware('screen.permission:notes_attachments,view')
        ->get('requests/{requestRecord}/notes', [NoteController::class, 'index']);
    Route::middleware('screen.permission:notes_attachments,add')
        ->post('requests/{requestRecord}/notes', [NoteController::class, 'store']);

    // Stage 47 — correcting the financial-impact flag is an ancillary
    // correction, not core request editing, so it rides the same narrow
    // notes_attachments,edit grant (R01/R02) rather than a new permission.
    Route::middleware('screen.permission:notes_attachments,edit')
        ->patch('requests/{requestRecord}/financial-impact', [RequestController::class, 'updateFinancialImpact']);

    // Stage 54 — recording [D] Art. 45's jurisdiction test is the same kind
    // of narrow ancillary correction as financial-impact above, so it rides
    // the same grant rather than a new permission tier.
    Route::middleware('screen.permission:notes_attachments,edit')
        ->patch('requests/{requestRecord}/jurisdiction-test', [RequestController::class, 'recordJurisdictionTest']);

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

    /*
     * Stage 32 — the candidate-requests worklist. Rides the `committee_candidates`
     * screen's own grants (add=[R03,R04] gates nominate, edit=[R03] gates the
     * other three) rather than `meetings` — see CommitteeCandidateController's
     * docblock for why defer/return-to-study run through WorkflowService while
     * nominate/request-completion run through CommitteeStatusService.
     */
    Route::middleware('screen.permission:committee_candidates,view')
        ->get('committee-candidates', [CommitteeCandidateController::class, 'index']);
    Route::middleware('screen.permission:committee_candidates,add')
        ->post('committee-candidates/{requestRecord}/nominate', [CommitteeCandidateController::class, 'nominate']);
    Route::middleware('screen.permission:committee_candidates,edit')->group(function () {
        Route::post('committee-candidates/{requestRecord}/defer', [CommitteeCandidateController::class, 'defer']);
        Route::post('committee-candidates/{requestRecord}/return-to-study', [CommitteeCandidateController::class, 'returnToStudy']);
        Route::post('committee-candidates/{requestRecord}/request-completion', [CommitteeCandidateController::class, 'requestCompletion']);
    });

    // Stage 32 — the meetings-unit command dashboard. A literal path declared
    // before the `meetings/{meeting}` wildcard below, same reason
    // department-options is.
    Route::middleware('screen.permission:meetings_dashboard,view')
        ->get('meetings/dashboard', [MeetingsDashboardController::class, 'index']);

    Route::middleware('screen.permission:meeting_agenda,view')
        ->get('meetings/department-options', [MeetingController::class, 'departmentOptions']);
    Route::middleware('screen.permission:meetings,view')
        ->get('meetings', [MeetingController::class, 'index']);
    Route::middleware('screen.permission:meetings,add')
        ->post('meetings', [MeetingController::class, 'store']);
    Route::middleware('screen.permission:meetings,view')
        ->get('meetings/{meeting}', [MeetingController::class, 'show']);
    Route::middleware('screen.permission:meetings,edit')->group(function () {
        Route::put('meetings/{meeting}', [MeetingController::class, 'update']);
        Route::post('meetings/{meeting}/send-invitations', [MeetingController::class, 'sendInvitations']);
        Route::post('meetings/{meeting}/attendees', [MeetingController::class, 'addAttendee']);
        Route::patch('meetings/{meeting}/attendees/{attendee}', [MeetingController::class, 'markAttendance']);
        Route::delete('meetings/{meeting}/attendees/{attendee}', [MeetingController::class, 'removeAttendee']);
    });
    Route::middleware('screen.permission:meetings,delete')
        ->delete('meetings/{meeting}', [MeetingController::class, 'destroy']);

    /*
     * Stage 31 — agenda building rides the `meeting_agenda` screen's own
     * permissions rather than `meetings` (grants are identical today, so no
     * role's access actually changes) — building the agenda is that screen's
     * declared domain, not the meeting record's.
     */
    Route::middleware('screen.permission:meeting_agenda,view')
        ->get('meetings/{meeting}/agenda/stats', [MeetingController::class, 'agendaStats']);
    Route::middleware('screen.permission:meeting_agenda,edit')->group(function () {
        Route::post('meetings/{meeting}/agenda', [MeetingController::class, 'addAgendaItem']);
        Route::put('meetings/{meeting}/agenda/reorder', [MeetingController::class, 'reorderAgenda']);
        Route::patch('meetings/{meeting}/agenda/{agendaItem}', [MeetingController::class, 'updateAgendaItem']);
        Route::delete('meetings/{meeting}/agenda/{agendaItem}', [MeetingController::class, 'removeAgendaItem']);
    });

    /*
     * Stage 46 — [D] Art. 22's compiled pre-meeting memo, one per agenda
     * item. `view` is the broad `meeting_agenda` grant (readable before/
     * during the meeting by anyone); `add` is this screen's first real use
     * of its own `add` tier (R03/R04/R09) rather than `edit` (R03/R09) —
     * see PresentationMemoController's docblock for why the رئيس/مقرر split
     * matters here.
     */
    Route::middleware('screen.permission:meeting_agenda,view')
        ->get('meetings/{meeting}/agenda/{agendaItem}/presentation-memo', [PresentationMemoController::class, 'show']);
    Route::middleware('screen.permission:meeting_agenda,add')->group(function () {
        Route::post('meetings/{meeting}/agenda/{agendaItem}/presentation-memo/generate', [PresentationMemoController::class, 'generate']);
        Route::patch('meetings/{meeting}/agenda/{agendaItem}/presentation-memo', [PresentationMemoController::class, 'update']);
    });

    /*
     * Stage 33 — the pre-meeting readiness gate. Rides the `meeting_readiness`
     * screen's own grants (view=* for the read side, edit=[R03] for convene —
     * which doubles as the "R03 exceptional override" requirement, see
     * MeetingReadinessController's docblock).
     */
    Route::middleware('screen.permission:meeting_readiness,view')
        ->get('meetings/{meeting}/readiness', [MeetingReadinessController::class, 'show']);
    Route::middleware('screen.permission:meeting_readiness,edit')
        ->post('meetings/{meeting}/convene', [MeetingReadinessController::class, 'convene']);

    /*
     * Stage 34 — the live meeting runner. Rides the `meeting_live` screen's
     * own grants: `edit` (R03, the chair) advances an item's state; `view`/
     * `add` (everyone/R03+R04) read and post to the discussion feed. Closing
     * a meeting stays on the generic `meetings,edit` update() above — see
     * that method's docblock for why it isn't a separate endpoint here.
     */
    Route::middleware('screen.permission:meeting_live,edit')
        ->patch('meetings/{meeting}/agenda/{agendaItem}/state', [MeetingController::class, 'updateItemState']);
    Route::middleware('screen.permission:meeting_live,view')
        ->get('meetings/{meeting}/agenda/{agendaItem}/notes', [MeetingDiscussionNoteController::class, 'index']);
    Route::middleware('screen.permission:meeting_live,add')
        ->post('meetings/{meeting}/agenda/{agendaItem}/notes', [MeetingDiscussionNoteController::class, 'store']);

    /*
     * Stage 44 — the runner's quick-info panel ([C] §6's five non-notes
     * tabs) plus the private attachment stream it needs. Both ride
     * `meeting_live,view`, deliberately not the request-detail visibility
     * gate — see MeetingController::agendaItemContext()'s docblock.
     */
    Route::middleware('screen.permission:meeting_live,view')
        ->get('meetings/{meeting}/agenda/{agendaItem}/context', [MeetingController::class, 'agendaItemContext']);
    Route::middleware('screen.permission:meeting_live,view')
        ->get(
            'meetings/{meeting}/agenda/{agendaItem}/attachments/{attachment}',
            [MeetingController::class, 'agendaItemAttachment'],
        )
        ->name('meetings.agenda-item.attachment');

    /*
     * Stage 36 — minutes: generate/regenerate a draft, the head's review
     * decision, and each attendee's own signature. `add` covers both
     * generating and signing (mirrors `decisions,add` covering vote-casting);
     * `approve` is the head-only review, same split as `decisions`.
     */
    Route::middleware('screen.permission:meeting_minutes,view')
        ->get('meetings/{meeting}/minutes', [MeetingMinutesController::class, 'show']);
    Route::middleware('screen.permission:meeting_minutes,add')->group(function () {
        Route::post('meetings/{meeting}/minutes/generate', [MeetingMinutesController::class, 'generate']);
        Route::post('meetings/{meeting}/minutes/sign', [MeetingMinutesController::class, 'sign']);
    });
    Route::middleware('screen.permission:meeting_minutes,approve')
        ->post('meetings/{meeting}/minutes/review', [MeetingMinutesController::class, 'review']);
    // Private signature images, same visibility gate as the document itself.
    Route::middleware('screen.permission:meeting_minutes,view')
        ->get('meeting-minutes/signatures/{signature}', [MeetingMinutesController::class, 'signatureImage'])
        ->name('meeting-minutes.signature');

    /*
     * Stage 37 — the live, meeting-scoped decision/output tracker. Reading is
     * broad like the screen; only the head's `edit` grant may certify that an
     * in-execution request is complete and closed.
     */
    Route::middleware('screen.permission:meeting_outputs,view')
        ->get('meetings/{meeting}/outputs', [MeetingOutputsController::class, 'show']);
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->post('meetings/{meeting}/outputs/{agendaItem}/complete', [MeetingOutputsController::class, 'complete']);

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
    // Stage 42 — a read-only preview of a template merged with this agenda
    // item's own data, sitting behind `view` like the rest of the register
    // reads below (it discloses nothing not already visible on the screen).
    Route::middleware('screen.permission:decisions,view')
        ->get('meetings/{meeting}/agenda/{agendaItem}/decision-draft', [DecisionController::class, 'draft']);

    /*
     * Stage 48 — [D] Art. 11/15/18: a member with a stake in an item formally
     * discloses it before deliberation. Rides `decisions` the same way voting
     * does — `add` to declare (the declaration IS the recusal, checked by
     * DecisionEligibility::isRecused against both the vote and the
     * discussion-feed endpoints), `view` to read who has declared.
     */
    Route::middleware('screen.permission:decisions,add')
        ->post('meetings/{meeting}/agenda/{agendaItem}/conflict-of-interest', [ConflictOfInterestController::class, 'store']);
    Route::middleware('screen.permission:decisions,view')
        ->get('meetings/{meeting}/agenda/{agendaItem}/conflict-of-interest', [ConflictOfInterestController::class, 'index']);

    /*
     * Stage 25 — the `decisions` screen itself, which Stage 21 left a
     * placeholder because the voting UI belonged in the meeting. These are the
     * read side: the register of recorded decisions, and the worklist of items
     * the caller still owes a vote on.
     *
     * `pending` sits behind `view`, not `add` — reading your own worklist is
     * reading. Its vote buttons post back to the endpoint above, which
     * re-checks `add` and the eligibility rules, so nothing is decided here.
     * Export is R08-only by default (the `decisions` grants seed `print` to
     * everyone but not `export`): reading the register on screen and carrying
     * it out as a file are different privileges.
     */
    Route::middleware('screen.permission:decisions,view')->group(function () {
        Route::get('decisions/filters', [DecisionController::class, 'filters']);
        Route::get('decisions/pending', [DecisionController::class, 'pending']);
        Route::get('decisions', [DecisionController::class, 'index']);
    });
    Route::middleware('screen.permission:decisions,export')
        ->get('decisions/export', [DecisionController::class, 'export']);

    /*
     * Stage 27 — the user guide. The only screen in the system where `view` is
     * granted to every role but the write verbs are R08-only, which is exactly
     * what a help section is: everyone reads it, one person maintains it.
     * Drafts are filtered out in the controller, not here — the permission
     * decides whether you can edit, the query decides what you can see.
     */
    Route::middleware('screen.permission:user_guide,view')
        ->get('guide-articles', [GuideArticleController::class, 'index']);
    Route::middleware('screen.permission:user_guide,add')
        ->post('guide-articles', [GuideArticleController::class, 'store']);
    Route::middleware('screen.permission:user_guide,edit')
        ->put('guide-articles/{guideArticle}', [GuideArticleController::class, 'update']);
    Route::middleware('screen.permission:user_guide,delete')
        ->delete('guide-articles/{guideArticle}', [GuideArticleController::class, 'destroy']);

    /*
     * Stage 26 — backups. Every verb here is R08-only, which is what the
     * `backup` screen's empty DEFAULTS entry already means; no seeder change
     * was needed to wire it.
     *
     * Downloading rides `export` rather than `view` for the same reason the
     * reports and audit screens split them: listing what snapshots exist and
     * walking away with a complete copy of the database are not the same
     * privilege. There is no restore route — see BackupController.
     */
    Route::middleware('screen.permission:backup,view')
        ->get('backups', [BackupController::class, 'index']);
    Route::middleware('screen.permission:backup,add')
        ->post('backups', [BackupController::class, 'store']);
    Route::middleware('screen.permission:backup,export')
        ->get('backups/{backup}/download', [BackupController::class, 'download']);
    Route::middleware('screen.permission:backup,delete')
        ->delete('backups/{backup}', [BackupController::class, 'destroy']);

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
    // Stage 24 — finally uses the `export` grant Stage 22 seeded and left idle.
    // Separate from `view` on purpose: reading the trail on screen and walking
    // out with a copy of it are different privileges.
    Route::middleware('screen.permission:audit_log,export')
        ->get('audit-logs/export', [AuditLogController::class, 'export']);

    /*
     * Stage 24 — dashboard KPIs and the reports screen.
     *
     * Both read one service, so the tiles and the exported file always agree.
     * `reports/requests/export` is the only endpoint here behind `export`
     * (R06/R07 by default): everyone may look at the numbers, far fewer may
     * carry them out of the system as a file. The literal `filters` and
     * `requests/export` paths sit above nothing that could shadow them, but
     * are declared first to match the ordering convention elsewhere in here.
     */
    Route::middleware('screen.permission:dashboard,view')
        ->get('dashboard', [DashboardController::class, 'index']);

    Route::middleware('screen.permission:reports,view')->group(function () {
        Route::get('reports/filters', [ReportController::class, 'filters']);
        Route::get('reports/requests', [ReportController::class, 'index']);
    });
    Route::middleware('screen.permission:reports,export')
        ->get('reports/requests/export', [ReportController::class, 'export']);

    /*
     * Stage 23 — notifications. Reads sit behind `notifications,view`, and the
     * two things a user can change (marking their own rows read, their own
     * channel preferences) behind `notifications,edit` — which is why
     * ScreenRolePermissionSeeder grants that action to every role: a bell you
     * cannot dismiss is not an administrative capability.
     *
     * Every one of these is scoped to the caller inside the controller, so the
     * permission decides whether the bell works, never whose notifications it
     * shows. Literal paths (unread-count, settings, read-all) are declared
     * before the {notification} wildcard so it can't swallow them.
     */
    Route::middleware('screen.permission:notifications,view')->group(function () {
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('notifications/settings', [NotificationController::class, 'settings']);
        Route::get('notifications', [NotificationController::class, 'index']);
    });
    Route::middleware('screen.permission:notifications,edit')->group(function () {
        Route::put('notifications/settings', [NotificationController::class, 'updateSettings']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    });

    /*
     * Stage 58, Track J — appeals (تظلمات) against an already-decided
     * request. A separate top-level resource, never nested under `requests`
     * — see STAGE_PLAN.md Track J's intro for why an appeal is never a
     * RequestType. No workflow logic yet (Stages 59+ add the intake
     * validation, jurisdiction gate, legal review, etc.).
     */
    Route::middleware('screen.permission:appeals,view')
        ->get('appeals', [AppealController::class, 'index']);
    Route::middleware('screen.permission:appeals,add')
        ->post('appeals', [AppealController::class, 'store']);

    // Stage 59 — supporting documents, uploaded as follow-up calls once the
    // appeal exists (same "create the parent, then attach" flow Stage 12/13
    // established for requests). Scoped to the appeal's own appellant or
    // R08 inside the controller, not RequestVisibility.
    Route::middleware('screen.permission:appeals,add')
        ->post('appeals/{appeal}/attachments', [AppealAttachmentController::class, 'store']);
    Route::middleware('screen.permission:appeals,view')
        ->get('appeals/{appeal}/attachments/{attachment}/preview', [AppealAttachmentController::class, 'preview'])
        ->name('appeals.attachments.preview');

    // Stage 60 — the formal-verification gate (صفة المتظلم / القرار محل
    // التظلم / المواعيد القانونية / عدم التكرار). One-shot: only an appeal
    // still at `submitted` can be verified (enforced in the controller).
    Route::middleware('screen.permission:appeals,edit')
        ->post('appeals/{appeal}/verify', [AppealController::class, 'verify']);

    // Stage 62 — Art. 77's jurisdiction test (is the committee competent, or
    // does this belong to the mayor / ministry / another org body / a
    // disciplinary board or court?) and Art. 75 point 4's legal-review
    // checklist. Both one-shot; a non-committee jurisdiction answer
    // terminates the appeal immediately (enforced in the controller).
    Route::middleware('screen.permission:appeals,edit')
        ->patch('appeals/{appeal}/jurisdiction-test', [AppealController::class, 'recordJurisdictionTest']);
    Route::middleware('screen.permission:appeals,edit')
        ->patch('appeals/{appeal}/legal-review', [AppealController::class, 'recordLegalReview']);

    // Stage 61 — the assembled original-matter dossier (memo/minutes/
    // decision/notification evidence/appeal documents). Per-appeal
    // visibility is Appeal::isVisibleTo(), not a coarser screen permission.
    Route::middleware('screen.permission:appeals,view')
        ->get('appeals/{appeal}/file', [AppealController::class, 'file']);
});
