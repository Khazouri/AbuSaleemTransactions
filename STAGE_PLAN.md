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

### Stage 58 — Appeal (تظلم) lifecycle
**Goal:** an employee can formally contest an already-decided matter, per
[D] Arts. 75–79's full specification — not just submit a Grievance-type
intake request through the ordinary pipeline.
**Build:** new `appeals` entity referencing a decided request/decision, its
own status machine, intake fields, a jurisdiction test that explicitly
excludes disciplinary-board matters ([D] Art. 77), legal review, a 5-outcome
result set, and non-reopening rules ([D] Art. 79). Comparable in size to all
of Track H — will need its own sub-staging when scheduled, not a single
stage.
**Source:** [D] Art. 75–79; [A] §9; [E]'s إعادة العرض/التظلم handling.

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
58                                                (Track I group 5 — appeal lifecycle; its own future sub-track)
```

**Stages you can pull forward if you want a break from the hard parts:** 10, 12, 22.
**Stages not to rush:** 9, 14, 16, 18, 57 — these are where correctness bugs hide (57 also carries real
migration risk for in-flight requests, per its own note in Track I).

---

## When you're ready

Tell me the stage number and I'll build it. Useful things to mention when you do:
- Anything already deviating from the plan (different package choices, schema tweaks)
- Whether you want the code as files you can drop in, or explained step by step
