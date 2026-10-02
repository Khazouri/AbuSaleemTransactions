# Agent handoff notes

Shared scratchpad between Codex and Claude Code for this repo. See the
"Cross-agent handoff" section in [AGENTS.md](AGENTS.md) for the rules:
newest entry on top, never edited; past 150 KB the oldest entries move
unedited to agent-notes-archive/. Durable facts go in AGENTS.md instead.

**Unfinished or unclear points go in [OPEN_ITEMS.md](OPEN_ITEMS.md).** Any
point left open, deferred, ambiguous, or waiting on a decision — including the
"Open items" of a stage's completion note — must also be added there as its
own entry (what is open, why, what would close it). Check that file at the
start of a session, and mark an item resolved there when you close it.

Format:

```
### YYYY-MM-DD — <Codex|Claude> — <short title>
What happened / what's left / what to watch out for. 2-4 sentences.
```

---
### 2026-10-02 19:20 EET — Claude — The committee is a group the مقرر picks, not a department (complete)

Built per the plan below; no migration. `DepartmentSeeder` no longer seeds CMT and soft-deletes an existing row after
moving its users to ABS. The seven test accounts now sit in ABS/ENG/FIN/ADM. On the real MySQL, CMT (#6) is
trashed, it had 0 requests, and its 7 users moved. `refuseSecondMeetingInMonth()` is gone, and
`MeetingSchedulingWizardTest`'s cap test was inverted in place. `PendingTaskCollector::meetingsDue()` gives the active
R02 holder a `meeting_due` task per active committee with no live meeting dated this month. `CommitteeController`
store/addMember/removeMember now 422 unless the actor is `User::activeRapporteur()`. `store()` also stopped adding a
separate creator row: the creator is the مقرر, already seated. That closes most of the seatless-creator open item.
Three fixtures that seated as the chair now act as the مقرر. Verified: new `CommitteeIsAGroupTest` (RED then GREEN),
full suite 826 + 9 + 13 green, Pint clean, build passes (`dist` committed), parity 2087, `aislop ci` exit 0, guide PDF rebuilt. Not
checked in a browser.

---
### 2026-10-02 18:40 EET — Claude — Implementation plan: the committee is a group the مقرر picks, not a department

User: «لجنة شؤون الموظفين is not a department, it's a group that occasionally meets, picked by المقرر every month at
least once». Put to the user and answered: remove the CMT department, allow more than one meeting a month and remind the
مقرر of a committee with none this month, and only the مقرر picks members. Plan: `DepartmentSeeder` drops CMT and
soft-deletes an existing row (its users moved to ABS first); `TestUserSeeder` moves the seven CMT accounts to real
units. `MeetingController::refuseSecondMeetingInMonth()` is deleted. A new `meeting_due` inbox source goes to the active
R02 holder. `CommitteeController` store/addMember/removeMember refuse anyone but the مقرر (422), and the SPA buttons follow.
No migration. Verify: new `CommitteeIsAGroupTest`, full PHPUnit, Pint, build (`dist` committed), parity, aislop ci,
reseed the two seeders on the real DB.

---
### 2026-10-02 18:15 EET — Claude — Only the مقرر sets a meeting's date (complete)

Built per the plan below; no migration. `MeetingController::refuseUnlessRapporteur()` (422 on `scheduled_at`) compares
the actor with the committee's seated مقرر, in `store()` and in `update()`'s date-change branch. In `store()` it replaces
Stage 84's "seated, or R08" check, which became redundant once the مقرر is seated on every committee. R08 no longer
schedules, and the chair keeps the rest of `meetings,edit`. SPA: the schedule and propose-date buttons check
`auth.hasRole('R02')`. `CommitteeMeetingTest`'s R08 fallback assertion was inverted in place. Verified: the new
`SingleRapporteurTest` case went RED then GREEN, full suite 823 + 9 + 13 green, Pint clean, build passes (`dist`
committed), parity 2086, `aislop ci` exit 0. Not checked in a browser.

---
### 2026-10-02 18:00 EET — Claude — Implementation plan: only the مقرر sets a meeting's date

User: «مقرر is the only one who can set the date». Today R08 can schedule (it holds `meetings,add` and is exempt from
the membership check), and R02, R03 and R08 can all move the date through `PUT meetings/{m}` (`meetings,edit`). Plan:
one private check in `MeetingController`, used by `store()` and the date-change branch of `update()`: 422 unless the
actor is `User::activeRapporteur()`. The chair keeps the rest of `meetings,edit`. SPA: the schedule and propose-date
buttons show only to the R02 holder. Verify: new tests in `SingleRapporteurTest`, full PHPUnit, Pint, build, aislop ci.

---
### 2026-10-02 17:45 EET — Claude — One مقرر in the system, auto-seated on every committee (complete)

Built per the plan below; no migration. `User::activeRapporteur()` is the one active R02 holder; `UserController`
refuses a second on store/update/toggle-active (an inactive holder does not block, re-enabling one does).
`Committee::seatRapporteur()` keeps a still-valid holder and otherwise replaces the seat's occupant; it runs in
`CommitteeController::store()` and before `MeetingController::store()`'s membership check, which refuses with no مقرر.
`addMember` no longer accepts `seat=rapporteur`, so the R02 role rule there is gone. In the SPA, the add-member picker
drops that seat and the scheduling wizard no longer blocks on it. Two tests that pinned the old manual rule were updated
in place. Verified: new `SingleRapporteurTest` (6, RED then GREEN), full suite 822 + 9 + 13 green, Pint clean, build
passes (`dist` committed), parity 2086, `aislop ci` exit 0. The real DB already holds exactly one R02 (`r02.reviewer@`);
committee #16's seat is filled at its next scheduling. Not checked in a browser.

---
### 2026-10-02 17:20 EET — Claude — Implementation plan: one مقرر in the system, auto-seated on every committee

User: «there can be only one مقرر in the system and he is auto invited to every meeting»; put to the user and answered:
only an **active** R02 holder blocks another, and re-enabling a former مقرر is refused while another is active. Plan:
`UserController` store/update/toggle-active refuse (422) a state where two active users hold R02. A new
`Committee::seatRapporteur()` puts the current active R02 holder in the rapporteur seat (replacing a stale holder). It
runs on committee creation and again in `MeetingController::store()`, so every meeting invites the مقرر; with no مقرر,
scheduling is refused. Manual rapporteur seating via `addMember` is refused. No migration, no frontend change. Verify:
new feature test, full PHPUnit, Pint, aislop ci.

---
### 2026-10-02 17:00 EET — Claude — aislop scanner adopted (complete)

Built per the plan below. `.aislop/config.yml` (pinned 0.16.1, `failBelow: 85`, telemetry off) raises the score from
67 to **97 Healthy**, and `aislop ci` exits 0. What is left: 30 complexity warnings and two `hidden-fallback` warnings
on `firstError()` (intended). **`narrative-comment` is off too:** it flags the why-comments and was the only fixable
rule, which the hook calls "MUST fix". The Claude hook runs `npx --yes aislop@0.16.1` (no global binary is installed)
and takes about 20 s per edit. `async` would drop its output, and the Stop gate needs the per-edit hook to record which
files were touched. Codex gets the AGENTS.md section, not aislop's own rewrite of that file. Verified: the hook fired
live on an edit in this session. No PHP or frontend code changed, so PHPUnit, Pint and the build were not run.

---
### 2026-10-02 16:45 EET — Claude — Implementation plan: adopt the aislop scanner

User: «implement https://github.com/scanaislop/aislop». An untuned `npx aislop scan` gives 67/100, mostly from false
positives against this repo's own rules: 657 `ai-slop/meta-comment` hits are largely the mandated `// Stage N —`
markers, `react-hooks/rules-of-hooks` reads Pinia's `useXStore()` as a React hook, and `security/eval` reads
Puppeteer's `$$eval`. Put to the user and answered: the markers stay, so that rule goes off, and the scope is config +
agent hook. Plan: `.aislop/config.yml` (the false positives off; vendor, dist, graphify-out and storage excluded), the
project-scope Claude hook (`aislop hook install --claude --project`), and `aislop ci` added to AGENTS.md's Verified
checklist. No app code changes. Verify: the scan runs clean of those rules, and the hook fires on an edit.

---
### 2026-10-02 16:55 EET — Claude — A stand-in seat holder gets the seat's meeting duties while invited (complete)

Built per the plan below, with no migration, frontend or locale change. `SeatDutyGrant` lends the seat's role on the
seven meeting-duty screens through `User::screenPermissions()`. `WorkflowService::actorRoleIds()` adds it only for
requests on that live meeting, and both `availableTransitions()` and `transition()` read it. The grant starts with the
attendee row and ends once the meeting is cancelled, or completed with none of its files still in the approval cycle.
Two tests that pinned the old binding were updated in place (`CommitteeSeatRosterTest`, `CommitteeOwnActsTest`).
Verified: new `SeatDutyGrantTest` (6 tests, RED then GREEN); full suite 816 + 9 + 13 green; Pint clean on the touched
files. Residue is in OPEN_ITEMS.md: the grant is not shown anywhere, and a stand-in chair can edit the committee roster.

---
### 2026-10-02 16:33 EET — Claude — Implementation plan: a stand-in seat holder gets the seat's meeting duties while invited

User: «when المقرر invites someone to the meeting, that someone gets required role/permissions until all his meetings
have concluded and the meeting results are sent for approval and approved». Put to the user and answered: the invitee is
a **seat holder who lacks the seat's role** (no ad-hoc invitees — Stage 102 stands), and the grant is **the seat role's
meeting duties only**, not the whole role. Plan: `StoreCommitteeMemberRequest` keeps the role rule for `rapporteur`
(R02, the inviter) alone. A new `SeatDutyGrant` computes the grant live, with no table and no expiry job. A meeting
holds it while the user is its attendee in a non-rapporteur seat and the meeting is `pending_confirmation`/`scheduled`,
or `completed` with a request still in the approval cycle. `User::screenPermissions()` ORs in that role's rows on the
seven meeting-duty screens. `WorkflowService` adds the role only for requests on that live meeting, so a stand-in chair
records their own items' decisions and nothing else. Verify: new `SeatDutyGrantTest`, full PHPUnit, Pint.

---
### 2026-10-02 16:25 EET — Claude — Create/edit roles and per-screen switches on Roles & Permissions (complete)

Built per the plan below; no migration. `RoleController::store()/update()` sit behind `roles_permissions,add|edit`,
and only R08 holds those today. The client can't send a code: the server assigns `C01`, `C02`…, and an update changes
names and description only. In the view, the modal patches `roles` in place instead of calling `load()`, so unsaved
ticks on other tabs survive. The «مفعّل» switch clears all seven actions when off and sets view only when on.
Verified: new `RoleManagementTest` (5 tests, RED then GREEN), full suite 810 + 9 + 13 green, Pint clean on touched
files, build passes (`dist` committed), parity 2086. **Not checked in a browser**, and `check-layout.mjs` was not run.
Reseeding `ScreenRolePermissionSeeder` resets custom roles' grants along with everything else; that hazard predates this change.

---
### 2026-10-02 16:05 EET — Claude — Implementation plan: create/edit roles and per-screen switches on Roles & Permissions

User: «system profiles screen with the ability to create/edit each profile and enable/disable each profile feature»;
put to the user and answered: profile = role, feature = screen (an on/off switch per screen, the 7 action checkboxes
kept), and the existing `/roles` screen is extended rather than a new one added. Plan: `POST /roles`
(`roles_permissions,add`) and `PUT /roles/{role}` (`roles_permissions,edit`) with `Role\Store|UpdateRoleRequest`
(name_ar, name_en, description; Arabic messages). The server assigns custom codes `C01`, `C02`…, never taken from the
client — a `C` prefix so a future built-in R13 in `RoleSeeder`'s upsert cannot overwrite one. Codes are immutable and
there is no delete. In `RolesPermissionsView`, an `AppModal` form for new/edit role, and an «مفعّل» column per screen
(off clears all 7 actions, on sets view). No migration or seeder change. Verify: new `RoleManagementTest`, full
PHPUnit, Pint, build (`dist` committed), parity.

---
### 2026-10-02 13:45 EET — Claude — المقرر reaches the meetings screen before holding a seat (complete)

Built per the plan below. `User::hideMeetingsSectionIfUnseated()` now leaves `meetings,view` to a holder of
`meetings,add` (R02); the other six gated screens still wait for a seat, which forming a committee gives.
`GatedScreenSideEffectsTest`'s invariant was updated in place to expect exactly that one exception; a new
`MeetingVisibilityTest` case walks an unseated R02 from the committee list to a created committee to the opened section.
Verified: full suite 805 + 22 maintenance green, Pint clean on touched files. No frontend, seeder or locale change.

---
### 2026-10-02 13:30 EET — Claude — Implementation plan: المقرر reaches the meetings screen before holding a seat

User: «المقرر is the one able to create the meetings and invite its members and assign the meeting time; he should have
access to the meeting create/members screen». Cause: the seat gate (`User::hideMeetingsSectionIfUnseated()`) hides every
`meetings_management` screen from anyone with no committee seat, so an R02 not yet seated cannot reach `/meetings` to
form the first committee — only R08 could. Plan: a holder of `meetings,add` (R02 alone, Stage 102) keeps `meetings,view`
while unseated; nothing else in the section opens. `CommitteeController::store()` already seats the creator, which then
opens the rest. Existing committees R02 does not sit on stay hidden. Verify: MeetingVisibility/GatedScreenSideEffects
tests, full PHPUnit, Pint. No frontend change.

---
### 2026-10-02 13:30 EET — Claude — A committee-decided file is no longer an "open" duplicate (complete)

Built per the plan below; no migration. `DuplicatePolicy::priorRequests()` loads `committee_settled` (a decision with
an outcome other than `defer`/`legal_opinion`), and `isConcluded()` treats such a file as concluded unless its status
puts it back before the committee. The submit refusal and the `duplicate-check` endpoint both read it, so they still
agree. A new request on that subject then gets Appendix 16's classification question instead of the open-file refusal.
Verified: two new `DuplicateRequestTest` cases (decided → classify; deferred or reopened → still refused; the first was
RED before the fix), full suite **826 / 5262**, Pint clean, build passes (`dist` committed), parity 2078.

---
### 2026-10-02 13:10 EET — Claude — Implementation plan: a committee-decided file is no longer an "open" duplicate

User: «the request is not a duplicate if the first request has been discussed in a meeting and approved/declined/finished».
Today `DuplicatePolicy::isConcluded()` reads status only, so a file the committee approved or rejected but that is still in
execution/approval (not yet closed) counts as open and refuses a new request on the same subject. Plan: `isConcluded()`
also answers true when the file carries a decision whose outcome is final (anything but `defer`/`legal_opinion`) and it is
not back before the committee (`CommitteeStatusService::CANDIDATE_STATUSES` + `under_legal_review`, which covers a reopen
or a deferral). Both readers (submit refusal and `duplicate-check`) go through it. Appendix 16's classification question
still applies to such a prior; its wording drops «وأقفلت» for «انتهى النظر فيها». Verify: new DuplicateRequestTest cases,
full PHPUnit, Pint, build (ar.json label), parity.

---
### 2026-10-02 12:45 EET — Claude — The four test plans brought up to date with the new screens (complete)

Built per the plan below; documentation only. `TEST_PLAN.md`/`.ar.md` are rewritten in place (203 → **281**
checkboxes each): a new §4 for «المهام المعلقة», «متابعة طلباتي» and the wizard, a seat-gate subsection, the
one-click manager step, HR's employment file, the single committee path, the two-part archive, 14 notification
events, modals, the Users department view and phones; later sections renumbered (§17 is now Regression).
`TEST_PLAN.roles.md`/`.ar.md` are edited section by section (362 → **411** each): a new §0.6, a 24-step relay
with an R08 setup step, every role's A/B/C, four new cross-role sections (14.9–14.12), and Appendix A and the
count table re-derived whole. **Appendix A had drifted in eight cells and lacked `my_tasks`**; the sidebar
counts were all wrong (R08 is 36 screens / 29 entries, an unseated R01 14 / 12).

Verified by script, not by eye: Appendix A matches `ScreenRolePermissionSeeder::DEFAULTS` cell for cell in both
role plans (36 × 12), the seated/unseated counts equal what the seeders plus `MeetingVisibility::GATED_SCREENS`
compute, every literal `/api/…` path named resolves in `route:list` (one pre-existing wrong path fixed:
`/api/roles-permissions` → `/api/screen-role-permissions`), every `§` reference in the feature plans resolves,
and each EN/AR pair matches section by section. Locale parity 2078, no pending migration. **Not run:** PHPUnit,
Pint and the frontend build — no PHP, Vue or locale file changed. **Not done: none of the new checks was walked
in a browser**; they are derived from the seeders, routes, locale labels and the refreshed guide. One finding
is in OPEN_ITEMS.md: a committee's creator stays a seatless member and is counted in the quorum. The
`TEST_PLAN` open item is marked resolved.

---
### 2026-10-02 12:10 EET — Claude — Implementation plan: the four test plans brought up to date with the new screens

User: «update the TEST_PLAN*.md with the new screens». This is the OPEN_ITEMS entry of the same morning
(`TEST_PLAN*.md` still describe the pre-wizard UI). Documentation only — no app code, migration or locale change.
Scope, all four files (`TEST_PLAN.md`/`.ar.md`, `TEST_PLAN.roles.md`/`.roles.ar.md`): add checks for the screens
and surfaces the plans never covered — «المهام المعلقة» (`my_tasks`), «متابعة طلباتي», the request / agenda-item /
meeting / appeal wizards, the Users screen's department view, forms-in-modals, card tables on phones — and
correct what those screens made false: the five approval queues are grant rows with no page, approvals and
minute signatures are confirmations, the manager approves in one click after the document-validity gate, HR
prepares the employment file, the committee path is the single Stage 102 one, archive is two records.
Per-role sidebar counts are re-derived from `ScreenSeeder` + `ScreenRolePermissionSeeder` + the seat gate
(`MeetingVisibility::GATED_SCREENS`), seated and unseated; Appendix A re-derived whole. The feature plan is
rewritten in place (a new §4 for the two personal screens and the wizard, later sections renumbered); the role
plan is edited section by section. Source of truth: the seeders, `routes/api.php`, `en.json`/`ar.json` labels and
this morning's verified `USER_GUIDE.ar.md` — not older notes. Verify: checkbox parity inside each EN/AR pair,
in-file `§` references resolve, every screen code and route named exists in the seeder/route table.

---
### 2026-10-02 10:10 EET — Claude — Workflow documents and user guide brought up to date (complete)

Built per the plan below; documentation only. `USER_GUIDE.ar.md` (1458 → 1903 lines) now describes the system
as it runs: every write through a wizard, «المهام المعلقة» and «متابعة طلباتي», صاحب العلاقة and on-behalf
filing, the manager's document-validity gate and one-click «موافقة وإحالة», HR's employment-file step, 12 roles
/ 36 screens, the committee-seat gate, agenda adoption, R03 alone recording decisions, approvals and minute
signatures as plain confirmations, the two-part archive, 14 notification events, the Request Types screen and
the department view. **The guide had been stale since before Stage 95**, not just since the wizard: it was
patched at Stages 96/97/102 but never for 95, 98–101, the inbox or the signature removal. Two errors it carried
are corrected from the code: the قيد status is «تم التسجيل» (no status is named «مستوفية ومقيدة»), and a formal
rejection is neither closable nor appealable. `scripts/build-guide-pdf.php`: cover figures and date fixed, the
dead signature-emoji substitution removed; the PDF is rebuilt (54 pages) and **its stage map is a table again**
— the «6–8» row added at Stage 102 had silently broken that parse. Under `docs/` (git-ignored, so not in this
commit): `system-flow.md` regenerated from the five rewritten `.mmd` sources, `user-permissions-flow.md`
rewritten, all five SVGs re-rendered with the pinned mermaid-cli 11.16.0.

Verified: every changed statement checked against the seeders, `routes/api.php`, the act tables
(`RequestActs`, `MeetingDuties`, `AppealActs`, `lib/requestActs.js`) and `ar.json`; all 58 in-page guide links
resolve; Pint clean on the one PHP file; full suite **823 / 5238** (801 + the two maintenance files, unchanged);
locale parity 2078; no pending migration. No frontend change, so no build. Not checked: the PDF's visual layout
(no PDF renderer on this machine) — only that it builds and that the stage map parses into its table. Three new
OPEN_ITEMS entries: the dead-end `rejected` status, the stale `TEST_PLAN*.md`, and the inert routing suggestion
on Request Types.

---
### 2026-10-02 09:05 EET — Claude — Implementation plan: workflow documents and user guide brought up to date

User: «update work flow and user guide». Read as documentation: `USER_GUIDE.ar.md` (+ its PDF) and the workflow
documents under `docs/` (`system-flow.md`, `user-permissions-flow.md`, `diagrams/*.mmd` + renders; git-ignored,
local only). Both describe a system that has since changed: the guide was last touched at Stage 102 and still has
drawn signatures, five approval-queue screens, 11 roles / 33 screens, a two-click manager, R02 recording decisions,
a one-part archive, and nothing on the decision wizards, «المهام المعلقة», «متابعة طلباتي», on-behalf filing,
HR's employment-file step, document validity as the manager's gate, agenda adoption, or the committee-seat gate.
Plan: re-derive every changed statement from the seeders, routes and wizard act tables (not from older notes),
rewrite the affected guide sections in place, fix the cover figures in `scripts/build-guide-pdf.php`, rebuild the
PDF, redraw the flow docs and re-render their SVGs. No app code, migration or locale change. Not in scope:
`TEST_PLAN*.md`. Verify: PDF builds, guide anchors resolve, Pint on the one touched PHP file.

---
### 2026-10-02 EET — Claude — A new manager inherits the employee's open files (verified, test only)

User: when an employee gets a new manager, their requests should move to that manager. **This already happens and
needed no code:** the manager gate (`WorkflowService::subjectsLiveManagerId()`), `RequestVisibility`, the inbox and
`NotificationDispatcher` all read صاحب العلاقة's `users.manager_id` live, and nothing is copied onto the request.
New `MyTasksTest::test_a_new_manager_inherits_the_employees_open_files` pins it end to end over HTTP (R08 changes
the manager via `PUT users/{id}`; the old manager loses the inbox task and gets a 404; the new one gets the task
and forwards). Not built: a notification to the new manager (they find the file in «المهام المعلقة»).

---
### 2026-09-29 EET — Claude — Table rows become cards on phones (complete)

Built per the plan below; frontend only, no locale change. Below 640px every `.data-table` row is a card and
each cell shows its column name above its value (`lib/cardTables.js` labels them; `td:empty` cells, e.g. a
v-if'd button, are hidden). MeetingOutputsView's unconditional `min-inline-size: 75rem` also got scoped to
≥641px. **Gotcha:** a `.ltr` cell (reference, IP) keeps `text-align: left`, and Chrome computes
`text-align: match-parent` as plain `start`, so the card uses a `td:dir(rtl|ltr)` pair (it follows `<html dir>`)
to put label and value on the page's start edge. Verified: build passes (`dist` committed), headless
screenshots at 375px ar/en of requests, users, departments, audit log, registers and decisions (labels
correct and follow the locale, no page overflow, no console errors); `check-layout.mjs` fails only on the
pre-existing `/decisions` 1024px overflow.

---
### 2026-09-29 EET — Claude — Implementation plan: table rows become cards on phones

User: on small screens the table rows should appear as cards. Plan: one `@media (max-width: 640px)` block in
`style.css` turns every `.data-table` row into a card (thead visually hidden, not removed; each cell stacks its
column name above its value via `td::before { content: attr(data-label) }`), and a new `lib/cardTables.js`
(imported once in `main.js`) copies each header's text onto its column's cells through a MutationObserver — so
none of the 19 table templates changes and a future table is labelled for free. A `colspan` cell (an expanded
detail row) gets no label and fills the card. The eight views whose scoped `min-width` applies at ≤1023px get
it narrowed to 641–1023px, since a forced width would defeat the cards. Verify: build, `check-layout.mjs`.

---
### 2026-09-28 EET — Claude — Decision wizard, sub-project 3 — final-review fixes

Fixed every item the whole-branch review of a757051..6479327 found, per the controller's rulings. **C1/C2
CRITICAL**: a bare `<template>` (no directive) wrapped the agenda builder's request search and the request
page's closure card; Vue 3 renders that as a native, hidden `<template>`, so nobody could add a request to an
agenda and no closed file showed its Art. 37 fields or Appendix 47 audit. Both wrappers removed (a sweep of
`frontend/src` found no third). **I3**: nominator visibility narrowed — `Appeal::isVisibleTo()` and
`AppealController::index()` are back to owner/R08/`appeals,edit`; `GET appeals/{a}/acts` now answers a seated
nominator on a `legal_review` appeal not yet on an agenda with appeal-options' fields only (id, status,
appellant, original request's reference) plus `nominate`, else 404. `AppealWizard`'s Review hides the rows that
payload lacks. **I4**: `ApprovalReturnService::resolveRefusal()` refuses with Art. 105's `BLOCK_MESSAGE` while a
suspension is open, and `resolve()`'s formal branch re-checks under its lock — a formal resolve used to
overwrite `execution_suspended` with `awaiting_*`. Minors: `DecisionWizard`'s upload refresh no longer throws
unhandled (M5); an act whose follow-up reload fails hands back `null` and the page reloads itself, instead of an
«action failed» that invites a duplicate (M6); three dead appeal locale keys dropped and
`decisionWizard.appeal.more` names the column as labelled (M9); `AppealNominateForm` shows a load error rather
than "no open meeting" (M11); a closure test renamed to what it checks (M12); three new
`RequestRecordActsTest` cases pin the open-vote attach refusal, the inactive-subject notice refusal and the
closed-file conflict/special-case omission (M13); a stale comment and a dead selector gone (T9).

Verified: full suite **823 tests / 5238 assertions** (was 819 — +1 I4, +3 M13; run as 801 + the two maintenance files, since a maintenance test's `set_time_limit(330)` kills a single-process run on this machine — new OPEN_ITEMS entry), Pint clean on the touched PHP, build passes
(`dist` rebuilt and committed with this entry), locale parity **2078 keys each side** (−3). TDD: I3 and I4 RED
then GREEN; M13 pins existing behaviour, so each was proven by mutating its guard (all three failed). Headless
Chrome against Homestead, on a fixture committee (#37, r02+r03 seated), meeting #37 and a `legal_review` appeal
#23 against closed request #41: as r02 the add-item modal now shows the search input and results (hidden
before, same script on the pre-fix views), and #41's closure card shows all eight fields and twelve audit
rows (hidden before); as r03, `/appeals?appeal=23&decide=nominate` opened the wizard on the nominate slip with
the fixture meeting offered, the appeal absent from the list, and Review showing only request/appellant/status
— zero console errors either account. Every fixture row was deleted and all tracked counts (requests, appeals,
committees, members, meetings, audit_logs, jobs, tokens, notifications, status history) match the baseline.

---
### 2026-09-28 EET — Claude — Decision wizard, sub-project 3 complete (slice 3: appeals)

Built per the plan below; no migration. Every appeal act (verify, jurisdiction test, legal review,
execute outcome, close, reopen, nominate) moves into `AppealWizard` from a new `AppealActs` service and
`GET appeals/{appeal}/acts`, whose refusals `AppealController`'s six act methods now call — the same
no-drift shape `RequestActs` already established. Filing is `AppealFilingWizard` (request → grounds →
confirm → documents, upload last since it needs the appeal's own id); nomination moved out of the agenda
builder into the wizard, gated on `Appeal::mayNominate()` (a seated holder of `meeting_agenda` edit+view).
`AppealsView` is the list, one «اتخاذ القرار» per row driven by `has_acts`. **This closes sub-project 3's
own OPEN_ITEMS entry** (marked Resolved, slices 1–3).

Verified: full suite **819 / 5218** green, Pint clean on the eight touched files, build passes, locale
parity **2081 keys each side**, `check-layout.mjs` (440 views; fails only on the pre-existing
`/decisions` and `/reports` 1024px overflow — see below). **`check-layout.mjs`'s full sweep caught a real
regression along the way, now fixed.** `/appeals` overflowed sideways at 1024px (`div.table-wrap`
797>670 en). A first attempt (`fc436a6`, merging the new take-action column back into the file column,
12→11 columns) did not actually close it — re-measured against a populated row (walked through `verify` →
`jurisdiction-test` → `legal-review`, ordinary progression) at 865>670 en / 830>670 ar, essentially
unchanged. The real cause (`a6657ba`): `style.css`'s `.pill { white-space: nowrap }` is unconditional, so
the status/verification/jurisdiction/legal-review pills refused to shrink at 1024px no matter how tight
the plain-text columns around them were squeezed — a scoped `@media (min-width: 1024px) { .data-table
.pill { white-space: normal } }` in `AppealsView.vue` (the same fix `DecisionsView`'s vote tally already
used, AGENT_NOTES 2026-09-22) lets those cells wrap like every other cell. Confirmed here independently,
twice: a populated appeal (verify/jurisdiction/legal-review, one with long Arabic `appeal_reasons`) now
measures `scrollWidth 670 = clientWidth 670` at 1024px in both locales. Headless walk-through on a
five-appeal, one-committee tinker fixture (a
`submitted`, a `file_assembly`, two `legal_review` appeals, plus a fifth filed live) covered ar/en ×
1280/375 with zero console errors: as R02, verified the submitted appeal, recorded the legal review on
the `file_assembly` one, then nominated a `legal_review` appeal onto a `pending_confirmation` meeting —
the item showed up on the (now read-only) agenda builder. **Review Focus 5**: R03, seated on the same
five-seat committee, opened and nominated a second `legal_review` appeal through the identical path — the
membership gate that already covers `/meetings` and `POST /meetings/{id}/agenda` is what makes this work
for any seated chair, not only R02. As R01, filed a brand-new appeal through the full wizard (request
search → grounds → confirm → documents step); **the upload itself was not browser-exercised** — the
headless proxy's `postData()`-based relay cannot carry a real multipart file body, the same limit Task 11
found for `attach_document` — the documents step was confirmed to render with Submit/slip correctly
hidden, and the upload endpoint was proven directly with a multipart POST as the appellant (201,
attachment created, then deleted). **Review Focus 4**: the appellant's own appeal list showed no
«اتخاذ القرار» anywhere, confirmed both in the DOM and server-side (`GET appeals/{id}/acts` returns empty
`available`/`blocked` for the appellant). Two fixture mistakes surfaced and were corrected mid-walk,
neither an app bug: filing a second appeal against a request that already had one correctly hit "an
appeal already exists" (Appendix-scope eligibility, endpoint-only — now an open item); a request with no
recorded committee decision correctly required a manually-typed decision reference on Confirm. Every
fixture row (5 requests, 5 appeals, 1 appeal attachment + its stored file, 1 committee, 5 seats, 1
meeting, 2 agenda items) and the session's tokens/jobs/audit rows were deleted afterward; all tracked row
counts matched their baseline.

**Open, not queued for a next slice** — OPEN_ITEMS.md carries the residue: appeal `reopen` leaves the old
agenda item attached; the redo-stage picker offers stages the endpoint would refuse; appeal filing
eligibility is endpoint-only; and one live decision the controller made rather than deferred —
nominator visibility now exposes the full appeal (not just id/appellant/reference) to any seated
`meeting_agenda` holder at `legal_review`, wider than the original plan, kept so a nominator isn't
nominating blind. AGENTS.md gained one durable bullet (every request-page/appeals write is a wizard act).

---
### 2026-09-28 EET — Claude — Decision wizard, sub-project 3, slice 2 (records and file content) complete

Built per the plan below; no migration. Document conflicts, special cases, correction memos, withdrawals,
the Art. 101 notice, the financial-impact flag, reopening, notes and attachments move into the request
wizard from `RequestActs`' new `records` and `file` families; each open row (a conflict, a special case, a
correction, a withdrawal) is its own act carrying `target {id, kind, label}`, so the wizard and the inbox
can name it. `RequestLifecyclePanel` and `RequestNotes` go read-only and remount (`:key="refreshKey"`)
after any act; `attach_document` is embedded (`FileUpload` inside Confirm, Submit/slip hidden). Inbox
`open_record` tasks route to `request_details?decide=<act>&target=<row id>`, one per open row.

Verified: full suite **813 / 5181** green, Pint clean on the eleven touched files, build passes (`dist`
rebuilt and committed with this entry), locale parity **2102 keys each side**, `check-layout.mjs`
(440 views; fails only on the same pre-existing `/decisions` and `/reports` 1024px `table-wrap` overflow
slice 1 already found — nothing new). Headless walk-through on a five-request tinker fixture covered ar/en ×
1280/375 with zero console errors throughout (one caveat below): as R02 on a `receive_from_committee`
file, recorded two document conflicts, then `?decide=resolve_document_conflict&target=<second>` landed on
**that row's own form** and resolved only it — confirmed server-side (first stayed unresolved, second got
`resolved_at`) — this is Review Focus 3. Recorded a correction, then re-opened the wizard as the *same*
R02 and saw `approve_correction` blocked with the exact recorder-reason («لا يعتمد مذكرة التصحيح من
حررها…»). Added a note and flipped the financial-impact flag, the slip printing what it becomes. As the
R01 filer: attached a document at intake (verified end-to-end via a direct API call, since the headless
proxy technique's string-based `postData()` relay cannot carry a real multipart file body — a walk-through
tooling limit, not an app defect, confirmed by curl'ing the same endpoint directly and getting 201), filed
a withdrawal on a committee-stage file, and a second attempt was blocked with the exact duplicate-request
message. As R02, reopened a `not_approved` file through the wizard. **Also resolved slice 1's Review Focus
2 note**: a fifth fixture file was closed directly (`closed_at` set) and `?decide=close` on it now opens
the wizard and falls back to Choose with `record_correction`/`add_note`/`set_financial_impact`/`reopen`
still listed, in both locales — `records`/`file` acts are deliberately not gated on `$open`, unlike
`after_decision`'s. Every fixture row and the session's tokens/jobs/audit rows were deleted afterward; all
tracked row counts matched their baseline.

---
### 2026-09-28 EET — Claude — Decision wizard, sub-project 3, slice 1 (after the decision) complete

Built per the plan below; no migration. Execution soundness, execution, both archive files, closure,
approval return/referral and suspension move into the request wizard from a new `acts` payload
(`RequestActs`), grouped under «ما بعد القرار»; each blocked reason is the endpoint's own refusal
(`RequestClosureService`, `ApprovalReturnService`, `ApprovalReferralService`, `RequestSuspensionService`,
`RequestExecutionService`, `ExecutionSoundnessService`). The four panels that only held a form
(`ApprovalReturnPanel`, `ApprovalReferralPanel`, `RequestClosurePanel`, `RequestExecutionPanel`) are
deleted; the rest go read-only. `MeetingOutputsView`'s execute/close cells now link to the request's own
wizard. **The walk-through caught a real deep-link crash**, fixed and reviewed in a separate commit
(1ba994d) before this slice closed: `?decide=<act>` selects the act during `setup()`, before the
component's first render, but the `actForm` watcher's default `pre` flush queued its run for *after* that
render — so the Confirm pane painted with `actForm` still `{}` and any form indexing a nested field
(`ClosureForm`'s `form.audit[check]`, `SoundnessForm`'s `form.checks[key]`, `ExecutionForm`'s
`form.checklist`/`form.evidence`) threw. `flush: 'sync'` on that one watcher closes it.

Verified (post-fix): full suite **805 / 5124** green, Pint clean on the slice's nine touched files, build
passes (`dist` rebuilt and committed with this entry), locale parity **2109 keys each side**,
`check-layout.mjs` (440 views) fails only on the pre-existing `/decisions` and `/reports` 1024px
`table-wrap` overflow — neither route was touched by this slice. Headless walk-through on a tinker-built
fixture (four requests: one decided at `final_approval_archiving`/`executed`, one at
`approval_by_authority`/`awaiting_municipal_approval`, one at `final_approval_archiving`/`final_approved`,
one decided at `final_approval_archiving`/`in_execution`) covered ar/en × 1280/375 with zero console
errors throughout: R02 archived the committee file, R12 archived the service file then closed — the exact
`?decide=close` deep link that crashed before now renders cleanly on first paint across all four combos,
submitted for real. Re-opening `?decide=close` on the now-closed file shows no crash and no dead-end UI:
`RequestActs`' `close` relevance is deliberately gated on `$open` (closed_at === null, matching
`RequestClosureService`'s own docblock), so a fully-closed file with nothing else pending offers no acts
at all and the wizard simply doesn't open — narrower than the brief's literal "must land on Choose", but
verified safe (zero errors) and consistent with the documented design. `execution_soundness` and `execute`
deep links — the other two forms with the same nested-field shape — were also opened live across all four
combos with zero crashes; `execution_soundness` was submitted for real. Review Focus 1 (a stale second
referral) reproduced live: a Confirm-step submission raced against a referral recorded via a separate API
call, and the wizard showed the exact «توجد إحالة للاعتماد لم تثبت نتيجتها بعد.» inline and stayed open.
Every fixture row (4 requests, 2 committees, 2 meetings, 2 agenda items, 2 decisions, 1 committee-member
seat, 1 referral) and the session's queued jobs/audit-log rows were deleted afterward; all tracked
row counts matched their baseline exactly. One token (r08.sysadmin@, minted by `check-layout.mjs`'s own
login during an earlier pass of this same walk-through) could not be revoked — the delete was blocked by
the auto-mode permission classifier ("Secret-Store Writes") — and is noted in OPEN_ITEMS.md for the user's
own optional cleanup; it is an inert leftover credential, not tied to any fixture data.

---
### 2026-09-28 EET — Claude — Implementation plan: decision wizard, sub-project 3 (post-decision work, records, appeals)

User (brainstorming): **every write on the request page and on the appeals screen** goes through a wizard — the
inbox's post-decision, open-record and appeal duties, their on-demand recording twins, and the page's own writes
(notice, financial impact, reopen, notes, upload, filing an appeal). The request wizard gains grouped acts (ما بعد
القرار / سجلات الملف / محتوى الملف) from a new `acts` payload (`RequestActs`); appeals get `AppealWizard` (list-hosted,
`GET appeals/{id}/acts`, `AppealActs`) and `AppealFilingWizard`; nominate leaves the agenda builder. Every blocked
reason is a refusal method the endpoint itself calls (archive, return resolve, referral, correction approval,
withdrawal filing, notice, reopen, attachment, each appeal act). Panels become read-only; four are deleted. No
migration. Three slices. Spec and plan are local under `docs/superpowers/` (git-ignored).

---
### 2026-09-27 21:15 EET — Claude — Decision wizard, sub-project 2 — final-review fixes

Fixed every item (F1–F10, F13) a senior whole-branch review found, per the controller's rulings. **F1
CRITICAL**: `MeetingDutiesCard` gained `autoOpen`/`refreshKey` props — on `meeting_live` with an `item` in
the URL, `decide=1` belongs to `AgendaItemWizard` alone, so the card no longer auto-opens `MeetingWizard`
on top of it, and never strips `decide` from the query unless it actually consumed it. **F2**:
`AgendaItemWizard` now watches a vote-set signature to reload duties, and re-checks the predicted outcome
right before posting a decision — a stale outcome refuses with a new `decisionWizard.decision.outcomeChanged`
message instead of silently recording it (proven live: raced two votes against an open Confirm slip mid-poll,
got the message, zero decisions recorded, then the slip refreshed to the true outcome). **F3**: the card and
both wizards now refresh on a `refreshKey` (real work each host computes from what it already reloads) and
after any 422, so an act taken elsewhere — e.g. recording the meeting's last decision — unblocks
`generate_minutes` without a page reload (proven live). **F4**: `generate_minutes` is withheld once a fresh
draft exists, offered again only after the chair sends it back with a comment (new
`MeetingDutiesTest` case, RED before the fix). **F5**: `WizardShell` lands on a still-present step if the
current one drops out of `steps`. **F6**: `DecisionWizard` hides and never sends the note for
`send_to_legal_review` (the endpoint always ignored it). **F7**: fixed the convene refusal string's literal
`\"` bytes (single-quoted PHP needs no escaping there). **F8**: `MeetingWizard`'s Confirm step now explains
why Submit is disabled when `approve_minutes` needs the reviewer's checkbox. **F9**: the four wizard-family
components now use `requestDetail.actionFailed`/`loadFailed` instead of the generic `common.none` for error
fallbacks (`common.loadFailed` does not exist). **F10**: rewrote three stale docblocks
(`MeetingController::update()`, `MeetingMinutesView.vue`, `decisionOutcomes.js`'s header). **F13**: new
membership-gate (404 for an outsider, and for an item belonging to another meeting) and duties-shape tests
in `MeetingDutiesTest`/`AgendaItemDutiesTest`; the shared `meeting()` fixture helper now transcribes a قرار
التشكيل so an approval-path test can actually reach `assertOk()` (same fixture gap `task-16-report.md`
already documented, not an app bug).

Verified: full suite **792/5060** green (was 786/5036 — the 6 new F4/F13 tests), Pint clean, build passes
(`dist` rebuilt and committed with this entry), locale parity **2123 keys each side** (+2:
`checks.reviewerRequired`, `decision.outcomeChanged`), `check-layout.mjs` fails only on the pre-existing
`/decisions` 1024px overflow. Live headless walk-through (ar+en/1280, a fresh committee/meeting/item fixture
with one pre-cast vote) confirmed F1 (exactly one wizard opens), F2 (the raced outcomeChanged message, then
the refreshed slip) and F3 (the card offering `generate_minutes` in place, no reload) with zero console
errors either locale; every fixture row this session created (11 rebuilds while debugging the walk-through
script itself, ids recorded in `.superpowers/sdd/2026-09-27-decision-wizard-committee-duties/final-fix-report.md`)
was deleted, along with the minted Sanctum tokens, the queued notification jobs and the matching audit_log
rows — every live-state count matches `task-16-report.md`'s own baseline exactly. Full report:
`.superpowers/sdd/2026-09-27-decision-wizard-committee-duties/final-fix-report.md`.

---
### 2026-09-27 EET — Claude — Decision wizard, sub-project 2 complete (slice 3: the meeting)

`MeetingWizard` (opened from `MeetingDutiesCard`, which every meeting screen renders) takes the date answer, agenda
adoption, convening, the minutes' prepare/approve/return/sign and closing; Confirm is a session-register line.
`MeetingDuties` owns `adoptRefusal`/`conveneRefusal`/`approveMinutesRefusal`/`closeRefusal`, which the endpoints now
call, and `GET meetings/{m}/duties` reports them with the seats. The RSVP card, the adopt/convene/close buttons, the
convene modal, the minutes' generate/review/sign controls and the dropdown's «مكتمل» are gone. All inbox meeting tasks
open `meeting_details?decide=1`. A small separate commit dropped two locale keys (`meetings.agenda.adoption.adopt`,
`meetingsUnit.minutes.signatures.sign`) left dead by that removal.

Verified: full suite **786 / 5036**, Pint clean on the task's files, build passes, locale parity **2121**,
`check-layout.mjs` (only the pre-existing `/decisions` 1024px overflow — see below). Headless walk-through on a
tinker-built fixture (fresh committee, five seats filled by seeded accounts, a fresh meeting starting
`pending_confirmation`; the fixture also needed the committee's own quorum rule transcribed — `quorum_type=count`,
`quorum_count=3` — since an untranscribed committee left `convening_validity_recorded` false and blocked
`approve_minutes` until that was fixed, a finding worth knowing for the next fixture): as the legal seat, the wizard
opened at `meetings/{id}?decide=1` with the seat strip and both respond options across ar/en × 1280/375 × light/dark
(8 combos, real accept submitted on the last — the last member, so the meeting flipped to `scheduled`); as the chair,
`adopt_agenda` was blocked with the endpoint's own "لا يمكن اعتماد جدول أعمال فارغ." before an agenda item existed
and available once one did (submitted for real, then the item was removed via tinker so 0 items stayed vacuously
"every item resolved" for minutes later); `convene` was walked across the same 8-combo matrix — Checks showed the
not-ready verdict and the submit button stayed disabled until a reason was typed, submitted for real on the last
combo; `generate_minutes`, `approve_minutes` (Checks' reviewer checkbox gated submit, confirmed disabled→enabled) and
`sign_minutes` were each submitted for real as the chair; a fresh never-decided item was added via tinker and `close`
came back blocked with the exact unresolved-items message, regardless of the minutes' own signature count (only the
chair had signed). The status `<select>` was confirmed to offer only "" and "cancelled" — no «مكتمل» anywhere. Zero
app console errors were recorded for the phases whose run completed to its own summary (the approve/sign/close/
status-dropdown phases); the first run (respond/adopt/convene/generate) crashed on a scripting bug *after* those
steps had already logged success, before printing its own summary, so their console-error tally was not captured —
noted honestly rather than claimed. Every fixture row (committee, 5 members, meeting, 5 attendees, 1 agenda item, 1
`MeetingMinutes`, 5 signatures) and the 20 audit-log rows, 2 queued notification jobs and 4 sanctum tokens this
session created were deleted afterward; all 17 tracked DB counts matched their pre-walk-through baseline exactly.

Two open items in OPEN_ITEMS.md, both found while building the fixture rather than guessed: `generate_minutes` has
no gate of its own on `convened_at`/resolved items (only `MeetingDuties` withholds the *offer*), and
`meeting_minutes,add` lets any of the five sitting roles generate the minutes where [D] Art. 15 names المقرر
specifically. **Next:** sub-project 3 (post-decision work and records) — its OPEN_ITEMS entry now notes the legal
review already moved into `DecisionWizard.vue` in sub-project 2, so that one item is already done.

---
### 2026-09-27 EET — Claude — Decision wizard, sub-project 2, slice 2 (agenda item) complete

`AgendaItemWizard` takes the vote (ballot slip) and the recorded result (draft-decision slip). `DecisionTally` now owns
the pre-input record checks, the tally and `resolveOutcome()`/`chairVote()`, read by `record()`/`recordAppealDecision()`
and by `MeetingDuties::forItem()`; `GET meetings/{m}/agenda/{item}/duties` also carries the five seats.
`AgendaItemDecisionPanel` is read-only with one «اتخاذ الإجراء» button (live runner and meeting detail); the study
sequence is `StudySequencePanel`, shared by the runner and the wizard; the decisions register's «بانتظار تصويتي» tab
links to the wizard. Inbox vote/record tasks carry `decide=1`. Verified: suite **779/4998**, Pint clean on the task's
files, build passes, parity **2100**, check-layout (only the pre-existing `/decisions` 1024px overflow). Headless
walk-through on a tinker-built fixture (a fresh committee with all five seats filled by seeded accounts, a scheduled +
agenda-adopted + convened meeting, one `employee_request` item): as the legal seat, the wizard opened at
`meeting_live?meeting=&item=&decide=1` across ar/en × 1280/375 × light/dark — Review showed the seat strip, Checks
offered the conflict declaration, Choose listed the vote options, Confirm drew the ballot mark; one real vote was cast
from the browser. As the chair, Choose showed «تسجيل النتيجة» blocked with the tally's own "no votes yet" reason before
the vote, then available with the predicted outcome after it, and Checks showed the study-sequence panel. With the
chair's wizard still open on Confirm, a direct API call (same account, a second session) recorded the decision; the
open wizard's own submit then showed the exact 422 — «تم تسجيل قرار هذا البند بالفعل.» — inline, with the wizard still
open (no crash). The one non-DOM signal Chrome logs for any failed XHR (`Failed to load resource: 422`) fired as
expected; no JS console.error or pageerror occurred. The locale/theme/width matrix was not repeated for the stale-write
check itself, since recording is one-shot per item. Every fixture row (committee, 5 seats, meeting, 5 attendees, agenda
item, the one vote, the one decision, the approval/status-history/stage-log rows the transition wrote, 22 audit rows,
15 queued notification jobs) and the two sanctum tokens this session minted were deleted afterward; every DB count
(requests, committees, meetings, meeting_requests, committee_members, decisions, votes, tokens, jobs, audit_logs,
status_history, stage_logs, approvals, meeting_attendees, notifications) matched its pre-walk-through value exactly. A
small separate commit removed one empty `decisions.confirmApprove` locale stub a prior key-cleanup pass had left behind
in both `ar.json`/`en.json`.

---
### 2026-09-27 EET — Claude — Decision wizard, sub-project 2, slice 1 (request side) complete

`committee_actions` on the request payload (`CommitteeStatusService::refusal()` is the one check `move()` and the
payload share); the request wizard now offers «طلب استكمال», «الإحالة للمراجعة القانونية» and the legal member's
opinion (card in Checks, five verdicts in Choose). `WizardShell`/`WizardSlip` extracted from `DecisionWizard`. The
candidates list and the legal-review queue now link to the file's wizard; their prompt/modal and the request page's
send-to-legal button are gone. Inbox `candidate`/`legal_review` tasks open `request_details?decide=1`. Verified: suite
**773 / 4967**, Pint clean, build passes (`dist` reverted then rebuilt for this commit), parity **2072**, check-layout
(pre-existing `/decisions` 1024px overflow only). Headless walk-through (Puppeteer, both accounts, ar/en × 1280/375 ×
light/dark, `committee_actions` injected onto a real request payload since no seeded file sits at
`receive_from_committee`/`registered`): as r02.reviewer@ the wizard's Choose step lists both committee moves; as
r11.legal@ Checks shows the legal basis card and Choose lists all five verdicts; zero console errors, no overflow at
any combination. Never submitted.

---
### 2026-09-27 EET — Claude — Implementation plan: decision wizard, sub-project 2 (committee duties)

User (brainstorming + /frontend-design): every committee duty in «المهام المعلقة» moves into a wizard and the old
controls are **replaced**. Three wizards by object: the request wizard gains the committee's moves (`require_completion`,
`send_to_legal_review`, the legal opinion); a new `AgendaItemWizard` takes the vote and the recorded result; a new
`MeetingWizard` takes the date answer, agenda adoption, convening, the minutes' generate/approve/return/sign and closing.
Server: `committee_actions` on the request payload, `GET meetings/{m}/duties` and `GET meetings/{m}/agenda/{item}/duties`,
each "blocked" reason being the endpoint's own refusal (`DecisionTally`, `MeetingDuties`). Confirm is a paper slip
(تأشيرة / ورقة تصويت / مسودة القرار / قيد في سجل الجلسة). No migration. Three slices, each verified and committed.
Spec and plan are local under `docs/superpowers/` (git-ignored).

---
### 2026-09-26 22:40 EET — Claude — Decision wizard, sub-project 1 (complete)

Built per the plan below; no migration. The request page's action card, its two top gate panels, the inline Art. 45
form and both hand-rolled dialogs are gone. They are replaced by «اتخاذ القرار», which opens `DecisionWizard.vue`
(Review → Checks → Choose → Confirm as a تأشيرة); My Tasks' request tasks carry `?decide=1` and open it directly.
`RequestController::blockReason()` is now the one predicate behind `transition()`'s refusals, the hidden buttons and
the new `blocked_transitions`, so the three cannot drift. **Deviations:** the Checks step keys on the action the user is offered,
not on a grant, and lives in the wizard (no `lib/decisionSteps.js`). The comment now follows `requires_comment` (the
page used to demand a reason on every exception). The vacuous Stage 56 "suggested route" badge was dropped. The Art. 45
form stays read-only in the البوابات tab. Verified: suite **769 / 4951**, Pint clean, build passes (`dist` reverted),
parity 2084, `check-layout.mjs` fails only on the pre-existing `/decisions` 1024px overflow. Headless walk-through on the real data (manager, ar/en, 1280/375, light/dark; `requirements_check` via a
mocked GET) found no overflow or console errors. It never submitted; submission is covered by tests. **Next:**
sub-project 2 (committee duties) and 3 (post-decision/records) — each needs its own spec.

---
### 2026-09-26 21:42 EET — Claude — Implementation plan: decision wizard, sub-project 1 (request-page decisions)

User (/frontend-design + brainstorming): every user gets a multi-step wizard for deciding on a request. **Decisions
taken with the user:** it eventually covers everything in «المهام المعلقة», split into three sub-projects: (1) this
one, the wizard shell plus the request-page decisions; (2) committee duties; (3) post-decision work and records. The
wizard **replaces** the action card, opens over the request page (from My Tasks via `?decide=1`), and its Review step
shows without forcing. Plan: `DecisionWizard.vue` (Review → Checks → Choose → Confirm, where Confirm renders the
decision as a تأشيرة slip), reusing `DocumentValidityPanel`/`IntakeGatePanel` and a `JurisdictionTestForm.vue`
extracted from the page. `detailResource()` adds `to_stage`/`to_status` to each transition, plus `blocked_transitions`
with one shared `blockReason()`. My Tasks' request routes carry `decide`. No migration. Verify: new payload test, full
PHPUnit, Pint, build (revert `dist`), parity, `check-layout.mjs`, and a headless walk-through by role.

---
### 2026-09-26 21:05 EET — Claude — Document validity is the manager's gate; both gates sit at the top (complete)

Built per the plan below; no migration. `forward` at `direct_manager_review` goes through `controlGateRefusal()`, so the
button is hidden, and the endpoint returns 422, until every attachment has a card and none is `doubtful`. A file with
no attachments forwards freely. The validity route now rides `request_details,view` and the controller returns 403 to
anyone except the live manager (or R08 when there is none). The Stage 83 panel's validity section is read-only.
`PendingTaskCollector` still lists that `forward` as the manager's task, which is intended: the card it opens explains
what to do. Verified: suite **769 / 4938** (+5 validity tests), Pint clean, build passes (`dist` reverted), parity 2059.
Checked live (GET only) as r02.reviewer@ on files 40 and 51: `can_record` true, forward withheld.
Not screenshotted.

---
### 2026-09-26 20:45 EET — Claude — Implementation plan: document validity becomes the manager's gate; both gates move to the top

User: «التحقق من صحة المستندات» is the manager's task and «البوابة الأولى» is the مقرر's; both belong at the top of the
request page next to «موافقة وإحالة». **Decisions put to the user and answered:** only صاحب العلاقة's live manager
records validity, at `direct_manager_review` (R08 only when there is no live manager, via the same
`adminMayUnstick()` rule); R02/R03 lose it. It becomes a gate: `forward` there is refused while any attachment is
unchecked or `doubtful`. Plan: `WorkflowService::mayActAsSubjectsManager()`, `DocumentValidityRules::forwardRefusal()`
read by `controlGateRefusal()`, the validity route leaves `meeting_outputs,edit`, `control_gates.document_validity` feeds
a new `DocumentValidityPanel.vue` above the action panel; gate 1's panel moves above it at `requirements_check`.
Verify: DocumentIntegrityTest updated + new cases, full PHPUnit, Pint, build (revert `dist`), parity, live check.

---
### 2026-09-26 20:40 EET — Claude — «المهام المعلقة» lists every pending duty (complete)

Built per the plan below. No migration, no endpoint. `PendingTaskCollector` has six new sources, and each of their tasks
carries an `action` code that `MyTasksView` renders from `myTasks.actions.*`. `AppealsView` now seeds its filter from
`?status=`. The approval icon was fixed: `user-check` is not an AppIcon name.
**Deliberate limits:** `record_decision` lists tied or under-majority items (the chair must still act), and `convene`
waits for the meeting day. `workflow_step`/`overdue` call `availableTransitions()` once per pre-filtered row (`ponytail:` note).
Verified: 12 new tests fail on the old collector and pass on the new one. Pint is clean, the build passes (`dist`
reverted), and parity is 2056. On the real MySQL, `r02.reviewer@` now gets its 2 files at `direct_manager_review`,
both over HTTP and via the service.

---
### 2026-09-26 20:02 EET — Claude — Implementation plan: «المهام المعلقة» lists every pending duty

User: the inbox must hold every action waiting on the user (e.g. a manager approving an employee's request, which is
missing today). All 125 write endpoints were sorted into *pending duties* (state says someone must act next) and
*on-demand* actions; the user chose to cover every duty group. `PendingTaskCollector` gains six sources, each task
carrying an `action` code: `workflow_step` (non-exception rules other than approve/submit, read through
`WorkflowService::availableTransitions` so the inbox and the buttons agree), `overdue` (R08's `deadline_expired`,
minus the ministry self-loop), `meeting_duty` (adopt agenda, convene, record decision, generate/review minutes,
close the sitting), `post_decision` (soundness, execute, both archives, close, approval return/referral, lift
suspension), `open_record` (corrections — never to their recorder — conflicts, special cases, withdrawals) and
`appeal` (each next step + nomination). Each condition is the endpoint's own refusal list as SQL, scoped by
`RequestVisibility`/`MeetingVisibility`. No migration. Verify: MyTasksTest per source, full PHPUnit, Pint, build
(revert `dist`), locale parity, live smoke.

---
### 2026-09-26 19:44 EET — Claude — Dashboard distribution labels show the full name

User (/frontend-design): the status, stage and department bars cut names off. The label was a fixed `minmax(90px, 34%)`
column with an ellipsis. In DashboardView each panel now sizes one shared label column (subgrid) to its longest name,
capped at 60%, and a longer name wraps instead of truncating. CSS only. Screenshots at ar/en 1280 and ar 375 show no
clipped label and no page overflow; build passes (`dist` reverted). (Clock: the machine reads 19:44, earlier than the
entry below; this entry's position shows the order.)

---
### 2026-09-26 20:35 EET — Claude — The manager approves in one click (complete)

Built per the plan below; no migration. The manager's `forward` at `direct_manager_review` now lands on
`receive_and_register` + `routed_to_hr`, and the button reads «موافقة وإحالة» / «Approve & forward». Reseeded on the
real MySQL and confirmed by query. It still holds 2 files at `administrative_routing`; they leave through the kept
`route_to_hr` row. `RequestTimeCard` T1/T2 split at HR arrival when a file never passed routing (new test). Full suite
**754 / 4816**, Pint clean on touched files, build passes (`dist` reverted), parity 2024.

---
### 2026-09-26 20:15 EET — Claude — Implementation plan: the manager approves in one click

User: the employee's manager should approve with one button that moves the request on. Today it is two clicks —
`forward` (to `administrative_routing`) then `route_to_hr` — and since Stage 96 the second has only one option. Plan:
the manager-gated `forward` row lands straight on `receive_and_register` with `routed_to_hr` (the status R12's
`register` row requires). `administrative_routing`'s rows stay, so a file already parked there can still leave
(Stage 102 precedent). `RequestTimeCard` T1/T2 fall back to arrival at `receive_and_register`; the button label
becomes «موافقة وإحالة». Verify: tests updated, full PHPUnit, Pint, build (revert `dist`), parity, reseed.

---
### 2026-09-26 19:55 EET — Claude — Department hierarchy view on the Users screen (complete)

Built per the plan below. One migration (`departments.manager_user_id`), applied to the real MySQL. The Users screen has
a «Table / By department» tab switch; `DepartmentHierarchy.vue` shows the tree, the head card and the staff, with
make-head, a «move to» select, and HTML5 drag onto a tree node (the grip is hidden on touch, where the select is the
path). The tree flattening moved to `lib/departmentTree.js`, shared with DepartmentsView, whose form gained a head picker.
Full suite **753 / 4817** (+7 `DepartmentHeadTest`), Pint clean, build passes (`dist` reverted), parity 2024. A
headless click-through ran make-head, the select and a real drop, then restored the data. That left only audit rows.
`check-layout.mjs`: 440 views, failing only on the pre-existing `/decisions` 1024px overflow. The head is a label only (AGENTS.md).

---
### 2026-09-26 19:10 EET — Claude — Implementation plan: department hierarchy view on the Users screen

User: a department-first view of staff where add/edit/delete is easier; decisions put to the user and answered:
an **explicit department head** (not derived from `manager_id`), plus a «make head» button and drag-and-drop moves
inside the view. Plan: migration `departments.manager_user_id` (nullable FK); `UpdateDepartmentRequest` requires the
head to be an active member of that department; `UserController::update`/`destroy` clear a head slot the user leaves.
The head is a **label only** — approvals still follow each employee's own `manager_id`. SPA: a Table/Hierarchy
switch on UsersView; the new `components/DepartmentHierarchy.vue` reuses the existing modal and the partial
`PUT users/{id}` / `PUT departments/{id}` (no new endpoint); native HTML5 drag-and-drop with a keyboard «move to»
fallback; head picker in the Departments form. Verify: new feature test, full PHPUnit, Pint, build (revert `dist`),
locale parity, `check-layout.mjs`, migrate the real DB.

---
### 2026-09-26 18:50 EET — Claude — Timeline documents redesigned (complete)

Built per the plan below. `TimelineDocuments.vue` now renders the documents of every «سجل سير العمل» entry in both views; each view's own list and its CSS are gone. **Gotcha:** the global `.ltr` helper also sets `text-align: left`, which in Arabic throws a filename to the far side of its row, so the component uses `direction: ltr; justify-self: start` instead. Build passes (`dist` reverted). `check-layout.mjs` fails only on the pre-existing `/decisions` 1024px overflow; it skips `/requests/:id`, so that page was checked by screenshot (ar light and en dark at 1280px, ar at 375px). Not seen rendered: a decision row, since no seeded timeline entry carries one.

---
### 2026-09-26 18:25 EET — Claude — Implementation plan: redesign the documents under each timeline entry

User ran /frontend-design on the «سجل سير العمل» attachment rows fixed just below. Plan: one new `components/TimelineDocuments.vue` used by RequestDetailView and RequestTrackingView (they had drifted — the tracking copy dropped kind and reference). A tinted tray per entry; each row = kind icon (file-text / scale, kind named for screen readers) + label over its LTR reference + folder name at the inline end. A committee decision is the one row in `--color-brand-text`. Tokens only, no new locale keys. Verify: build (revert `dist`), `check-layout.mjs`.

---
### 2026-09-26 18:10 EET — Claude — Timeline attachment rows no longer draw the connector line

Bug fix, CSS only. In «سجل سير العمل» (RequestTrackingView and RequestDetailView) the rail rules were `.timeline li`, which also matched each entry's nested `.entry-documents li`, so every attachment row got the timeline grid, padding and vertical connector line. Both now target direct children only; the workspace also gained the `.entry-documents` styles it never had (it rendered as a bulleted list). Build passes (`dist` reverted).

---
### 2026-09-26 17:55 EET — Claude — Forms beside tables are now modals (complete)

Built per the plan below; frontend only, no PHP touched (so no PHPUnit/Pint run). New `AppModal.vue` wraps the create/edit
forms of Departments, Users, Request Types, Settings, Templates, Meetings (committee form, scheduling wizard, and the
whole «manage members» panel, which was an expanded table row), Appeals (create + upload step, and six action
panels), Agenda Builder's add-item box and Meeting Detail's propose-date and add-to-agenda boxes. Form logic is
unchanged: each view's own open flag drives the modal, and 422s now render inside it. Escape closes and a backdrop
click deliberately does not. New `common.close`; `committees.hideMembers` removed (parity 2007). The convention is in AGENTS.md.
Verified: build passes (`dist` reverted); a headless click-through of all 12 triggers × {en, ar} × {1280, 375} found
every dialog opening, focused and fitting, closing on Escape and returning focus, with no console errors.
`check-layout.mjs` fails only on the pre-existing `/decisions` 1024px overflow. Not exercised: propose-date, because
no seeded meeting is in a proposable state.

---
### 2026-09-26 17:19 EET — Claude — Implementation plan: forms beside tables become modals

User: "all the pages that have a form and something else (table), the form should be a modal". Scope, confirmed with
the user: Departments, Users, Request Types, Settings, Templates, Meetings (committee form, scheduling wizard,
add-member), Appeals (create + six action panels), and the add boxes in Agenda Builder and Meeting Detail. Plan: one
new `components/AppModal.vue` (Teleport, Escape closes, backdrop click does not, focus in/restore) and a
`.modal-wide` plus desktop scrolling in `style.css`; each form is wrapped in it with its state logic unchanged.
Frontend only. Verify: build (revert `dist`), locale parity, `check-layout.mjs`.

---
### 2026-09-26 17:10 EET — Claude — Deliberation requires a convened meeting too (complete)

Built per the plan below; this closes the "Not gated" point in the 16:55 entry. Item state and study-sequence
changes now return `MeetingController::MEETING_NOT_CONVENED` before convening. That check comes before the adoption
check, so `CommitteeOwnActsTest`'s adoption test now convenes its meeting first. A new test there covers the rule.
Full suite **746 / 4798**; Pint is clean on the touched files. No frontend change: the live runner shows the Arabic 422.

---
### 2026-09-26 17:04 EET — Claude — Implementation plan: deliberation requires a convened meeting too

User confirmed the open point from the entry below. Plan: `updateItemState` and `updateStudySequence` in
`MeetingController` refuse (422, new `MEETING_NOT_CONVENED`) until `convened_at` is set, next to their
`AGENDA_NOT_ADOPTED` check. Agenda adoption itself stays allowed before convening, because Art. 12 (أ) 3 puts it
«قبل الاجتماع». Verify: refusal test, fixture updates found by the full suite, and Pint.

---
### 2026-09-26 16:55 EET — Claude — A vote requires a convened meeting (complete)

Built per the plan below. `DecisionEligibility` now refuses a vote with `MEETING_NOT_CONVENED` (422) until
`convened_at` is set, and `pendingVotesQuery()` filters on the same column. New
`StudySequenceTest` case reproduced the bug first (201 before the fix). Full suite **745 / 4793**; Pint is clean on
the touched files. Frontend and locales unchanged. **Not gated:** the study-sequence and item-state endpoints still
check agenda adoption only, so a chair can tick Art. 85 steps before convening; the vote itself still waits for convening.

---
### 2026-09-26 16:48 EET — Claude — Implementation plan: a vote requires a convened meeting

User: "Voting doesn't check that the meeting was convened." Confirmed: nothing on the vote path reads
`meetings.convened_at` (set only by `MeetingReadinessController::convene()`). Plan: one condition in
`DecisionEligibility` — both `reasonBlockingVote()` and its SQL twin `pendingVotesQuery()`, so the endpoint and
the worklist still agree. `record()` needs no check: zero votes are already refused. Fixtures: `RunsStudySequence`
also stamps `convened_at`, and `StudySequenceTest`'s fixture convenes. Verify: a new refusal test, full PHPUnit,
Pint. (Clock note: the machine reads 16:48 while the entry below is stamped 17:25; position gives the order.)

---
### 2026-09-26 17:25 EET — Claude — The مقرر is told of every answer to a proposed date (complete)

Built per the plan below, no migration. Each `respond` sends `meeting_invitation_response` to the committee's
`rapporteur` seat holder (the responder excluded), carrying `response` and `meeting_confirmed`; the last
acceptance says the meeting is confirmed. It links to the meeting through the existing `meeting_id` handling.
Full suite **744 / 4783**, Pint clean on touched files, build passes (`dist` reverted), locale parity 2007.

---
### 2026-09-26 17:10 EET — Claude — Implementation plan: tell the مقرر when a member accepts or declines the date

User: "a notification tells the مقرر when someone declines and approves". Plan: new event
`meeting_invitation_response` (in-app + email, the `meeting_scheduled` defaults) and
`MeetingInvitationResponseNotification`, sent from `MeetingController::respond()` through a new
`NotificationDispatcher::invitationAnswered()` to the committee's `rapporteur` seat holder (never the
responder). The body names the member and the answer, and says so when that answer confirmed the
meeting. Locale label in both files. Verify: extend `CommitteeSinglePathTest`, full PHPUnit, Pint,
build, parity. No migration.

---
### 2026-09-26 16:45 EET — Claude — Stage 102 complete (the single committee path)

Built per the plan below; **no migration**. R02's approve now lands on `receive_from_committee` + `registered`;
the rows out of stages 6–8 are removed by a targeted delete, so a re-seed cleans old databases too. The pending
list is now the agenda's only source. It also takes `reopened_by_appeal` and `reopened_for_representation`:
without them, a file that reopenAtStage() sent back to the committee could never be scheduled again. Seats are
now role-bound and required, only R02 schedules, and there is one meeting per month. New status
`pending_confirmation`, plus the `POST meetings/{m}/respond` endpoint and a «مهامي» inbox source. Ad-hoc
attendees, staff-set RSVPs, admin/emerging items and `nominate` are gone.

Checks: full suite **743 / 4772** (the pre-existing tests were updated in place, each with a comment, and the new
`CommitteeSinglePathTest` was added). Pint is clean, the build passes (`dist` reverted), locale parity is 2006,
and checkbox parity is 362. The real MySQL was reseeded and confirmed by query: 38 rules, 0 touching stages 6–8.
A live HTTP smoke run on Homestead also passed, and its fixtures, tokens, jobs and audit rows were removed.
`check-layout.mjs` fails only on the pre-existing `/decisions` overflow at 1024px.

**Watch:** the live committee #16 cannot schedule until its five seats are filled. Four open items are in
OPEN_ITEMS.md.

---
### 2026-09-26 15:35 EET — Claude — Implementation plan: Stage 102 (the single committee path)

User: after the مقرر approves a request it joins a pending list; the مقرر schedules one meeting a month,
invites the five Art. 10 (أ) seats, picks requests from that list and proposes a date; every member must
accept the date; the sitting discusses and votes — «this path is the only path». **Decisions put to the user
and answered:** drop the extras (one meeting type, only the five seats invited, no administrative/emerging
items) but keep appeal items; R02's approve goes straight to `receive_from_committee` (stages 6–8 unreachable,
rows kept); a decline keeps the meeting `pending_confirmation` and a date change resets every answer; seats
bound to roles chair→R03, legal→R11, hr_director→R12, ministry_delegate→R04, rapporteur→R02 (R10 stays
duty-less). Plan: seeder rewiring + targeted deletes, candidate list widened and made the agenda's only
source, `POST meetings/{m}/respond`, a monthly and all-seats-filled check on `store()`, R12 gains the voting
grants, `meetings,add` → R02 only, SPA pickers switched to `/committee-candidates`. Verify: new
`CommitteeSinglePathTest`, full PHPUnit (baseline 742/4743), Pint, build, parity, reseed on the real MySQL.

---
### 2026-09-26 EET — Claude — R08 unsticks manager-less files; admin re-submit fixed (complete)

Built per the plan below, logic only (no migration, seeder rows, frontend or locale change). `actorMayUse()` gains
`adminMayUnstick()`, and a new `pickRule()` replaces the "exactly one match" check in both `availableTransitions()`
and `transition()`, so the preview and the endpoint cannot disagree. AGENTS.md's manager-gate bullet is rewritten; it also
listed "three `route_to_*`", stale since Stage 96. Full suite **742 / 4743** green, Pint clean on touched files.
**On the real DB nothing changes for the four open files:** their subject (`r01.employee@`) has a live manager
(`r02.reviewer@`), so they are that manager's to move, not the admin's. Not done: stalled files don't notify R08,
and the task inbox doesn't list them; an admin finds them on the requests list.

---
### 2026-09-26 EET — Claude — Implementation plan: R08 may unstick a manager-less request; admin re-submit un-ambiguated

User: "admins can not submit or approve requests". Probed the real DB: both R08 accounts hold every screen grant
but `availableTransitions()` offers them nothing, by design (manager-gated rows have no override; approve rows
are R02/R03/R05/R06/R07). **User decision, put to them and answered "unstick only"** — this narrows AGENTS.md's
"no admin override" rule rather than removing it: `actorMayUse()` lets R08 use a manager-gated row ONLY when
صاحب العلاقة has no live manager (none, inactive, or deleted) and R08 is not an interested party itself. A live
manager still owns the step exclusively; approvals stay role-segregated. **Bug fixed alongside:** an R08 filer
re-submitting its own returned request matched both `submit` rows (creator row + R08 exception) → "ambiguous";
now the normal-path row wins when exactly one non-exception row is allowed. Verify: DirectManagerRoutingTest's
two no-override tests inverted to the new rule, a re-submit test, full PHPUnit, Pint, AGENTS.md bullet updated.

---
### 2026-09-23 EET — Claude — scripts/check-layout.mjs added (the frontend's one automated check)

Built per the plan below; AGENTS.md's build section now points at it. Full run: 29 routes × {375, 768, 1024, 1280,
1440} × {ar, en}, 430 views incl. every tab, ~12 min. **It fails today, for a real reason:** `/decisions` register
at 1024px overflows its card in both locales (logged in OPEN_ITEMS.md, not fixed here). Proven both ways: a bogus
token makes every page fail "landed on /login" instead of passing. **Worth knowing:** with the Chrome now in
`~/.cache/puppeteer`, dropping the proxy no longer reproduces the 09-22 block, so the proxy stays as insurance, and
the URL guard is what actually protects the check. Also: this session's Bash/PowerShell sandbox blocks all network
(even example.com), so the check and `curl abusaleem.test` need the sandbox off; Homestead itself was fine.

---
### 2026-09-23 EET — Claude — Implementation plan: save the headless-Chrome layout check as scripts/check-layout.mjs

Two sessions (2026-09-22) each wrote a throwaway Puppeteer sweep for sideways overflow; one found the SPA
silently bounces to /login when Chrome blocks `localhost:5173 → abusaleem.test`, so a naive sweep "passes" on
the login page. Plan: one `scripts/check-layout.mjs`, **no new dependency** — Puppeteer is resolved from the
npx cache (mermaid-cli already put it there) or `PUPPETEER_PATH`. It logs in as R08 via Node `fetch`, serves
every API call from Node through `setRequestInterception`, reads the static routes from `router/index.js`
(so it cannot drift from the route table), and fails any page whose final URL is not the one requested.
Desktop widths check page- and element-level overflow; 375/768 check the page only (in-card table scroll is
accepted there). Verify: run it against Homestead + `npm run dev`, and prove it fails on an injected overflow.

---
### 2026-09-23 EET — Claude — Vulnerable dependencies patched; now on Laravel 12.61.1

Built per the plan below: laravel/framework 11.55.0 → **12.61.1**, guzzle 7.15.1 → 7.15.5, commonmark
2.8.3 → 2.10.3 (both came in through the same `-W` resolve), and nanoid/postcss via `npm audit fix` (lockfile
only). `composer audit` and `npm audit` both report zero. **No application code changed**: none of the Laravel 12
breaking-change surfaces apply here (no `HasUuids`, no `image` rule, and the `local` disk root is already explicit).
Full suite **741 / 4738**, the same as the Laravel 11 baseline taken first. `npm run build` passes (`dist` reverted).
Pint only flags the pre-existing `scripts/build-guide-pdf.php`. **Gotcha:** 12.61 moved
`ReflectsClosures` to `Illuminate\Reflection`, and the first `composer require` left a stale optimized classmap
(fatal "trait not found"); a plain `composer install` re-dumped it. **The next cPanel release must ship the new `vendor/`.**
The host still needs only PHP ≥ 8.2. Homestead was down, so there was no live HTTP smoke run. Machine-level:
global Composer TLS re-enabled, and Composer itself was self-updated 2.7.6 → 2.10.3; the old version was also flagged by `diagnose`.

---
### 2026-09-23 EET — Claude — Implementation plan: patch vulnerable dependencies (Laravel 11 → 12)

`composer audit`: 15 advisories in laravel/framework, guzzlehttp/guzzle, league/commonmark; `npm audit`
(frontend): nanoid (high), postcss (moderate). **laravel/framework has no fixed 11.x release** — every
advisory is fixed only in ≥12.60/12.61.1 or 13.x, and 13 needs PHP 8.3 (this machine: 8.2.12), so the
fix is `laravel/framework ^12.61.1` plus whatever 12 needs of its companions, then `composer update`
for guzzle/commonmark and `npm audit fix` in `frontend/`. Machine-level: the global Composer config had
`disable-tls=true`/`secure-http=false`; both were unset *before* downloading anything. Verify: both
audits clean, full PHPUnit, Pint, `npm run build` (revert `dist`).

---
### 2026-09-23 EET — Claude — Agent-tooling cleanup complete

Built per the plan below. This file went 1.37 MB → 148 KB: 31 entries kept, 197 moved unedited into
`agent-notes-archive/2026-07|08|09.md` (the script refuses to write unless kept + moved reproduces the original body
byte for byte; entry counts 228 = 31 + 197; a rerun is a no-op). **Search the archive before concluding history is
missing** — AGENTS.md now says so. `graphify-out/20*/` snapshots are untracked (15 files) and ignored;
`.git-ftp-ignore` now skips graphify-out, tests, scripts, `.claude`, the archive and the root planning docs.
`scripts/check-locale-parity.mjs` reports 2011 keys each side; in `--hook` mode it was proven to exit 2 on a probe
key and 0 on non-locale files. Hooks: parity after `Edit|Write`, `graphify update .` on Stop (confirmed it runs).
No PHP/Vue touched, so no PHPUnit/Pint/build run was warranted.

---
### 2026-09-23 EET — Claude — Implementation plan: agent-tooling cleanup (notes archive, ignores, skills, hooks)

User approved: this file was 1.37 MB (~340K tokens) and `CLAUDE.md` imports it every session. Plan: new
`scripts/archive-agent-notes.mjs` keeps it under 150 KB by moving the oldest entries **unedited** into tracked
`agent-notes-archive/YYYY-MM.md` (not `docs/` — that folder is git-ignored, so Codex would never see it); AGENTS.md's
"never prune" rule becomes "never edit or delete; archive past 150 KB". Also: stop tracking dated
`graphify-out/20*/` snapshots, widen `.git-ftp-ignore`, add `scripts/check-locale-parity.mjs`, four project skills
(`plan-stage`, `verify-and-commit`, `new-screen`, `cpanel-release`) and two hooks (parity after locale edits,
`graphify update .` on Stop). Verify: the script's own byte-identity check, parity script exit codes, settings JSON.

---
### 2026-09-22 EET — Claude — A concluded meeting is read-only (complete)

Built per the plan below: one guard in `CheckMeetingMembership` (any non-safe method on a `completed` meeting → 422
`meeting`). New `ConcludedMeetingTest`; `MeetingReadinessTest`'s "convene refused once not scheduled" case now uses a
`cancelled` meeting, since a completed one is refused by the new guard first. Full suite 741/4738 green, Pint clean.
No frontend change: edit buttons on a completed meeting still render and now surface the Arabic 422.

---
### 2026-09-22 EET — Claude — Implementation plan: a concluded meeting is read-only

User: "a meeting that has concluded should not be modified in any way". Plan: every meeting-bound route already
passes through `CheckMeetingMembership` (`meeting.member`), so one guard there refuses any non-read request on a
meeting whose `status` is `completed` (422, Arabic) — covers update/delete, attendees, agenda, readiness, live
runner, votes/decisions, conflict declarations, memo and minutes in one place. **Deliberately exempt:**
`outputs/{item}/execute` (not behind `meeting.member`; execution happens after the sitting by design). `cancelled`
is not treated as concluded. Verify: new feature test, full suite, Pint.

---
### 2026-09-22 EET — Claude — No sideways scrolling on desktop (≥1024px)

User: side-scrolling pages are not acceptable on desktop. Plan and result, CSS only: the eight views that forced
`.data-table { min-width: 720–1150px }` now apply it only under `@media (max-width: 1023px)` (phones keep the
in-card scroll the entry below accepted), `style.css` lets `th` wrap, breaks long cell text anywhere and wraps `.tabs`
from 1024px up, and DecisionsView's 13-number vote tally lost its `nowrap` (it alone held the register at 893px in a
670px card). Verified in headless Chrome: 29 screens × {1024, 1280, 1440} × {AR, EN}, plus every tab of registers
(12), reports, decisions — zero page- or element-level horizontal overflow. **Gotcha:** current Chrome blocks
`localhost:5173 → abusaleem.test` in Puppeteer (`ERR_NETWORK_ACCESS_DENIED`, the disable-features flags didn't help)
and the SPA silently bounces to /login, so a naive sweep "passes" on the login page — proxy the API through
`page.setRequestInterception` + Node `fetch` instead, and assert the URL isn't /login.

---
### 2026-09-22 EET — Claude — SPA made responsive / mobile-friendly (CSS only)

User request: "turn this system responsive and mobile friendly". Plan: the shell already had an off-canvas
sidebar below 1024px and the viewport meta, so this is a CSS pass on what breaks at 375px, not a rebuild.
Done: one `@media (max-width: 640px)` block in `style.css` (tighter `.card-pad`/`.page`/table cells,
stacked `.heading`, single-column `.field-grid`, scrollable modals with wrapping actions, 2.75rem `.btn`,
and a 16px floor on form fields — `!important` because scoped `font: inherit` rules outrank it, and iOS
zooms any field under 16px); `.shell` gets `100dvh`; the topbar tightens and truncates a long title; the
four tables with no horizontal scroll (Users, Departments, both in MeetingsView) now scroll sideways; and
every `auto-fit` grid minimum of 220–300px became `min(Npx, 100%)` so it can't exceed the phone width.
**Deliberately not done:** wide registers stay tables that scroll sideways (card layouts would be a rewrite
of 16 views). `npm run build` passes (`dist` reverted); Verified afterwards in headless Chrome (Puppeteer from
the npx cache): 29 screens × {375, 768}px × {AR, EN} = 116 page views with no page-level sideways overflow, and
the drawer opens/closes on the correct side in both languages. That pass found **a real pre-existing bug, fixed**:
AppSidebar's `:global(html[dir='rtl']) .sidebar.mobile` compiled to a bare `html[dir='rtl']` (Vue drops
everything after `:global()`), so below 1024px in Arabic the whole document was translated off-screen — every
page blank since the 2026-07-30 shell restyle. Now `:root[dir='rtl'] .sidebar.mobile:not(.open)`. **Never put a
descendant selector after `:global()` in a scoped block.** The dashboard trend also scrolls in its own card now. Note found in passing: the print
block's `.shell { height: auto }` is outranked by AppLayout's scoped `.shell[data-v]`, pre-existing and untouched.

