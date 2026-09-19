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
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\MeetingDiscussionNoteController;
use App\Http\Controllers\Api\MeetingMinutesController;
use App\Http\Controllers\Api\MeetingOutputsController;
use App\Http\Controllers\Api\MeetingReadinessController;
use App\Http\Controllers\Api\MeetingsDashboardController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\PresentationMemoController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\RequestDraftController;
use App\Http\Controllers\Api\RequestLegalReviewController;
use App\Http\Controllers\Api\RequestLifecycleController;
use App\Http\Controllers\Api\RequestTrackingController;
use App\Http\Controllers\Api\RequestTypeController;
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

/**
 * The maintenance console's one-time bootstrap — the only unauthenticated
 * endpoint in this system that can change the database.
 *
 * It exists because of a real chicken-and-egg on a host with no SSH: the
 * console needs its own migration and screen row before it can be opened, and
 * running migrations is the console's own job. Something has to break that
 * loop from outside, and there is no shell to do it from.
 *
 * Four things keep it narrow, and none of them should be relaxed:
 *   - it answers only when MAINTENANCE_BOOTSTRAP_TOKEN is set to at least 24
 *     characters and the caller supplies it exactly (hash_equals);
 *   - it 404s on every failure, so its existence is not confirmed to anyone
 *     without the token (DevTestUserController's own reasoning);
 *   - it SELF-DISABLES the moment the console becomes reachable through the
 *     ordinary authenticated screen, so there is no cleanup step to forget;
 *   - it does one fixed job — migrate, seed the screen row, grant it to R08 —
 *     and takes no parameters describing what to run.
 *
 * GET renders a confirmation page and POST performs it: an administrator with
 * no shell has no curl either, so this has to work from a browser's URL bar.
 * Declared before the `maintenance/*` authenticated routes further down, which
 * live behind auth:sanctum and could not shadow it in any case.
 */
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/maintenance/bootstrap', [MaintenanceController::class, 'bootstrapForm']);
    Route::post('/maintenance/bootstrap', [MaintenanceController::class, 'bootstrap']);
});

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
     * Request types (أنواع الطلبات).
     *
     * The catalogue behind the intake type picker, and behind three rules
     * other stages read: the SLA that becomes a request's due_date (Stage
     * 17), the decision grade forcing ministry escalation (Stage 18) and
     * [D] Appendix 57's document matrix (Stage 72). Seeded since Stage 53
     * with no way to maintain it; this is that screen.
     *
     * Same shape as departments, for the same reason — master data, so
     * per-verb gating rather than a bare apiResource(), and toggle-active
     * declared BEFORE the {requestType} wildcard so it can't be shadowed.
     */
    Route::middleware('screen.permission:request_types,view')
        ->get('request-types', [RequestTypeController::class, 'index']);
    Route::middleware('screen.permission:request_types,add')
        ->post('request-types', [RequestTypeController::class, 'store']);
    Route::middleware('screen.permission:request_types,edit')->group(function () {
        Route::patch('request-types/{requestType}/toggle-active', [RequestTypeController::class, 'toggleActive']);
        Route::put('request-types/{requestType}', [RequestTypeController::class, 'update']);
    });
    Route::middleware('screen.permission:request_types,delete')
        ->delete('request-types/{requestType}', [RequestTypeController::class, 'destroy']);

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
    // Stage 83 — [D] Appendix 16's own search, so the intake screen can show
    // an existing open file before the submitter fills a form the server would
    // refuse. A literal path, registered here rather than beside the rest of
    // Stage 83's routes so it precedes the `requests/{requestRecord}` wildcard.
    Route::middleware('screen.permission:request_intake,view')
        ->get('requests/duplicate-check', [RequestLifecycleController::class, 'duplicateCheck']);
    Route::middleware('screen.permission:request_intake,add')
        ->post('requests', [RequestController::class, 'store']);

    /*
     * Stage 89 — «متابعة طلباتي». Its own path prefix rather than a `mine`
     * filter on `requests`, so the screen can be gated by its own screen code
     * per AGENTS.md's convention — the Stage 32 committee-candidates worklist
     * is the same shape: a scoped read over `requests` with its own controller
     * and its own grant. No ordering hazard with the wildcards below, since
     * nothing else claims the `my-requests` prefix.
     */
    Route::middleware('screen.permission:request_tracking,view')->group(function () {
        Route::get('my-requests', [RequestTrackingController::class, 'index']);
        Route::get('my-requests/{requestRecord}', [RequestTrackingController::class, 'show']);
    });

    /*
     * Stage 88 — an intake that can be put down and picked up.
     *
     * Literal `requests/drafts` paths, registered here so they precede the
     * `requests/{requestRecord}` wildcard below — otherwise the model binding
     * would try to resolve a request numbered "drafts".
     *
     * All of them ride `request_intake,edit`: the grant this stage found
     * seeded (R01/R02/R05) with nothing consuming it. Creating rides `edit`
     * too rather than `add`, because a draft somebody can create and never
     * update is worse than no draft at all — and because drafts are an added
     * capability, not a new requirement, so the roles holding only `add`
     * (R03/R04/R06) keep filing exactly as they do today.
     */
    Route::middleware('screen.permission:request_intake,edit')->group(function (): void {
        Route::get('requests/drafts', [RequestDraftController::class, 'index']);
        Route::post('requests/drafts', [RequestDraftController::class, 'store']);
        Route::get('requests/drafts/{draft}', [RequestDraftController::class, 'show']);
        Route::put('requests/drafts/{draft}', [RequestDraftController::class, 'update']);
        Route::delete('requests/drafts/{draft}', [RequestDraftController::class, 'destroy']);

        Route::post('requests/drafts/{draft}/attachments', [RequestDraftController::class, 'storeAttachment']);
        Route::patch(
            'requests/drafts/{draft}/attachments/{draftAttachment}',
            [RequestDraftController::class, 'updateAttachment'],
        );
        Route::delete(
            'requests/drafts/{draft}/attachments/{draftAttachment}',
            [RequestDraftController::class, 'destroyAttachment'],
        );
        // Named, because RequestDraftAttachmentResource builds the review
        // step's preview link from it — the same way an attachment's own
        // preview route is named for AttachmentResource.
        Route::get(
            'requests/drafts/{draft}/attachments/{draftAttachment}/preview',
            [RequestDraftController::class, 'previewAttachment'],
        )->name('requests.drafts.attachments.preview');
    });

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

    // Stage 91 — what the upload form has to ask about one more document:
    // this request type's own [D] Appendix 57 matrix, keyed, plus which rows
    // the file already covers and which mandatory ones are still outstanding.
    // Rides the same grant as the upload it renders, since it exists for that
    // form and shows nothing the request workspace does not already show.
    Route::middleware('screen.permission:notes_attachments,add')
        ->get('requests/{requestRecord}/document-options', [AttachmentController::class, 'documentOptions']);

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

    // Stage 78 — [D] Appendix 63's بوابة 1 (قبل القيد). The other half of the
    // same gate the jurisdiction test above already guards: Art. 45 answers
    // "is this the committee's business?", this answers Appendix 20's "هل
    // الوقائع والوثائق صحيحة ومكتملة؟". Same actor, same moment, same grant.
    Route::middleware('screen.permission:notes_attachments,edit')
        ->patch('requests/{requestRecord}/intake-gate', [RequestController::class, 'recordIntakeGate']);

    // Stage 66, Track J — [D] Arts. 34–37/78–79's re-presentation path for a
    // concluded request, independent of any Appeal. Rides the same
    // appeals,edit grant (R02 + R08) Track J's other post-decision
    // reconsideration actions use on this model (see RequestController::
    // reopen()'s own docblock for why RequestVisibility isn't checked here).
    Route::middleware('screen.permission:appeals,edit')
        ->patch('requests/{requestRecord}/reopen', [RequestController::class, 'reopen']);

    // Stage 75 — [D] Art. 37's الإقفال. Rides `meeting_outputs,edit`, the
    // grant that already owned the one existing closure action (Stage 37/69):
    // that screen's declared domain is following a decision through execution
    // and close, and gating the second and third of Art. 37's final paths
    // differently would put two closure actions behind two permissions.
    // Appendix 47 addresses closure to المقرر ("لا يغلق المقرر أي معاملة
    // إلا بعد…"), which is why that grant gained R02 alongside R03.
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->patch('requests/{requestRecord}/close', [RequestController::class, 'close']);

    /*
     * Stage 77 — [D] Art. 94 / Appendix 34: the approving body sends the file
     * back, and Art. 94 requires a formal إجراء إعادة معالجة recording both the
     * reason and the action taken — never a quiet edit of an approved محضر.
     * Two endpoints because the article names two things and nobody knows the
     * second at the moment of the first.
     *
     * Same `meeting_outputs,edit` grant (R02 + R03) closure and execution
     * already ride: that screen's declared domain is following a decision
     * through approval, execution and close, and Art. 30 addresses this
     * register to مقرر اللجنة, which is R02's own role name.
     */
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->patch('requests/{requestRecord}/approval-return', [RequestController::class, 'recordApprovalReturn']);
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->patch('requests/{requestRecord}/approval-return/resolve', [RequestController::class, 'resolveApprovalReturn']);

    /*
     * Stage 80 — [D] Art. 30's سجل الإحالات للاعتماد, i.e. Art. 98's register
     * 7. Two endpoints for the same reason the return register above has two:
     * the article records an outward moment (تاريخ الإحالة · رقم كتاب الإحالة ·
     * الجهة المحال إليها) and an inward one (تاريخ ورود النتيجة · رقم قرار
     * الاعتماد · الملاحظات) that nobody can answer at the same time.
     *
     * Same `meeting_outputs,edit` grant (R02 + R03), because Art. 30 addresses
     * the register to مقرر اللجنة. Deliberately NOT a gate on the approval
     * transition: the article says "ويسجل", not "ولا يحال قبل".
     */
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->post('requests/{requestRecord}/approval-referrals', [RequestController::class, 'recordApprovalReferral']);
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->patch('requests/{requestRecord}/approval-referrals/{referral}/result', [RequestController::class, 'recordApprovalReferralResult']);

    /*
     * Stage 78 — [D] Art. 103's قائمة فحص سلامة القرار, verified "قبل إحالة
     * النتيجة للتنفيذ", and Art. 105's إيقاف إجرائي.
     *
     * All three ride the same `meeting_outputs,edit` grant (R02 + R03) the
     * closure, execution and approval-return registers already use — that
     * screen's declared domain is following a decision through approval,
     * execution and close, which is exactly the stretch of a file's life
     * Arts. 103 and 105 govern.
     *
     * The soundness checklist is deliberately NOT on R07's own
     * `final_approval` grant: the file is prepared by the مقرر who holds it
     * and referred to execution by the authority who approves it, so
     * preparation and decision stay in different hands.
     */
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->patch('requests/{requestRecord}/execution-soundness', [RequestController::class, 'recordExecutionSoundness']);
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->patch('requests/{requestRecord}/suspend', [RequestController::class, 'suspend']);
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->patch('requests/{requestRecord}/suspend/lift', [RequestController::class, 'liftSuspension']);

    /*
     * Stage 83 — [D]'s lifecycle edge cases: document conflicts (Appendix 30),
     * document validity (Appendix 31), material-error corrections (Appendix
     * 53), the six special cases (Appendix 60), and withdrawal before and
     * after a decision (Appendices 68/69).
     *
     * Reading rides `request_details,view` and is additionally scoped by
     * RequestVisibility inside the controller, so the grant decides whether the
     * screen works and never whose file is visible. Recording and determining
     * ride `meeting_outputs,edit` (R02 المقرر + R03) — the same grant Stages
     * 75/76/77/80 already use for the rapporteur's own determinations about a
     * file, and the audience Appendices 30/53/60 address.
     *
     * Filing a withdrawal is the ONE exception, and deliberately: Appendix 68's
     * first step is the employee putting a written request on their own file,
     * so it rides `notes_attachments,add` (R01-R05) with the controller
     * additionally requiring the actor to be the request's own creator.
     *
     * The literal `requests/duplicate-check` path is registered before the
     * `{requestRecord}` wildcard routes above for the same reason
     * `meetings/department-options` is — a wildcard would otherwise swallow it.
     */
    Route::middleware('screen.permission:request_details,view')
        ->get('requests/{requestRecord}/lifecycle', [RequestLifecycleController::class, 'index']);

    Route::middleware('screen.permission:meeting_outputs,edit')->group(function () {
        Route::post('requests/{requestRecord}/document-conflicts', [RequestLifecycleController::class, 'storeDocumentConflict']);
        Route::patch('requests/{requestRecord}/document-conflicts/{conflict}/resolve', [RequestLifecycleController::class, 'resolveDocumentConflict']);
        Route::patch('requests/{requestRecord}/attachments/{attachment}/validity', [RequestLifecycleController::class, 'recordDocumentValidity']);
        Route::post('requests/{requestRecord}/special-cases', [RequestLifecycleController::class, 'storeSpecialCase']);
        Route::patch('requests/{requestRecord}/special-cases/{specialCase}/resolve', [RequestLifecycleController::class, 'resolveSpecialCase']);
        Route::post('requests/{requestRecord}/corrections', [RequestLifecycleController::class, 'storeCorrection']);
        Route::patch('requests/{requestRecord}/corrections/{correction}/approve', [RequestLifecycleController::class, 'approveCorrection']);
        Route::patch('requests/{requestRecord}/withdrawals/{withdrawal}/determine', [RequestLifecycleController::class, 'determineWithdrawal']);
    });

    Route::middleware('screen.permission:notes_attachments,add')
        ->post('requests/{requestRecord}/withdrawals', [RequestLifecycleController::class, 'storeWithdrawal']);

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
     * screen's own grants (`add` gates nominate, `edit` gates the other three)
     * rather than `meetings` — see CommitteeCandidateController's
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

    /*
     * Stage 68 — [D] Art. 21 / [E] stage 08's pre-meeting legal review.
     * The queue path is declared before the `{requestRecord}` ones so the
     * model wildcard cannot swallow the literal segment.
     *
     * The two write tiers are NOT interchangeable and split by Appendix 6's
     * RACI row: `add` is the legal member recording a verdict (R11 only),
     * `edit` is the rapporteur handing a file over (R02/R09).
     */
    Route::middleware('screen.permission:legal_review,view')->group(function () {
        Route::get('legal-reviews', [RequestLegalReviewController::class, 'index']);
        Route::get('requests/{requestRecord}/legal-reviews', [RequestLegalReviewController::class, 'show']);
    });
    Route::middleware('screen.permission:legal_review,edit')
        ->post('requests/{requestRecord}/legal-reviews/request', [RequestLegalReviewController::class, 'requestReview']);
    Route::middleware('screen.permission:legal_review,add')
        ->post('requests/{requestRecord}/legal-reviews', [RequestLegalReviewController::class, 'store']);

    // Stage 32 — the meetings-unit command dashboard. A literal path declared
    // before the `meetings/{meeting}` wildcard below, same reason
    // department-options is.
    Route::middleware('screen.permission:meetings_dashboard,view')
        ->get('meetings/dashboard', [MeetingsDashboardController::class, 'index']);

    Route::middleware('screen.permission:meeting_agenda,view')
        ->get('meetings/department-options', [MeetingController::class, 'departmentOptions']);
    // Stage 63 — the appeal picker for the agenda builder's `appeal` item
    // form; a literal path declared before the `meetings/{meeting}`
    // wildcard, same reason department-options is.
    Route::middleware('screen.permission:meeting_agenda,view')
        ->get('meetings/appeal-options', [MeetingController::class, 'appealOptions']);
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
     * permissions rather than `meetings` — building the agenda is that
     * screen's declared domain, not the meeting record's. The two screens'
     * grants were identical when this moved; they are not any more (Stage 84
     * seats R02/R03/R09 here and R02/R03 on `meetings`), so read the seeder
     * rather than assuming they track each other.
     */
    Route::middleware('screen.permission:meeting_agenda,view')
        ->get('meetings/{meeting}/agenda/stats', [MeetingController::class, 'agendaStats']);
    // Stage 82 — [D] Art. 83's ordering and Appendix 24's per-item profile.
    // Registered before the `{agendaItem}` wildcards below for the same
    // reason `meetings/department-options` precedes `meetings/{meeting}`.
    Route::middleware('screen.permission:meeting_agenda,view')
        ->get('meetings/{meeting}/agenda/ordering', [MeetingController::class, 'agendaOrdering']);
    Route::middleware('screen.permission:meeting_agenda,edit')->group(function () {
        Route::post('meetings/{meeting}/agenda', [MeetingController::class, 'addAgendaItem']);
        Route::put('meetings/{meeting}/agenda/reorder', [MeetingController::class, 'reorderAgenda']);
        Route::post('meetings/{meeting}/agenda/apply-order', [MeetingController::class, 'applyAgendaOrder']);
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
    // Stage 82 — النموذج 11's card, i.e. [D] Art. 85's per-item sequence.
    // Read by everyone who can watch the sitting; ticked by the chair, the
    // same split the runner's own state controls already use.
    Route::middleware('screen.permission:meeting_live,view')
        ->get('meetings/{meeting}/agenda/{agendaItem}/study-sequence', [MeetingController::class, 'studySequence']);
    Route::middleware('screen.permission:meeting_live,edit')
        ->patch('meetings/{meeting}/agenda/{agendaItem}/study-sequence', [MeetingController::class, 'updateStudySequence']);
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
     * broad like the screen; the `edit` grant records that an in-execution
     * request's effect has been carried out.
     *
     * Stage 75 — Art. 38's code 20 is no longer reachable here: closure moved
     * to PATCH requests/{requestRecord}/close, since two of Art. 37's four
     * final paths close requests that never reached an agenda.
     */
    Route::middleware('screen.permission:meeting_outputs,view')
        ->get('meetings/{meeting}/outputs', [MeetingOutputsController::class, 'show']);
    Route::middleware('screen.permission:meeting_outputs,edit')
        ->post('meetings/{meeting}/outputs/{agendaItem}/execute', [MeetingOutputsController::class, 'execute']);

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
     * Stage 84 — `export` is the R06/R07 tier every other export in the app
     * uses (registers, reports, audit_log), all of which share this
     * controller's own ReportDocument/ReportExporter path. It was R08-only
     * purely because the grant key was absent, which is not a policy: `view`
     * is already '*', so both roles read these rows — vote tallies included —
     * on screen today, and register 6 already exports the same population to
     * them. Reading on screen and carrying a file out remain different
     * privileges; everyone else still cannot export.
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
     * The maintenance console — deployment operations for a host with no
     * shell (cPanel shared hosting), where there is otherwise no way to run
     * `php artisan migrate` after uploading a release.
     *
     * R08-only through the `maintenance` screen's empty DEFAULTS entry, same
     * as `backup` and `settings` above it. The action split:
     *
     *   view   diagnostics, the command catalogue, and the run history
     *   add    run a command
     *   delete clear the history
     *
     * `approve` is NOT a route here on purpose: it gates the destructive
     * commands (migrate:fresh, migrate:rollback), which arrive at the same
     * `add` endpoint as every other command, so the check belongs in the
     * controller where the catalogue entry is known — see
     * MaintenanceController::guardDestructive().
     *
     * `maintenance/runs` is declared before `maintenance/runs/{maintenanceRun}`
     * for readability; the wildcard could not shadow it either way, but the
     * ordering matches the convention used throughout this file.
     */
    Route::middleware('screen.permission:maintenance,view')->group(function () {
        Route::get('maintenance', [MaintenanceController::class, 'index']);
        Route::get('maintenance/runs', [MaintenanceController::class, 'runs']);
        Route::get('maintenance/runs/{maintenanceRun}', [MaintenanceController::class, 'show']);
    });
    Route::middleware('screen.permission:maintenance,add')
        ->post('maintenance/run', [MaintenanceController::class, 'run']);
    Route::middleware('screen.permission:maintenance,delete')
        ->delete('maintenance/runs', [MaintenanceController::class, 'clearHistory']);

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
     * Stage 81 — [D] Art. 106's twelve performance indicators, Appendix 10's
     * ten early-warning conditions, and the three periodic reports (Art. 107,
     * Appendices 39 and 40).
     *
     * On the `reports` screen rather than one of their own: these describe the
     * same population that screen already lists to everyone, so a new screen
     * would be a new grant for a narrower view of already-visible data. The
     * literal `reports/performance/...` paths are declared before the
     * `{report}` wildcard for the ordering reason the registers block records.
     */
    Route::middleware('screen.permission:reports,view')->group(function () {
        Route::get('reports/performance/indicators', [PerformanceController::class, 'indicators']);
        Route::get('reports/performance/warnings', [PerformanceController::class, 'warnings']);
        Route::get('reports/performance/periodic/{report}', [PerformanceController::class, 'report']);
    });
    Route::middleware('screen.permission:reports,export')
        ->get('reports/performance/periodic/{report}/export', [PerformanceController::class, 'exportReport']);

    /*
     * Stage 80 — [D] Art. 98's twelve official registers.
     *
     * `registers` (the catalogue) is declared before `registers/{register}` so
     * the literal path can never be read as a register code, per the ordering
     * convention elsewhere in here. The `{register}/export` path is a segment
     * deeper and cannot collide either way.
     *
     * Grants mirror `reports` exactly: everyone may read a register, far fewer
     * may carry one out of the system as a file. That is the same population
     * the reports screen already lists to everyone, so nothing here widens who
     * can see whose file.
     */
    Route::middleware('screen.permission:registers,view')->group(function () {
        Route::get('registers', [RegisterController::class, 'catalog']);
        Route::get('registers/{register}', [RegisterController::class, 'index']);
    });
    Route::middleware('screen.permission:registers,export')
        ->get('registers/{register}/export', [RegisterController::class, 'export']);

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

    // Stage 64 — the stages an appeal_redo outcome may target. A literal
    // path, declared before the {appeal} wildcard routes below so it can
    // never be shadowed (same ordering convention as departments'
    // toggle-active route).
    Route::middleware('screen.permission:appeals,edit')
        ->get('appeals/redo-stage-options', [AppealController::class, 'redoStageOptions']);

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

    // Stage 64 — executes Stage 63's already-recorded committee outcome
    // against the original Request.
    Route::middleware('screen.permission:appeals,edit')
        ->patch('appeals/{appeal}/execute-outcome', [AppealController::class, 'executeOutcome']);

    // Stage 65 — notifies the appellant of the final result and records the
    // closure. Releases Appeal::openAgainst()'s hold on the original
    // request's own closure (Track J intro, scope decision 3).
    Route::middleware('screen.permission:appeals,edit')
        ->patch('appeals/{appeal}/close', [AppealController::class, 'close']);

    // Stage 66 — [D] Arts. 78–79's non-reopening rule: a closed appeal may
    // only be reopened for one of ReopenReasonCatalog's enumerated reasons.
    Route::middleware('screen.permission:appeals,edit')
        ->patch('appeals/{appeal}/reopen', [AppealController::class, 'reopen']);
});
