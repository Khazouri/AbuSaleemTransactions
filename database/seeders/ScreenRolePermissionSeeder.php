<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use Illuminate\Database\Seeder;

/**
 * Builds the starting permission matrix: one row per screen per role, each with
 * seven action flags. (Phrased without the figures on purpose — both counts
 * have drifted here before, and the seeder derives them rather than asserting
 * them: it loops over whatever ScreenSeeder and RoleSeeder actually produced.)
 *
 * How the rules below are applied:
 *   - R08 (System Admin) is granted every action on every screen.
 *   - Every other role starts with NOTHING and is granted only what the
 *     DEFAULTS table lists — least privilege by construction.
 *   - '*' means "all roles".
 *
 * The shape of the data is deliberate. Notice the approval screens: each names
 * exactly one role, so اعتماد وزارة الحكم المحلي is visible and actionable only
 * to R06. That is the segregation of duties the whole approval chain rests on.
 *
 * This is a STARTING POINT, not the final configuration — Stage 8 gives admins
 * a grid to edit these rows, and Stage 9 enforces whatever they end up as.
 *
 * Runs last: needs both roles and screens to exist.
 */
class ScreenRolePermissionSeeder extends Seeder
{
    /**
     * screen code => [action => roles allowed]
     * Any action not listed for a screen stays false for every role but R08.
     */
    private const DEFAULTS = [
        // Everyone needs the dashboard and the request list.
        'dashboard' => ['view' => '*', 'print' => '*'],
        'requests' => ['view' => '*', 'print' => '*', 'export' => ['R06', 'R07']],

        // Intake: the roles that actually register incoming paperwork.
        // R07 is absent — the dean approves, they don't do data entry.
        // Stage 95 — `approve` is this screen's previously unused tier and is
        // what «filing on someone else's behalf» rides: naming a صاحب العلاقة
        // other than yourself is an act of authority over another employee's
        // file, so it is a grant rather than something every filer may do.
        // Exactly the two roles besides R01 that already hold `edit` here —
        // the pair Stage 88's own comment calls the roles that actually
        // compose intakes (R02 المقرر, R05 مدير إدارة الشؤون الإدارية). R01 is
        // the employee filing for themselves, and R03/R04/R06 approve rather
        // than file. Deliberately narrow: widening it is one line here, and
        // R12 is left out because it holds no `add` at all, so granting it
        // on-behalf without filing would be incoherent.
        'request_intake' => ['view' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'add' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'edit' => ['R01', 'R02', 'R05'], 'approve' => ['R02', 'R05']],

        // Stage 89 — «متابعة طلباتي». Deliberately request_intake's own view
        // list rather than the '*' that requests/request_details/appeals carry.
        // Those three are '*' because their controllers scope per row while the
        // POPULATION is shared; this screen's population is "the files I
        // filed", so the roles that can file are exactly the roles with
        // something to track. R07 is absent for the same reason it is absent
        // from intake above — the dean approves, they don't file — and an
        // always-empty sidebar entry is noise, not access. Nothing is hidden by
        // this: requests and request_details below stay '*'.
        // Every role has tasks, and the contents are per-actor by
        // construction — PendingTaskCollector emits a source only when the
        // caller holds the grant to act on it — so narrowing this would only
        // produce an empty sidebar entry for somebody.
        'my_tasks' => ['view' => '*'],
        'request_tracking' => ['view' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'print' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06']],

        'request_details' => ['view' => '*', 'print' => '*', 'export' => '*'],

        // Notes/attachments: broad read, narrower write.
        //
        // Stage 84 — R01 dropped from `edit`. [D] Appendix 45's صلاحية الموظف
        // is "إنشاء طلب · رفع مستند · استكمال نقص · متابعة الحالة المسموحة":
        // uploading is the employee's own listed capability, editing is not.
        // This tier also gates Art. 45's jurisdiction test and Appendix 63's
        // بوابة 1 — the completeness attestation the قيد hangs on — which
        // Appendix 6's RACI gives to مقرر اللجنة with the الموظف column left
        // empty, and which Appendix 19 forbids the submitter from signing off
        // on ("لا يكون مقدم الطلب هو معتمد الطلب"). R01 loses Stage 47's
        // financial-impact correction as a consequence; R02 keeps it.
        //
        // Stage 87 — R12 (HR Manager) added: [F] names إدارة الموارد البشرية
        // as co-owner of the study at `observations`, and RequestVisibility's
        // new bounded clause only lets R12 open a request there — this grant
        // is what lets them actually contribute once they can. Bounded the
        // same way R05's own membership here is: a screen-level capability,
        // narrowed in practice by which requests the actor can even see.
        //
        // 2026-09-20 — R09/R10 were added here on the bound that all three of
        // R12/R10/R09 held a `register` row at `receive_and_register`, so a
        // receiving body could otherwise accept a file and attach nothing to
        // it ([E] stage 03: «تستكمل ما يقع ضمن اختصاصها من بيانات وإفادات»).
        // Stage 96 removed them again with the bound itself: R12 is now the
        // only receiving body, because [D] Appendix 6 has no column for the
        // other two.
        'notes_attachments' => ['view' => '*', 'add' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R12'], 'edit' => ['R02']],

        // Stage 58 — appeals against an already-decided request. `view` is
        // broad (like `requests`): the controller scopes the query to the
        // caller's own appeals unless they're R08 or hold `edit` here, so
        // this permission only decides whether the screen is reachable at
        // all, not whose rows show up. `add` is deliberately R01-only — per
        // STAGE_PLAN.md Track J's intro, filing an appeal is an
        // employee-facing action, not committee administration.
        // Stage 60 — `edit` is the formal-verification action
        // (AppealController::verify()), granted to R02 (المقرر — قسم شؤون
        // الموظفين's reviewer role), the first staff grant on this screen.
        // Widen further once a later Track J stage (legal review, committee
        // presentation) needs its own staff role here too.
        'appeals' => ['view' => '*', 'add' => ['R01'], 'edit' => ['R02'], 'print' => '*'],

        // Committee work belongs to the committee roles (R03 head, R04 member).
        // Stage 28's 7 new meetings-unit screens start with the same shape
        // as `meetings` itself — they're empty navigation shells with no
        // real actions yet, so exact parity is the correct default. Later
        // Track H stages (29-37) should tighten these per-screen once real
        // actions land (e.g. `meeting_live` almost certainly wants narrower
        // gating once it does something).
        // Stage 96 — R09 (Committee Secretary) is out of all four screens the
        // diagram-alignment redesign gave it: [D] Appendix 6 has no أمين سر
        // اللجنة column, and every duty it held here is the appendix's own
        // مقرر اللجنة (R02), which already holds both tiers on
        // `committee_candidates` and `meeting_agenda`. R02 is deliberately
        // NOT added to `meetings_dashboard`: its add/edit grants gate no route
        // at all (only `view` appears in routes/api.php), so a role there
        // would be decoration.
        'meetings_dashboard' => ['view' => '*', 'add' => ['R03', 'R04'], 'edit' => ['R03'], 'print' => '*'],
        // Stage 84 — R02 in, R04 out. [D] Appendix 45 gives المقرر القيد ·
        // الفحص · المتابعة, which is precisely this worklist (nominate, defer,
        // return to study, request completion), while Art. 13 (أ) limits
        // ordinary members to studying, discussing and voting.
        'committee_candidates' => ['view' => '*', 'add' => ['R02', 'R03'], 'edit' => ['R02', 'R03'], 'print' => '*'],
        // Stage 68 — [D] Art. 21's pre-meeting legal review. The two write
        // tiers split by Appendix 6's RACI row for المراجعة القانونية, where
        // the legal member is مسؤول and the rapporteur only منسق:
        //   add  = record a review (create a RequestLegalReview row) — R11 only,
        //          because Art. 14 (ب) makes the legal opinion the legal
        //          member's own act.
        //   edit = dispatch a file TO review — the coordinating act, so the
        //          rapporteur (R02). Stage 96 dropped R09 from both tiers.
        // Membership gate — `view` narrowed from '*' to the three roles that
        // act on this queue. Like meeting_outputs, this screen is exempt from
        // the committee-membership gate: Art. 21's review happens BEFORE a file
        // reaches the committee and is a queue of requests rather than of
        // sittings, so gating it on a seat could stall a mandatory step on a
        // roster mistake. Art. 21's own requirement that committee members can
        // read the recorded opinion at study time is still met — it is on the
        // request's own detail screen, whose `view` remains '*'.
        'legal_review' => ['view' => ['R02', 'R11'], 'add' => ['R11'], 'edit' => ['R02'], 'print' => '*'],
        // Stage 84 — R02 in, R04 out. [D] Appendix 45 gives المقرر إنشاء
        // الاجتماع outright, and Art. 15 (أ) أولًا 12-13 / ثانيًا 1 give them
        // توجيه الدعوات and تسجيل حضور الأعضاء, which is what `edit` gates
        // here. Art. 13 (أ) gives ordinary members no convening role at all;
        // الدعوة is the chair's under Art. 12 (أ) 1, so R03 keeps both tiers.
        // This screen also carries committee CRUD (see routes/api.php), so
        // R02 can maintain the committee record too — Appendix 45 has no
        // committee-formation entry, so that coupling is recorded, not split.
        'meetings' => ['view' => '*', 'add' => ['R02', 'R03'], 'edit' => ['R02', 'R03'], 'print' => '*'],
        // Stage 84 — R02 in, R04 out. Appendix 45 gives المقرر إدارة جدول
        // الأعمال, and Art. 15 (أ) أولًا 10-11 give them إعداد مشروع جدول
        // الأعمال and تجهيز ملفات العرض ومذكرات العرض — the `add` tier here is
        // the presentation memo. The chair keeps both tiers (Art. 12 (أ) 3 is
        // مراجعة واعتماد جدول الأعمال). Stage 96 dropped R09: the appendix
        // gives إدارة جدول الأعمال to المقرر as a single مسؤول.
        'meeting_agenda' => ['view' => '*', 'add' => ['R02', 'R03'], 'edit' => ['R02', 'R03'], 'print' => '*'],
        'meeting_readiness' => ['view' => '*', 'add' => ['R03', 'R04'], 'edit' => ['R03'], 'print' => '*'],
        // Stage 84 — R02 joins `add` (the live discussion feed): Art. 15 (أ)
        // ثانيًا 5 makes تدوين المناقشات المقرر's own duty. `edit` — advancing
        // the item state and ticking Art. 85's study sequence — stays R03,
        // since Art. 12 (أ) 9-10 give إقفال المناقشة and طرح الموضوعات
        // للتصويت to the chair.
        'meeting_live' => ['view' => '*', 'add' => ['R02', 'R03', 'R04'], 'edit' => ['R03'], 'print' => '*'],
        // Stage 84 — `export` joins R06/R07 (below).
        //
        // Stage 97 — `approve` (recording the tallied result) is R03 ALONE
        // again. Stage 84 gave it to R02 on Appendix 45's تسجيل النتيجة, but
        // every committee outcome row in workflow_transitions requires R03, so
        // an R02 passed this 403 gate and then 422'd in WorkflowService — a
        // grant that only ever looked like a capability. Appendix 6 row 10 has
        // المقرر «توثيق» (documentation, which R02 already holds through
        // `meeting_minutes,add`), not the tally. The trade re-opens
        // Appendix 45's تسجيل النتيجة, recorded in compliance-matrix.md.
        //
        // `add` deliberately does NOT gain R02: that tier casts a vote, and
        // Art. 16 (أ) 2 forbids المقرر voting unless قرار التشكيل says
        // otherwise — which is Stage 73's `rapporteur_votes` flag on the
        // committee record, not a permission.
        //
        // `export` joins the R06/R07 tier every other export in the app uses
        // (registers, reports, audit_log), all of which share this screen's
        // own ReportDocument/ReportExporter path. Not a new disclosure:
        // `view` is already '*', so both roles read these rows — vote tallies
        // included — on screen today, and register 6 already exports the same
        // population to them. Art. 102's tally restriction is about صاحب
        // العلاقة, whom Stage 79 excluded from DecisionRecordedNotification.
        'decisions' => ['view' => '*', 'add' => ['R03', 'R04'], 'approve' => ['R03'], 'print' => '*', 'export' => ['R06', 'R07']],
        // Stage 36: `add` covers both generating a draft and casting one's
        // own signature (mirrors `decisions,add` covering vote-casting);
        // `approve` is the head's review decision, same split as `decisions`.
        // Stage 84 — R02 joins `add`. Appendix 45 lists المحاضر among المقرر's
        // capabilities and Art. 15 (أ) ثانيًا 9 / ثالثًا 1 give them إعداد
        // مسودة المحضر and إعداد المحضر بصورته النهائية. R04 stays because
        // this tier also casts a member's own signature (Art. 13 (أ) 15).
        // `approve` stays R03 — Art. 12 (أ) 13 is the chair's own اعتماد.
        'meeting_minutes' => ['view' => '*', 'add' => ['R02', 'R03', 'R04'], 'approve' => ['R03'], 'edit' => ['R03'], 'print' => '*'],
        // Stage 37: everyone may follow live outputs; only the head certifies
        // execution completion through `edit`.
        // Stage 75 — R02 joins `edit`: Appendix 47 addresses closure to المقرر
        // ("لا يغلق المقرر أي معاملة إلا بعد الإجابة بنعم على الآتي"), and R02's
        // RoleSeeder name is literally المقرر. Additive rather than a swap — the
        // source removes nothing from the chair, and this grant also gates the
        // Stage 75 request-closure route.
        // Stage 92 — `edit` still gates the rapporteur/chair-only certifications
        // (Art. 30/103/105's approval-return, execution-soundness and suspension
        // records), but [F] step 10's executing-body question is a different
        // one: who actually carried the decision out. `approve` is that
        // answer's own tier — execute() and close() alone ride it — so R12 (HR
        // Manager, the executing body [D] names most often) can record its own
        // execution and closure without also gaining the other five, unrelated
        // `edit` actions. R02/R03 keep `approve` too, for whichever file some
        // other body executed. Mirrors the decisions.add/decisions.approve
        // split exactly.
        //
        // Membership gate — `view` narrowed from '*' to the three roles that
        // actually act on this screen. It is the one meetings-group screen the
        // committee-membership gate CANNOT hide, because its principal actor
        // R12 (HR Manager) deliberately holds no committee seat: gating it
        // would revoke the execution and closure reach that exists for them.
        // Narrowing `view` instead keeps an ordinary employee out while leaving
        // R12 able to do the job the grant was created for.
        'meeting_outputs' => ['view' => ['R02', 'R03', 'R12'], 'add' => ['R03', 'R04'], 'edit' => ['R02', 'R03'], 'approve' => ['R02', 'R03', 'R12'], 'print' => '*'],

        // One approval screen per authority — single-role by design, so no one
        // can approve at a level that isn't theirs.
        'reviewer_approval' => ['view' => ['R02'], 'approve' => ['R02']],
        'committee_head_approval' => ['view' => ['R03'], 'approve' => ['R03']],
        'admin_manager_approval' => ['view' => ['R05'], 'approve' => ['R05']],
        'ministry_approval' => ['view' => ['R06'], 'approve' => ['R06']],
        // Stage 57 — 'authority_approval' (competent_authority) removed; R07
        // keeps this one checkpoint only. ScreenSeeder deletes the row, so
        // this entry would be dead data if left in.
        'final_approval' => ['view' => ['R07'], 'approve' => ['R07']],

        // Administration: empty array = R08 only.
        'users' => [],
        'departments' => [],
        'request_types' => [],
        'roles_permissions' => [],
        'settings' => [],
        'templates' => [],
        'backup' => [],
        // The maintenance console. Empty, i.e. R08-only on every action — and
        // that includes `approve`, which here means "may run a command that
        // destroys data" (migrate:fresh, migrate:rollback). Granting any of
        // these to another role would hand it the ability to drop the whole
        // database, so widen this only with that consequence in mind.
        'maintenance' => [],

        // Oversight: visible to all, exportable only by the senior roles.
        'reports' => ['view' => '*', 'print' => '*', 'export' => ['R06', 'R07']],
        // Stage 80 — [D] Art. 98's twelve registers. `reports`' shape
        // verbatim: everyone may read a register (it lists the same
        // population that screen already shows to everyone), far fewer may
        // carry one out of the system as a file.
        'registers' => ['view' => '*', 'print' => '*', 'export' => ['R06', 'R07']],
        'audit_log' => ['view' => '*', 'export' => ['R06', 'R07']],
        // Stage 23: `edit` is granted to everyone because on this screen it
        // means "mark my own notifications read / set my own channel
        // preferences" — those endpoints are scoped to the caller, so this is
        // a self-service right, not an administrative one.
        'notifications' => ['view' => '*', 'edit' => '*'],
        'user_guide' => ['view' => '*', 'print' => '*'],
    ];

    /** Maps the short action names used above to the real column names. */
    private const COLUMNS = [
        'view' => 'can_view',
        'add' => 'can_add',
        'edit' => 'can_edit',
        'delete' => 'can_delete',
        'approve' => 'can_approve',
        'print' => 'can_print',
        'export' => 'can_export',
    ];

    public function run(): void
    {
        $roles = Role::all();
        $screens = Screen::all();

        foreach ($screens as $screen) {
            // No entry for this screen means "R08 only".
            $grants = self::DEFAULTS[$screen->code] ?? [];

            foreach ($roles as $role) {
                // The unique(screen_id, role_id) index makes this a safe upsert:
                // re-seeding resets the matrix to these defaults.
                ScreenRolePermission::updateOrCreate(
                    ['screen_id' => $screen->id, 'role_id' => $role->id],
                    $this->flagsFor($role, $grants),
                );
            }
        }
    }

    /**
     * Work out the seven action flags for one role on one screen.
     *
     * @param  array<string, string|array<string>>  $grants  This screen's DEFAULTS entry
     * @return array<string, bool> Column name => allowed
     */
    private function flagsFor(Role $role, array $grants): array
    {
        // System Admin bypasses the table entirely: everything, everywhere.
        if ($role->code === 'R08') {
            return array_fill_keys(array_values(self::COLUMNS), true);
        }

        // Everyone else starts closed, then we open only what's granted.
        $flags = array_fill_keys(array_values(self::COLUMNS), false);

        foreach ($grants as $action => $allowed) {
            // Grant if the rule is '*' (all roles) or names this role's code.
            if ($allowed === '*' || in_array($role->code, (array) $allowed, true)) {
                $flags[self::COLUMNS[$action]] = true;
            }
        }

        return $flags;
    }
}