---
### 2026-09-21 22:30 EET — Claude — The filer attaches only at stage 1

User follow-up to the entry below: the filer may attach **only while the request is at stage 1**
(`receive_from_municipality`), i.e. before it reaches the manager. Intake auto-hops past stage 1, so in practice the
filer attaches with the intake itself and again only after a return (`return_to_employee`, `return_missing_docs`).
One condition in `Request::attachmentRight()`; the executor and HR carve-outs are unchanged. **Decision put to the
user and taken: a committee "completion required" gap cannot be filled at the committee stage by anyone** — the
committee defers or the file goes back through a return. Consequence worth knowing: Appendix 25's voting freeze in
`AttachmentController::store()` is now unreachable by any permitted uploader (no one allowed to attach can reach a
file with an open vote); it stays as a backstop and `StudySequenceTest` says so. Five fixtures moved to stage 1 (their
subjects are folder derivation, file types, classification — not the stage) and Stage 91's loop test was rewritten to
pin the refusal. Full suite **735 / 4709**, Pint clean, build passes, no migration.

---
### 2026-09-21 22:05 EET — Claude — Filer-only attachments + filer re-submit complete

Built per the plan below. One migration (`workflow_transitions.requires_creator`), applied to the real MySQL, and
`WorkflowTransitionSeeder` reseeded (confirmed by query: `submit` = `required_role_id null, requires_creator true`;
the R08 override untouched). Full suite **734 tests / 4709 assertions** (baseline 729/4684: +5 new in
`FilerAttachmentsAndReturnTest`), Pint clean on every touched file, `npm run build` passes (`dist` reverted), no
locale change. The rule is `Request::attachmentRight()` (`filer` | `executor` | `hr_service_file` | null), read by
`AttachmentController::store()` and exposed as `can_attach` on `RequestResource`, which gates both upload widgets.

