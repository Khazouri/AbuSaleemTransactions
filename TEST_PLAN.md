# Test plan — Abu Saleem Transactions

A manual QA script for the whole system, organised **by feature**, covering Tracks A–O
(Stages 1–102) and what was built on top of them: the decision wizards, «المهام المعلقة»
(Pending Tasks), «متابعة طلباتي» (My Requests), forms in modals and the phone layout.

> Arabic version: [TEST_PLAN.ar.md](TEST_PLAN.ar.md) — same content, same checkbox count.
> Role-by-role version: [TEST_PLAN.roles.md](TEST_PLAN.roles.md) / [.ar.md](TEST_PLAN.roles.ar.md).

**How this differs from the role plan.** [TEST_PLAN.roles.md](TEST_PLAN.roles.md) walks the
system **one section per role** — it answers "what can R05 do, and what must R05 be refused?".
This file walks it **one section per subsystem** — it answers "does the appeal lifecycle work
end to end?". They are complements, not duplicates: use this one to prove a feature works, and
the role plan to prove the permission matrix is real. Neither supersedes the other.

**Why a manual pass at all.** The automated suite proves each stage in isolation against
in-memory sqlite. It does not prove the system works for the people it was built for, and
several whole classes of bug only appear against MySQL and a browser: Arabic round-tripping,
RTL layout, private-file streaming, queued notifications, and every rule whose enforcement is
split between a Vue guard and API middleware.

**How to use it.** Work top to bottom — later sections assume the request built in §5/§6 still
exists. Every step has a checkbox and an explicit expected result. Record failures in §17
rather than fixing them mid-pass; a half-fixed system invalidates the steps after it.

**How an action is taken — read this before §5.** No page carries loose action buttons. Every
write on a request, a meeting, an agenda item or an appeal goes through a **wizard** opened by
one button («اتخاذ القرار» / *Make a decision*, or «اتخاذ الإجراء» on a meeting or an item), and
the normal way to reach it is the **Pending Tasks** screen. Where a step below names a workflow
action (`forward`, `approve`, …) it means: open the wizard and choose that action. §4 tests the
wizard and the two personal screens themselves.

**Ground truth this plan is written against** (re-derive with the script in §17 if in doubt):

| | |
|---|---|
| Workflow stages | **12** (eight on the path today — 3, 6, 7 and 8 are no longer reached) |
| Roles | **12** (R01–R12) |
| Screens | **36** (31 with a page, plus 5 approval grant rows that have none) |
| Request statuses | **39** |
| Notification event types | **14** |
| Approval levels | **5** |
| Seeded test accounts | **16** |

---

## 1. Setup

### 1.1 Bring the system up

```bash
# Backend (repo root)
composer install
php artisan migrate            # must report nothing pending
php artisan db:seed            # reference data: roles, departments, stages, screens, matrix
php artisan db:seed --class=TestUserSeeder   # the sixteen accounts below

# Serve
composer dev                   # or: php artisan serve / Homestead at http://abusaleem.test

# Frontend (from frontend/)
npm install
npm run dev                    # Vite at http://localhost:5173
```

- [ ] `php artisan migrate:status` shows every migration `Ran`, nothing pending.
- [ ] `curl http://abusaleem.test/api/ping` returns `{"message":"pong", ...}`.
- [ ] The SPA loads at `http://localhost:5173` and redirects to `/login`.
- [ ] A queue worker is running (`php artisan queue:work`) — `QUEUE_CONNECTION=database`, so
      without one **no notification in §13 is ever delivered** and the failure looks like a bug
      in the dispatcher rather than a missing worker.

`TestUserSeeder` is **not** called from `DatabaseSeeder` — run it explicitly. That is
deliberate: a production `db:seed` must not mint known-password accounts. It is idempotent, so
re-running it is safe and will *reset* any role you changed by hand.

The dev server port is pinned (`strictPort: true`). If 5173 is taken, Vite **fails loudly**
rather than drifting to 5174 — which would hand the browser an origin `config/cors.php` does
not allow, making every call fail as "server unreachable" while the API is perfectly healthy.

- [ ] Stop the dev server, occupy 5173 with something else, run `npm run dev` again: it refuses
      to start with "Port 5173 is already in use" rather than silently choosing another port.

### 1.2 Resetting between passes

A full reset is `php artisan migrate:fresh --seed && php artisan db:seed --class=TestUserSeeder`.
This destroys all requests, committees, meetings, decisions, appeals, audit rows and backups.
For a partial reset, re-running `TestUserSeeder` alone restores the accounts and their roles
without touching request data.

> **Do not run `db:seed --class=ScreenRolePermissionSeeder` on an install whose permissions have
> been customised.** It resets the entire matrix to its defaults, discarding every grant an
> administrator changed through the Roles & Permissions screen.

### 1.3 Test accounts

All passwords are `password`. All have a `phone` set (needed for §13's SMS checks — note the
phone **cannot** be set through the Users screen, only by this seeder or by the user's own
notification-preferences screen).

| # | Email | Role(s) | Dept | Purpose |
|---|---|---|---|---|
| 1 | `r01.employee@abusaleem.test` | R01 | ENG | Submits. Manager is #2. |
| 2 | `r02.reviewer@abusaleem.test` | R02 | REP | المقرر — the قيد, scheduling, the agenda, minutes, archive, closure. Also #1's direct manager. |
| 3 | `r03.head@abusaleem.test` | R03 | CMT | Committee chair — adopts the agenda, convenes, records the decision, approves minutes. |
| 4 | `r04.member1@abusaleem.test` | R04 | CMT | Votes — holds the civil-service delegate seat in §9. |
| 5 | `r04.member2@abusaleem.test` | R04 | CMT | Holds no seat — proves the seat gate (§2.2). |
| 6 | `r04.member3@abusaleem.test` | R04 | CMT | Holds no seat. |
| 7 | `r05.manager@abusaleem.test` | R05 | ADM | Approval level 3; may file on another employee's behalf. |
| 8 | `r06.ministry@abusaleem.test` | R06 | ABS | Ministry — approval level 4, exports. |
| 9 | `r07.director@abusaleem.test` | R07 | ABS | Final approval — level 5. |
| 10 | `r08.sysadmin@abusaleem.test` | R08 | ADM | Admin. Second admin, so you can suspend one safely. |
| 11 | `multi.role@abusaleem.test` | R03+R04 | CMT | Proves `screenPermissions()` **unions** across roles. |
| 12 | `inactive.user@abusaleem.test` | R01 | FIN | `is_active = false` — must be refused at login *and* as an actor. |
| 13 | `r09.secretary@abusaleem.test` | R09 | CMT | A retained login with no duty (Stage 96). |
| 14 | `r10.diwan@abusaleem.test` | R10 | ABS | A retained login with no duty (Stage 96). |
| 15 | `r11.legal@abusaleem.test` | R11 | CMT | العضو القانوني — the only role that records a legal review; a voting seat. |
| 16 | `r12.hr@abusaleem.test` | R12 | HR | HR — prepares the employment file and registers at stage 4; a voting seat; records execution. |

