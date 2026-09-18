<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use Illuminate\Database\Seeder;

/**
 * Builds the starting permission matrix: every screen x every role
 * (33 x 11 rows), each with seven action flags.
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
        'request_intake' => ['view' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'add' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'edit' => ['R01', 'R02', 'R05']],
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
        'notes_attachments' => ['view' => '*', 'add' => ['R01', 'R02', 'R03', 'R04', 'R05'], 'edit' => ['R02']],

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
        // Diagram-alignment redesign (see AGENT_NOTES.md): R09 (Committee
        // Secretary) receives the file once study is complete and prepares
        // the committee's agenda — the same "add"/"edit" reach R03 (head) and
        // R04 (member) already hold on these three screens, since agenda
        // placement is gated by this screen permission inside
        // CommitteeStatusService, not by a fixed WorkflowService role.
        'meetings_dashboard' => ['view' => '*', 'add' => ['R03', 'R04', 'R09'], 'edit' => ['R03', 'R09'], 'print' => '*'],
        // Stage 84 — R02 in, R04 out. [D] Appendix 45 gives المقرر القيد ·
        // الفحص · المتابعة, which is precisely this worklist (nominate, defer,
        // return to study, request completion), while Art. 13 (أ) limits
        // ordinary members to studying, discussing and voting.
        'committee_candidates' => ['view' => '*', 'add' => ['R02', 'R03', 'R09'], 'edit' => ['R02', 'R03', 'R09'], 'print' => '*'],
        // Stage 68 — [D] Art. 21's pre-meeting legal review. The two write
        // tiers split by Appendix 6's RACI row for المراجعة القانونية, where
        // the legal member is مسؤول and the rapporteur only منسق:
        //   add  = record a review (create a RequestLegalReview row) — R11 only,
        //          because Art. 14 (ب) makes the legal opinion the legal
        //          member's own act.
        //   edit = dispatch a file TO review — the coordinating act, so the
        //          rapporteur roles (R02 case officer, R09 committee secretary).
        // `view` is '*' because Art. 21 requires the recorded opinion to be
        // readable by the committee's own members at study time.
        'legal_review' => ['view' => '*', 'add' => ['R11'], 'edit' => ['R02', 'R09'], 'print' => '*'],
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
        // مراجعة واعتماد جدول الأعمال) and R09 keeps them as this system's own
        // agenda secretary.
        'meeting_agenda' => ['view' => '*', 'add' => ['R02', 'R03', 'R09'], 'edit' => ['R02', 'R03', 'R09'], 'print' => '*'],
        'meeting_readiness' => ['view' => '*', 'add' => ['R03', 'R04'], 'edit' => ['R03'], 'print' => '*'],
        // Stage 84 — R02 joins `add` (the live discussion feed): Art. 15 (أ)
        // ثانيًا 5 makes تدوين المناقشات المقرر's own duty. `edit` — advancing
        // the item state and ticking Art. 85's study sequence — stays R03,
        // since Art. 12 (أ) 9-10 give إقفال المناقشة and طرح الموضوعات
        // للتصويت to the chair.
        'meeting_live' => ['view' => '*', 'add' => ['R02', 'R03', 'R04'], 'edit' => ['R03'], 'print' => '*'],
        // Stage 84 — two changes here.
        //
        // `approve` (recording the tallied result) gains R02: Appendix 45
        // lists تسجيل النتيجة among المقرر's own capabilities and Art. 15 (أ)
        // ثانيًا 6 is تسجيل نتيجة التصويت بدقة. The outcome is computed from
        // the votes, so المقرر cannot record one the committee did not reach
        // — the thing Art. 16 (أ) 4 actually forbids.
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
        'decisions' => ['view' => '*', 'add' => ['R03', 'R04'], 'approve' => ['R02', 'R03'], 'print' => '*', 'export' => ['R06', 'R07']],
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
        'meeting_outputs' => ['view' => '*', 'add' => ['R03', 'R04'], 'edit' => ['R02', 'R03'], 'print' => '*'],

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