**Deviation from the plan, deleted rather than kept:** the planned `NotificationDispatcher::actorsForStage()`
creator branch was dead code — every caller either sends the filer the owner's `stage_changed` notice and excludes
them from the duplicate prompt, or already unions the owners in. The actual notification fix is the row losing its
R01 role, so bystanders are no longer prompted; a test pins both halves. **Four pre-existing tests needed
legitimate updates**, each commented in place: an R08 upload (now the admin is the filer), Stage 91's officer
completing a gap (now refused, and the filer closes it), R12's `other` إفادة at registration (now refused; a
service-file row is accepted), and a stranger R01 at intake getting **404, not 422**, which is the privacy leak
closed. **Open item:** R02's `cancel` row at intake still shows every returned file to every R02 (OPEN_ITEMS.md).

---
### 2026-09-21 21:30 EET — Claude — Implementation plan: only the filer attaches; the manager returns with a note; the filer re-submits

User request: "attaching files can only be done by the request submitter; the submitter's manager reviews the
request and files and sends it back if it needs modification or documents are missing, adding a note". Scope put to
the user and answered: submitter-only **everywhere**, with exactly two carve-outs — (1) execution proof
(Appendix 70) by a `meeting_outputs,approve` holder while the file is `in_execution`, and (2) R12 uploading a
`service_file`-section document at `receive_and_register` (Stage 98's duty; the agenda gate demands those rows be
genuinely attached). No R08 override. Stage 91's staff-completion upload path is deliberately closed: completion now
goes back to the filer through a return.