- [ ] All sixteen appear in the Users screen as R08, and #12 shows as inactive.
- [ ] Signed in as #1, the Users screen is not in the sidebar and `/users` is refused.

### 1.4 The login-screen test-account picker

The picker is gated by the **backend**, not the build: `GET /api/dev/test-users` answers 404
unless `APP_ENV=local`. The same `dist/` therefore shows it against a local API and hides it
against a real one, and no known-password address is ever compiled into the bundle.

- [ ] With `APP_ENV=local`, the login screen shows the picker and one click signs you in.
- [ ] `curl http://abusaleem.test/api/dev/test-users` returns the sixteen accounts, and the
      payload contains **no password hash**.
- [ ] Set `APP_ENV=production`, clear the config cache, reload: the panel is gone and the
      endpoint 404s (not 403 — a 403 would confirm the path exists).

---

## 2. Permissions and navigation

`screen_role_permissions` is the single source of truth, read by **both** the API middleware
(`screen.permission:<code>,<action>`) and the Vue router guard. The property under test is that
they always agree.

### 2.1 Sidebar per role

Sign in as each account and count sidebar entries. `GET /api/screens` returns more screens than
the sidebar shows, for two reasons: `request_details` and `notes_attachments` carry `:id` in
their route, and the five **approval screens have no page at all** (their `route` is null — the
task inbox replaced the five queues, and the rows survive only as the grants that decide who
approves at each checkpoint). So **sidebar = API − 2 − 1 for an approval row the role holds**.

A third rule moves the numbers: seven of the Meetings Management screens are hidden from anyone
who holds **no committee seat** (§2.2). A fresh seed has no committee, so until §9.1 every
account except R08 shows the *no seat* column. The five seats are bound to R03, R11, R12, R04
and R02; no other role can hold one.

| Role | No seat: API / sidebar | With a seat: API / sidebar |
|---|---|---|
| R01 | 14 / 12 | — |
| R02 | 17 / 14 | 24 / 21 |
| R03 | 17 / 14 | 24 / 21 |
| R04 | 14 / 12 | 21 / 19 |
| R05 | 15 / 12 | — |
| R06 | 15 / 12 | — |
| R07 | 13 / 10 | — |
| R08 | **36** / 29 (exempt from the seat rule) | — |
| R09 | 12 / 10 | — |
| R10 | 12 / 10 | — |
| R11 | 13 / 11 | 20 / 18 |
| R12 | 13 / 11 | 20 / 18 |

- [ ] Each role's sidebar count matches the table, before and after its account is seated.
- [ ] Only R08 sees the admin block (Users, Departments, Request Types, Roles & Permissions,
      Settings, Templates, Backup, Maintenance).
- [ ] No account has an approval screen in its sidebar — R08 included — yet the five rows
      (`reviewer_approval` … `final_approval`) are listed on the Roles & Permissions grid.
- [ ] As R08 the **Meetings Management** group renders as a collapsible section containing nine
      screens; `decisions` sits **outside** it as a flat top-level entry.
- [ ] `multi.role@` sees the **union** of R03's and R04's screens — not the intersection. This
      is the only account that would notice that regression.

### 2.2 The committee-seat gate

- [ ] Before any committee exists, `r04.member1@` has **no** Meetings Management group, and
      `/meetings` typed in the address bar is blocked.
- [ ] Two screens of that group follow the role, not the seat: an unseated R02 or R03 still sees
      `المراجعة القانونية` and `المخرجات` (and nothing else of the group); an unseated R11 sees
      the first, an unseated R12 the second.
- [ ] After §9.1 seats them, the five seated accounts gain the other seven screens — and each
      sees only the meetings of the committee they sit on.
- [ ] `r04.member2@` (R04, no seat) still has no group: the gate reads the seat, not the role.
- [ ] R08 sees the group with no seat of its own — it is the role that builds the roster.

### 2.3 Guard and API agree

- [ ] As R01, type `/users` in the address bar: the router guard blocks navigation and you land
      somewhere sensible rather than on a blank screen.
- [ ] As R01, call `GET /api/users` with your bearer token: **403**, not 200 and not 404.
- [ ] As R08, open Roles & Permissions, revoke `requests → view` from R01, save. Sign in as
      R01: the Requests screen has left the sidebar and the API refuses it. Restore the grant.

### 2.4 `view`, `export` and `approve` are different privileges

- [ ] As R01, open Reports: it renders, and there is **no** export button.
- [ ] As R01, call `GET /api/reports/requests/export?format=xlsx`: **403**.
- [ ] As R06, the same call returns an `.xlsx` file.
- [ ] As R06, `GET /api/decisions/export` also succeeds (Stage 84 added this tier — it used to
      be R08-only, inconsistently with every other export).
- [ ] As R04, `GET /api/decisions/export`: **403**.

### 2.5 Deletion is R08-only

- [ ] As R05, the Departments screen is not reachable at all, and
      `DELETE /api/departments/{id}` returns 403.
- [ ] As R08, deleting a department that still has children or users is **refused** with a
      reason — master data is deactivated (`toggle-active`), never erased.

---

## 3. Authentication and session

- [ ] Correct credentials return a token; the SPA stores it and lands on the dashboard.
- [ ] Wrong password returns a generic failure that does **not** reveal whether the email
      exists.
- [ ] `inactive.user@` is refused with its own distinct message.
- [ ] Seven rapid login attempts hit the `throttle:6,1` limit and return **429**. Wait a minute
      before continuing — this is the single most common cause of a confusing "empty token"
      during a manual pass.
- [ ] Reloading the page keeps you signed in (token in `localStorage`).
- [ ] Revoke the token server-side, then act in the SPA: the 401 interceptor logs you out and
      returns you to `/login` rather than leaving a broken screen.
- [ ] Sign out: the token is gone and the back button does not restore a signed-in view.

---

## 4. Pending Tasks, My Requests and the wizard

Three surfaces every later section relies on. Come back to this section after §6 if a check
needs a file that does not exist yet.

### 4.1 «المهام المعلقة» — Pending Tasks (`/my-tasks`)

- [ ] The screen is in **every** account's sidebar, R09 and R10 included. With nothing waiting
      it says so ("Nothing is waiting on you right now") rather than showing empty groups.
- [ ] File a request as #1 (§5). As #2 — #1's manager — it is listed under **Requests awaiting
      your action** with the action named «Approve & forward» and how long it has waited. As R05
      it is not listed.
