# Abu Saleem Request System — Staged Local Build Plan

**How this works:** 24 stages, each small enough to build, run locally, and verify in one sitting.
You tell me which stage to work on; I produce the code for that stage only.
Each stage lists: **Goal → What gets built → Done when**.

Stages are ordered by dependency, not difficulty. Don't skip ahead unless the stage says it's independent.

---

## TRACK A — Local Environment & Foundation

### Stage 1 — Local environment setup
**Goal:** A running Laravel API and Vue app on your machine, talking to each other.
**Build:**
- Laravel 11 project (`backend/`), Vue 3 + Vite project (`frontend/`)
- MySQL database created locally, `.env` configured
- CORS + Sanctum stateful domain config so `localhost:5173` can call `localhost:8000`
- One test endpoint (`GET /api/ping`) called from the Vue app to prove the wiring
**Done when:** `php artisan serve` + `npm run dev` both run, and the Vue page displays a response fetched from Laravel.
**Independent:** yes — nothing else needed first.

### Stage 2 — Base schema (part 1: org + identity)
**Goal:** Core tables exist and are seeded.
**Build:**
- Migrations: `departments`, `users`, `roles`, `permissions`, `role_user`, `permission_role`
- Seeders: 8 roles (R01–R08), permission list, a few sample departments, one admin user
- Eloquent models with relationships
**Done when:** `php artisan migrate:fresh --seed` runs clean and you can query roles/departments in tinker.

### Stage 3 — Base schema (part 2: workflow + lookup tables)
**Goal:** The lookup/reference tables the whole system reads from.
**Build:**
- Migrations: `workflow_stages`, `workflow_transitions`, `request_statuses`, `request_types`, `screens`, `screen_role_permissions`
- Seeders: the 11 stages, all statuses, the 22 screens
- `workflow_transitions` left **empty** for now (filled in Stage 14)
**Done when:** migrate:fresh --seed runs clean, all 11 stages and 22 screens are in the DB.

### Stage 4 — Authentication
**Goal:** Login works end to end.
**Build:**
- Sanctum login/logout/me endpoints
- Vue: login page, Pinia auth store, token handling, axios interceptor
- Route guard skeleton (logged-in vs not)
**Done when:** You can log in as the seeded admin, see a protected page, and log out.

### Stage 5 — App shell & layout
**Goal:** The frame every screen will live inside.
**Build:**
- Vue layout: sidebar nav, top bar, content area
- RTL setup + Arabic font (Cairo/Tajawal), vue-i18n scaffolding with AR/EN toggle
- Nav items driven by the `screens` table
**Done when:** App renders RTL in Arabic, sidebar lists screens, navigation works between placeholder pages.

---

## TRACK B — Admin & Master Data (simple CRUD)

### Stage 6 — Departments CRUD
**Build:** API + Vue screen — list, create, edit, deactivate. Handles the self-referencing `parent_id`.
**Done when:** You can create a nested department tree from the UI.

### Stage 7 — Users management
**Build:** API + Vue screen — list, create, edit, deactivate users; assign roles via `role_user`.
**Done when:** You can create a user, assign them R02 (المقرر), and log in as them.

### Stage 8 — Permission matrix editor
**Goal:** The tool you'll use to configure everything else.
**Build:**
- Grid UI: 22 screens × 8 roles, with view/add/edit/delete/approve/print/export toggles
- Bulk-save API endpoint writing to `screen_role_permissions`
**Done when:** You can toggle a permission in the UI, save, and see it persisted.

### Stage 9 — Permission enforcement layer
**Goal:** One source of truth, enforced on both sides.
**Build:**
- Laravel middleware/policies reading `screen_role_permissions`
- Login response returns the user's permission set
- Vue: route guards + a `v-can` style directive/helper for hiding buttons
**Done when:** A user without `users.view` cannot reach the Users screen via UI *or* by calling the API directly.

### Stage 10 — Settings & templates screens
**Build:** CRUD for `settings` (key/value) and `templates`. Low risk, no logic attached yet.
**Done when:** Both screens work; settings values are readable from the backend.

---

## TRACK C — Requests Core

### Stage 11 — Requests schema + list view
**Build:**
- Migrations: `requests`, `request_stage_logs`, `request_status_history`, `attachments`, `notes`
- API: list endpoint with filters (status, department, type, date range) + pagination
- Vue: requests list screen
**Done when:** Manually-inserted test rows appear in the list and filters work.

### Stage 12 — File upload component
**Build:** Reusable Vue upload component + Laravel storage endpoint. Validates PDF/DOC/DOCX/JPG/PNG, 20MB cap. Writes to `attachments`.
**Done when:** You can upload a valid file and get rejected on an invalid type/size.
**Independent:** can be built any time after Stage 5.

### Stage 13 — Request intake flow
**Goal:** The full "create a request" journey (Image 6).
**Build:**
- Reference number generator (`YYYY-DEPT-000123`), race-safe inside a DB request
- Intake form: basic data → attachments → validation → submit
- On submit: create request, assign reference number, set status `new` / stage 1
- Notes component on the request detail page
**Done when:** You complete the form and get a request with a unique reference number at stage 1.

---

## TRACK D — Workflow Engine (the core)

### Stage 14 — Transition map + WorkflowService (happy path)
**Goal:** The state machine, data-driven.
**Build:**
- Seed all happy-path rows in `workflow_transitions` (stage 1→2→3…→11, with action + required role)
- `WorkflowService::transition(request, action, actor)`: validates role, validates prerequisites, updates stage/status, writes `request_stage_logs` + `request_status_history`
- Unit tests for the happy path
**Done when:** A test walks a request from stage 1 to stage 11 in code, with correct log rows.

### Stage 15 — Request detail page + stage actions
**Build:**
- Vue: request detail view showing current stage, status, timeline, attachments, notes
- Approve / reject / forward buttons wired to the transition API, gated by role
**Done when:** You can move a request through several stages from the UI, logging in as the right role each time.

### Stage 16 — Exception flows
**Build:**
- Seed exception transitions: نقص مستندات (return for missing docs), رفض المراجعة, طلب تعديل, إلغاء الطلب
- Return-to-previous-stage logic with mandatory comment/reason
- Vue: exception action buttons + reason modals
**Done when:** Each exception path works and is correctly recorded in the stage log.

### Stage 17 — SLA / deadlines
**Build:** `due_date` handling per request type, a scheduled command flagging overdue requests, and the انتهاء المهلة escalation path.
**Done when:** An artificially back-dated request gets flagged as overdue by the scheduled command.

---

## TRACK E — Approvals & Committees