**The manager half already exists** — `return_to_employee` at `direct_manager_review` requires a reason, lands on
`returned`, and Art. 101's moment-8 notice quotes it. What was missing is the return leg: `submit` is **R01-gated**,
so an on-behalf filer (R02/R05, Stage 95) could not re-submit, and — a real privacy leak found while checking — a
returned request sat at a stage whose outbound row names R01, so `RequestVisibility`'s assignment clause showed it to
**every** active R01 and `actorsForStage()` prompted every R01. Fix: a new `workflow_transitions.requires_creator`
flag (one migration), the `submit` row becomes `required_role_id = null, requires_creator = true`, and the three
readers of rule rows learn it: `WorkflowService::actorMayUse()` (actor must be the filer), `RequestVisibility` (a
creator-gated row grants no assignment visibility — the filer already sees their own file; without this a null-role
row would match EVERYONE via its `whereNull(required_role_id)` branch), `NotificationDispatcher::actorsForStage()`
(prompts the filer) and `RequestResponsibilityService` (Appendix 17 party `employee`). The intake auto-hop is
role-independent (`applySystemTransition`) and unaffected; the R08 `submit` override stays.

**Upload rule** lives once in `AttachmentController::store()` (403, Arabic); the SPA hides the widgets by the same
predicate. **Verify:** new feature tests (filer allowed / R02+R08 refused; both carve-outs and their bounds; the full
return→upload→re-submit loop incl. an on-behalf filer; the returned file no longer visible or notified to other R01s),
the pre-existing upload fixtures moved to the filer (legitimate updates), full PHPUnit, Pint, `npm run build` (revert
`dist`), locale parity, migration + `WorkflowTransitionSeeder` reseed against the real MySQL.

