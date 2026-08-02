# Abu Saleem Transaction System — Staged Local Build Plan

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
- Migrations: `workflow_stages`, `workflow_transitions`, `transaction_statuses`, `transaction_types`, `screens`, `screen_role_permissions`
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

## TRACK C — Transactions Core

### Stage 11 — Transactions schema + list view
**Build:**
- Migrations: `transactions`, `transaction_stage_logs`, `transaction_status_history`, `attachments`, `notes`
- API: list endpoint with filters (status, department, type, date range) + pagination
- Vue: transactions list screen
**Done when:** Manually-inserted test rows appear in the list and filters work.

### Stage 12 — File upload component
**Build:** Reusable Vue upload component + Laravel storage endpoint. Validates PDF/DOC/DOCX/JPG/PNG, 20MB cap. Writes to `attachments`.
**Done when:** You can upload a valid file and get rejected on an invalid type/size.
**Independent:** can be built any time after Stage 5.

### Stage 13 — Request intake flow
**Goal:** The full "create a transaction" journey (Image 6).
**Build:**
- Reference number generator (`YYYY-DEPT-000123`), race-safe inside a DB transaction
- Intake form: basic data → attachments → validation → submit
- On submit: create transaction, assign reference number, set status `new` / stage 1
- Notes component on the transaction detail page
**Done when:** You complete the form and get a transaction with a unique reference number at stage 1.

---

## TRACK D — Workflow Engine (the core)

### Stage 14 — Transition map + WorkflowService (happy path)
**Goal:** The state machine, data-driven.
**Build:**
- Seed all happy-path rows in `workflow_transitions` (stage 1→2→3…→11, with action + required role)
- `WorkflowService::transition(transaction, action, actor)`: validates role, validates prerequisites, updates stage/status, writes `transaction_stage_logs` + `transaction_status_history`
- Unit tests for the happy path
**Done when:** A test walks a transaction from stage 1 to stage 11 in code, with correct log rows.

### Stage 15 — Transaction detail page + stage actions
**Build:**
- Vue: transaction detail view showing current stage, status, timeline, attachments, notes
- Approve / reject / forward buttons wired to the transition API, gated by role
**Done when:** You can move a transaction through several stages from the UI, logging in as the right role each time.

### Stage 16 — Exception flows
**Build:**
- Seed exception transitions: نقص مستندات (return for missing docs), رفض المراجعة, طلب تعديل, إلغاء المعاملة
- Return-to-previous-stage logic with mandatory comment/reason
- Vue: exception action buttons + reason modals
**Done when:** Each exception path works and is correctly recorded in the stage log.

### Stage 17 — SLA / deadlines
**Build:** `due_date` handling per transaction type, a scheduled command flagging overdue transactions, and the انتهاء المهلة escalation path.
**Done when:** An artificially back-dated transaction gets flagged as overdue by the scheduled command.

---

## TRACK E — Approvals & Committees

### Stage 18 — Approval chain
**Build:**
- `approvals` table wiring — records every action with level + role
- Sequential enforcement (can't skip a required approver)
- The conditional branch: ministry approval only above the decision-grade threshold
**Done when:** The full chain (reviewer → committee → admin manager → ministry/dean → final) runs correctly, and skipping a level is blocked.

### Stage 19 — E-signature
**Build:** Signature-pad Vue component → saved image → `signature_path` on the approval record; approval trail visual on the transaction detail page.
**Done when:** An approval captures a signature and it renders in the trail.

### Stage 20 — Committees & meetings
**Build:**
- Migrations + CRUD: `committees`, `committee_members`, `meetings`, `meeting_attendees`, `meeting_transactions`
- Agenda builder (add/remove/reorder transactions on a meeting), attendee management, minutes field
**Done when:** You can schedule a meeting, add transactions to its agenda, and mark attendance.

### Stage 21 — Decisions & voting → workflow integration
**Build:**
- `decisions` + `votes` tables, voting UI in the meeting screen, tally logic
- A recorded decision automatically triggers the right `WorkflowService::transition()` call
- Handle "defer" (stays in committee for the next meeting)
**Done when:** A committee vote moves the transaction to the next stage with no manual intervention.

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

## Suggested order

```
1 → 2 → 3 → 4 → 5        (foundation — do these in order)
6 → 7 → 8 → 9 → 10       (admin; 9 depends on 8)
11 → 12 → 13             (transactions core)
14 → 15 → 16 → 17        (workflow engine — the critical path)
18 → 19 → 20 → 21        (approvals & committees)
22 → 23 → 24             (cross-cutting)
25 → 26 → 27             (the remaining seeded screens; 25 depends on 21 and 24)
```

**Stages you can pull forward if you want a break from the hard parts:** 10, 12, 22.
**Stages not to rush:** 9, 14, 16, 18 — these are where correctness bugs hide.

---

## When you're ready

Tell me the stage number and I'll build it. Useful things to mention when you do:
- Anything already deviating from the plan (different package choices, schema tweaks)
- Whether you want the code as files you can drop in, or explained step by step