- [ ] Clicking the task opens the request page **with the wizard already open**
      (`/requests/{id}?decide=1`).
- [ ] After #2 acts, the task leaves #2's list and appears in R12's as «Prepare employment file
      & register». The inbox follows the file; nobody refreshes a queue by hand.
- [ ] A group heading appears only when it holds a task, and a task appears only for someone
      who holds the grant to perform it.
- [ ] A task past its date is flagged **Overdue**, and the header's overdue count includes it.
- [ ] The screen lists and never acts: a task row has no approve or reject control of its own.
- [ ] `GET /api/my-tasks` with no token → 401; with R09's token → 200 and no tasks.

### 4.2 «متابعة طلباتي» — My Requests (`/my-requests`)

- [ ] The screen is in the sidebar for R01–R06 and absent for R07, R09, R10, R11 and R12 — the
      roles that cannot file. As R07, `/my-requests` is blocked and `GET /api/my-requests` → 403.
- [ ] As #1 it lists the request just filed with its current step, who has it now, the next
      action, and when the current step is expected to finish.
- [ ] Search by the receipt number (`PM-RCV/…`), the reference number or the subject narrows
      the list; the scope switch offers In progress · Concluded · All.
- [ ] «Show progress» expands the request's timeline and the notices sent to you about it.
- [ ] Another employee's request is not listed, and `GET /api/my-requests/{that id}` → 404.
- [ ] A request #2 filed **on #1's behalf** appears in #1's list — the screen follows صاحب
      العلاقة, not only the person who typed it in.

### 4.3 The wizard

- [ ] The request page carries one button, «اتخاذ القرار» (*Make a decision*), shown **only**
      when you have something to do on that file now. Its five tabs — File, Control gates,
      Approvals, Committee & legal, Outputs — are read-only: none holds an edit or submit control.
- [ ] The wizard runs Review → Checks → Choose an action → Confirm. **Checks** appears only when
      a check is yours to record at this point; each check saves with its own button.