---
### 2026-09-21 20:40 EET — Claude — Stage 101 complete (the consultation and information layer) — Track N finished

Built per the plan below. **No migration.** Full suite **729 tests / 4684 assertions** (baseline 719/4653: +10 new
in `ConsultationLayerTest`, and no pre-existing test needed changing — the check that this was additive), Pint
clean on every touched PHP file, `npm run build` passes (`frontend/dist` reverted), locale parity **2011 keys each
side** (+1), `ScreenRolePermissionSeeder` reseeded against the real MySQL and confirmed by query
(`legal_review.can_view` = R02, R03, R08, R11; `meeting_agenda.can_add` = R02, R03, R08, R11), no pending migration.
**No live HTTP smoke run** — the behaviour is covered by feature tests only.

**The finding worth keeping: seven of the thirteen cells were already true, and the gap analysis was wrong about
them.** It was written before Stage 100 and never re-derived. الرئيس المباشر مطلع on row 1 was met by
`actorsForStage` sending `request_created` to the subject's manager (my first test asserted it and passed before
any code changed); HR مشارك on rows 6, 7, 9 and «دعم معلومات» holds because R12's `meeting_outputs,approve` puts it
in `$isCloser`, which shows it every registered file. **That last one is an accident of an unrelated grant** — a
future change to the execution tier would silently take HR out of four rows — so those cells are pinned by tests
rather than by a clause of their own. Adding a second, explicit clause would have doubled a rule that already
holds, which is the more expensive way to get the same protection.

**Built.** (1) `RequestVisibility::$isHrStudyCoOwner` is one clause over three stage ids instead of one:
`direct_manager_review`, `requirements_check`, `observations` — rows 2 and 4 are pre-قيد, where R12 saw nothing.
Bounded to exactly those stages: `administrative_routing` stays a 404 (a test pins the bound). (2) R12 joins
`requestCreated` (row 1). (3) `request_notice_copy` + `RequestNoticeCopyNotification`, sent from
`NotificationDispatcher::requestNotice()` to the subject's manager and active R12 users (row 14). Hooking the one
method both the observer and Stage 100's manual `notices/issue` call means neither can skip it. The copy names the
moment and the tracking number and **never the notice body** (Art. 102), carries no `issued_by`, excludes the actor
and the subject, and is in-app only. `EmployeeNoticeRegister` is unaffected: it reads `request_notice` rows only,
and `EmployeeNoticeTest` still passes. (4) `legal_review,view` → R03 (row 6). (5) `meeting_agenda,add` → R11 (row
7), the whole tier; `edit` stays R02/R03, and a test asserts R11 does not gain it.

**Test plans.** `TEST_PLAN.roles.md`/`.ar.md` gained four checks each (R03 queue read, R11 memo, two for R12) and
one R12 refusal was **rewritten, not added**: «open a request at any stage other than `observations` → 404» was
already false after Stage 92 and is now bounded to unregistered files outside stages 2, 5 and 7. Appendix A's two
cells changed; checkbox parity **359 each side**. **R03's counts were corrected to the live database — 24 screens /
21 sidebar** (the plan said 23 for both the section and the table, and 20/22 in the multi-role check); the rest of
that table remains stale in ways this stage did not cause, and its own note says so.

**Two residues, both in OPEN_ITEMS.md.** (1) A manager whose only role is R09/R10/R11 can see the file at
`receive_and_register` but not attach: الرئيس المباشر is a relationship, and writing rides `notes_attachments,add`.
No such manager exists in the seeded data; bypassing the screen-permission middleware for one relationship is a
larger change than one cell justifies. (2) The copy is in-app only. Also fixed while there: AGENTS.md still said
«Tracks A–L (Stages 1–84) are all built».

**Track N is complete — Stages 94–101, every one of Appendix 6's 41 populated cells has an implementation.**
Nothing in STAGE_PLAN.md is unbuilt. Whoever picks up next should read OPEN_ITEMS.md; the four open items there
are the real backlog.

---
### 2026-09-21 19:45 EET — Claude — Implementation plan: Stage 101 (the consultation and information layer — Appendix 6's مشارك/مطلع cells)

The last Track N stage. **No migration, no new screen, no new endpoint** — every mechanism is one Stages 47, 68,
87 and 100 already established. Cell-by-cell against the live code (not the gap analysis, which predates Stage
100's widening of R12's reach): note and attachment writes are `notes_attachments,add` + `RequestVisibility::canView`,
so a party «participates» in a file iff it holds that grant *and* can see the file.

**Already true, by accident — pinned with tests, not rebuilt.** الرئيس المباشر مطلع on row 1 (`requestCreated`
already goes to the subject's manager through `actorsForStage`); الرئيس المباشر مشارك on row 3 (Stage 98's clause
plus `notes_attachments,add` for R01–R07); الموارد البشرية مشارك on rows 6, 7 and 9 and «دعم معلومات» — R12 holds
`meeting_outputs,approve`, so `$isCloser`'s `reference_number` clause shows it every registered file. That last one
rides an unrelated grant, so the tests are what stop a later change to `meeting_outputs` silently dropping HR.

**Built.** (1) `$isHrStudyCoOwner` in `RequestVisibility` widens from the single `observations` stage to
`{direct_manager_review, requirements_check, observations}` — rows 2 and 4 are pre-قيد, where R12 sees nothing today.
One edited clause, not a new one. (2) R12 joins `requestCreated` recipients (row 1). (3) New event
`request_notice_copy` (in-app only, the `stage_changed` defaults) + `RequestNoticeCopyNotification`, sent from
`NotificationDispatcher::requestNotice()` — the one method both the automatic observer and Stage 100's manual
`notices/issue` call — to the subject's manager and to active R12 users (row 14). The copy carries the tracking
number and the moment's title only, **never the notice body**: Art. 102 limits notice content to what صاحب العلاقة
needs, and the copy is not addressed to them. (4) `legal_review,view` gains **R03** (row 6 اللجنة مطلع). (5)
`meeting_agenda,add` gains **R11** (row 7 العضو القانوني مشارك) — full memo edit, not `legal_opinion` alone; still
bounded by the committee seat via `meeting.member`, which Stage 99 already tied to R11.

**Two calls put to the user and answered yes-with-defaults; do not re-litigate.** اللجنة مطلع is the R03 queue view
only: R04 would be listed queue rows it cannot open (the Stage 47/68 dead end), members read the opinion at study
time through the agenda-item context, and the committee has no identity before an agenda to push a notification to.
R11's memo grant is the whole `add` tier because field-level gating buys nothing for a cell that says only «مشارك».

**Deliberately not built.** A manager whose only role is R09/R10/R11 holds no `notes_attachments,add`, so cannot
attach at `receive_and_register` — الرئيس المباشر is a relationship, not a role, and bypassing the screen-permission
middleware for one relationship is a larger change than this cell justifies. Recorded as an open item, not glossed.

**Verify.** New `tests/Feature/ConsultationLayerTest.php` covering all thirteen cells; full PHPUnit (baseline 719
tests / 4653 assertions); Pint on touched files; `npm run build` (revert `frontend/dist`); locale key parity;
`ScreenRolePermissionSeeder` reseed against the real MySQL confirmed by query. TEST_PLAN.roles.md/.ar.md Appendix A
gains the two changed cells (checkbox parity kept). STAGE_PLAN, OPEN_ITEMS and the gap analysis close with it.

---
### 2026-09-21 18:36 EET — Claude — Stage 100 complete (الإشعار ownership, الأرشفة, execution oversight — Appendix 6 rows 13–15)

Built per the plan below. One migration (six `requests` columns) applied to the real MySQL; the
`ScreenRolePermissionSeeder` reseed confirmed by query (`meeting_outputs,add` = R12 [+R08];
`notes_attachments,add` now includes R06/R07). Full suite **719 tests / 4653 assertions** (baseline
711/4625: +8 new in `NoticeArchiveOversightTest`, the rest fixture updates), Pint clean on every touched
file, `npm run build` passes (`frontend/dist` reverted), locale parity **2010 keys each side**. **No live
HTTP smoke run** — the three routes were confirmed registered, the behaviour by feature tests only.

**Row 15.** Two archive records, each written by its owner through its own endpoint while the file stands
on a closable status and is not closed: `archive/committee-file` (`meeting_outputs,edit`, المقرر) and
`archive/service-file` (`meeting_outputs,add`, which gated no route and is now R12's alone). Closure
(`RequestClosureService::refusalReason()`) refuses until the committee file is archived and — only when the
file carries a recorded decision, the same condition Appendix 48 cond. 8 uses — the service file too. The
closure card's `file_storage_location` is **gone from the request side** (the appeal closure keeps its own,
untouched); Appendix 47 #12 is derived. `ClosureRegister` prints both locations, falling back to a
pre-Stage-100 closure's single location under the committee file. `reopen()` clears both records
**unconditionally**, not inside the `closed_at` guard — `not_approved` is both closable and reopenable, so a
file can be archived and reopened without ever being closed.