### Stage 18 — Approval chain
**Build:**
- `approvals` table wiring — records every action with level + role
- Sequential enforcement (can't skip a required approver)
- The conditional branch: ministry approval only above the decision-grade threshold
**Done when:** The full chain (reviewer → committee → admin manager → ministry/dean → final) runs correctly, and skipping a level is blocked.

### Stage 19 — E-signature
**Build:** Signature-pad Vue component → saved image → `signature_path` on the approval record; approval trail visual on the request detail page.
**Done when:** An approval captures a signature and it renders in the trail.

### Stage 20 — Committees & meetings
**Build:**
- Migrations + CRUD: `committees`, `committee_members`, `meetings`, `meeting_attendees`, `meeting_requests`
- Agenda builder (add/remove/reorder requests on a meeting), attendee management, minutes field
**Done when:** You can schedule a meeting, add requests to its agenda, and mark attendance.

### Stage 21 — Decisions & voting → workflow integration
**Build:**
- `decisions` + `votes` tables, voting UI in the meeting screen, tally logic
- A recorded decision automatically triggers the right `WorkflowService::transition()` call
- Handle "defer" (stays in committee for the next meeting)
**Done when:** A committee vote moves the request to the next stage with no manual intervention.

---

## TRACK F — Cross-cutting Features

### Stage 22 — Audit log
**Build:** Model observers writing to `audit_logs` (old/new values, IP, user agent), plus a read-only viewer screen with filters.
**Done when:** Every create/update/approve action appears in the audit viewer.
**Note:** Build this *before* heavy testing so the test runs generate real audit data.

### Stage 23 — Notifications
**Build:**
- Laravel notification classes per event type, queued
- Channels: in-app, email, SMS — respecting `notification_settings`
- Vue: notification bell + store; `notification_settings` preferences screen
**Done when:** A stage transition fires notifications on the enabled channels only.

### Stage 24 — Dashboard KPIs & reports
**Build:**
- Real KPI queries: average cycle time, completion %, pending count, SLA breaches (cached)
- Reports screen with filters + Excel/PDF export
**Done when:** Dashboard reflects real data and a filtered report exports correctly.

---

## TRACK G — The remaining seeded screens

Stage 3 seeded 23 screens into the `screens` table and their grants into
`screen_role_permissions`. Stages 6–24 built 20 of them. These three stages
finish the sheet: `decisions` was intentionally left as a placeholder in Stage
21 (the plan put the voting UI inside the meeting screen), while `backup` and
`user_guide` were seeded with permissions but never assigned to any stage at
all.

None of these needs a permission change — every action each one uses was
already granted when the matrix was first seeded.

### Stage 25 — Decisions register & pending-votes worklist
**Build:**
- `GET /decisions` register of every recorded committee decision (filters:
  outcome, committee, date range, reference/subject search) + `/decisions/export`
  reusing Stage 24's `ReportDocument`/`ReportExporter`
- `GET /decisions/pending` — the agenda items the signed-in member still owes a
  vote on, across every committee they sit on
- `App\Services\DecisionEligibility` — one predicate shared by the worklist
  query and the vote endpoint's guard, so the list can't offer an item the
  endpoint refuses
- Vue: `/decisions` with a register tab and a worklist tab; voting posts back to
  the Stage 21 endpoint. Recording a decision stays on the meeting screen.

**Done when:** A decision recorded in a meeting appears in the register, and the
agenda item it settled disappears from that member's worklist.

### Stage 26 — Backup & retention
**Build:**
- `backups` table + `App\Contracts\DatabaseDumper` (driver map in
  `AppServiceProvider`, `MysqlDumper` shelling to mysqldump)
- `BackupService`: zip of `database.sql` plus the private attachment/signature
  trees; failures recorded as a row rather than swallowed
- `backup:run` command, scheduled nightly, pruning past the retention window
- Vue: `/backup` — create, list, download, delete

**Done when:** An admin can take a snapshot, download it, and the nightly run
prunes expired ones.
**Note:** Restore is deliberately *not* in scope. Reloading the database is a
server-side operation performed with the system stopped, not a web button.

### Stage 27 — User guide
**Build:** `guide_articles` (bilingual, `Template`-shaped) + CRUD behind the
`user_guide` grants; Vue reading pane with search, category grouping, R08 inline
editing, and a print view.
**Done when:** An admin publishes an article and every other role can read and
print it, while drafts stay hidden from them.
**Note:** Article bodies render as plain text, never `v-html` — the content is
API-editable and every role lands on this screen.

---

## TRACK H — Meetings Management Unit (the committee-side redesign)

A redesign package (see [AGENT_NOTES.md](AGENT_NOTES.md), 2026-08-24) reframes the
committee side from "meeting admin" into a full **request → study → committee →
meeting → vote → decision → minutes → execution → close** pipeline. Today that
side is only Stages 20–21: `MeetingsView` (a bare schedule form) and
`MeetingDetailView` (an agenda list with ↑/↓ reorder, inline voting, and one
free-text `minutes` field). These 10 stages evolve that into the 9-screen unit
the design calls for. Every stage below names the current artifact it **changes**,
so none is greenfield-only.

**Locked scope decisions:** the sidebar becomes a grouped, collapsible
"إدارة الاجتماعات" section; the live-meeting screen is a **state-driven runner**
(no websockets/broadcasting — the video area is a static placeholder); the request
state machine is **extended** (new statuses seeded and wired through the workflow,
not UI-only relabels).

**Source legend** (attached redesign package — commit to `docs/meetings-redesign/`
so these citations resolve): **[Design PDF]** `اجتماع لجنة.pdf` (9-screen design,
§1–§9 + sidebar on p5); **[Lifecycle PDF]** `1.pdf` (12-stage lifecycle, the
Stage-7 5-step wizard, the Stage-10 internal path, the state-sequence on p2);
**[Workflow infographics]** the stage-5–11 municipality infographics; **[UI
mockups]** the 9 meeting-screen images.

### Stage 28 — Meetings-Unit navigation shell, screens & permissions
**Goal:** The grouped sidebar section and the 9 screen slots exist and route.
**Build:**
- Add `group` + `sort_order` to the `screens` table + `ScreenSeeder`; seed the 9
  codes (`meetings_dashboard`, `committee_candidates`, keep `meetings`,
  `meeting_agenda`, `meeting_readiness`, `meeting_live`, keep `decisions`,
  `meeting_minutes`, `meeting_outputs`) under an "إدارة الاجتماعات" group
- Seed grants following the existing pattern (view=`*`, the R03/R04 edit path)
- `stores/screens.js` builds a nested `navGroups`; `AppSidebar.vue` renders the
  collapsible group; non-meeting screens stay top-level
- Register lazy routes to empty view scaffolds
**Mechanism:** flat sidebar → grouped; the single `meetings` screen splits its
concerns across sibling screens.
**Done when:** the group renders for a committee role and each new route loads an
(empty) screen behind its permission.
**Source:** [Design PDF] p5 (sidebar restructure) + the 9-screen overview table
(p1); [UI mockups] all 9 (shared left-nav group).

### Stage 29 — Request lifecycle status expansion + workflow wiring
**Goal:** The committee sub-states exist without corrupting the stage machine.
**Build:**
- Add 7 statuses to `RequestStatusSeeder`: `nominated_for_committee`,
  `on_agenda`, `under_discussion`, `awaiting_recommendation_approval`,
  `completion_required`, `in_execution`, `completed_closed`
- `App\Services\CommitteeStatusService` — a guarded **status-only** write path
  for the moves that must not change workflow stage; `WorkflowService` stays the
  sole stage authority
- Reconcile `in_execution`/`completed_closed` with existing `approved`/
  `final_approved`/`archived` rather than duplicating them
**Mechanism:** 13 → 20 statuses; agenda placement and the live runner set status
without moving stages.
**Done when:** a request passes
ready→nominated→on_agenda→under_discussion→awaiting_recommendation_approval and a
feature test proves the stage number is untouched by the status-only moves.
**Source:** [Lifecycle PDF] p2 "حالات الطلب خلال الدورة" + the 12-stage table
(p1); [Workflow infographics] stages 5–7.
**Note:** don't rush — this touches the correctness-sensitive workflow engine.

### Stage 30 — Meeting scheduling model + 5-step wizard
**Build:**
- Migrate `meetings` (+`meeting_number`, `meeting_type` {دوري/استثنائي/طارئ},
  `chairman_user_id`, `rapporteur_user_id`, `expected_duration_minutes`,
  `agenda_deadline`, `description`)
- Migrate `meeting_attendees` (+`invitation_status`
  {pending|confirmed|declined|no_response}, `responded_at`); extend
  `MeetingController::store/update` + a send-invitations action
- Rebuild the `MeetingsView` schedule form into the 5-step wizard
  (requests → details → members/invitations → review/agenda → approve/schedule)
**Mechanism:** bare schedule form → wizard; attendees gain confirm-attendance.
**Done when:** a meeting is scheduled through the wizard with invitations sent and
attendance status tracked.
**Source:** [Design PDF] §3 (p2); [Lifecycle PDF] Stage 7 "المعالج من 5 خطوات"
(p1); [UI mockups] the wizard step screens + the الأعضاء والدعوات screen.

### Stage 31 — Agenda builder enhancements
**Build:**
- Migrate `meeting_requests` (+`priority`, `estimated_minutes`, `item_type`
  {employee_request|administrative|emerging}, nullable `subject`/`department_id`
  so **non-request admin items** are allowed)
- Agenda-stats + grouping endpoints; dedicated agenda-builder screen (priority,
  time totals, group-similar)
**Mechanism:** request-only ordered list → typed, prioritized, timed agenda
with admin items.
**Done when:** an agenda mixes employee requests and admin items with priorities
and a computed total time.
**Source:** [Design PDF] §4 (p2 — تجميع الطلبات المتشابهة, الزمن المتوقع);
[Lifecycle PDF] Stage 8; [UI mockups] the ترتيب جدول الأعمال + مراجعة وجدول
الأعمال screens.

### Stage 32 — Candidate-requests screen + command dashboard
**Build:**
- `GET /committee-candidates` (requests in `ready`/`nominated_for_committee`)
  with actions add-to-meeting / defer / return-to-study / request-completion,
  each writing status via `CommitteeStatusService`
- `GET /meetings/dashboard` aggregating KPIs + next-meeting readiness + the
  request lifecycle funnel; build both screens
**Mechanism:** the ad-hoc agenda search in `MeetingDetailView` → a first-class
linking worklist; new command dashboard.
**Done when:** a studied request appears as a candidate, is added to a meeting
from that screen, and the dashboard counts move.
**Source:** [Design PDF] §1 (p1 — 6 KPI cards, next-meeting block, the
38→18→16→2→16 funnel) + §2 (p1–2 — إضافة/تأجيل/إعادة للدراسة/طلب استكمال); [UI
mockups] the الطلبات المرشحة للعرض table + the مركز قيادة اللجنة dashboard.

### Stage 33 — Meeting readiness control center
**Build:**
- `GET /meetings/{id}/readiness` computing file %, member %, agenda completeness,
  invitations %, expected quorum, and an exceptions-only list → ready/not-ready
- The screen; gate "convene" on readiness (R03 exceptional override with a
  logged reason)
**Mechanism:** nothing today → a pre-meeting gate.
**Done when:** an incomplete file blocks convening until resolved or overridden.
**Source:** [Design PDF] §5 "Pre-Meeting Control Center" (p2–3); [UI mockups] the
مستوى الجاهزية donut on the meeting-detail and dashboard screens.

### Stage 34 — Live meeting runner
**Build:**
- Migrate `meeting_requests` (+`item_state`
  {presented|discussion|voting|deciding|complete}) and a discussion-notes store
- Runner screen: current-item panel, per-item timer, discussion feed, live vote
  panel (polling), progress, present-attendees, video **placeholder**; reuse the
  existing vote/decision endpoints; block meeting close until every item is
  `complete` or decided
**Mechanism:** inline agenda voting → a dedicated run-the-meeting screen.
**Done when:** a chair runs a meeting item-by-item and cannot close it with
unresolved items.
**Source:** [Design PDF] §6 (p3–4 — "أهم شاشة", بدء التصويت); [Lifecycle PDF]
Stage 10 internal path (p2); [UI mockups] the مباشر الاجتماع runner (video = the
static placeholder).

### Stage 35 — Decision templates & richer outcomes
**Build:**
- Reuse the `Template` model for decision text; map new outcome types (conditional
  approval, request-legal-opinion, refer-to-another-body) onto workflow actions
  (new exception transitions as needed); surface on the runner + `DecisionsView`
**Mechanism:** fixed approve/reject/defer → templated, richer outcome set.
**Done when:** a conditional-approval decision is recorded from a template and
drives the correct transition.
**Source:** [Design PDF] §7 (p4 — the قالب list); [Workflow infographics] stage 7
قرار اللجنة.

### Stage 36 — Minutes preparation & approval (المحاضر)
**Build:**
- Auto-compile minutes from meeting data (attendance, quorum, agenda, per-item
  summary, discussions, votes, decisions)
- Minutes approval lifecycle (draft → head review → member signatures → approved
  → meeting closed) via new fields/table + `SignaturePad`; the screen; gate
  meeting close on minutes approval
**Mechanism:** the single `minutes` text field → a generated, reviewed, signed,
approved document.
**Done when:** minutes generate, get signed and approved, and only then does the
meeting close.
**Source:** [Design PDF] §8 (p4 — the إعداد المقرر→…→إغلاق cycle); [Lifecycle PDF]
"بعد انتهاء الاجتماع" + Stage 12 (p1–2); [Workflow infographics] stage 8 اعتماد.

### Stage 37 — Meeting outputs follow-up (مخرجات الاجتماعات)
**Build:**
- Meeting-scoped outputs view linking each agenda item → decision → next action →
  responsible body → execution status; follow decisions into execution
  (`in_execution` → `completed_closed`)
**Mechanism:** the generic decisions register → a meeting-to-request outputs
tracker that closes the loop.
**Done when:** a meeting's page lists every decision with its downstream action
and live execution status.
**Source:** [Design PDF] §9 (p4–5 — the رقم الطلب|الموظف|قرار اللجنة|الإجراء
التالي|الجهة المسؤولة|الحالة table + the full تقديم→…→إغلاق backbone); [Workflow
infographics] stages 8–11.

---

## TRACK I — Employee Affairs Committee Official Process Alignment

Five documents describe this committee's process (see
[docs/employee-committee-lifecycle/README.md](docs/employee-committee-lifecycle/README.md)
for the full lineage — that folder is git-ignored but persists locally).
**[D]** (`دليل إجراءات لجنة شؤون الموظفين`, 142-page/114-Article procedures
manual) and **[E]** (`المسار التفصيلي المعتمد`, a 21-stage detailed flow) are
the **standard** — same July-2026 edition as the shorter **[A]** (`مسار تقديم
طلب وعمل لجنة`), which stays a useful summary but loses to [D]/[E] on
specifics. **[B]**/**[C]** (`1.pdf` / `اجتماع لجنة.pdf`) are the *different*,
informal documents Track H above was actually built from — kept for UI/UX
reference only, never authoritative on process shape. Full comparison:
[docs/employee-committee-lifecycle/gap-analysis.md](docs/employee-committee-lifecycle/gap-analysis.md).

**Two corrections already folded in below, not re-litigated per stage:**
current system's R10 "وكيل الديوان" role and its 3-way `administrative_routing`
(HR/Diwan/Committee-secretary) looked invented against the informal
infographics an earlier redesign session used, but [D] Art. 10/11 and [E]
stage 03 show the standard routes to exactly the same three parties — so
Stage 56 below is a verification task, not a rebuild. Same for the
`direct_manager_review` gate against Stage 55.

### Stage 38 — Source reconciliation
**Goal:** [D]/[E] formally recorded as the standard process reference.
**Build:**
- `docs/employee-committee-lifecycle/` committed to memory (git-ignored, so
  it stays local — durable pointer lives in AGENTS.md/AGENT_NOTES.md instead)
- No code change — this stage is the housekeeping the other 20 depend on.
**Done when:** anyone opening AGENTS.md or this file knows where to look
before touching committee/workflow code.

### Stage 39 — Agenda drag-and-drop reorder
**Goal:** `meeting_agenda`'s reorder matches [C]'s design (drag & drop), not
the up/down buttons Stage 20/31 shipped.
**Build:** replace `MeetingAgendaBuilderView.vue`'s up/down controls with a
drag-and-drop list, same `agenda_order` write path underneath.
**Source:** [C] §4.

### Stage 40 — Group-similar-by-request-type in the agenda builder
**Goal:** `agendaStats()`'s grouping matches [C]'s example (by request type),
not the current department-only axis.
**Build:** add a request-type grouping option to `MeetingController::agendaStats()`
and the agenda builder UI, alongside the existing department grouping.
**Source:** [C] §4 ("جميع طلبات الترقية... في مجموعة واحدة").

### Stage 41 — Abstain vote option
**Goal:** a committee member who attended but didn't vote either way can be
represented.
**Build:** add `abstain` (or `ممتنع`) to `votes.vote`'s value set, a
`votes_abstain_count` column on `decisions`, and the vote UI's tally display.
**Source:** [C] §7 ("الحاضرون 7 | صوّت 7 | موافق 5 | غير موافق 1 | ممتنع 1").

### Stage 42 — Decision-draft auto-generation
**Goal:** picking a decision template merges the record's own data into the
draft text, not just a static body the user edits by hand.
**Build:** extend `DecisionController::record()`'s template-fill step to
interpolate request/employee/date fields into the chosen `Template`'s body.
**Source:** [C] §7 ("توليد مسودة القرار من بيانات المعاملة"); [D]'s
decision-phrasing bank (`official-procedures-manual-index.md` §11) for the
canonical phrasing set to draw from.

### Stage 43 — Move `decisions` out of the meetings sidebar group
**Goal:** `decisions` is a shared/common screen again, per [C]'s explicit
instruction, not nested inside `meetings_management`.
**Build:** change `decisions`' `group` in `ScreenSeeder` back to null/shared;
update `AppSidebar.vue` placement accordingly. No permission change needed.
**Source:** [C] ("القرارات... وحدات مشتركة في النظام ولا نكررها داخل قسم
الاجتماعات").

### Stage 44 — Verify remaining [C] fidelity gaps
**Goal:** confirm or close two unverified items from the gap analysis rather
than leave them as open questions.
**Build:**
- Check `committee_candidates`'s columns against [C] §2 (مدة االنتظار,
  االجتماع المقترح, a standalone فتح الملف action) — add whichever are
  actually missing
- Check `MeetingLiveView`'s tabs against [C] §6's six (ملخص الطلب|بيانات
  الموظف|الدراسة|المرفقات|الطلبات السابقة|مالحظات اللجنة)
**Source:** [C] §2, §6.

### Stage 45 — Fixed 5-seat committee roster
**Goal:** `committees`/`committee_members` can represent [D] Art. 10's
institutional composition, not just an open R03/R04 headcount.
**Build:**
- Add a `seat` enum to `committee_members` (chair/legal/hr_director/
  ministry_delegate/rapporteur) alongside the existing `is_head`, or replace
  `is_head` with it
- Seed/UI: a committee's membership screen enforces exactly these 5 seats
  filled, one occupant each
- `مندوب الخدمة المدنية` seat is display/voting per [D] Art. 14 but never
  substitutes for a separate central-approval referral — don't let filling
  this seat silently skip Stage 57's ministry-approval logic
**Mechanism:** open membership pool → fixed institutional roster.
**Done when:** a committee's 5 seats are individually identifiable and a
feature test proves the ministry-delegate seat's vote doesn't skip central
approval on its own.
**Source:** [D] Art. 10, 14 (`official-procedures-manual-index.md` §3).

### Stage 46 — Presentation memo (مذكرة العرض) compiler
**Goal:** each committee item has a compiled pre-meeting memo, not just the
raw file.
**Build:** new `PresentationMemoCompiler` service mirroring
`MeetingMinutesCompiler`'s shape, producing [D] Art. 22's exact field list
(reference number, employee, work unit, subject, submission date, referring
body, facts summary, employment status, key documents, legal opinion, prior
decisions, the specific question the committee is asked to decide); attach
to the agenda item, viewable before/during the meeting.
**Mechanism:** nothing today → a pre-meeting compiled artifact, symmetric
with the post-meeting minutes compiler.
**Source:** [D] Art. 22; [A] §4 stage 7.

### Stage 47 — Salaries & Benefits Dept participation trigger
**Goal:** requests with a financial effect (promotion, settlement, allowance,
back-pay, grade change) trigger a قسم المرتبات والمزايا review step.
**Build:** a `has_financial_impact` flag (derived from request type or set
manually) triggering a parallel review/opinion + notification during the
study stage. [D] doesn't name this seat on the committee itself (only [A]
does, as a participating party outside the fixed 5-seat roster) — confirm
during Stage 45's roster work whether [D] treats this as deliberate scope
narrowing before building it as a full parallel-review step.
**Source:** [A] §3 party 5.

### Stage 48 — Rapporteur/voting-member separation + conflict of interest
**Goal:** a meeting's rapporteur can't also vote unless the tashkil decision
grants it, and a member with a stake in an item must formally recuse.
**Build:**
- Enforce in `DecisionEligibility`/vote-casting: block a vote from the
  meeting's `rapporteur_user_id` unless a flag on the tashkil/committee
  record grants dual status
- New conflict-of-interest declare/recuse action per agenda item: disclose
  before discussion → recorded in the compiled minutes → blocked from
  deliberation/voting once recused
**Source:** [D] Art. 11, 15, 18; [A] §11 controls #4–5; [C]'s rapporteur/
voting-member distinction.

### Stage 49 — Richer committee decision outcomes
**Goal:** the decision-outcome set matches the standard's shape rather than
current system's 6-outcome list built ad hoc across Stages 21/35.
**Build:**
- Add an explicit "عدم اختصاص" outcome (distinct from `refer_to_another_body`)
  matching [D] status 14 — a new workflow_transitions exception row + status
- Reconsider whether "إعادة الملف الستكمال بيانات" should be reachable as an
  *in-meeting decision outcome* (per [A]/[E]) rather than only as
  `CommitteeStatusService::require_completion`'s pre-meeting, status-only
  action — these are two different moments in the standard's own model
**Source:** [A] §4 stage 9 (7 outcomes); [D] Art. 26 (4 outcomes, deferral
covering 6 named reasons); [E] stage 12.

### Stage 50 — Minutes content completeness
**Goal:** close every gap the exploration found against [D] Art. 28's minute-
content list.
**Build:** extend `MeetingMinutesCompiler`'s content schema with: attendee
roles/titles (not just id+name), a per-item facts summary, a
documents-reviewed list, per-member dissenting opinion + reason (surface
`votes.comment`, currently captured but never compiled in), a legal-basis
field distinct from the free-text decision comment, a referral-authority
field, and embedded signature references.
**Source:** [D] Art. 28; [A] §4 stage 10.

### Stage 51 — Employee-facing visibility
**Goal:** an employee viewing their own request sees what [A] §7 requires.
**Build:** extend `RequestDetailResource` with a `documents_complete` flag
and a `committee_summary` block (meeting number/date, agenda item number,
committee result, decision number/date) when applicable; audit notification/
UI copy against [D] Art. 32's rule that a result is never described as
final/approved before every required approval tier is actually complete.
**Source:** [A] §7; [D] Art. 32, 101.

### Stage 52 — Per-stage operational timeframes (soft SLA)
**Goal:** a soft, non-binding per-stage duration target exists alongside the
existing hard per-type SLA — not a replacement for it.
**Build:** a target-duration lookup per `workflow_stages` row (or a small
lookup table), sourced from [D]'s appendix figures; surfaced as an
escalation-coloring indicator (green/yellow/red/critical per [D]'s scheme),
never blocking.
**Source:** [D] appendix "المدد التشغيلية المستهدفة"; [A] §12.

### Stage 53 — RequestType catalogue + per-type document checklists
**Goal:** `RequestType` matches [D] Art. 46's six primary families plus
secondary categories, each with its own required-attachment list.
**Build:**
- Add missing types, notably **"تثبيت بعد الاختبار"** (confirmation after
  probation — [A]'s jurisdiction item #1, [D]'s first type-specific chapter)
  plus plain التعيين/التعاقد, تسوية وضع وظيفي as first-class, and تظلم من
  تقييم أداء distinct from generic Grievance
- A `required_documents` list per type (soft checklist at intake), sourced
  from [D]'s per-type chapters (Arts. 48–79) and checklist appendices
**Source:** [D] Art. 46–79 + appendices; [A] §10.

### Stage 54 — Committee jurisdiction validation at initial review
**Goal:** initial review runs [D] Art. 45's 6-question jurisdiction test
before anything reaches the agenda, with a matching outcome set.
**Build:** extend `requirements_check`'s outcomes beyond today's
approve/return_missing_docs/cancel to cover [A] §4 stage 3's 4 results
(accepted/suspended-for-docs/referred-elsewhere/rejected-with-reason),
gated on the 6-question test.
**Source:** [D] Art. 45; [A] §4 stage 3.

### Stage 54b — Status-vocabulary reconciliation
**Goal:** a deliberate mapping exists between the current 25-status enum and
[D] Art. 38's canonical 20-code dictionary, so nothing on either side is
silently unrepresented.
**Build:** a reconciliation table (not necessarily a 1:1 replacement — the
current vocabulary carries committee-substate and 3-way-routing information
[D]'s list doesn't split out); document the mapping in
`docs/employee-committee-lifecycle/gap-analysis.md` §4 and decide, per
divergent status, whether to rename/merge/keep.
**Source:** [D] Art. 38 (`official-procedures-manual-index.md` §5).

### Stage 55 — ⚠ Verify `direct_manager_review` gate wording
**Goal:** confirm whether the current mandatory gate (forward /
`return_to_employee`-with-required-comment / cancel) already complies with
[D] Art. 10 ("no withholding without a written reason") — likely close, per
the gap analysis — before assuming a rebuild is needed.
**Build:** re-read [D] Art. 10 against `WorkflowTransitionSeeder`'s
`direct_manager_review` rows; adjust copy/validation messages only if an
actual gap is found, not the gate mechanism itself.
**Source:** [D] Art. 10; gap-analysis.md §13.

### Stage 56 — ⚠ Verify 3-way routing selection rule
**Goal:** confirm the 3-way `administrative_routing` (route_to_hr/
route_to_diwan/route_to_committee_secretary) selects among وكيل الديوان/مدير
الموارد البشرية/رئيس قسم شؤون الموظفين "بحسب الموضوع" (by subject matter,
per [D] Art. 11/[E] stage 03), not as a free choice.
**Build:** if the current selection is effectively manual/arbitrary, design a
subject-matter-based routing rule (per request type or category) rather than
leaving it to whoever's acting; this is additive to the existing 3 paths, not
a replacement of them.
**Source:** [D] Art. 11; [E] stage 03; gap-analysis.md §13.

### Stage 57 — ⚠ Pre-committee ministry stage + approval-tail restructure
**Goal:** replace `ministry_endorsement` (currently pre-committee, workflow
stage 8) and the 4-level `decision_grade`-gated approval tail with the
standard's two-path model (mayor-only under delegated authority, or
mayor→ministry when required), matching [D] Art. 31's "بانتظار االعتماد
المركزي" status and [E] stages 15–17.
**Build:** this is the one item in this track that changes already-shipped,
tested behavior with real blast radius (in-flight requests currently sitting
at `ministry_endorsement`/`approval_by_authority`/`local_governance_ministry`
need a defined migration path) — needs its own design pass before touching
code, not a quick fix alongside another stage.
**Source:** [A] §5; [D] Art. 31, 92–97; [E] stages 15–17; gap-analysis.md
§14.

## TRACK J — Appeal (تظلم) Lifecycle

Sub-staged out of the single former "Stage 58" placeholder, per [D] Arts.
75–79's full specification (the primary source — transcribed in
[docs/employee-committee-lifecycle/official-procedures-manual-index.md](docs/employee-committee-lifecycle/official-procedures-manual-index.md)
§7's التظلمات الوظيفية entry) and [A] §9's 6-stage summary (تقديم → التحقق
الشكلي → جمع الملف الأصلي → المراجعة القانونية → العرض على اللجنة أو الجهة
المختصة → التبليغ واإلغالق) — where they overlap, [D]'s fuller Article text
wins, matching every other Track I stage's precedence rule.

**Citation caveat, applies to every stage below:**
`official-procedures-manual-index.md` records Arts. 75–79 as a **single
block** with six topics (intake fields / jurisdiction check / Art. 77's
disciplinary separation / legal review / the 5-outcome set / notify+close)
and attributes specific article numbers to only **Art. 77** and **Arts.
78–79**. The numbered 1–6 sequence below comes from **[A] §9**, which is
genuinely numbered. So each stage cites "[D] Arts. 75–79 (<topic>)" plus its
[A] §9 step — nothing here claims an article-point granularity the index
doesn't actually have. Per the folder README's own caveat, re-read [D]'s PDF
before citing an exact article number in code comments.

**Three scope decisions made once here, not re-litigated per stage below:**

**(1) An appeal is a new entity, not a `RequestType`.** `appeals` references
an already-decided `Request`; it is never a Grievance-type `Request` routed
through the ordinary intake pipeline. This is already settled in
gap-analysis.md §12: `GRIV` (and Stage 53's `PEVG`) are ordinary intake types
"processed through the identical committee pipeline as every other request
type, **not a way to contest an already-decided matter**." Both concepts
legitimately coexist — a grievance *about* something is a request; a تظلم
*against a committee decision* is an appeal. Expect this to be the single
most likely thing for a future session to conflate.

**(2) Appeals get their own small status machine**, not a detour through the
14-stage `workflow_stages` table — the appeal cycle is a self-contained 6
steps ([A] §9), and forcing it through the main request workflow would
conflate two different state machines the same way Stage 29's
`CommitteeStatusService` was deliberately kept separate from
`WorkflowService` for committee sub-statuses.

**(3) ⚠ An open appeal blocks closure of the original request — a
cross-system rule, not an appeal-local one.** [D] Arts. 34–37's اإلقفال rule
says a matter is only "مقفلة" once one of 4 terminal paths completes, the
fourth being "any تظلم or re-presentation path concluded, **with the file
kept open until it does**." Today `WorkflowService::hasTerminalStatus()`
(`cancelled|archived|in_execution|completed_closed`) and Stage 37's
`MeetingOutputService` close action know nothing about appeals, so filing one
against an already-`completed_closed` request currently has no effect on it.
Stages 59/65/66 each carry their half of this rule. **Deliberately NOT
modelled as a new request status**: [A] §8 does name "محل تظلم" / "أعيد
فتحها بموجب تظلم أو حكم" as request states, but that list is [A]'s looser
27-state one, which the summary document itself says to reconcile against [D]
Art. 38's 20-code dictionary — and Art. 38 has **no** under-appeal code.
Stage 54b already did that reconciliation and kept the current 28 statuses.
So this is a derived flag / blocked-closure rule computed from the existence
of an open `Appeal`, which satisfies [D]'s behavioural requirement without
reopening Stage 54b's decision.

### Stage 58 — Appeal entity, schema & status machine
**Goal:** the foundational `appeals` table and its own status machine exist,
independent of `requests`/`workflow_stages`.
**Build:** `appeals` (appellant `user_id`, `original_request_id` FK →
`requests`, nullable `original_decision_id` FK → `decisions` — not every
decided matter rode a committee vote, e.g. Stage 54's `reject_formally` at
`requirements_check` — plus a free-text fallback decision reference/date for
that case), a small seeded `appeal_statuses` table (not reusing
`request_statuses`), `Appeal` model, a new top-level `appeals` screen (not
nested under `meetings_management` — filing an appeal is an employee-facing
action, not committee administration) with permissions seeded.
**Done when:** an `Appeal` row can be created against an existing decided
`Request` and is visible on its own screen; no workflow logic yet.
**Source:** [D] Art. 47 (التظلمات classification), Arts. 75–79 (intake
block); gap-analysis.md §12.

### Stage 59 — Appeal intake
**Goal:** an employee can submit a written appeal against one of their own
already-decided matters.
**Build:** intake form — pick the target decided request (restricted to
statuses meaning the matter actually reached a result: Art. 38 codes 12
موافق عليها من اللجنة / 13 غير موافق عليها / 14 عدم اختصاص / 17 معتمدة
نهائيًا / 19 منفذة / 20 مغلقة ومؤرشفة, mapped onto this system's own status
codes via Stage 54b's reconciliation table rather than re-derived), القرار
المتظلم منه (auto-filled from the pick), تاريخ العلم به, أسباب االعتراض,
الطلب النهائي, supporting documents. Non-duplication check per [A] §9 step 2
("عدم تكرار نفس التظلم دون وقائع جديدة") — block a second appeal against the
same decision unless new facts are declared. Also sets the Track J intro's
scope-decision (3) flag: an open appeal blocks the original request's
closure.
**⚠ Attachments are a real design decision, not free reuse:**
`attachments.request_id` is a hard FK to `requests` with `cascadeOnDelete` —
**not** polymorphic — so an appeal's own supporting documents have nowhere to
live today. Three options, pick one deliberately: make `attachments`
polymorphic (touches every existing attachment consumer), a separate
`appeal_attachments` table (duplication, but zero blast radius), or hang
appeal documents off the original request with a label (muddles whose
document is whose — probably wrong for an adversarial record).
**Done when:** an appeal can be filed against a decided request the employee
owns, its documents are stored, and a same-facts duplicate is refused.
**Source:** [D] Arts. 75–79 (intake fields); [A] §9 steps 1–2.

### Stage 60 — Formal verification gate (التحقق الشكلي)
**Goal:** قسم شؤون الموظفين's admissibility check before an appeal proceeds
any further.
**Build:** a verification action checking, per [A] §9 step 2: صفة المتظلم
(appellant standing), القرار محل التظلم (the target decision is real and
appealable), المواعيد القانونية (any statutory deadline, if one is
configured — no document in this folder states an actual number of days, so
this is a configurable setting seeded empty, **not** a fabricated deadline),
عدم التكرار (Stage 59's duplication check, re-verified here by a human rather
than only the intake-time heuristic). A failing check closes the appeal
immediately with a recorded reason; passing advances it.
**Done when:** an appeal can be formally rejected at intake (bad standing, no
valid target, deadline missed, duplicate) or advanced, each outcome logged
with who/when/why.
**Source:** [D] Arts. 75–79 (jurisdiction/formal check); [A] §9 step 2.

### Stage 61 — Original file assembly (جمع الملف األصلي)
**Goal:** the appeal record shows the complete original-matter dossier
alongside the new appeal documents.
**Build:** a read-only `AppealFileCompiler` reusing already-built compilers
rather than re-deriving their data — Stage 46's `PresentationMemoCompiler`
for the original مذكرة العرض, Stage 36/50's `MeetingMinutesCompiler` for the
original محضر, the linked `Decision` (if any) from Stage 21/35/49, plus the
appeal's own newly-submitted documents.
**⚠ إثبات التبليغ (proof of notification) is only partially evidenceable
today:** [A] §9 step 3 lists it as part of the original dossier, but Stage
23's `notifications` table is Laravel's standard **database-channel** store —
it persists a row per in-app notification and nothing for email or SMS, which
have no delivery log at all. So this stage can honestly show in-app notice
proof and must show *absence of evidence* (not a fabricated "notified") for
the other two channels. Building a real per-channel delivery log is a
separate piece of work, not assumed here.
**Done when:** opening an appeal shows the original request, memo, minutes,
decision and whatever notification evidence genuinely exists, in one place,
with no data re-entered by hand.
**Source:** [D] Arts. 75–79 (original-file assembly); [A] §9 step 3.

### Stage 62 — Jurisdiction test & legal review
**Goal:** Art. 77's explicit disciplinary-matter exclusion, plus the Art. 75
point 4 legal-review checklist, both gate whether an appeal can reach
committee presentation.
**Build:** a structured jurisdiction-test form (mirroring Stage 54's
6-question-test UI pattern, not free text) asking whether the committee is
actually the competent body for this appeal or whether it belongs to
عميد البلدية / وزارة الحكم المحلي / another org body / **a disciplinary
board or court** — the last option, per Art. 77, must terminate the appeal
with a referral outcome rather than let it proceed, since [D] is explicit
that a disciplinary matter is never routed through لجنة شؤون الموظفين as a
substitute for a real disciplinary board. A separate structured legal-review
record: factual error? legal-text violation? new documents? formation/
quorum/reasoning defect in the original decision? issued by a competent
body?
**Done when:** an appeal cannot reach Stage 63 without both records present;
a disciplinary-flagged appeal is refused/referred instead of proceeding.
**Source:** [D] Arts. 75–79 (legal review), **Art. 77** (the one
article-level citation the index is explicit about — "ال يجوز الخلط مع
المسار التأديبي", per gap-analysis.md §12); [A] §9 step 4.

### Stage 63 — Committee presentation & 5-outcome decision
**Goal:** an appeal can be placed before اللجنة أو الجهة المختصة and decided
with Art. 75 point 5's own outcome vocabulary — distinct from the ordinary
committee-decision outcomes Stages 35/41/49 already built.
**Build:** extend `MeetingRequest.item_type` (Stage 31: currently
`employee_request|administrative|emerging`) with a new `appeal` value plus a
nullable `appeal_id` — `request_id` is already nullable (Stage 31 made it so
for admin items), so the column shape needs no further change — letting
appeals ride the existing agenda/vote/signature machinery instead of a
parallel one. A new, appeal-specific outcome set on the decision record —
قبول التظلم وسحب أو تعديل القرار / قبول جزئي / رفض مسبب / إحالة لجهة أخرى /
إعادة اإلجراءات من المرحلة التي وقع فيها العيب — kept separate from
`DecisionController::ACTIONS` (currently 7 entries after Stages 35/41/49),
not merged into it, since these five outcomes mean something structurally
different (see Stage 64) from an ordinary approve/reject/defer.
**⚠ Scope reality check — this is not a one-line enum widening.** A repo grep
finds **~14 hardcoded `item_type === 'employee_request'` guards** across
`DecisionController` (×2), `MeetingController` (×5),
`PresentationMemoController` (×2), `ConflictOfInterestController`,
`MeetingDiscussionNoteController`, `StoreMeetingAgendaRequest`, plus
`DecisionEligibility::pendingVotesQuery()`'s SQL filter (Stage 31/48). Every
one needs an individual yes/no decision for appeals — voting and
conflict-of-interest almost certainly yes; the presentation-memo compiler
reads `$agendaItem->request` directly and would need widening or exclusion;
`agendaStats()`'s `$byType` map needs a fourth bucket. Inventory them before
coding, don't fix forward one 500 at a time.
**Done when:** an appeal can be nominated onto a meeting agenda and decided
with exactly one of the 5 appeal outcomes, using the same vote-tally/
signature mechanics every other committee decision already uses, and every
`employee_request` guard has been deliberately re-decided rather than
inherited.
**Source:** [D] Arts. 75–79 (the 5-outcome set); [A] §9 step 5.

### Stage 64 — ⚠ Outcome execution (accept / amend / refer / redo)
**Goal:** each of the 5 outcomes has a real effect on the *original* decided
request, not just a label on the appeal.
**Build:** قبول التظلم / قبول جزئي → writes the withdrawal/amendment back
onto the original `Request` record itself, never a new one — per [D] Arts.
34–37's إعادة العرض rule ("keeps the same original reference number… a new
transaction is never created for re-presenting the same matter merely because
it's being presented again", **note its own trailing caveat**: "unless
there's a genuine legal/organizational reason requiring one", so this is a
strong default, not an absolute); رفض مسبب → closes the appeal with the
recorded reasoning, no change to the original request; إحالة لجهة أخرى →
referral bookkeeping, reusing the `refer_to_another_body`-style pattern
Stage 49 already built; **إعادة اإلجراءات من المرحلة التي وقع فيها العيب is
the one genuinely novel case** — it must re-enter the *original* request's
own `WorkflowService` state machine at the specific stage the legal-review
found the defect, not restart the request from scratch. This is real new
coupling between two systems that were deliberately kept separate everywhere
else in this track (see the Track J intro) — needs its own design pass
before coding, the same caution Stage 57 required.
**Done when:** each outcome demonstrably mutates the right record (the
appeal, and/or the original request re-entering its workflow at the correct
stage) with a full audit trail.
**Source:** [D] Arts. 75–79 (the 5-outcome set), Arts. 34–37 (إعادة العرض's
same-reference-number rule); [E]'s المسارات األربعة بعد قرار اللجنة
diagram (the general re-presentation/return-path shape, applied here to
appeal outcomes specifically).

### Stage 65 — Notification & closure
**Goal:** Art. 75 point 6 — the appellant receives written notice of the
final result and issuing body; the appeal's own closure record is complete.
**Build:** a new `appeal_decided` event on Stage 23's existing
`NotificationDispatcher` + `NotificationSetting::EVENT_TYPES` (same
channel-preference mechanism, no new notification pipeline — this part IS a
clean reuse). An appeal-closure record carrying [D] Arts. 34–37's own
closure-field list: closure date, final result, final decision number if any,
approving body, execution date, executing body, notice status, file storage
location. Concluding the appeal also releases the Track J intro's scope-
decision (3) hold on the original request's closure.
**⚠ There is no existing closure-record precedent to mirror — verified, not
assumed.** A grep for `closed_at`/`closure`/`closed_by` on `Request` and its
migrations returns **nothing**: Stage 37 gave requests a `completed_closed`
*status* and nothing else, and "preserve, don't erase" is the master-data
deletion pattern (departments/committees), unrelated to closure records. So
these 8 fields are new work sourced from [D] directly — and the same gap
exists for ordinary requests, which this stage does **not** close (that would
be retrofitting Track I's Stage 37, a separate decision).
**Done when:** the appellant is notified through their configured channels
when the appeal concludes, a concluded appeal shows a closed state with those
fields populated, and the original request becomes closable again.
**Source:** [D] Arts. 75–79 (notify+close), Arts. 34–37 (the closure-field
list and the "file kept open until the تظلم path concludes" rule); [A] §9
step 6.

### Stage 66 — Non-reopening rule + the reopen mechanism
**Goal:** [D] Arts. 78–79 — neither a closed appeal nor a concluded original
matter may be reopened merely because the affected party is unhappy with the
result.
**Build:** a `reopen` action gated behind an explicit, **enumerated** reason
— new document / external reply arrived / material-error correction /
legal-status change / the approving body sent it back / a competent authority
directed a re-study (Arts. 78–79's own list, transcribed in the index) —
never a free-text-only justification. Wired onto a closed `Appeal` and onto
the original request's re-presentation path. Note this is the *positive*
counterpart to Arts. 34–37's عدم الموافقة rule ("a matter is never
re-presented with the same facts and documents merely because the affected
party is dissatisfied — only via a legal path, or when new documents/facts
with real effect surface"), so the two articles agree and the enumerated list
serves both.
**Explicit scope note, not a silent gap:** [A] §8 also names "أعيد فتحها
بموجب … حكم" (reopened by a court judgment) alongside تظلم. No document in
this folder specifies judgment-driven reopening beyond that one mention, and
[A] §8 is the looser 27-state list that loses to [D] Art. 38 anyway (see
scope decision 3 above), so this stage gives a court judgment the same
enumerated-reason mechanism and no more — a dedicated court-judgment workflow
is out of scope.
**Done when:** reopening — of either an appeal or its original matter — is
only possible through the enumerated-reason gate, is logged, and a plain "I
disagree with the outcome" attempt is rejected.
**Source:** [D] Arts. 78–79 (the enumerated reopen reasons), Arts. 34–37
(the matching عدم الموافقة non-re-presentation rule); [A] §9 step 6.

---

# TRACK K — Verbatim-source alignment ([D] and [E] as actually written)

> Tracks I and J aligned this system to [D]/[E] working from
> [official-procedures-manual-index.md](docs/employee-committee-lifecycle/official-procedures-manual-index.md),
> which states plainly that it is "a **structured index**, not a verbatim
> reproduction — re-read the source PDF before relying on an exact figure or
> field list for anything implementation-critical." In September 2026 the
> **full verbatim text of both documents** was supplied for the first time.
> Track K is the correction pass that follows: it audits the real text —
> including **all 78 of [D]'s organizational appendices, none of which had
> ever been read** — and closes what the paraphrase could not surface.
>
> **What the verbatim text confirmed as already correct**, so it is not
> re-litigated below: Art. 10's five-seat roster (matches
> `CommitteeMember::SEATS` seat-for-seat), Art. 45's six jurisdiction
> questions (match Stage 54's `jurisdiction_test` field-for-field), Art. 26's
> four committee outcomes (present as a superset), and Arts. 75–79's appeal
> lifecycle and enumerated reopen reasons (match Track J, including
> `ReopenReasonCatalog::CODES`). The process *shape* is right; what follows is
> a finite list, not a rebuild.
>
> **Source-citation warning:** [D] contains **two** article-numbering
> collisions. The already-known duplicate Art. 103, and — found during Track
> K's audit — **Articles 9–20 appear twice**: occurrence **(أ)** is Baab 2's
> "الفصل الرابع: تشكيل لجنة شؤون الموظفين" (Arts. 9–20), and occurrence **(ب)**
> restarts the numbering across Baab 3's chapter *also* labelled "الفصل الرابع"
> (الأطراف المشاركة، Arts. 9–14) and Baab 4's "الفصل الخامس" (المسار الإجرائي،
> Arts. 15–23), then runs on to 114. So "المادة 15" is both *مقرر اللجنة* and
> *نقطة بداية المعاملة*. Existing Track I/J citations of Arts. 10–14 mean (أ);
> citations of Arts. 15–23 mean (ب) — both verified correct, but always name
> the chapter alongside an article number from this manual.
>
> **Two scope decisions taken once, not re-litigated per stage:**
> **(1) ملف الخدمة is treated as living outside this application.** Art. 12
> mandates a service file and Appendix 52 mandates updating it after
> execution; Track K implements the *evidence* that it was updated (Stage 76),
> not an employment-record module.
> **(2) Statuses reconcile to Art. 38 (Stage 69), deliberately reversing Stage
> 54b's "keep as-is" decision** — that call was correct when the goal was a
> documented mapping; the goal is now literal identity with Art. 38's
> twenty-code dictionary.
>
> Articles that are pure human-conduct governance — Art. 8's twenty
> principles, Arts. 18–19's حياد/سرية duties, Appendix 49's integrity rules,
> and the parts of Appendix 66's prohibitions describing staff behaviour
> rather than system states — are recorded as **not applicable** in Stage 67's
> matrix and deliberately not turned into code.

### Stage 67 — Verbatim sources + corrected compliance matrix
**Goal:** the docs folder stops depending on a paraphrase for anything the
real text can answer, and a single current matrix says exactly where the
system stands against every provision.
**Build:** save both documents' full text as `source-manual-verbatim.md` and
`source-detailed-flow-verbatim.md`; repoint `README.md`'s lineage table at
them and demote the index/flow paraphrases; record the newly-found Arts. 9–20
duplicate-numbering defect alongside the known Art. 103 one; produce
`compliance-matrix.md` tagging every Article 1–114, every appendix 1–78 and
every [E] stage 01–21 as **compliant** (citing the implementing file) /
**divergent** / **missing** / **not applicable**.
**Note on the matrix's home:** it is a *new* file rather than a rewrite of
`gap-analysis.md`, which instead gets a superseding header. This folder is
git-ignored, so overwriting would have destroyed the only copy of *why*
several Track I decisions were made (§4's status reconciliation, §14's Stage
57 resolution, §§16–17's verification verdicts) — reasoning the matrix cites
but does not reproduce.
**Done when:** every provision appears exactly once with a tag, and the
divergent/missing rows are the ones Stages 68–83 close. No code change.

### Stage 68 — Pre-meeting legal review (the largest missing mandated step)
**Goal:** [E]'s numbered **stage 08** exists. Today `legal_review` lives only
on `Appeal` (Stage 62); the ordinary request path has no legal review at all,
which Stage 46's own note already found ("no model for that review exists
anywhere in this codebase").
**Build:** a `RequestLegalReview` record carrying Appendix 22's بطاقة السند
القانوني fields (التشريع الأساسي، رقم المادة، القرار أو المنشور المكمل،
اختصاص اللجنة قرار/توصية/رأي/لا اختصاص، جهة الاعتماد، هل يلزم اعتماد مركزي،
مدة قانونية مؤثرة، شروط مانعة) plus النموذج 06's five-outcome verdict (سليم
قانونيًا وجاهز للعرض / يحتاج استكمال مستند أو بيان / يحتاج إيضاح قانوني أو
إداري / ملاحظة بشأن الاختصاص / مسألة قانونية تستوجب العرض مع بيانها); a new
**R11 legal-officer role** (Stage 45 added a `legal` committee *seat*, but no
role and no permissions); the Art. 38 code **07** status; and an
agenda-insertion gate, per Art. 24's ملف العرض contents and Appendix 7's
readiness question "هل تمت المراجعة القانونية المطلوبة؟".
**Done when:** a request cannot reach the agenda without a completed review,
and each of the five verdicts routes correctly.
**Source:** [D] Art. 21, 24; [E] stage 08; Appendices 6, 7, 22.

### Stage 69 — ⚠ Art. 38 status-dictionary reconciliation
**Goal:** the status vocabulary *is* Art. 38's twenty-code dictionary.
**Build:** split `approved` into codes **15/16** (بانتظار اعتماد البلدية vs
بانتظار الاعتماد المركزي — Art. 31 names the latter explicitly); separate code
**13** (غير موافق عليها) from the overloaded `cancelled`, which is the one item
Stage 54b explicitly left open; separate **19** (منفذة) from **20** (مغلقة
ومؤرشفة); code **07** arrives with Stage 68. Validate the seeded transition map
against **Appendix 5**'s allowed-transition rules ("بانتظار استكمال النواقص
تعاد إلى تحت فحص الاكتمال ولا تقفز مباشرة إلى جدول الأعمال"; "موافق عليها من
اللجنة لا تنتقل مباشرة إلى التنفيذ").
**⚠ Blast radius:** the terminal-status list is duplicated in four places —
`WorkflowService::hasTerminalStatus()`, `CommitteeStatusService`'s own copy,
`RequestVisibility::apply()` and `ApprovalController::index()` — per Stage 64's
own finding. All four must move together, plus
`ReportMetricsService::COMPLETED_STATUSES`.
**Source:** [D] Art. 38, 31; Appendix 5; gap-analysis §4.

### Stage 70 — Unified numbering + the قيد point
**Goal:** every artifact carries the number [D] says it must, granted when [D]
says it is granted.
**Build:** add `decision_number` to `decisions` (Art. 89 requires رقم القرار
*inside* the محضر; Stage 51 recorded refusing to fabricate one) and a number to
`meeting_minutes`; adopt Appendix 15's scheme (`M-COM/YEAR/SERIAL`,
`PM-MTG/…`, `PM-MIN/…`, `PM-DEC/…`); and move the committee reference-number
grant from intake to the post-completeness قيد, since Art. 15 says handing the
request to the direct manager "لا يعد… قيدًا" and Art. 20 grants the number only
after completeness — Art. 38's status 06. The employee keeps an intake receipt.
**Source:** [D] Arts. 15, 20, 89, 99; Appendices 15, 74; النموذج 05.

### Stage 71 — Real operational durations + escalation routing
**Goal:** Stage 52's soft SLA uses [D]'s own figures instead of the [A] §12
substitute it adopted when the appendix was unavailable — which its own note
said to "replace outright rather than layering a second interpretation on top."
**Build:** reseed `target_days_min`/`max` from **Appendix 37**'s table; wire
**Appendix 38**'s escalation targets so the bucket does something — أصفر
notifies the current owner, أحمر escalates to مقرر اللجنة + مدير الموارد
البشرية, and حرج (delay touching a legal deadline or a statutory right)
escalates to رئيس اللجنة + السلطة المختصة. Today nothing acts on the bucket
beyond colouring it.
**Source:** [D] Appendices 37, 38, 71.

### Stage 72 — Real per-type document checklists
**Goal:** `RequestType.required_documents` reflects [D]'s own matrices, not
Stage 53's admitted "judgment-call starting checklist."
**Build:** re-derive from **Appendix 57**'s four groups (أساسية مشتركة /
خاصة / مشروطة / ناتجة عن دورة اللجنة), keeping only client-submittable items —
**Appendix 4**'s seven lists are the committee's *internal* file-completeness
checks and include internal actions (e.g. الرأي القانوني) an employee would
never attach.
**Source:** [D] Appendices 57, 4; Arts. 48–79's per-type chapters.

### Stage 73 — ⚠ Committee identity card + configurable quorum
**Goal:** stop inventing a quorum. `MeetingReadinessService.php:65` and
`MeetingMinutesCompiler.php:88` both hard-code `ceil(activeMembers/2)`, and the
file's own comment admits it was invented for lack of a spec — but **Appendix
64 forbids exactly that**: "ولا يجوز للدليل إنشاء نسبة نصاب أو أغلبية من تلقاء
نفسه."
**Build:** add Appendix 65's بطاقة تعريف اللجنة fields to `Committee` (قرار
التشكيل رقم/تاريخ، من له حق التصويت، النصاب اللازم، الأغلبية اللازمة، معالجة
تساوي الأصوات، قواعد توقيع المحضر، جهة اعتماد المحاضر، حالات التنحي) and make
readiness, the minutes compiler and `DecisionController`'s tally read them
instead of `ceil(n/2)` + plurality-with-tie-422.
**⚠ Needs a defined behaviour for meetings already held and minuted under the
old rule** before touching code.
**Source:** [D] Appendices 64, 65; Arts. 84, 87.

### Stage 74 — Structured decisions, deferrals and refusals
**Goal:** decisions, deferrals and refusals carry the structure [D] requires
rather than one free-text comment.
**Build:** Appendix 27's four decision parts (موضوع / وقائع / سند / منطوق);
seed **Appendix 59**'s seven official Arabic decision formulas as `Template`
rows, since Stage 35's template mechanism currently ships with nothing seeded
("entirely inert until an R08 admin creates one"); capture Art. 34's five
deferral fields (سبب التأجيل، المطلوب استكماله، الجهة المسؤولة، المستند أو
الإفادة المطلوبة، أي مدة مقررة) instead of a bare comment, per Appendix 29
("يمنع استخدام عبارة تأجيل للمراجعة دون بيان المطلوب"); and structure refusal
reasoning per Art. 91 and Appendix 28, which reject generic phrases such as
"لمصلحة العمل" or "لعدم الاستحقاق".
**Source:** [D] Arts. 34, 89, 90, 91; Appendices 27, 28, 29, 59.

### Stage 75 — Request closure record
**Goal:** ordinary requests get the closure record Stage 65 built for appeals
and explicitly declined to retrofit here ("the same gap exists for ordinary
requests, which this stage does not close").
**Build:** Art. 37's eight closure fields (تاريخ الإقفال، النتيجة النهائية،
رقم القرار النهائي، جهة الاعتماد، تاريخ التنفيذ، الجهة المنفذة، حالة الإشعار،
موقع حفظ الملف); Appendix 47's thirteen-point pre-closure audit; and Appendix
48's eight conditions under which closure must be refused. Mirror Stage 65's
appeal-closure shape rather than inventing a second one.
**Source:** [D] Art. 37; Appendices 47, 48; النموذج 18.

### Stage 76 — Execution proof
**Goal:** "تم التنفيذ" stops being a claim and becomes evidence — Appendix 70
is explicit: "لا يكفي أن تقول الجهة المنفذة (تم التنفيذ) بل يجب إرفاق دليل
التنفيذ."
**Build:** Art. 95's execution-file contents and النموذج 17's seven-point
tracking checklist (تم إصدار القرار الإداري / تحديث ملف الموظف / تحديث النظام /
إحالة الأثر المالي / إخطار التقسيم التنظيمي / إخطار الموظف / إرفاق مستند
التنفيذ), with a required evidence attachment before
`MeetingOutputService::complete()` will close the item. Per the Track K intro's
scope decision (1), the ملف الخدمة update is *evidenced* here, not modelled.
**Source:** [D] Arts. 95, 96, 97; Appendices 52, 70; النموذج 17.

### Stage 77 — Return from the approving body
**Goal:** [A] §5's "Path 3", flagged as having no equivalent since
gap-analysis §14.
**Build:** Appendix 34's شكلية/موضوعية split, honouring Art. 94's rule that
"فلا يعدل المحضر المعتمد بصورة غير رسمية، بل ينشأ إجراء إعادة معالجة يثبت سبب
الإعادة والإجراء الذي اتخذ بشأنها" — a formal re-processing action, never a
quiet edit of an approved محضر.
**Source:** [D] Art. 94; Appendix 34; [A] §5 Path 3.

### Stage 78 — The four mandatory control gates
**Goal:** Appendix 63 requires the system itself to block progression at four
points — قبل القيد / قبل جدول الأعمال / قبل الاعتماد / قبل الإقفال — "وتمنع
المنظومة الإلكترونية الانتقال إذا كانت متطلبات البوابة غير مكتملة". Stage 33
built gate 2 only.
**Build:** gates 1, 3 and 4; Art. 103's twelve-point قائمة فحص سلامة القرار
before execution; and Art. 105's rule that a material fact discovered to be
wrong **suspends execution immediately** and refers the matter back to legal
review — there is no suspend action today.
**Source:** [D] Arts. 103, 104, 105; Appendices 20, 63.

### Stage 79 — Art. 101's twelve notification moments
**Goal:** the employee is told at each of the twelve moments [D] enumerates.
**Build:** `NotificationSetting::EVENT_TYPES` has nine events, and status-only
moves fire **nothing** — `stage_changed` only ever fires from
`WorkflowService::transition()`, so إدراج الطلب بجدول الأعمال، بدء التنفيذ and
إقفال المعاملة are currently silent. Add the missing events, make
`CommitteeStatusService` and `MeetingOutputService` dispatch, and honour Art.
102's content limits — no مداولات, no per-member vote, no other employees' data.
**Source:** [D] Arts. 101, 102; النموذج 16.

### Stage 80 — The twelve official registers
**Goal:** Art. 98's twelve named registers exist.
**Build:** الواردة / الناقصة / الاجتماعات / جدول الأعمال / المحاضر / القرارات
والتوصيات / الإحالات للاعتماد / القرارات المعادة من جهة الاعتماد / التنفيذ /
التظلمات / المؤجلة / الإقفال والأرشفة — mostly filtered views and exports over
data that already exists, plus Art. 100's "المستند المرتبط" column on each
timeline entry.
**Source:** [D] Arts. 98, 99, 100; Appendices 11, 12.

### Stage 81 — The thirteen official KPIs
**Goal:** the dashboard measures what Art. 106 says to measure.
**Build:** [D]'s own thirteen indicators (متوسط مدة الفحص الأولي، نسبة الملفات
الناقصة، متوسط مدة استكمال النواقص، نسبة الملفات الجاهزة قبل الاجتماع، عدد
المعاملات بكل اجتماع، نسبة المعاملات المؤجلة، نسبة التأجيل بسبب نقص مستندات،
متوسط مدة الاعتماد، متوسط مدة التنفيذ بعد الاعتماد، نسبة القرارات المعادة من
جهة الاعتماد، عدد المعاملات المفتوحة المتأخرة، متوسط الدورة الكاملة), Art.
107's periodic report, and Appendix 10's ten early-warning conditions.
**Source:** [D] Arts. 106, 107; Appendices 10, 39, 40.

### Stage 82 — Agenda ordering and item fields
**Goal:** the agenda is ordered by rule, not by hand.
**Build:** Art. 83's mandated priority (المؤجلة من اجتماعات سابقة → المرتبطة
بمدد قانونية → العاجلة المعتمدة → المكتملة بحسب تاريخ جاهزيتها); Appendix 24's
per-item fields (الرأي القانوني، نوع القرار المطلوب، جهة الاعتماد المتوقعة، هل
سبق عرضه، رقم الاجتماع السابق) and its two priority levels; and Art. 85's
nine-step per-item sequence as the live runner's checklist (النموذج 11).
**Source:** [D] Arts. 82, 83, 85; Appendices 24, 25; النموذج 11.

### Stage 83 — Lifecycle edge cases
**Goal:** the situations [D] anticipates but the system currently cannot
represent.
**Build:** duplicate-transaction prevention (Appendix 16 — search by employee +
subject, attach to the open file rather than create a second); the mandatory
"المسؤول الحالي" and "الإجراء التالي" fields (Appendices 17, 18 — "يمنع وجود
معاملة بحالة عامة مثل (قيد الإجراء) دون معرفة ما المطلوب فعليًا"); urgent-flag
rules (Appendix 33 — عاجل only for five enumerated reasons, recorded);
material-error correction (Appendix 53); document-conflict handling (Appendix
30) and document-validity checks (Appendix 31); withdrawal before and after a
decision (Appendices 68, 69); and Appendix 60's six special cases (وفاة الموظف
أثناء نظر المعاملة، انتهاء الخدمة، النقل أثناء الدراسة، تغير التشريع أثناء
السير، فقدان مستند، اكتشاف مستند غير صحيح بعد القرار).
**Source:** [D] Appendices 16, 17, 18, 30, 31, 33, 53, 60, 68, 69.

---

## Suggested order

```
1 → 2 → 3 → 4 → 5        (foundation — do these in order)
6 → 7 → 8 → 9 → 10       (admin; 9 depends on 8)
11 → 12 → 13             (requests core)
14 → 15 → 16 → 17        (workflow engine — the critical path)
18 → 19 → 20 → 21        (approvals & committees)
22 → 23 → 24             (cross-cutting)
25 → 26 → 27             (the remaining seeded screens; 25 depends on 21 and 24)
28 → 29 → 30 → 31 → 32 → 33 → 34 → 35 → 36 → 37   (Track H — meetings unit; 28 & 29 first, all depend on 20–21)
38 → 39 → 40 → 41 → 42 → 43 → 44                  (Track I group 1–2 — housekeeping + design-fidelity; cheap, do first)
45 → 46 → 47 → 48 → 49 → 50 → 51 → 52 → 53 → 54 → 54b   (Track I group 3 — additive standard-alignment work)
55 → 56                                           (Track I group 4a — verification only, may need no code change)
57                                                (Track I group 4b — real conflict; needs its own migration design)
58 → 59 → 60 → 61 → 62 → 63 → 64 → 65 → 66        (Track J — appeal lifecycle; 58 & 59 first, 63 depends on 20–21/31, 64 needs its own design pass)
67                                                (Track K — the verbatim audit; everything below reads its matrix)
68 → 69 → 70                                      (Track K — the structural corrections; 69 needs 68's status 07, 70 needs 69's status set)
71 → 72 → 74                                      (Track K — reseeding with [D]'s real appendix data; independent of each other)
73                                                (Track K — quorum; needs a migration story for already-minuted meetings)
75 → 76 → 77 → 78                                 (Track K — closure, execution proof, approval-return, the four gates)
79 → 80 → 81 → 82 → 83                            (Track K — notifications, registers, KPIs, agenda rules, edge cases)
```

**Stages you can pull forward if you want a break from the hard parts:** 10, 12, 22, 74 (seeding
Appendix 59's seven decision formulas is self-contained and immediately useful).
**Stages not to rush:** 9, 14, 16, 18, 57, 64, 69, 73 — these are where correctness bugs hide (57, 64,
69 and 73 also carry real coupling/migration risk, per their own notes in Tracks I/J/K).

---

## When you're ready

Tell me the stage number and I'll build it. Useful things to mention when you do:
- Anything already deviating from the plan (different package choices, schema tweaks)
- Whether you want the code as files you can drop in, or explained step by step