- [ ] **Choose** lists every action open to you with where the file goes next ("The file moves
      to … with status …").
- [ ] An action that is yours but blocked is listed under **Not available yet** with its reason.
      Make the same call through the API: it is refused with **the same sentence**.
- [ ] An action you hold no grant for is not listed at all — not greyed out, absent.
- [ ] **Confirm** renders the act as a paper slip (a تأشيرة on a request). An action that
      requires a reason cannot be submitted until one is typed; the others take an optional note.
- [ ] Acts are grouped under four headings: This stage's decision · After the decision · The
      file's records · The file's content.
- [ ] Open the wizard on Confirm in one session, perform the same act in a second session, then
      submit in the first: the refusal is shown **inside the wizard**, which stays open, and no
      duplicate is recorded.

---

## 5. Intake, numbering and the قيد point

This is where [D] Art. 15's rule bites: handing a request to the direct manager **is not a
registration**. The employee gets a *receipt*; the reference number is granted later.

- [ ] As R01, open Request Intake («إرسال الطلب», `/requests/create`). The form has two steps:
      Details & documents, then Review & submit.
- [ ] Pick a type — the **required-documents checklist** appears, split into "الأساسية
      المشتركة" and "الخاصة بالنوع", with each conditional item's condition in brackets and the
      mandatory ones marked (required). The employment-record documents are **not** asked of
      the submitter — HR supplies them at stage 4.
- [ ] Attach a PDF and a PNG; each file must say **which listed document it is** (or "other
      document"). There is no default — an unclassified upload is refused.
- [ ] Try to submit with a (required) document missing: refused, naming what to attach.
- [ ] Leave the page half-way and come back: the request is under **Unsent drafts**, «Resume»
      restores the fields and the attachments, «Discard draft» removes it.
- [ ] Submit. The success screen shows an **intake receipt** of the form `PM-RCV/2026/000001`
      and states plainly that this is a receipt, not a قيد.
- [ ] The new request's `reference_number` is **null**, and its stage is
      `direct_manager_review` — intake auto-hops there in the same transaction.
- [ ] As R01 the form has no «صاحب العلاقة» picker. As R02 or R05 it does, defaulting to "This
      request is about me"; as R01, posting someone else's `subject_user_id` is refused (422).
- [ ] Try to file a **second** request of the same type for the same employee: refused, naming
      the open file to attach the documents to instead (Appendix 16's non-duplication rule).
- [ ] File a request of a *different* type: accepted. That is what the rule actually permits.
- [ ] After submission **only the filer attaches, and only while the file is at stage 1**: as R01
      «Attach a document» is not available while the file sits with the manager, and as R02 the
      upload endpoint on R01's request is refused.

---

## 6. The golden path — twelve stages, eight on the path

Walk one request end to end. The point is that **no single account can do it** — needing R08 to
get past any step is itself a defect. Each actor finds the file in Pending Tasks.

| # | Stage | Actor | Action |
|---|---|---|---|
| 1 | `receive_from_municipality` | the filer | `submit` (automatic at intake) |
| 2 | `direct_manager_review` | #2 (the subject's own manager) | document-validity check, then «Approve & forward» (`forward`) — lands on stage 4 |
| 3 | `administrative_routing` | — | off the path: the one-click forward skips it |
| 4 | `receive_and_register` | R12 (HR) | prepare the employment file, then «Register receipt» (`register`) |
| 5 | `requirements_check` | R02 | jurisdiction test and gate 1, then `approve` — **this is the قيد** |
| 6–8 | `reviewer_review` · `observations` · `forward_to_committee` | — | off the path since Stage 102: the `approve` at stage 5 lands on stage 9, the committee's pending list |
| 9 | `receive_from_committee` | the committee (§9); R03 records | vote → record the result |
| 10 | `approval_by_authority` | R05 | `approve` |
| 11 | `local_governance_ministry` | R06 | `approve` |
| 12 | `final_approval_archiving` | R07 | `approve` → `in_execution` |

- [ ] Step 2 works as **#2 specifically** (R01's assigned manager), not merely as "some R02".
- [ ] An account that is not this employee's manager — R05, or R08 while that manager is live —
      is offered nothing at step 2, and the transition endpoint refuses it.
- [ ] Step 2: «Approve & forward» sits under **Not available yet** until every attachment has
      been checked (§7). Once it is available, **one click** lands the file on stage 4 with
      status `routed_to_hr` — stage 3 never appears in the timeline.
- [ ] Step 4: «Register receipt» is unavailable until the employment file is prepared (§7).
      After it the status is `in_review`, *not* `registered`, and there is still no reference
      number.
- [ ] R05 cannot open a file it did not file while it is at stage 4 (**404**) — it holds no rule
      there.
- [ ] Step 5: `approve` is listed under Not available yet before the Art. 45 jurisdiction test
      is recorded, and the endpoint refuses it.
- [ ] Record the jurisdiction test (all six questions) and gate 1 (§7), then `approve`.
      **Now** the reference number is minted — `PM-COM/2026/0001` — the status becomes
      `registered`, and the file is on the committee's pending list at stage 9.
- [ ] Send the file back with `return_missing_docs`, then walk it forward through this same hop
      again: the reference number is **unchanged**. One number for life (Art. 99).
- [ ] Each hop writes both a stage-log row and a status-history row; the timeline on the request
      screen shows الجهة and any linked document per entry.
- [ ] Steps 5, 9, 10, 11 and 12 each write an `Approval` ledger row — five levels, one per
      checkpoint — visible on the Approvals tab with who, in which role, when, and their note.
- [ ] **There is no drawn signature anywhere.** An approval is a confirmed click, and the click
      is the record; no signature pad appears in any wizard.
- [ ] Skipping a level is impossible: as R07, open the request while it is still at stage 10 —
      no `approve` is offered, and `POST /api/approvals/final/{id}` is refused.
- [ ] Neither the filer nor صاحب العلاقة may approve or attest. File a request as #2 on #1's
      behalf: at stage 5, #2 is refused both gate 1 and `approve`.
- [ ] R08 holds **no** approval role: as R08 no `approve` is offered at any level. An admin who
      must approve needs the role added on the Users screen.

### 6.1 The ministry-bypass branch

- [ ] File a request whose type has a `decision_grade_threshold` above its grade. At stage 10,
      approving sends it **straight to stage 12**, skipping the ministry.
- [ ] Its status on arrival is `final_approved`, not `approved` — the bypass path stamps the
      correct terminal-ish status, which is the bug Stage 57 had to fix explicitly.
- [ ] A request at or above the threshold visits stage 11 first.

---

## 7. The control gates

Appendix 63 requires the system itself to block progression at four points. Two owner checks
come before the first of them and block the same way. All are recorded in the wizard's
**Checks** step and shown afterwards on the request's Control gates and Outputs tabs.

**Before gate 1 — document validity (the manager, stage 2).**

- [ ] Every attachment gets nine checks. Only two may be answered غير منطبق (the seal, and
      copy-matches-original); waiving any other is refused by name. The result per document is
      computed: سليم or محل شك.
- [ ] `forward` is refused while any attachment is unchecked or محل شك. A file with **no**
      attachments forwards freely.
- [ ] Only the subject's own live manager records it: as R03, or as R02 on a file whose subject
      they do not manage, the endpoint answers **403**.

**Before gate 1 — the employment file (HR, stage 4).**

- [ ] One answer per employment-record document the type's matrix requires — مرفق / لا ينطبق /
      ناقص — plus the attestation. لا ينطبق is accepted only for a conditional document; a ناقص
      blocks `register`. A document already attached is derived and not asked.
- [ ] R12 may upload an employment-record document at this stage — and nothing else.
- [ ] Neither the filer nor صاحب العلاقة may record it on their own file.

**Gate 1 — before the قيد.** At `requirements_check`:

- [ ] Every seeded required document must be answered. An unanswered one blocks `approve`.
- [ ] A document the source marks conditional ("بحسب الموضوع") may be answered **لا ينطبق**.
- [ ] An unconditional one may **not** — waiving it is refused, naming the document.
- [ ] The Appendix 20 facts attestation is required.
- [ ] **As R01, on your own request:** recording the jurisdiction test or gate 1 is
      **refused** (Stage 84 — Appendix 19's separation rule). The employee cannot attest to the
      completeness of their own file.
- [ ] Even an R02 who *created* the request on someone's behalf is refused, with the Appendix 19
      message. Send a **valid** payload when testing this — an invalid one fails validation
      first and you will wrongly conclude the block is missing.
- [ ] The Control gates tab displays **who recorded each half and when**.

**Gate 2 — before the agenda.** Covered in §9.3.

**Gate 3 — before the minutes go for approval.** Covered in §9.6.

**Gate 4 — before closure.** Covered in §11.3.

---

## 8. Exceptions, SLA and escalation

- [ ] `return_to_employee`, `return_missing_docs`, `reject_formally`, `declare_no_jurisdiction`
      and `cancel` each **require a reason**; the Confirm step will not submit without one, and
      the reason lands in the timeline.
- [ ] In the Choose step, exception actions sit under **Other outcomes**, not beside the normal
      action as equal-weight alternatives.
- [ ] A returned request appears in **its filer's** Pending Tasks under «My requests needing
      completion». The filer attaches what is missing, then «Submit request»; no other R01 can
      see the file (**404**) or is notified of it.
- [ ] A cancelled or archived request accepts no further workflow action.
- [ ] Set a request's `due_date` into the past, run `php artisan requests:flag-overdue`: it is
      flagged **once**. Run it again — nothing changes, and no second notification fires.
- [ ] The flagged file appears in **R08's** Pending Tasks under «Overdue requests awaiting
      escalation», with the action «Escalate — deadline expired» and a mandatory reason.
- [ ] The requests list shows a per-stage timeliness dot. Its four levels are Appendix 38's:
      **أخضر** within the allowance, **أصفر** *approaching* (still inside it), **أحمر** past it,
      **حرج** only when a legal deadline is also recorded.
- [ ] Run `php artisan requests:escalate-delays` on a file sitting past its stage target: the
      **current owner** is notified. Push it further: R02 + R05. Record a `legal_deadline` on it
      and re-run: it becomes حرج and reaches R03 + R07 — while still telling the earlier rungs.
- [ ] Re-run immediately: the same rung is **not** announced twice.
- [ ] Move the request to another stage: the ladder resets and the new stage can escalate again.

---

## 9. Committee and meetings

The path is single and fixed (Stage 102): R02's `approve` at stage 5 puts a file on the
committee's pending list; R02 schedules one meeting a month from that list; the five seats
accept the date; the sitting deliberates and votes. Three wizards carry every write — the
request wizard (completion, legal review, defer, return to study), the **meeting wizard**
(«اتخاذ الإجراء» on the meeting-actions card every meeting screen shows) and the **item
wizard** (the vote and the recorded result).

### 9.1 Committees

- [ ] As R08, open Meetings and create a committee — the form opens **in a modal** — then fill
      its five seats, each with the holder of its bound role: chair R03 · legal R11 ·
      hr_director R12 · ministry_delegate R04 (#4) · rapporteur R02. A seat cannot be filled
      twice.
- [ ] Seating a user who does not hold the seat's role is refused (try #4 in the legal seat).
- [ ] A committee with no recorded quorum rule shows **غير مثبت**, not a number. The system
      refuses to invent one (Appendix 64 forbids it).
- [ ] The quorum counts **every active member on the roster — the creator's seatless row
      included**. With R08's own row still there (six members), "أكثر من نصف الأعضاء" requires
      **4** and "لا يقل عن نصف" requires **3**: the comparator is what distinguishes them, which
      proves the invented `ceil(n/2)` is gone rather than relabelled. Remove R08's row before
      going on — five members, quorum 3.
- [ ] A committee with an empty seat cannot schedule a meeting — refused, naming the reason.
- [ ] Deleting a committee that has meetings is refused.

### 9.2 The pending list and legal review

- [ ] The Committee Candidates screen («الطلبات المرشحة») **is** the committee's pending list:
      every file R02 approved at stage 5, with waiting time, file completeness and proposed
      meeting. It is a list only — «Open file» on a row opens the request wizard.
- [ ] As R02, choose «Send for legal review». The status becomes `under_legal_review`; its
      **stage does not move**; the file appears in R11's Pending Tasks under «Awaiting legal
      review».
- [ ] As R11, the file appears in the legal-review queue and opens successfully — R11 holds no
      workflow transition, so this proves the visibility widening works.
- [ ] As R11, fill the legal-basis card in Checks and pick a verdict under «Your legal opinion».
      `sound_ready` and `present_with_note` permit the agenda; the other three block and return
      the file. A blocking verdict requires a note.
- [ ] As R02, the verdicts are not offered and recording one is **403**. As R11, «Send for
      legal review» is not offered and dispatching is **403**. The two write tiers are not
      interchangeable.
- [ ] As R03, «Return to study» on a pending-list file sends it back to stage 5 with a reason;
      R02's fresh `approve` returns it to the list. As R02 the same act is refused.

### 9.3 Agenda (gate 2)

- [ ] The pending list is the agenda's **only** source: «Add item» opens a search over it, and
      posting any other request to the agenda is refused.
- [ ] Adding an un-reviewed request is **refused**.
- [ ] A request with an unresolved document conflict is refused, in the appendix's own words.
- [ ] A request whose mandatory document is still missing is refused — completeness is read
      from the real attachments here, not from an earlier attestation.
- [ ] Items reorder by **drag and drop**, and the order persists.
- [ ] The ordering panel computes Art. 83's rank (deferred → legal deadline → urgent → complete
      by readiness date → uncategorised). "Apply order" rewrites the agenda.
- [ ] Depart from the computed order without a justification: readiness reports
      `agenda_order_departs_from_rule` and the meeting is **not ready** until you record one.
- [ ] Priority has exactly **two** levels (عالية / عادية). A declared عالية requires both a
      ground from Appendix 33's five and a written مبرر.
- [ ] There are only two kinds of item — an employee request and an appeal. No free-text
      administrative or emerging item can be added.
- [ ] As R03, «Adopt the agenda» in the meeting wizard: refused on an empty agenda, and one-shot.
      Before it no item can be advanced or studied; after it adding, removing and reordering
      are all refused, while an item's priority and time stay editable.
- [ ] As R04, try to add an agenda item: **403** (Stage 84 — Art. 13 gives members study,
      discussion and voting, not authorship). As R02, add one: succeeds.

### 9.4 Scheduling, the date, readiness and convening

- [ ] As R02, «Schedule meeting» runs a five-step modal (requests from the pending list →
      details → members → review → approve). The meeting number is minted (`PM-MTG/2026/01`),
      the five seats are invited automatically and nobody else can be added.
- [ ] The new meeting is `pending_confirmation`, and the chosen requests have left the pending
      list.
- [ ] A second meeting for the same committee in the same calendar month is refused (a
      cancelled one does not count).
- [ ] As R02, schedule for a committee you **do not sit on**: refused with
      "لا يجوز جدولة اجتماع للجنة لست عضوًا فيها." As R03 or R04, scheduling is **403** —
      `meetings,add` is R02's alone.
- [ ] Each of the other four members finds the meeting in Pending Tasks under «Meeting dates
      awaiting your answer» and answers **for themselves** in the meeting wizard («Accept the
      date» / «Decline the date»). Nobody can record another member's answer.
- [ ] The meeting becomes `scheduled` only when the last member accepts. R02 is notified of
      every answer, and the last acceptance says the meeting is confirmed.
- [ ] One decline keeps it `pending_confirmation`. R02 changes the date: **every** answer resets
      and the members are asked again.
- [ ] The readiness screen shows four percentages, the quorum, and an **exceptions-only** list.
      A meeting with an incomplete file or an unanswered invitation reports the matching
      exception and is **not ready**.
- [ ] As R03, «Convene the meeting» in the meeting wizard: a ready meeting needs no reason; a
      **not**-ready one requires a written override, and the submit button stays disabled until
      it is typed. A `pending_confirmation` meeting cannot be convened at all.
- [ ] Convening freezes the voting rules onto the meeting. Edit the committee's quorum
      afterwards: the held meeting still reports the rules it was convened under.

### 9.5 Running the meeting, voting and decisions

- [ ] The live runner shows the current item, a timer, the attendee list and a discussion feed.
      Before the meeting is convened **and** its agenda adopted it refuses to advance an item
      or tick a study step.
- [ ] Art. 85's nine-step study card blocks out-of-order ticks — marking a later step before its
      predecessor is refused, naming the missing step.
- [ ] Two steps are **derived, not tickable**: التصويت and إثبات النتيجة.
- [ ] عرض الرأي القانوني is غير منطبق on an item with no legal opinion.
- [ ] **Voting is refused until the sequence completes** — and at the same moment the item is
      absent from the member's «Awaiting your vote» group and from the Decisions screen's
      «بانتظار تصويتي» tab. The three must agree.
- [ ] Complete the sequence: the item appears in both worklists, each row opens the **item
      wizard**, and the vote is cast from its Confirm step as a ballot slip.
- [ ] The item wizard's Review step shows the five seats and each one's state (voted, absent,
      conflict declared).
- [ ] A non-member cannot see the meeting at all. A member marked absent cannot vote. A member
      who declares a conflict of interest — in the wizard's Checks step — cannot vote **or**
      post to the discussion feed.
- [ ] Once a vote exists, regenerating the presentation memo for that item is refused
      (Appendix 25's material freeze).
- [ ] As R03, «Record the result» in the item wizard shows the outcome the votes produce before
      you record it. A 2-1 plurality records. A **tie** is refused rather than guessed, and so
      is a zero-vote tally — both listed under Not available yet with the reason.
- [ ] Let a member vote while R03's wizard is open on Confirm: the stale outcome is refused
      («تغيّرت نتيجة التصويت…») and the slip refreshes to the true one.
- [ ] Every decision requires Appendix 27's four parts (موضوع / وقائع / سند / منطوق) and Art.
      90's instrument (قرار / توصية / رأي).
- [ ] A منطوق of only "اتخاذ اللازم" is refused; "اتخاذ اللازم نحو إحالة الملف … خلال أسبوع"
      is accepted. The rule tests **insufficiency**, not prohibition.
- [ ] A تأجيل requires Art. 34's four mandatory fields; a bare "تأجيل للمراجعة" is refused. The
      deferred file returns to the pending list for a later meeting.
- [ ] A رفض requires a reason **code** plus specifics — bare "لعدم الاستحقاق" is refused.
- [ ] As R02 (المقرر), casting a vote is refused (Art. 16), and so is recording the result —
      `decisions,approve` is R03's alone.

### 9.6 Minutes (gate 3)

Every step of the minutes runs from the meeting wizard; the Minutes screen reads the document.

- [ ] «Prepare the minutes» is offered only once the meeting is convened and **every item has
      an outcome**. The draft compiles attendance, the applied quorum rule, and per-item votes
      and decisions, and is numbered `PM-MIN/2026/01`.
- [ ] **Change the agenda after generating, then try to approve:** refused. This is gate 3's
      real value — an approved محضر describing a sitting that did not happen.
- [ ] As R03, «Approve the minutes» stays disabled until the reviewer's statement on the Checks
      step is ticked; approval then records Appendix 8's sixteen checks.
- [ ] A meeting with **no recorded attendance** cannot have its minutes approved — it proves
      neither حضور nor صحة انعقاد.
- [ ] Instead of approving, R03 may «Send the minutes back» with a reason. Only then is
      «Prepare the minutes again» offered — a fresh, unreviewed draft is not regenerated.
- [ ] As R11, a legal note can be recorded on the **draft** and is refused once it is under
      signature. It never blocks approval.
- [ ] Approval creates one signature row per attendee marked present, and each finds the
      minutes in Pending Tasks under «Minutes awaiting your signature». Signing is a confirmed
      click — no drawing. The last signature flips the document to `approved` automatically.
- [ ] A non-signer cannot sign, and nobody can sign twice.
- [ ] «Close the meeting» is refused until the minutes are approved and every item has an
      outcome. As R02, approving minutes: **403** — that is the chair's act (Art. 12).
- [ ] A closed meeting is **read-only**: attendance, agenda, votes, decisions and minutes all
      refuse any further write (422).
- [ ] On the meeting page the status control offers only **cancel**; «completed» is never
      picked by hand — it is what closing produces.

---

## 10. Appeals (تظلم)

The Appeals screen is a list. Filing runs through the **appeal filing wizard**; every later
act through the **appeal wizard**, opened by «اتخاذ القرار» on the appeal's row.

- [ ] As R01, file an appeal through the four-step filing wizard (request → grounds → confirm →
      documents) against a **decided** request you own. Appeals against an undecided request,
      or someone else's, are refused.
- [ ] A second appeal on the same file requires a **new-facts declaration**.
- [ ] Attach a document in the wizard's last step — it lives on the appeal, not on the original
      request.
- [ ] The appellant never sees «اتخاذ القرار» on their own appeal, and
      `GET /api/appeals/{id}/acts` returns nothing for them.
- [ ] As R02, run formal verification: a pass advances it; a fail requires a reason and closes
      it as `rejected`.
- [ ] The appellant cannot verify their own appeal.
- [ ] Record the jurisdiction test: a "committee" answer advances to `file_assembly`; any of the
      four non-committee answers terminates it as `outside_jurisdiction`.
- [ ] Record the Art. 75 legal review — all five questions are required together.
- [ ] Open the assembled **file** («View original file»): it shows the original request, its
      presentation memo, the minutes excerpt, the decision, the appeal's own documents, and the
      in-app notification evidence. Email/SMS honestly report *no evidence available* rather
      than claiming delivery.
- [ ] «Present to the committee» in the appeal wizard puts the appeal on an open agenda — only
      once it has reached `legal_review`, and only for R02 or R03 seated on that committee. The
      agenda builder itself no longer offers it.
- [ ] Decide it with one of the five appeal outcomes. Every outcome requires a comment.
- [ ] Execute the outcome: `appeal_accept` and `appeal_partial_accept` make the **original**
      request terminal; `appeal_reject` leaves it untouched; `appeal_refer` is non-terminal;
      `appeal_redo` reopens the original at a stage you name.
- [ ] Close the appeal: it records the closure card and notifies the appellant.
- [ ] **The original request cannot be closed while an appeal against it is open.**
- [ ] Reopen a closed appeal: only for one of the six enumerated reasons. A plain "I disagree"
      is refused.

---

## 11. Execution, archive, closure, suspension, reopen

All of it is recorded from the request wizard under **After the decision**, and each step shows
up for its owner in Pending Tasks under the group of the same name. On the Outputs screen the
execute and close cells of a row are links that open the wizard on that act.

### 11.1 Execution proof

- [ ] Claiming execution with **no evidence attachment** is refused: "لا يكفي أن تقول الجهة
      المنفذة (تم التنفيذ)؛ يجب إرفاق دليل التنفيذ."
- [ ] Whoever records execution — R02, R03 or **R12** — may upload the evidence while the file
      is `in_execution`, and at no other time.
- [ ] Nominate an attachment belonging to a **different** request: refused.
- [ ] النموذج 17's six executor checks: a single "لا" refuses, quoting the failed question back.
- [ ] On a request flagged as carrying a financial effect, the referral check must be "نعم" —
      `not_applicable` is refused (Art. 97).
- [ ] The seventh check (هل تم إرفاق مستند التنفيذ؟) is **derived** — spoof it as "لا" in the
      payload and it comes back "نعم" from real state.
- [ ] Art. 103's twelve-point soundness checklist is required before `in_execution`. Eight of its
      twelve are derived: spoof `minutes_signed: "no"` and it is overwritten from real state.

### 11.2 The archive — two files, two owners

- [ ] «Archive the committee file» is R02's (or R03's); «Archive the service file» is **R12's
      alone**. Each records where the file is kept, who archived it and when.
- [ ] Either can be recorded, and corrected, only while the file is on a closable status and
      not yet closed.
- [ ] The service file is required **only when a committee decision exists**: a pre-committee
      عدم اختصاص closes with the committee file alone.
- [ ] As R02, archiving the service file is refused; as R12, archiving the committee file is
      refused.

### 11.3 Closure (gate 4)

- [ ] All three of Art. 37's closable paths work: executed, not-approved, and outside-jurisdiction.
- [ ] Appendix 48's conditions each refuse **by name** — and each is shown in the wizard under
      Not available yet: a deferred file says "لا يجوز إقفال معاملة مؤجلة.", a returned one says
      so in its own words, an un-archived one names the missing archive, and so on.
- [ ] Appendix 47's twelve-point audit: a single "لا" refuses, quoting the question.
- [ ] Three of the twelve are **server-derived**, not asked — the appeal-path check, the archive
      location, and the execution document.
- [ ] `final_result_code` and `notice_status` are computed: spoof both in the payload and the
      real values come back.
- [ ] A closed file is not offered closure again. What stays open on it: a correction memo, a
      note, the financial-impact flag and reopening.
- [ ] As R04, closing: **403**. As R02, R03 or R12: succeeds.

### 11.4 Suspension and reopen

- [ ] Suspend a file under Art. 105: the status becomes `execution_suspended`, the **stage does
      not move**, and no stage-log row is written.
- [ ] While suspended, `approve` is refused through **both** the transition endpoint and the
      approvals endpoint, and **no `Approval` row is written** by the refused attempt.
- [ ] Lifting is refused until a legal review recorded *after* the suspension exists.
- [ ] The suspended file appears in R11's legal-review queue.
- [ ] Lift it: `fact_confirmed` restores the frozen status; `referred_to_committee` sends it back
      to `receive_from_committee`.
- [ ] Reopen a concluded request for one of the six enumerated reasons: it moves to the stage you
      name, and the **employment file, gate 1, the jurisdiction test, the soundness record and
      both archive records are all cleared** so the new lap cannot pass on the previous lap's
      answers.
- [ ] The approval-return register is **not** cleared — earlier rounds stay readable.

---

## 12. Registers, reports and KPIs

- [ ] The Registers screen lists Art. 98's **twelve** registers in order, each with its own
      columns.
- [ ] Register 2 (الناقصة) keeps a row for a file whose shortfall has since been **cleared** —
      a register of نواقص whose rows vanish when fixed is a worklist, not a register. The row
      reads `still_incomplete: لا`.
- [ ] Register 6 carries Appendix 12's **thirteen** columns.
- [ ] Register 7 names its own request (reference and subject are populated, not blank).
- [ ] Register 11 carries Art. 34's five deferral fields.
- [ ] Register 12 prints both archive locations.
- [ ] An unknown register code returns 404.
- [ ] **Rows, not screens:** as R06 or R07 (the export holders) a register lists every file; as
      R01 the same register lists only the files R01 may open.
- [ ] Reports: the KPI tiles, the breakdown bars and the twelve-month trend all render, and the
      filters narrow them. On the dashboard every bar's label shows its full name — none is
      cut off with an ellipsis.
- [ ] The Art. 106 tab returns **twelve** indicators, in the article's own order, each with its
      stated purpose.
- [ ] An indicator with nothing to measure reports **null**, not a confident zero.
- [ ] The per-request time card (T1–T10) matches the municipality-wide averages segment for
      segment — the same derivation feeds both.
- [ ] A segment whose endpoints have not both happened reports null, not zero.
- [ ] The meetings dashboard shows Appendix 11's eleven buckets; the "overdue" bucket
      deliberately **cross-cuts** the others, and the payload says so.
- [ ] Appendix 10's alerts each name **the party the file is waiting on**, not just a day count.
- [ ] Export a register and a report as both `xlsx` and `pdf`. Open the PDF: **Arabic is shaped
      and joined correctly**, not rendered as disconnected left-to-right letterforms.

---

## 13. Notifications

Requires a running queue worker (§1.1). A notification informs; a pending task obliges — the
task stays in the inbox until it is done, whether or not the notification was read.

- [ ] `NotificationSetting::EVENT_TYPES` offers **fourteen** event types on the preferences
      screen, each with in-app / email / SMS toggles.
- [ ] Advancing a request notifies its filer, صاحب العلاقة **and** the next actor, on their
      enabled channels only.
- [ ] Mute an event entirely: nothing is delivered on any channel.
- [ ] Clear a user's phone: the SMS channel is dropped rather than queued undeliverably.
- [ ] The bell badge updates within a minute and the dropdown lists unread items.
- [ ] **Art. 101:** the employee is notified at each mapped moment. The same `registered` status
      reads as moment 1 the first time and moment 3 after a نواقص loop.
- [ ] At the قيد the employee receives `reference_assigned`, naming the superseded receipt and
      the new reference number.
- [ ] Each Art. 101 notice also sends `request_notice_copy` to the subject's manager and to HR —
      in-app only, naming the moment and the number, **never the notice text**.
- [ ] Each answer to a proposed meeting date sends `meeting_invitation_response` to R02, never
      to the member who answered.
- [ ] A status row where from == to (an adjacent stage sharing a broad status) notifies **nobody**
      — it would announce a state the file never left.
- [ ] The actor is never notified about their own action.
- [ ] **Art. 102:** the committee's vote tally reaches members but **not** the employee. The
      employee's own notice quotes "للأسباب المثبتة في القرار المعتمد" and contains no tally.
- [ ] Only the two act-on-it moments quote the recorded reason; the others withhold it.
- [ ] The request's Outputs tab shows the register of notices actually sent for that file, and a
      notice R02 issued by hand («The file's content») names who issued it.

---

## 14. Audit log

- [ ] Creating and updating a request writes audit rows with the actor, and the update row
      records **only the changed attributes**, old and new.
- [ ] A no-op save writes nothing.
- [ ] Password fields are redacted on both sides.
- [ ] `migrate:fresh --seed` writes **no** audit rows — bootstrap data is not user activity.
- [ ] The viewer filters by user, action, model and date range.
- [ ] A client-supplied raw class string is rejected; the filter speaks short registry keys.
- [ ] As R06, export the audit log. As R01: 403.

---

## 15. Admin, backup and maintenance

- [ ] Departments, Users, Request Types, Roles & Permissions, Settings and Templates all CRUD
      correctly as R08.
- [ ] On every one of those screens the create/edit form opens **in a modal** from a button; it
      is never rendered inline above the table. Escape closes it and returns focus to the
      button; a click on the backdrop does **not** close it. A validation error (422) is shown
      inside the modal.
- [ ] A department with children or users cannot be deleted — only deactivated.
- [ ] Assigning a user a manager works from the Users screen and survives a reload. (This was
      decorative for a while: the field was in the form but not in the API contract.)
- [ ] The Users screen switches between **Table** and **By department**. The department view
      shows the tree, each department's head card and its staff.
- [ ] In that view, «Make head» sets the head, «Move to…» moves a person to another department,
      and dragging a person onto a tree node does the same. On a touch screen the drag grip is
      absent and the select still works.
- [ ] The head must be an active member of that department, and the slot empties by itself when
      the head moves, is deactivated or is deleted.
- [ ] **The head is a label only.** Make someone head of #1's department who is not #1's
      manager: #1's request is still the manager's to forward, and the head is offered nothing.
- [ ] Request Types: change a type's service level and its ministry-escalation grade — a request
      filed afterwards picks both up. Deleting a type a request already uses is refused;
      deactivating it removes it from the intake picker.
- [ ] Add a row to a type's required-documents matrix: it appears on the intake checklist, in
      gate 1 and (for an employment-record row) in HR's employment-file check, immediately.
- [ ] Take a backup from the Backup screen. If `mysqldump` is not on PATH, the run is recorded
      as **failed with its reason on screen**, not lost in a 500.
- [ ] Download a snapshot; delete one; retention prunes only after a newer snapshot exists.
- [ ] There is no restore button — restoring is a server-side operation, and the screen says so.
- [ ] The Maintenance console (R08 only) shows diagnostics: pending migrations, shell
      availability with its reason, `max_execution_time`, writable paths, and each binary's probe.
- [ ] Every command shows its **exact argv** before you run it.
- [ ] A non-catalogue string (`rm -rf /`, `migrate; whoami`) is refused and **records no run**.
- [ ] `migrate:fresh` and `migrate:rollback` require both the `approve` grant **and** typing the
      confirmation phrase the API dictates.
- [ ] An artisan command runs in-process and records its real output; a shell command is refused
      with a clear reason when the host forbids `proc_open`.
- [ ] A second concurrent run is refused outright rather than queued.

---

## 16. Localisation, RTL, theme, phones and print

- [ ] Switch to Arabic: `dir="rtl"` is set on `<html>` and the whole layout mirrors — sidebar,
      tables, form labels, icons.
- [ ] No screen scrolls sideways at 1024px, 1280px or 1440px, in either language.
      `node scripts/check-layout.mjs` (Homestead and `npm run dev` up) sweeps every route at five
      widths in both locales and must report no overflow.
- [ ] At 375px the sidebar is a drawer opened by a button, and it slides in from the correct
      side in **both** languages.
- [ ] At 375px every table row is a **card**: each value sits under its column's name, the
      labels follow the language, and the page does not scroll sideways.
- [ ] At 375px a wizard and a modal fit the screen, scroll inside themselves, and their buttons
      stay reachable.
- [ ] Every visible string is translated; no raw locale keys (`meetings.foo.bar`) leak through.
- [ ] Arabic text entered in a form round-trips correctly through save and reload — no `????`.
- [ ] Switch the theme to dark: every screen remains readable. Check the dashboard bars, the
      audit-log action badges, the decision outcome pills and the wizard's paper slip
      specifically — hard-coded colours hide there.
- [ ] Set the OS to dark with the app on "system": it follows, with no light flash on first
      paint.
- [ ] Print a decisions register and a guide article: the dark palette flattens to ink-on-white
      and the whole page prints, not just the first screenful.

---

## 17. Regression and sign-off

Re-derive the counts rather than trusting this document:

```bash
# stages / roles / screens / statuses
php artisan tinker --execute="echo App\Models\WorkflowStage::count().' '.App\Models\Role::count().' '.App\Models\Screen::count().' '.App\Models\RequestStatus::count();"
# checkbox parity between this file and its Arabic twin
grep -c '^- \[ \]' TEST_PLAN.md TEST_PLAN.ar.md
```

- [ ] The counts match the ground-truth table at the top (12 / 12 / 36 / 39).
- [ ] `vendor/bin/phpunit` is green.
- [ ] `vendor/bin/pint --test` reports no diffs on files you touched.
- [ ] `npm run build` succeeds.
- [ ] The two TEST_PLAN files have an equal checkbox count.

### Defect log

| # | Section | What happened | Expected | Severity |
|---|---|---|---|---|
| | | | | |

### Sign-off

| Role | Account | Walked by | Date | Result |
|---|---|---|---|---|
| R01 | `r01.employee@` | | | |
| R02 | `r02.reviewer@` | | | |
| R03 | `r03.head@` | | | |
| R04 | `r04.member1@` | | | |
| R05 | `r05.manager@` | | | |
| R06 | `r06.ministry@` | | | |
| R07 | `r07.director@` | | | |
| R08 | `r08.sysadmin@` | | | |
| R09 | `r09.secretary@` | | | |
| R10 | `r10.diwan@` | | | |
| R11 | `r11.legal@` | | | |
| R12 | `r12.hr@` | | | |

---

## Appendix — reference data

**The twelve stages, in order:** `receive_from_municipality` · `direct_manager_review` ·
`administrative_routing` · `receive_and_register` · `requirements_check` · `reviewer_review` ·
`observations` · `forward_to_committee` · `receive_from_committee` · `approval_by_authority` ·
`local_governance_ministry` · `final_approval_archiving`. Stages 3, 6, 7 and 8 keep their rows
so an old file still renders its history, but no rule reaches them any more.

**The five approval levels:** `requirements_check` (1) · `receive_from_committee` (2) ·
`approval_by_authority` (3) · `local_governance_ministry` (4) · `final_approval_archiving` (5).

**The numbering series (Appendix 15 / النموذج 05):** `PM-RCV/YYYY/000001` intake receipt ·
`PM-COM/YYYY/0001` request · `PM-MTG/YYYY/01` meeting · `PM-MIN/YYYY/01` minutes ·
`PM-DEC/YYYY/001` decision.

**The twelve roles:** R01 موظف · R02 المقرر · R03 رئيس اللجنة · R04 عضو اللجنة ·
R05 مدير إدارة الشؤون الإدارية · R06 وزارة الحكم المحلي · R07 المدير العام / العميد ·
R08 مدير النظام · R09 أمين سر اللجنة · R10 وكيل الديوان · R11 العضو القانوني ·
R12 مدير إدارة الموارد البشرية.

**The five committee seats and the role each is bound to:** chair R03 · legal R11 ·
hr_director R12 · ministry_delegate R04 · rapporteur R02.

**The five approval grant rows:** `reviewer_approval` · `committee_head_approval` ·
`admin_manager_approval` · `ministry_approval` · `final_approval` — API slugs `reviewer` ·
`committee-head` · `admin-manager` · `ministry` · `final`. They are screens in the matrix and
have no page: each decides who may approve at one checkpoint, and the work itself arrives in
Pending Tasks. (There were six until Stage 57 deleted `authority_approval` along with the
`competent_authority` stage.)

**The wizards:** the request wizard (`DecisionWizard`, on the request page) · the meeting wizard
and the agenda-item wizard (on every meeting screen) · the appeal wizard and the appeal filing
wizard (on the Appeals screen).