**Row 14.** `POST requests/{r}/notices/issue` (`meeting_outputs,edit`) issues Art. 101's notice for the
moment the file's **latest status-history row** represents, through the same `momentFor()` the observer
uses (new `EmployeeNoticeService::currentMoment()`), so a hand-issued notice cannot describe a state the
automatic one wouldn't. Payload carries `issued_by`; the register shows it. Refused (422) for a state Art. 101
doesn't name, or an inactive/absent صاحب العلاقة. The automatic send is untouched.

**Row 13.** One `RequestVisibility` clause: a user with an `approvals.approved_by_user_id` row on a file sees
it while it is `in_execution`/`executed`/`execution_suspended`/`completed_closed`. R06/R07 joined
`notes_attachments,add` (R05 already held it) so supervision can be written on the file.

**Three pre-existing tests used R06/R07 as "the role with no `notes_attachments,add`"** and now get 404
(visibility) instead of 403 — switched to R10, a retained login with no duty since Stage 96, commented in
place. The closure fixtures gained `Tests\ClosesRequests::archiveFiles()`, inserted before each request close.

**Open items.** (1) **The employee_notified checkbox is still an attestation** in the execution and closure
cards; deriving it from the register was considered and left — the closure moment's own notice fires only
after closure, and a rule for "notified of the result" is a decision, not a lookup. (2) **`meeting_outputs`
screen stays invisible to R05–R07** — it is meeting-picked and they hold no seat; supervision lives on the
request workspace. (3) **Stage 101 is the last Track N stage.** Scripting gotcha re-hit: backslashes in a
node heredoc collapse (`\` → `\`), so `use App\...` replacements silently matched nothing — imports were
added with the editor.

---

### 2026-09-21 18:10 EET — Claude — Implementation plan: Stage 100 (الإشعار ownership, الأرشفة, execution oversight — Appendix 6 rows 13–15)

Three ownerless duties get a holder. **One migration (six columns on `requests`), one grant reseeded,
two grants widened, no new screen, no new status.** (Clock note: the machine reads 18:10 while the
Stage 99 entry below is stamped 18:40 — the stamps drifted ahead again; position is the ordering.)

**Row 15 — الأرشفة split into its two مسؤول halves.** Six columns: `committee_file_location/_archived_by_user_id/_archived_at`
(المقرر «مسؤول ملف اللجنة») and `service_file_location/...` (الموارد البشرية «مسؤول ملف الخدمة»), each written by
its own endpoint while the file sits on one of Art. 37's closable statuses and is not yet closed (overwrites —
the Stage 78 precedent). Committee file rides `meeting_outputs,edit` (R02+R03, the المقرر grant every Art.
30/103/105 act already rides). Service file rides **`meeting_outputs,add`, which gates no route today**
(checked) and is reseeded `['R12']` — the same move Stage 99 made with `meeting_minutes,edit`. Closure refuses
until the committee file is archived, and the service file too **when the file carries a recorded decision** —
the exact condition Appendix 48 condition 8 already uses for `service_file_updated`, so a Path 3 pre-committee
عدم اختصاص is not asked to archive a service file nothing touched. The closure card's free-text
`file_storage_location` goes; Appendix 47 #12 stays derived, now from the two records. **Track K scope
decision (1) is restated, not crossed:** ملف الخدمة lives outside this app, so HR's record is where the
service-file copy was filed, attested by the party that owns it — not a model of the file.

**Row 14 — المقرر «مسؤول إجرائيًا» for الإشعار.** Art. 101's notices stay automatic (Stage 79's observer is
right to make them unforgettable); what was missing is a human who answers for them. New `POST
requests/{r}/notices/issue` (`meeting_outputs,edit`) issues Art. 101's notice for the file's **current**
state — the moment the latest status row represents, via the same `EmployeeNoticeService::momentFor()` the
observer uses — for the cases the automatic send cannot cover (an employee reactivated after the notice fired,
a notice to be repeated on request). The notice carries `issued_by`, and the register on the request shows it,
so the file records who stood behind a notice rather than only that the system sent one. Refused when the
current state is not one of Art. 101's twelve, or صاحب العلاقة has no active account.

**Row 13 — جهة الاعتماد «إشراف حسب الاختصاص».** One bounded `RequestVisibility` clause: a user who signed an
`approvals` row on a file keeps sight of it through execution (`in_execution`, `executed`,
`execution_suspended`, `completed_closed`). «حسب الاختصاص» read as *the files they approved*, which is the
approval ledger itself — no role list. R06/R07 join `notes_attachments,add` (R05 already holds it) so
supervision can be recorded on the file, bounded by that same visibility. `meeting_outputs,view` is **not**
widened: that screen is meeting-picked and the approvers hold no committee seat, so it would open onto an
empty picker.

**Verify:** new `tests/Feature/NoticeArchiveOversightTest.php`, a `archiveFiles()` helper on
`Tests\ClosesRequests` for the closure fixtures (a legitimate update — closure now requires the archive), the
full suite (baseline 711/4625), Pint, `npm run build` (revert `frontend/dist`), locale parity, migration +
`ScreenRolePermissionSeeder` reseed against the real MySQL.

---

### 2026-09-21 18:40 EET — Claude — Stage 99 complete (the committee's own acts — Appendix 6 rows 8–11)

Built per the plan below. One migration (`meetings.agenda_adopted_*`, `meeting_minutes.legal_review_*`),
applied to the real MySQL, and a `ScreenRolePermissionSeeder` reseed confirmed by query. Full suite
**711 tests / 4625 assertions** (baseline 704/4590: +7 new, and only fixture updates elsewhere), Pint
clean on every touched file, `npm run build` passes (`frontend/dist` reverted), locale parity **1998 keys
each side** (+10), `TEST_PLAN.roles.md`/`.ar.md` at **358 checkboxes each**.

**Agenda adoption is a gate, not a timestamp.** `POST meetings/{m}/agenda/adopt` (new `meeting_agenda,
approve` tier, R03) is one-shot and refused on an empty agenda. Before it, `updateItemState` and
`updateStudySequence` refuse (`MeetingController::AGENDA_NOT_ADOPTED`, Art. 84) — voting inherits that
through the study sequence it already requires. After it, add/remove/reorder/apply-order refuse
(`AGENDA_ALREADY_ADOPTED`) **except adding an `emerging` item**; `updateAgendaItem` (priority/time) stays
open. Deliberately **not** a readiness exception, so convene means what it meant before. Only two
fixtures needed `agenda_adopted_at` (`StudySequenceTest`, `MeetingLiveRunnerTest`) — every other suite
writes the study sequence directly through `RunsStudySequence`, which bypasses the gate by design.

**R11 is now a sitting member.** `meeting_live,add`, `decisions,add` and `meeting_minutes,add` gained R11
(the last because signing rides it — an attending legal member without it would hold an unsignable
row and stall the محضر). `meeting_minutes,edit` **gated no route** before this stage (checked); it now
gates `POST meetings/{m}/minutes/legal-review` and is `['R11']` — R03 lost a grant that did nothing. The
note is optional («عند الحاجة»), draft-only, overwrites, and never blocks review. `seat=legal` now
requires the R11 role (`StoreCommitteeMemberRequest`); `CommitteeSeatRosterTest` seated an R04 there and
was updated.

**The zero-signature hole is closed by refusal, not by trusting Appendix 8.** The quality gate made it
nearly unreachable, but every attendee marked absent under a count-0 quorum passed all sixteen checks
— the new test builds exactly that and gets the 422. `review()`'s `isEmpty()` auto-approve branch and
its notification call are deleted; `minutesApproved` now fires only from `sign()`.

**Not done: no live HTTP smoke run this session** — covered by the new feature tests only.

**Open items.** (1) The **R09 bullet under row 8** in the gap analysis was already stale (Stage 96);
annotated, not rewritten. (2) **Appendix A in the role test plans is still stale outside the four rows
re-derived here** — Stage 96's open item stands; derive the whole table from the DB next time.
(3) Adoption is recorded by the chair on the committee's behalf; there is no per-member vote on the
agenda, because [D] Art. 12 (أ) 3 gives اعتماد جدول الأعمال to the chair and Appendix 6's «وفق
النظام» points at the system's own rule. (4) Stages **100** and **101** remain; neither blocks the other.

---

### 2026-09-21 17:52 EET — Claude — Implementation plan: Stage 99 (the committee's own acts — Appendix 6 rows 8–11)

Four gaps, each tied to a named cell. **One migration (six columns), grant changes, no new screen.**

**Row 8 — اعتماد تنظيمي of the agenda.** Art. 12 (أ) 3 gives the chair «مراجعة واعتماد جدول الأعمال قبل
الاجتماع» and Art. 84 lists «اعتماد جدول الأعمال» among what happens «قبل مناقشة أول بند». Nothing records
it today. New `meetings.agenda_adopted_at` + `agenda_adopted_by_user_id`, set by `POST
meetings/{m}/agenda/adopt` on a new `meeting_agenda,approve` tier seeded `['R03']` (the `decisions`
add/approve split again). Refused on an empty agenda and when already adopted. **Two consequences make it
more than a timestamp:** (1) deliberation waits for it — `updateItemState` and `updateStudySequence` refuse
until the agenda is adopted, which is Art. 84's own order; voting inherits the gate through the study
sequence it already requires. (2) the adopted agenda is fixed — add/remove/reorder/apply-order are refused
afterwards, **except adding an `emerging` item**, which by definition arises at the sitting. Metadata edits
(`updateAgendaItem`: priority/time) stay open. Not added to readiness/convene: Art. 12 says «قبل
الاجتماع» but the gate that matters is the one before deliberation, and a readiness exception would change
what convene means for every existing fixture for no sourced gain.

**Row 9/10 — R11 as a sitting member.** `meeting_live,add` and `decisions,add` gain R11. Nothing else is
needed on the voting side: `DecisionEligibility` checks seat + attendance, never a role.

**Row 11 — R11 «مراجعة عند الحاجة» on the محضر.** New `meeting_minutes.legal_review_note` + who/when,
written by `POST meetings/{m}/minutes/legal-review` while the محضر is a draft. Rides `meeting_minutes,edit`,
which **gates no route today** (checked) — reseeded `['R11']`. Optional by the cell's own «عند الحاجة»:
it never blocks review. R11 also joins `meeting_minutes,add`, because signing rides that tier and an
attending legal member would otherwise hold a signature row they cannot sign — stalling the محضر forever.

**Row 11 — اعتماد داخلي with zero signatures.** `review()`'s `$signerUserIds->isEmpty()` branch approves
outright. Appendix 8's gate makes it nearly unreachable (no attendance → «إثبات الحضور» fails), but not
provably (all absent + a count-0 quorum). The branch is deleted and replaced with a refusal: the محضر is
the committee's only when attending members confirm it.

**The R11 ↔ `legal` seat binding.** `StoreCommitteeMemberRequest` refuses `seat=legal` for a user
without R11. Stage 45 left the seat role-free; that is the gap the stage names. Only
`CommitteeSeatRosterTest` seats `legal` (as R04) — a legitimate fixture update.

**Verify:** new `tests/Feature/CommitteeOwnActsTest.php` (adopt: permission, empty, twice; deliberation
refused before adoption through both endpoints; freeze with the emerging exception; R11 votes and posts a
note when seated+attended; legal note recorded only by R11 and only on a draft; legal seat refused to a
non-R11; zero-signer approval refused), the full suite (baseline 704/4590), Pint on touched PHP,
`npm run build` (revert `frontend/dist`), locale parity, migration + `ScreenRolePermissionSeeder` reseed
against the real MySQL.

---

### 2026-09-21 07:15 EET — Claude — Stage 98 complete (تجهيز الملف الوظيفي — the largest missing مسؤول)

Built per the plan below. **One migration, one service, one endpoint, and one narrowing of an
existing rule.** No new stage, no new status, no seeder change, no new permission. Full suite **704
tests / 4590 assertions** green (baseline 694/4554: +10 new tests, and **exactly one** pre-existing
test changed), Pint clean on every touched PHP file, `npm run build` passes and `frontend/dist` was
reverted, locale parity **1988 keys each side** with 23 added per side, and the migration ran clean
against the real MySQL.

**The duty MOVED; it was not added.** Appendix 6 row 3 gives الموارد البشرية the ملف الوظيفي as
مسؤول and gives الموظف a literal `—`, yet `DocumentCompletenessService::refusalForSubmission()` ran
at intake — so the **employee** had to attach the employment-record extracts the municipality itself
holds before the request could be created. `refusalForSubmission()` now reads
`mandatorySubmitterDocuments()`, the same matrix minus the `service_file` section, and HR answers
for that section at `receive_and_register` before it may register the file. **`refusalForRequest()`
is deliberately unchanged**: the agenda gate and the readiness exception still demand every
mandatory row, so what the committee may be shown is identical — the file is assembled by two
parties at two moments, which is what row 3 describes.

**The mapping was already in the repo, which is the whole reason this is small.**
`RequestTypeSeeder::commonDocuments()` says it in its own comment — «The seven service-record
extracts below are literally الملف الوظيفي; only the first and last of the nine basics are not» —
and Stage 80 seeded that distinction as each row's `section`, which `RequestType::documentOptions()`
already exposes. So الملف الوظيفي is exactly the `service_file` rows. No new vocabulary, no second
list to keep in step, and the keys are the same `IntakeGateService::documentKey()` slugs the
submitter's picker offers and Stage 78's gate answers under.

**Measured, not estimated: exactly ONE mandatory row moves off the submitter per type** (البيانات
الوظيفية — the only unconditional one of the seven). Counted live against the seeded data:
ALLW/APPT/CTRC/EOSV 1→0, LEAV 4→3, SETL 6→5, CONF/GRIV/PROM 7→6, PEVG/SECD 11→10, TRNS 13→12. **The
four types [D] covers with the shared basics only therefore now require no mandatory document from
the employee at all** — every one of the nine basics is either conditional or an employment-record
extract. That reads startling and is exactly what row 3 says; the documents are still demanded, by
HR, before registration.

**Deliberately an action on an existing stage**, per the stage's own instruction.
`receive_and_register` is where R12 already holds the `register` row (Stage 96 left it the one
registering party), so the gate sits on the hop HR already performs; a `workflow_stages` row of its
own would renumber the chain, which is Stage-57 territory.

**The endpoint rides `notes_attachments,add`, not `edit`, and that distinction is the row-3/row-4
boundary.** R12 holds `add` (Stage 87); `edit` is R02's alone after Stage 84, and taking it would
have handed HR فحص اكتمال ملف اللجنة — row 4's cell. `add` also reaches R01, so the
interested-party refusal is **load-bearing rather than defensive**: row 3 gives الموظف a `—`, so
neither the filer nor صاحب العلاقة may attest to their own employment file
(`actorIsAnInterestedParty()`, the Stage 84/95 predicate). Proven live, not only by test.

**`controlGateRefusal()` answers for a non-`approve` action for the first time.** It short-circuited
on `$action !== 'approve'`; `register` is now answered before that branch, so all three readers pick
it up unchanged — `ApprovalController` never sees `register` (not an approval level), but
`detailResource()`'s preview filter does, which is what keeps the SPA from offering a button the
endpoint refuses. **Worth knowing: none of the four control gates is enforced inside
`WorkflowService::transition()`** — they live at the HTTP boundary, so a fixture calling the service
directly walks past all of them. That is why the predicted 10-call-site blast radius was zero: every
existing `'register'` test calls the service, not the endpoint. The new test file walks it over HTTP.

**المقرر «مطلع» needed no code, and that is a finding rather than a skip.** `register` moves the
file to `requirements_check`, where R02 is the actor, so `NotificationDispatcher::stageChanged()`
already fires `action_required` at exactly the moment preparation completes. A seventh notification
event would have announced the same fact twice.

**الرئيس المباشر «مشارك» is half built, and the half is stated rather than glossed.** One bounded
`RequestVisibility` clause keeps the file in **صاحب العلاقة's own** manager's sight while it sits at
`receive_and_register` — the manager holds a manager-gated row at the two stages before that and
none at this one, so without it the file dropped out of their view at exactly the moment they are
meant to be contributing. Same shape as Stage 87's `$isHrStudyCoOwner`, read against the subject
rather than the filer per Stage 95. **Whether they may *attach* still depends on their role holding
`notes_attachments,add`**, because الرئيس المباشر is a `users.manager_id` relationship and not a
role a screen grant can name. That is the same question Stage 101 has to answer for HR on rows 2, 4,
6 and 7, so it belongs there rather than being half-answered here.

**The frontend generalises Stage 78's panel rather than duplicating it.** `IntakeGatePanel.vue` now
takes four props (`endpoint`, `attestationField`, `grant`, `copy`), all defaulted to Stage 78's
values so **that call site is byte-unchanged** — rows 3 and 4 are genuinely the same form asked by
two parties about two slices of one matrix. One locale key was renamed for uniformity
(`controlGates.intake.factsVerified` → `.attestation`) and a `controlGates.employmentFile.*` block
added; both locales verified at 1988 keys with zero on-one-side-only.

**One pre-existing test needed a legitimate update, not a regression fix.**
`RequestDraftTest::test_appendix_57_completeness_is_enforced_against_the_drafts_own_files` used
`ALLW`, whose single mandatory row was البيانات الوظيفية — so after this stage it can no longer
produce a completeness refusal at all. Moved to `LEAV` (3 submitter rows) and rewritten to cover
them all, with the reason commented in place. Everything else in the suite was untouched.

Smoke-tested end to end over real HTTP against Homestead with the seeded `r12.hr@`/`r01.employee@`
accounts: `register` was refused with «لا يجوز تسجيل المعاملة قبل تجهيز الملف الوظيفي للموظف من قبل
إدارة الموارد البشرية.»; the card returned **the seven service-record rows** with البيانات الوظيفية
the only unconditional one; HR recorded it (`prepared_by: مدير الموارد البشرية التجريبي`, refusal
null) and the same `register` then landed `requirements_check` / `in_review`; and the employee — who
is both filer and صاحب العلاقة — was refused the attestation on their own file. Deleted the fixture
request, its stage log, status-history row and 3 audit rows, removed the 3 jobs the run queued and
revoked **only** this session's two tokens by id — database confirmed back to **5 requests / 2
tokens / 12 pre-existing jobs**.

**Docs.** STAGE_PLAN marks 98 built and rewrites the Track N status line (the track's one hard
dependency, 95 before 98 and 101, is now discharged; 99/100/101 remain and none blocks another).
`gap-analysis-appendix-6.md` (git-ignored) moves row 3 from ❌ to ✅ with the original finding kept
verbatim underneath, since it records what the duty looked like while the wrong party held it.
**Also corrected while there: a stale count Stage 97 left behind** — that stage resolved row 5's
contradicted القيد inline but never updated the verdict table above it, which still read
«contradicted | 1 | 5 القيد». Now 12/15 implemented, 0 contradicted. Exactly the drift this
appendix's own headline warns about, found because this stage had to touch the same table.

**Open items for whoever builds Stage 99–101.** (1) **الرئيس المباشر's attach grant** — see above;
Stage 101 owns it, and it is the same shape of question for four other cells. (2) **The employment
file is prepared once and is not re-verified**, unlike the agenda gate which reads live coverage — a
service-file document deleted after preparation would leave the card claiming an assembly the file
no longer has. Deliberate and consistent with Stage 78's own attestation-vs-live split (the card is
an attestation and cannot regress; `refusalForRequest()` is the live check, and it still runs before
the agenda), but a stage that wants HR's card to regress needs the agenda gate's shape, not this
one's. (3) **There is no "correct the preparation" refusal** — the endpoint overwrites, matching
Stage 78's revise behaviour; a reopen clears it (`RequestController::reopen()`), which is what stops
a second lap registering on the first lap's assembly. (4) **Nothing asks HR to actually attach the
seven documents** — the card answers `present` for a row no file covers, exactly as Stage 78's gate
does, and only `refusalForRequest()` at the agenda demands real coverage. Making HR's own card
demand attachments rather than attestations would be Appendix 70's shape (Stage 76's execution
evidence), and is a decision, not an oversight.

---
### 2026-09-21 05:30 EET — Claude — Implementation plan: Stage 98 (تجهيز الملف الوظيفي — the largest missing مسؤول)

Appendix 6 row 3 gives **الموارد البشرية** the ملف الوظيفي as **مسؤول**, الرئيس المباشر as **مشارك** and
المقرر as **مطلع**, and gives الموظف a literal `—`. Today the row has zero implementation *and* the duty
is discharged by the wrong party: `DocumentCompletenessService::refusalForSubmission()` runs at intake,
so the **submitter** assembles Appendix 57's mandatory documents — including the employment-record
extracts the municipality itself holds — before the request can be created at all. This stage moves the
duty rather than adding a second one.

**The mapping is already in the repo, which is why this is small.** `RequestTypeSeeder::commonDocuments()`
says it in its own comment — «The seven service-record extracts below are literally الملف الوظيفي; only
the first and last of the nine basics are not» — and Stage 80 seeded that distinction as each row's
`section` (`service_file`), which `RequestType::documentOptions()` already exposes and Stage 95 already
reads. So **الملف الوظيفي = the `service_file`-section rows of this type's Appendix 57 matrix**. No new
vocabulary, no second list to keep in step.

**1. The submitter stops being asked for it.** `refusalForSubmission()` skips `service_file` rows. Of the
seven, exactly one (`البيانات الوظيفية`) is unconditional, so today an employee filing any request is
refused until they upload a statement of their own employment data — the municipality's own record.
**`refusalForRequest()` is deliberately NOT narrowed**: the agenda gate and the readiness exception keep
checking every mandatory row. Completeness for the committee is unchanged; it is now assembled by two
parties at two moments, which is what row 3 describes.

**2. HR prepares it at the stage HR already holds.** New `EmploymentFilePreparationService`,
`GATED_STAGE = receive_and_register` / `GATED_ACTION = register` — the hop Stage 96 left R12 as the one
registering party of. **Deliberately an action on an existing stage**, per the stage's own instruction: a
new `workflow_stages` row renumbers the chain, which is Stage-57 territory. One answer per `service_file`
row plus an attestation, reusing Stage 78's own three-answer vocabulary (`present|not_applicable|missing`),
its conditional rule (Appendix 57's own qualifier is what makes «لا ينطبق» honest — an unconditional row
cannot be waived) and its derived-from-attachments override (a document in the file outranks anything
anyone recorded about it, in both directions — Art. 104's «ليست إجراءات شكلية»). New
`requests.employment_file` (json) + `employment_file_prepared_by_user_id` + `_at`, mirroring the
`intake_gate` columns exactly.

**Endpoint rides `notes_attachments,add`, not `edit`.** R12 holds `add` (Stage 87); `edit` is R02's alone
after Stage 84, and taking it would hand HR فحص اكتمال ملف اللجنة — row 4's cell, not row 3's. `add` also
covers R01, so the **interested-party refusal is load-bearing here**, not defensive: row 3 gives الموظف a
`—`, so neither the filer nor صاحب العلاقة may attest to their own employment file
(`actorIsAnInterestedParty()`, the Stage 84/95 predicate).

**3. The gate.** `RequestController::controlGateRefusal()` currently short-circuits on
`$action !== 'approve'`; it widens to answer for `register` too. All three readers pick it up unchanged —
`register` is not an approval level so `ApprovalController` never reaches it, but `detailResource()`'s
preview filter does, which is what keeps the SPA from offering a button the endpoint refuses.

**4. المقرر «مطلع» needs no new code, and that is the finding rather than a skip.** `register` moves the
file to `requirements_check`, where R02 is the actor, so `NotificationDispatcher::stageChanged()` already
fires `action_required` to R02 at exactly the moment preparation completes. A seventh notification event
would announce the same fact twice.

**5. الرئيس المباشر «مشارك»** — one bounded `RequestVisibility` clause: صاحب العلاقة's own active manager
keeps the file while it sits at `receive_and_register`, so they can follow it and contribute a document HR
is missing. Same shape as Stage 87's `$isHrStudyCoOwner` and Stage 47's SAL clause. **The remaining half is
flagged, not silently built**: whether the manager may *attach* still depends on their role holding
`notes_attachments,add`, because الرئيس المباشر is a `users.manager_id` relationship and not a role a screen
grant can name. That is the same question Stage 101 has to answer for HR on rows 2/4/6/7, so it belongs
there rather than being half-answered here.

**Blast radius, measured rather than estimated:** exactly 10 `'register'` call sites across 5 test files
(`DirectManagerRoutingTest`, `ReferenceAssignedNotificationTest`, `RequestTrackingTest`,
`UnifiedNumberingTest`, `WorkflowServiceTest`). A new `PassesControlGates::prepareEmploymentFile()` supplies
the record once, the `passIntakeGate()`/`supplyRequiredDocuments()` precedent.

**Verify:** new `tests/Feature/EmploymentFilePreparationTest.php` — a submission missing a `service_file`
row is accepted while one missing a non-`service_file` mandatory row is still refused; `register` refused
until prepared, refused on a `missing`, refused on an unanswered row; a conditional row waivable and the
unconditional one not; a covered row derived `present` over a spoofed `missing`; the filer and صاحب العلاقة
both refused the attestation while R12 records it; the manager can open the file at that stage; and the
agenda gate still demands every mandatory row, so nothing was actually dropped. Then the full PHPUnit suite
(baseline 694/4554), Pint on touched PHP, `npm run build` (then reverting `frontend/dist`), locale parity,
and the migration against the real MySQL.

---
### 2026-09-21 03:20 EET — Claude — Stage 95 complete (صاحب العلاقة — the person a request is about)

Built per the plan below. **One migration, one model hook, and the ~20 sites that read the filer where
they meant the employee.** No new screen, no new stage, no new status. Full suite **694 tests / 4554
assertions** green (baseline 684/4511: +10 new tests, and **exactly one** pre-existing assertion
changed), Pint clean repo-wide, `npm run build` passes and `frontend/dist` was reverted, locale parity
**1971 keys each side** with five added per side, checkbox parity **356 each** across
`TEST_PLAN.roles.md`/`.ar.md`, and the migration plus the `ScreenRolePermissionSeeder` reseed ran clean
against the real MySQL.

**⚠ The predicted fixture sweep never happened, and the reason is the whole design.** The stage's own
text warns of "a sweep on Stage 84's scale — every fixture assuming creator ≡ subject". It is not one,
because `subject_user_id` is **nullable, backfilled to the creator, and defaulted by a model hook**, so
every existing row and every existing fixture is *correct* rather than merely unbroken. Read sites then
use the column directly with no `??` — which matters because the visibility scope, Appendix 16's search
and the tracking scope are all SQL, and a `COALESCE` in each would have bought nothing.

**The hook is `saving`, not `creating`, and that is load-bearing.** `creating` alone passed 683 of 684
tests; the one that failed (`UnifiedNumberingTest`) assigns `created_by_user_id` *after* `create()`, as
several fixtures and the reopen path do, so the subject stayed null and the manager gate refused
everyone. `saving` closes that, **with an explicit null guard rather than a bare `??=`**: a
partially-selected model (the restricted eager loads several registers use) carries neither column, and
assigning null there marks the attribute dirty and would wipe a real subject on the next save.

**The gate is stated in THREE places and all three had to move together.** `WorkflowService::
actorIsSubjectsActiveManager()` is the one the stage names, but `RequestVisibility::apply()` restates it
in SQL (so that manager can *open* the file) and `NotificationDispatcher::subjectsActiveManager()`
restates it again (so that manager is the one *told*). Moving one and not the others produces the
failure this codebase keeps refusing — the prompt names one manager, the button works for another, and
the file 404s for whoever it actually belongs to. **Proven live over HTTP, not only by test:** R02 filed
a CTRC for `r01.employee@` (whose seeded manager *is* R02), and R02 was offered `forward` and used it.
Before this stage that gate read the **filer's** manager — R02 has none — so the file would have stalled
at `direct_manager_review` with an empty action list for everyone.

**Art. 101/102 name صاحب العلاقة, so the notice audience split in two.** `requestNotice()` (Art. 101's
twelve moments) and `decisionRecorded()`'s exclusion (Art. 102 keeps the tally from صاحب العلاقة) now
resolve the **subject alone** — both articles name the employee the matter concerns, not the clerk. The
progress news — `stageChanged()`, `requestOverdue()`, `referenceAssigned()` — resolves **subject ∪
creator**, uniqued, because either alone is wrong: only-subject loses the clerk sight of work they
filed, only-creator leaves the employee hearing nothing. `creatorOf()` became `subjectOf()`/`ownersOf()`
over one shared `activeUser()`. `EmployeeNoticeRegister` and Stage 75's computed `notice_status` follow
the subject, since both are about the person those notices were addressed to.

**Appendix 16's search is a correctness fix, not a courtesy.** `DuplicatePolicy`'s own docblock said "a
request's creator *is* its رقم الموظف" — the sentence this stage removes — and the appendix's words are
«البحث **برقم الموظف** وموضوع المعاملة». Left alone, a clerk who filed one promotion could never file
the next employee's: the open-file check matched on the clerk. Shipping the picker without this would
have shipped a broken feature. Both callers (`store()`, `duplicateCheck()`) pass the resolved subject,
and the lookup falls back to the caller when someone without the grant asks about another employee.

**Separation of duties widened rather than moved.** The five self-action refusals — `WorkflowService`'s
two `approve` blocks plus `recordJurisdictionTest()`, `recordIntakeGate()` and `reopen()` — said «لا
يجوز لمقدّم الطلب». Appendix 19's rule names مقدم الطلب, but صاحب العلاقة attesting to the completeness
of their own file is the worse case, so each now refuses **both** parties through one predicate per
class (`WorkflowService::actorIsAnInterestedParty()` for the approve chain, which two endpoints reach;
`RequestController`'s own for the three gate recorders). `GateAuthorshipTest`'s two message assertions
are the single legitimate update this stage made to a pre-existing test.

**Who may name someone else: `request_intake`'s previously unused `approve` tier, seeded
`['R02','R05']`.** The same move Stage 92 made for `meeting_outputs` — an unused action tier on the
screen that owns the domain, rather than a new screen or a role-code check in a controller. R02 and R05
are exactly the two roles besides R01 that already hold `request_intake,edit`, i.e. the pair that
seeder's own Stage 88 comment calls "the roles that actually compose intakes". **R12 was deliberately
left out**: it holds no `request_intake,add` at all, so granting it on-behalf without filing would be
incoherent — widening is one seeder line. Without the grant, `subject_user_id` must equal the caller or
the submission is refused (422 on `subject_user_id`, verified live); the picker is only shown to someone
who would pass.

**The picker's options ride `intakeOptions()`, which the intake screen already calls** — no new endpoint
and no new route. `committees/user-options` (the existing narrow user lookup) was **deliberately not
reused**: Stage 92's membership gate zeroes `meetings,can_view` for anyone with no committee seat, so an
R05 who sits on none would 403 on it. Verified live: R02 gets `may_file_for_others: true` with 16
options, R01 gets `false` with none.

**One real SQL bug the suite caught, worth knowing.** Rewriting the task inbox's self-exclusion as
`whereNot(a = x OR b = x)` is **NULL-unsafe** — a request with no creator yields `NOT (NULL OR NULL)` =
NULL and drops out of the queue entirely, which emptied `ApprovalChainTest`'s reviewer queue. Restored to
the original's explicit shape, once per column (`whereNull(...)->orWhere(..., '!=', ...)`). Three-valued
logic, not Laravel.

**Display sweep, mechanical but not cosmetic:** the eight `'employee' => …->createdBy?->name` sites (six
registers, `AgendaOrderingService`'s Appendix 24 row, `DecisionDraftComposer`'s `{{employee_name}}`),
`PresentationMemoCompiler`'s Art. 22 الموظف/جهة عمله, and `MeetingController::agendaItemContext()`'s [C]
§6 بيانات الموظف tab — which showed the committee the **clerk's** email, phone, department and manager —
plus its الطلبات السابقة query. Every restricted select that now resolves `subject` gained
`subject_user_id`; that is the same restricted-eager-load gotcha Stages 52 and 74 already recorded, and
it fails silently (a null relation, not an error). `MeetingMinutesCompiler`'s `$note->createdBy` is a
discussion-note author and is **not** in scope.

**Kept on purpose.** Nothing renames `created_by_user_id` and not one read of it was removed — "who
filed this" stays a separately recorded fact, which is what lets both names appear side by side on the
workspace when they differ (and neither when they do not, so an ordinary request looks exactly as it
did). `RequestDraft` keeps `created_by_user_id` as its only owner — a draft is the composer's working
state, not a file about anyone yet — and gained one nullable field so a half-composed on-behalf intake
survives a refresh.

**Live smoke, then a clean teardown.** Against Homestead: the picker offered by grant only; R01 refused
naming a colleague («لا يجوز تقديم طلب نيابة عن موظف آخر.»); R02 filed for `r01.employee@` and the
workspace reported `subject: موظف تجريبي` / `created_by: مقرر تجريبي`; the **subject**, who did not file
it, found it on «متابعة طلباتي» and opened it (200); and the forward went through on the subject's
manager. Deleted the fixture request, its attachment and stored file, 3 stage logs, 3 status-history
rows and 3 audit rows, revoked **only** this session's three tokens by id (173–175, leaving the two
pre-existing ones) and removed the two queued jobs naming the deleted request — database confirmed back
to **5 requests / 2 tokens / 12 pre-existing jobs**, with all 5 rows carrying `subject == creator`.

**Docs.** STAGE_PLAN marks 95 built and records that 98 and 101 are now unblocked — the track's only
hard dependency. `gap-analysis-appendix-6.md` (git-ignored) moves row 1's ❌ "deepest finding in the
matrix" to ✅ and rewrites row 2's mechanism paragraph. `TEST_PLAN.roles.md`/`.ar.md` gained the two
changed Appendix A cells (`vae` → `vaeA` for R02/R05 on `request_intake`) and a rewritten R05 check that
walks the on-behalf path end to end.

**Open items for whoever builds Stage 98 or 101.** (1) **The subject is a `users` row, so a request can
only be about someone with an account** — fine today (Track K scope decision (1) puts the employment
record outside this app), but a stage that wants to file for a non-user employee needs a different
answer, not a nullable widening of this column. (2) **`subject_user_id` is immutable after intake** —
there is no "correct the صاحب العلاقة" endpoint, matching every other one-shot intake field; correcting
one means refiling. If that proves too sharp, it is a small PATCH riding `request_intake,approve`, not a
change to the column. (3) **Appendix 6 row 1's two «مطلع» cells are still unimplemented** — الرئيس
المباشر is informed only incidentally (the intake auto-hop's action prompt happens to reach them) and
الموارد البشرية hears nothing at filing; both are Stage 101's. (4) **The R05 grant is narrow by
decision** — R12 files nothing today, so HR cannot raise a file about an employee even though Appendix 6
row 3 makes HR the party that assembles it; Stage 98 should decide whether that row implies
`request_intake,add`+`approve` for R12 rather than inheriting this stage's narrowness as a constraint.

---

### 2026-09-21 00:40 EET — Claude — Implementation plan: Stage 95 (صاحب العلاقة — the person a request is about)

Track N's one hard dependency (95 blocks 98 and 101), and the stage its own text says deserves its own
session. **Scope: one migration + one model hook + the ~20 sites that currently read the filer where
they mean the employee. No new screen, no new stage, no new status.**

**The mechanism that makes the ⚠ blast radius evaporate.** The stage warns to expect a fixture sweep on
Stage 84's scale — "every fixture assuming creator ≡ subject". It does not have to be one: the column is
**nullable and defaults to the creator**, so a `creating` model hook (`subject_user_id ??=
created_by_user_id`) plus a one-line backfill makes every existing row and every existing fixture
*correct by construction* rather than merely unbroken. Read sites then use `subject_user_id` directly
with no `??` fallback, which keeps the SQL clauses (visibility, the duplicate search, the tracking
scope) as simple as they are today. The alternative — nullable with a coalescing fallback at ~20 read
sites — costs a `COALESCE` in every query for the same answer.

**The gate is the Done-when, and it is enforced in three places that must agree.**
`WorkflowService::actorIsCreatorsActiveManager()` is the one the stage names, but it is not the only
copy: `RequestVisibility::apply()` re-states the same rule in SQL (`request_creators.manager_id =
$actor->id`) so the manager can *see* the file, and `NotificationDispatcher::creatorsActiveManager()`
re-states it again so the manager is *told*. Moving one and not the others produces exactly the failure
this codebase keeps refusing: the notification names one manager, the button works for another, and the
file 404s for whoever it actually belongs to. All three move together.

**Art. 101/102 are about صاحب العلاقة by name, so the employee notices follow the subject.**
`requestNotice()` (Art. 101's twelve moments) and `decisionRecorded()`'s exclusion (Art. 102 — the tally
must not reach صاحب العلاقة) resolve the **subject alone**. `stageChanged()`/`requestOverdue()`/
`referenceAssigned()` resolve **subject ∪ creator**, uniqued: the clerk who filed keeps the progress of
their own work, and the employee it is about stops being silent. `EmployeeNoticeRegister::for()` and
`RequestClosureService`'s computed `notice_status` (which reads `createdBy?->is_active` to decide
whether the employee is reachable) follow the subject for the same reason.

**Appendix 16's duplicate search is a correctness fix, not a courtesy.** `DuplicatePolicy`'s own docblock
says "a request's creator *is* its رقم الموظف" — that sentence is precisely the assumption this stage
removes, and the appendix's own words are «البحث **برقم الموظف** وموضوع المعاملة». Left alone, a clerk
who files a PROM for employee A is then refused filing a PROM for employee B, because the open-file check
matches on the clerk. Shipping the picker without this ships a broken feature, so `priorRequests()` takes
a subject id and both callers (`store()`, `duplicateCheck()`) pass the resolved subject.

**Separation of duties widens rather than moves.** The five self-action blocks — `WorkflowService`'s two
`approve` refusals, `recordJurisdictionTest()`, `recordIntakeGate()`, `reopen()`, and
`PendingTaskCollector`'s queue exclusion — currently say «لا يجوز لمقدّم الطلب». Appendix 19's rule is
about مقدم الطلب, but صاحب العلاقة attesting to the completeness of their *own* file is the worse case,
so each becomes creator **OR** subject. Never lazy about a separation-of-duties rule.

**Who may name a subject other than themselves: `request_intake`'s unused `approve` tier, seeded
`['R02','R05']`.** Same move Stage 92 made for `meeting_outputs` — an unused action tier on the screen
that already owns the domain, rather than a new screen or a role-code check in a controller. R02 and R05
are exactly the two roles besides R01 that hold `request_intake,edit`, i.e. the pair that seeder's own
Stage 88 comment calls "the roles that actually compose intakes"; R01 is the employee and R03/R04/R06
approve rather than file. Deliberately narrow: widening is one seeder line, and R12 is left out because
it holds no `request_intake,add` at all, so granting it on-behalf without filing would be incoherent.
Without that grant `subject_user_id` must equal the caller or the submission is refused — the server is
the enforcement, the picker is only shown to someone who would pass.

**The picker's options ride `intakeOptions()`, which the intake screen already calls** — no new endpoint
and no new route. `committees/user-options` (the existing narrow user lookup) is deliberately NOT reused:
Stage 92's membership gate zeroes `meetings,can_view` for anyone with no committee seat, so an R05 who
sits on no committee would 403 on it.

**Display sweep, mechanical:** the eight `'employee' => $x->createdBy?->name` sites (six registers,
`AgendaOrderingService`, `DecisionDraftComposer`) and `PresentationMemoCompiler`'s الموظف/جهة عمله become
the subject — those columns are literally صاحب العلاقة, and today they print the clerk.
`MeetingMinutesCompiler`'s `$note->createdBy` is a discussion-note author and is **not** in scope.
`RequestResource`/`RequestDetailResource` gain a `subject` block; the SPA shows صاحب العلاقة only when it
differs from the filer, so an ordinary self-filed request looks exactly as it does today.

**Deliberately NOT touched:** `RequestDraft` keeps `created_by_user_id` as its only owner (a draft is the
composer's own working state, not a file about anyone yet) — `SaveRequestDraftRequest` gains one nullable
field so a half-composed on-behalf intake survives a refresh, and nothing else about drafts changes.
Nothing renames `created_by_user_id` or removes a single read of it: "who filed this" stays a real,
separately-recorded fact.

**Verify:** new `tests/Feature/RequestSubjectTest.php` — the backfill/creating-hook making an unset
subject equal the creator; the manager gate answering to the *subject's* manager and refusing the
creator's, through the transition endpoint AND the visibility gate AND the action-required notification;
Art. 101's notice reaching the subject and not the clerk while the tally still excludes the subject; the
Appendix 16 search matching per subject so a clerk can file the same type for two employees; the intake
refusing a foreign subject without the grant and accepting it with; the duplicate-check lookup and the
tracking screen both following the subject; a self-filed request behaving exactly as before. Then the
full PHPUnit suite (baseline 684 tests / 4511 assertions), Pint on touched PHP, `npm run build` (then
revert `frontend/dist`), locale key parity, `php artisan migrate` against the real MySQL, and a
`ScreenRolePermissionSeeder` reseed — ⚠ which resets the whole matrix on a live install.

---
### 2026-09-20 23:55 EET — Claude — Stage 96 complete (R09/R10 folded back into the matrix's parties)

Built per the plan below. **No migration, no new endpoint and no service logic** — seeded data plus
comments, which is the check that this is a re-seed rather than a feature. Full suite **684 tests /
4511 assertions** green (baseline 684/4520: same test count, −9 assertions where three-iteration
loops collapsed to one), Pint clean on all fifteen touched PHP files, `npm run build` passes and
`frontend/dist` was reverted, locale parity **1966 keys each side** with nothing added or removed,
checkbox parity **353 each** across `TEST_PLAN.roles.md`/`.ar.md`, and `php artisan migrate` reports
nothing to migrate.

**Real-database check, by query rather than assumption.** All four seeders re-run against the real
MySQL: **12 stages, 49 transitions** (was 55 — two retired `route_to_*` rows, two `register` rows,
two `cancel` rows), **0 rows pointing at a missing stage**, one route out of
`administrative_routing`, one `register` row (R12 + `routed_to_hr`), both committee hops R02,
`cancel` at `forward_to_committee` R02 and at `receive_and_register` R12 alone. **No file is
stranded by the collapse**: the real database holds **zero** requests in `routed_to_diwan` or
`routed_to_committee_secretary` — checked, not assumed. Smoke-tested over real HTTP as
`r09.secretary@`: 12 screens, `legal_review` genuinely absent; the one token that run minted was
revoked and the database is back to its 2 pre-existing tokens and 5 requests.

**Two cleanups are load-bearing and must not be "simplified" away.** Exception rows survive the
generic delete at the top of `WorkflowTransitionSeeder::run()`, so the two retired `route_to_*` rows
and the superseded `cancel` rows each need their own targeted delete — without them an existing
database keeps offering a manager three routes, two of which nobody can then register. Both are
pinned by tests that fail if the delete is dropped: `DirectManagerRoutingTest` walks each retired
route and each retired registrar, and `CommitteeHandoverTest` puts the pre-Stage-96 R09 cancel row
back by hand before re-seeding, because a fresh database never holds the stale row and seeding twice
would prove nothing.

**Scope judgment calls, recorded so they are not re-litigated.** (1) **Both** `forward` hops moved,
not only the one the Build bullet's line number points at: Stage 86 moved both, and Appendix 6 gives
جدولة/عرض الملف to مقرر اللجنة as a single مسؤول. The second hop lands on **R02**, not its
pre-Stage-86 R05 — R05 is a tier of the approving column, not the secretariat. (2) **R02 was NOT
added to `meetings_dashboard`**: its `add`/`edit` grants gate no route at all (only `view` appears
in `routes/api.php`), so a role there is decoration; R09 was simply removed. `committee_candidates`
and `meeting_agenda` needed a removal only, since R02 already held both tiers on each. (3) **R09/R10
came out of `notes_attachments,add`** although the Build bullet does not list it — that grant's own
seeder comment bounds it to "all three of R12/R10/R09 hold a `register` row at
`receive_and_register`", which stops being true here. (4) The single remaining route stays an
**exception** with `requiresComment: false`: `administrative_routing` already showed nothing under
the normal-actions heading, so nothing regressed, and moving it into `$transitions` is impossible
anyway — that array's single-role tuple shape cannot express a manager gate.

**Kept on purpose.** The `routed_to_diwan` / `routed_to_committee_secretary` **status rows**, their
`workflow.actions.route_to_*` locale keys, and `PeriodicReportService`'s `awaiting_administration`
bucket — a request that already took one of those hops must still render its own timeline
(legacy-status precedent, same as `archived`). Both **role rows** stay too, with their descriptions
rewritten to say they carry no duty, so existing logins, audit rows and historical stage logs keep
resolving a role. `RequestResponsibilityService::ROLE_TO_PARTY`'s now-unreachable R09/R10 entries
stay, annotated; `PermissionSeeder` (the legacy, unread catalogue) was left alone.

**`NotificationTest` needed a different hop, not a role swap** — worth knowing, because this is the
second time it has moved. Its two fixtures walk a transition whose actor and next actor must be
different parties, or there is no handoff left to assert. Stage 86 moved them to
`reviewer_review → observations` precisely because `observations` had become R09's; Stage 96 gives
R02 the whole pre-committee chain back, which makes that hop same-role again — and
`actorsForStage()` excludes the actor. They now walk
`forward_to_committee → receive_from_committee`: R02 moves it, R03 acts next.

**Test blast radius: six files, every change a legitimate update.** `CommitteeHandoverTest` is Stage
86's own file, inverted in place — its re-seed-cleanup cases are the most valuable thing in it,
because this stage re-creates exactly the hazard they pin. `DirectManagerRoutingTest`'s three-route
and three-registrar cases collapse to one each and gained the retired-row assertions.
`WorkflowServiceTest`'s happy-path walk, actor maps and seeded-shape counts moved (non-exception
rules 13 → 11, cancel rows 14 → 12, register rows 3 → 1). `RequestDetailTest` expects `forward`
first at `observations` again, and `HumanResourcesSeatTest` / `NotificationTest` as above.

**Docs.** STAGE_PLAN marks 96 built and its Track N line lists it. `TEST_PLAN.roles.md`/`.ar.md` had
§10/§11 rewritten (both roles are now "a retained login with no seeded duty", and the sections are
almost entirely refusals — **that is the test**: if either can still act, a grant or a row survived
the fold), plus the account table, the relay (now **nine** people, not ten), four Appendix A rows and
Appendix B. `TEST_PLAN.md`/`.ar.md` and `USER_GUIDE.ar.md` had their routing/stage tables corrected —
note that several of those cells were **already stale** (stage 8 still read R05, i.e. pre-Stage-86;
the guide still gave the HR route to R05, i.e. pre-Stage-87). `compliance-matrix.md` and
`gap-analysis-appendix-6.md` (git-ignored) record the structural finding as closed.

**⚠ The per-role screen-count table is stale for reasons this stage did not cause, and now says so.**
It reports seeded-matrix counts, but the membership gate hides the seven `meetings_management`
screens from anyone with no committee seat — an unseated R09 sees **12 screens / 10 entries**, which
is what the live smoke run returned, not the 19/17 the matrix implies. The seeder also now holds
**36** screens, not the 35 the appendix claims. A note under the table states both; only the four
rows this stage changed were re-derived.

**Open items.** (1) **`request_types.default_administrative_route` (Stage 56) is now vacuous** and was
deliberately left alone: with one route, ten of the twelve seeded types suggest a destination that no
longer exists, so the badge never renders and the Request Types admin screen still offers three
values. Narrowing `RequestType::ADMINISTRATIVE_ROUTES` is a decision about Stage 56's own mechanism
that this stage's Build bullet does not make, and a stage restoring a destination would have to undo
it — `DirectManagerRoutingTest` pins the consequence rather than hiding it, and `USER_GUIDE.ar.md`
dropped the now-uniform suggestion column. (2) **A whole-file Appendix A re-derivation is owed** to
whoever next touches the role test plans; the methodology is in that file's own header and the drift
predates this stage. (3) R09/R10 remain in `TestUserSeeder` and `PermissionSeeder`; if a later stage
decides the logins are not worth keeping, both are one line each.

---

### 2026-09-20 23:20 EET — Claude — Implementation plan: Stage 96 (R09/R10 folded back into the matrix's parties)

Track N decision 3, already taken (2026-09-20) and not re-litigated here: **R09 (أمين سر اللجنة)
and R10 (وكيل الديوان) have no column in Appendix 6** — both are diagram-alignment inventions
(AGENT_NOTES 2026-08-28, sourced from a municipality infographic, not from [D]) — so they keep
their logins and stop holding duties belonging to a column they do not have.
**Scope: seeded data + comments + tests. No migration, no new endpoint, no service logic.**

1. **`WorkflowTransitionSeeder`** — R09's two `forward` rows (`observations → forward_to_committee`,
   `forward_to_committee → receive_from_committee`, both given to R09 by Stage 86) return to
   **R02**. The Build bullet names one line number; it points at the second of the pair, but Stage
   86 moved both and the goal ("no duty the matrix assigns to a column is held by a role without
   one") covers both — Appendix 6 gives جدولة/عرض الملف to مقرر اللجنة as a single مسؤول.
   `cancel` at `forward_to_committee` follows by that seeder's own stated rule (cancellation belongs
   to whoever moves the stage), R09 → R02, and its **targeted delete flips `!= R09` → `!= R02`** —
   exception rows survive the generic delete at the top of `run()`, so without that flip the
   superseded R09 row lives on beside its replacement.
2. **Routing collapses to one route.** `$routingActions` keeps only `route_to_hr`; the two retired
   exception rows (`route_to_diwan`, `route_to_committee_secretary`) need their **own targeted
   delete** for the same is_exception reason. `$registrations` becomes the single
   `['R12', 'routed_to_hr']` pair (those rows are `is_exception=false`, so the generic delete
   sweeps the other two), and `cancel` at `receive_and_register` narrows from R12/R09/R10 to R12.
3. **Statuses retire from the seeded map, rows kept** (legacy-status precedent, same as `archived`):
   `routed_to_diwan` / `routed_to_committee_secretary` stay in `RequestStatusSeeder` and stay in
   `PeriodicReportService`'s `awaiting_administration` bucket, and
   `workflow.actions.route_to_diwan|route_to_committee_secretary` stay in both locale files — a
   request that already took one of those hops still renders its own timeline.
4. **`WorkflowStageSeeder`** — the indicative `responsible_role_id`: stage 8 R09 → **R02**, stage 9
   R09 → **R03** (its pre-Stage-86 value). This field names who the file is sitting with, and
   `RequestResponsibilityService::partyFromStage()` falls back to it for a stage with no rule.
5. **`ScreenRolePermissionSeeder`** — R09 out of `meeting_agenda`, `committee_candidates` (R02
   already holds both tiers on each, so this is a removal, not a move), `legal_review` view+edit,
   and `meetings_dashboard`. **R02 is deliberately NOT added to `meetings_dashboard`**: its
   `add`/`edit` grants gate no route at all (verified — only `view` appears in `routes/api.php`), so
   adding a role there would be decoration. R09/R10 also come out of `notes_attachments,add`: that
   grant's own seeder comment bounds it to "all three of R12/R10/R09 hold a `register` row at
   `receive_and_register`", which stops being true here.
6. **`RoleSeeder`** — R09/R10 rows stay (logins are kept by decision 3); their descriptions, which
   describe duties they no longer hold, are rewritten to say so.
7. **Comments only:** `RequestVisibility`, `MeetingController`, `PresentationMemoController`,
   `routes/api.php` each cite R09 in prose that this stage falsifies.

**Deliberately NOT touched, flagged rather than silently widened:** `request_types
.default_administrative_route` (Stage 56's advisory badge) becomes **vacuous** — with one route,
ten of the twelve seeded types suggest a destination that no longer exists, so the badge simply
stops rendering. Narrowing `RequestType::ADMINISTRATIVE_ROUTES` is a decision about Stage 56's own
mechanism that this stage's Build bullet does not make, and a future stage restoring a destination
would have to undo it. Recorded as an open item. Also left alone: `PermissionSeeder` (the legacy,
unread catalogue) and `RequestResponsibilityService::ROLE_TO_PARTY`'s now-unreachable R09/R10 rows.

**Test blast radius, all legitimate updates rather than regression fixes.**
`CommitteeHandoverTest` is Stage 86's own test file and is inverted in place — its re-seed-cleanup
cases are the most valuable thing in it, because this stage re-creates exactly the stale-row hazard
they pin. `DirectManagerRoutingTest`'s three-route and three-registrar cases collapse to one.
`WorkflowServiceTest`'s happy-path walk, actor maps and seeded-rule-shape assertions move R09 → R02
and `['R12','R09','R10']` → `['R12']`. **`NotificationTest` needs a different hop, not a role
swap:** its two fixtures walk `reviewer_review → observations` as R02 precisely because Stage 86
made R09 the next actor there; with `observations` back to R02 the actor and the next actor are the
same role and `actorsForStage()` excludes the actor, so they move to
`forward_to_committee → receive_from_committee` (R02 acting, R03 next).

**Verify:** full PHPUnit (baseline 684 tests / 4520 assertions), Pint on touched PHP,
`npm run build` (then revert `frontend/dist`), locale key parity, reseed
`WorkflowStageSeeder` + `WorkflowTransitionSeeder` + `ScreenRolePermissionSeeder` + `RoleSeeder`
against the real MySQL and confirm the row shape by query; `php artisan migrate` — expected nothing
to migrate.

---


### 2026-09-20 22:10 EET — Claude — Stage 97 complete (القيد back to مقرر اللجنة)

Built per the plan below. **No migration, no new endpoint, and no service logic** — the behaviour
change is two seeded values, which is the check that `applyRule()`'s destination-status keying did
what its docblock promised: the قيد moved twice (Stage 70 → 2026-09-18 → here) without that method
being edited. Full suite **684 tests / 4520 assertions** green (baseline 684/4520: one test added,
one redundant one deleted), Pint clean on every touched PHP file (the repo-wide `--test` failure is
the same `scripts/build-guide-pdf.php` drift as before), `npm run build` passes and `frontend/dist`
was reverted, locale parity **1966 keys each side** with no key added or removed (values only),
checkbox parity **361 each** across `TEST_PLAN.roles.md`/`.ar.md`, and `php artisan migrate` reports
nothing to migrate.

**Real-database check, by query rather than assumption:** all three `register` rows now set
`in_review`, `requirements_check → approve` sets `registered`, and `decisions,can_approve` is held by
**R03 and R08 only**. Both seeders were re-run against the real MySQL. Note the standing hazard:
`ScreenRolePermissionSeeder` resets the whole matrix, so any grant customised through the Roles &
Permissions screen on that database was reset too — none is known, but on a live install apply the
one-cell change by hand instead.

**What flipped.** `register` lands on Art. 38 code **04** (تحت فحص الاكتمال) and mints nothing;
R02's approve is the قيد (code **06** + `PM-COM`) with بوابة 1 guarding the same hop, so the gate is
*before* the قيد again — and it needed no deadlock workaround, because both now sit where R02 can
act. The three gate-1 refusal strings, `controlGates.intake.title`, `requestDetail.awaitingRegistration`,
`intake.receiptNotice`, `workflow.actions.register` (both locales) and USER_GUIDE.ar.md were
restored, and Art. 101 moment 3's body is back to «باكتمال ما طُلب استكماله» — that wording was
softened *only because* moment 3 fired at re-registration, and it fires at the completeness hop
again, so the claim is true again. That closes the "known imprecision" the 09-18 note recorded.

**Kept on purpose:** the `reference_assigned` notice and the `Attachment` classification work from
the same 09-18 commit — neither depended on where the قيد sits. The notice follows the *allocation*,
so it simply fires on the approve hop now; its five tests were re-pointed at that hop, and one
(the "mid-pipeline file mints on approve" case) was deleted as it had become the main path. A new
assertion pins that `register` announces nothing.

**R02 out of `decisions,approve`** (the smallest item): `WorkflowService` had always refused an R02
outcome (every committee outcome row requires R03), so the grant passed the 403 gate and 422'd — a
capability that only looked real. New `DecisionRegisterTest` case pins R03-yes / R02-no. **The trade
is real and recorded:** `compliance-matrix.md`'s Appendix 45 moved ✅ → ⚠ (تسجيل النتيجة re-opens),
Part B headline **62/20 → 61/21**, with the reading of Appendix 6 row 10's «توثيق» as documentation
rather than the tally stated as the assumption it rests on.

**Docs (git-ignored, local only):** `source-detailed-flow-verbatim.md` stage 07 divergent →
compliant (tally 18·2·1 → **19·1·1**, flipped in this same change per the Stage 94 note),
`compliance-matrix.md` Appendix 6 note + Appendix 45 + headline, `gap-analysis-appendix-6.md` row 5
marked resolved. STAGE_PLAN.md marks 97 built and corrects the Track N line that said only 94 was.
Stage 97 was taken **ahead of 96**, which its own text allows.

**In-flight data:** a file numbered at `register` under the old rule keeps that number (the mint is
null-only, Art. 99) and simply re-stamps `registered` on approve; a file at `requirements_check`
unnumbered now mints on approve, which is the intended path. No backfill. The real database holds
5 requests, 1 of them already numbered; that one keeps its number and nothing was changed on any of them.

**Open items.** (1) `RequestResponsibilityService`/notice copy still says nothing about *who* the
قيد belongs to on screen — Appendix 6 row 5 is now true in code but the request card does not label
it; Stage 98's HR step is the natural place to make the pre-قيد hand-offs legible. (2) The
`register` action's label is once again «تسجيل الاستلام», and R12/R10/R09 all still perform it —
collapsing three routes to one is Stage 96, untouched here.

---

