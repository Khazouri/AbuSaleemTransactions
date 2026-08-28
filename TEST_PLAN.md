# Test plan — Abu Saleem Transactions

A manual QA script for the whole system, Stages 1–27.

> Arabic version: [TEST_PLAN.ar.md](TEST_PLAN.ar.md) — same content.

**Why this document exists.** The automated suite (80 tests / 538 assertions) proves each
stage in isolation against in-memory sqlite. It does *not* prove that the system works for
the people it was built for, because until now the only login that existed was
`admin@abusaleem.test` — the System Admin, who holds all seven actions on all 23 screens.
Clicking through as R08 makes the six single-role approval screens all visible at once,
which is the exact opposite of what the permission matrix encodes. This plan walks the
system as the twelve accounts created by `TestUserSeeder`, so the role gates are actually hit.

**How to use it.** Work top to bottom. Every step has a checkbox and an explicit expected
result. Record failures in §16 rather than fixing them mid-pass — a half-fixed system
invalidates the steps after it.

---

## 1. Setup

### 1.1 Bring the system up

```bash
# Backend (repo root)
composer install
php artisan migrate            # must report nothing pending
php artisan db:seed            # reference data: roles, departments, stages, screens, matrix
php artisan db:seed --class=TestUserSeeder   # the twelve accounts below

# Serve
composer dev                   # or: php artisan serve / Homestead at http://abusaleem.test

# Frontend (from frontend/)
npm install
npm run dev                    # Vite at http://localhost:5173
```

- [ ] `php artisan migrate:status` shows every migration `Ran`, nothing pending.
- [ ] `curl http://abusaleem.test/api/ping` returns `{"message":"pong", ...}`.
- [ ] The SPA loads at `http://localhost:5173` and redirects to `/login`.

`TestUserSeeder` is **not** called from `DatabaseSeeder` — it must be run explicitly. That
is deliberate: a production `db:seed` must not mint twelve known-password accounts. It is
idempotent, so re-running it is safe and will *reset* any role you changed by hand.

### 1.2 Resetting between passes

A full reset is `php artisan migrate:fresh --seed && php artisan db:seed --class=TestUserSeeder`.
Note this destroys all requests, meetings, decisions, audit rows and backups. For a
partial reset, re-running `TestUserSeeder` alone restores the accounts and their roles
without touching requestal data.

### 1.3 Test accounts

All passwords are `password`. All have a `phone` set (needed for §11 SMS checks —
note the phone **cannot** be set through the Users screen, only by this seeder).

| # | Email | Role(s) | Dept | Purpose |
|---|---|---|---|---|
| 1 | `r01.employee@abusaleem.test` | R01 Employee | ENG | Files requests. **Moves none of them** — R01 appears nowhere in `workflow_transitions.required_role_id` |
| 2 | `r02.reviewer@abusaleem.test` | R02 Reviewer | REP | Stages 1→5; owns every backward exception |
| 3 | `r03.head@abusaleem.test` | R03 Committee Head | CMT | Stage 7; records binding decisions |
| 4 | `r04.member1@abusaleem.test` | R04 Committee Member | CMT | Voter |
| 5 | `r04.member2@abusaleem.test` | R04 Committee Member | CMT | Voter |
| 6 | `r04.member3@abusaleem.test` | R04 Committee Member | CMT | Voter (third seat → 2-1 plurality possible) |
| 7 | `r05.manager@abusaleem.test` | R05 Admin Manager | ADM | Stages 5, 6, and the stage-8 branch point |
| 8 | `r06.ministry@abusaleem.test` | R06 Ministry | ABS | Stage 9; holds `export` on reports/audit |
| 9 | `r07.director@abusaleem.test` | R07 Director/Dean | ABS | Stages 10 and 11; **no intake access** |
| 10 | `r08.sysadmin@abusaleem.test` | R08 System Admin | ADM | Admin screens; leaves `admin@abusaleem.test` untouched |
| 11 | `multi.role@abusaleem.test` | R03 **+** R04 | CMT | Permissions must be a **union**, never an intersection |
| 12 | `inactive.user@abusaleem.test` | R01 (`is_active=false`) | FIN | Must be refused at login |

Pre-existing: `admin@abusaleem.test` / `password` (R08). Leave it alone so you always have
a working admin if a test suspends account #10.

---

## 2. Permission matrix

The Vue router guard (`frontend/src/router/index.js` → `auth.can(meta.screenCode,'view')`)
is documented as **UX only**; `CheckScreenPermission` on the API is the real enforcement.
The whole point of this section is proving the two always agree.

### 2.1 Sidebar per role

Sign in as each account and compare the sidebar against this table. `request_details`
and `notes_attachments` are returned by `GET /api/screens` but filtered out of the sidebar
by `AppSidebar.vue` (they need a request id), so the **visible** count is two fewer
than the API count shown here.

| Account | API screen count | Approval screens visible |
|---|---|---|
| R01 employee | 11 | *none* |
| R02 reviewer | 12 | `reviewer_approval` only |
| R03 head | 12 | `committee_head_approval` only |
| R04 member | 11 | *none* |
| R05 manager | 12 | `admin_manager_approval` only |
| R06 ministry | 12 | `ministry_approval` only |
| R07 director | 12 | `authority_approval` **and** `final_approval` |
| R08 admin | 23 | all six |
| multi.role (R03+R04) | 12 | `committee_head_approval` only |

- [ ] Each role sees exactly one approval screen (R07 two, R01/R04 none, R08 all six).
- [ ] **R07 has no "استلام الطلب" (request intake) entry.** Deliberate — the dean
      approves, they do not do data entry.
- [ ] R01–R07 see no `users`, `departments`, `roles_permissions`, `settings`, `templates`
      or `backup` entry. Those six screens are R08-only.
- [ ] `multi.role` sees the **union** of R03 and R04 — in particular it can both vote
      (`decisions,add`, R03+R04) *and* record a decision (`decisions,approve`, R03 only).

### 2.2 Guard vs. API agreement

For each denied screen, do both halves:

- [ ] As R01, type `/users` in the address bar → redirected to `/dashboard`, no flash of
      the Users screen.
- [ ] As R01, `GET /api/users` with R01's bearer token → **403**
      `{"message":"لا تملك صلاحية الوصول إلى هذا القسم."}`
- [ ] As R02, type `/approvals/ministry` → redirected to `/dashboard`.
- [ ] As R02, `GET /api/approvals/ministry` → 403.
- [ ] As R04, `POST /api/meetings/{id}/agenda/{item}/decision` → 403 (`decisions,approve`
      is R03 only) even though R04 can vote on the same item.

### 2.3 `view` vs `export` are different privileges

Five screens split these deliberately. A user who can read the screen must still be refused
the file.

- [ ] R01 `GET /api/reports/requests` → 200; `GET /api/reports/requests/export?format=xlsx` → **403**.
- [ ] R01 `GET /api/audit-logs` → 200; `GET /api/audit-logs/export?format=xlsx` → **403**.
- [ ] R06 and R07 succeed on both of the above (they hold `export`).
- [ ] R06 `GET /api/decisions` → 200; `GET /api/decisions/export` → **403**. Decisions
      export is **R08-only** — the seed grants `print` to everyone but `export` to nobody
      but R08. This asymmetry is easy to mistake for a bug; it is not.
- [ ] R08 `GET /api/backups` → 200 and `GET /api/backups/{id}/download` → 200; every other
      role gets 403 on both.

### 2.4 Nobody but R08 can delete

`can_delete` is false for R01–R07 on **every** screen.

- [ ] As R03 (who can create committees), `DELETE /api/committees/{id}` → 403.
- [ ] As R08, the same call succeeds (subject to the history guard in §10.1).

---

## 3. Authentication and session

- [ ] Valid credentials → 200 with a token; SPA lands on `/dashboard`.
- [ ] Wrong password → **422** `بيانات الدخول غير صحيحة` (not 401 — the SPA's login form
      renders Laravel's `errors` object).
- [ ] Unknown email → the **same** message as a wrong password (no user enumeration).
- [ ] `inactive.user@abusaleem.test` with the **correct** password → 422
      `هذا الحساب غير مفعّل. يرجى مراجعة مدير النظام.` Distinct message: credentials were
      right, the account is disabled.
- [ ] 7 failed attempts within a minute → **429**. `throttle:6,1` is 6/min/IP, and it
      counts successful logins too — expect this to bite if you script logins in bulk.
- [ ] While signed in, navigating to `/login` redirects to `/dashboard` (`guestOnly`).
- [ ] Signed out, navigating to `/reports` redirects to `/login?redirect=/reports`, and
      logging in returns you to `/reports`.
- [ ] Corrupt the token in `localStorage`, reload → axios 401 interceptor logs you out
      rather than leaving a broken shell.
- [ ] Logout invalidates the token: replay the old bearer token → 401.

---

## 4. The golden path — stage 1 to archived

The core test. One request, six different people, eleven moves. Actor and expected
status come straight from `WorkflowTransitionSeeder`.

**Setup:** as **R01**, create a request (§8.1). Type **GRIV تظلم** (20-day SLA), and
set **`decision_grade` = 15** — at or above every type's threshold of 10, so it takes the
full route *through* ministry. Note the reference number.

| # | Stage | Action | Sign in as | Expected new stage | Expected status | Signature? |
|---|---|---|---|---|---|---|
| 1 | 1 استلام من البلدية | forward | **R02** | 2 | `in_review` | no |
| 2 | 2 فحص المتطلبات | approve | **R02** | 3 | `in_review` | **yes** — level 1 |
| 3 | 3 مراجعة المقرر | forward | **R02** | 4 | `in_review` | no |
| 4 | 4 إبداء الملاحظات | forward | **R02** | 5 | `ready` | no |
| 5 | 5 اعتماد الوزارة | forward | **R05** | 6 | `ready` | no |
| 6 | 6 تحويل للجنة | forward | **R05** | 7 | `in_meeting` | no |
| 7 | 7 استلام من اللجنة | approve | **R03** | 8 | `decided` | **yes** — level 2 |
| 8 | 8 اعتماد حسب الصلاحيات | approve | **R05** | 9 | `approved` | **yes** — level 3 |
| 9 | 9 وزارة الحكم المحلي | approve | **R06** | 10 | `approved` | **yes** — level 4 |
| 10 | 10 اعتماد الجهة المختصة | approve | **R07** | 11 | `final_approved` | **yes** — level 5 |
| 11 | 11 الاعتماد النهائي | approve | **R07** | 11 (self-loop) | `archived` | **yes** — level 6 |

- [ ] Every step above lands on the expected stage and status.
- [ ] At each step, **only** the named role sees an action button. Sign in as a different
      role at the same stage and confirm the workspace offers nothing.
- [ ] **R01 can never move it.** At every one of the eleven stages, R01 sees the detail
      screen (view is granted to `*`) with no workflow buttons at all.
- [ ] The stage timeline grows by one row per move, with actor and timestamp.
- [ ] The approval trail shows **six** signed approvals at levels 1–6 with visible
      signature images.
- [ ] After step 11 the request is `archived` and **terminal** — no further action is
      offered, and a raw `POST /api/requests/{id}/transition` returns 422.

### 4.1 Skipping a level is impossible

- [ ] With the request at stage 2, sign in as R05 and `POST /api/approvals/admin-manager/{id}`
      → 422 `لا توجد الطلب في مستوى الاعتماد المطلوب.`
- [ ] With the request at stage 8, sign in as R06 and try the ministry queue → same 422
      (the request has not reached stage 9 yet).

---

## 5. The ministry-bypass branch

The one destination that is **not** visible in the seeded transition map — it is computed
at runtime by `WorkflowService::destinationStageId()`.

Rule: on `approve` out of stage 8, if `Request::requiresMinistryApproval()` is false,
the request jumps straight to stage **10**, skipping ministry. That method is false
only when the type has a threshold and `decision_grade` is **below** it. Every seeded type
has a threshold of **10**.

Create three requests and walk each to stage 8:

- [ ] `decision_grade = 5` (below 10) → R05 approves at stage 8 → lands at **stage 10
      اعتماد الجهة المختصة**, skipping stage 9. Status still `approved`.
- [ ] `decision_grade = 10` (at the threshold) → goes to **stage 9** (ministry). The
      threshold is inclusive.
- [ ] `decision_grade = 15` (above) → goes to **stage 9**.
- [ ] For the bypassed request, the approval trail keeps levels 1, 2, 3 then jumps to
      **5** — level 4 (ministry) is legitimately absent. Levels are business constants, not
      a sequence counter, so a gap here is correct.
- [ ] R06 never sees the bypassed request in the ministry queue.

---

## 6. Exception flows

All five exception transitions are `requires_comment = true`.

| Action | From → To | Role | Resulting status |
|---|---|---|---|
| `return_missing_docs` نقص مستندات | 2 → 1 | R02 | `incomplete` |
| `reject_review` رفض المراجعة | 3 → 2 | R02 | `rejected` |
| `request_edit` طلب تعديل | 4 → 3 | R02 | `returned` |
| `cancel` إلغاء | self-loop, any open stage | stage-dependent* | `cancelled` |
| `defer` تأجيل | 7 → 7 | R03 | `deferred` |

\* cancel role by stage: 1–4 R02, 5–6 R05, 7 R03, 8 R05, 9 R06, 10–11 R07.

- [ ] Each of the three backward paths moves to the expected stage and status and records
      the reason in the timeline.
- [ ] Submitting any exception with an **empty or whitespace-only** reason → 422. Test both
      the UI modal and a raw API call with `"comment": "   "`.
- [ ] Cancel from stage 6 as R05 → status `cancelled`, stage stays **6**. The self-loop is
      deliberate: it records where work stopped rather than pretending cancellation was
      progress toward approval.
- [ ] A `cancelled` request is terminal — no further actions offered, API returns 422,
      and it disappears from every approval queue.
- [ ] Cancel from stage 6 as **R02** → refused (stage 6's cancel role is R05).

---

## 7. SLA and overdue escalation

`due_date = submitted_at.startOfDay() + type.default_sla_days`. Seeded SLAs: LEAV 7,
ALLW 10, PROM/SECD/TRNS 15, GRIV/EOSV 20.

- [ ] Create a LEAV request; `due_date` is 7 days out. Create a GRIV; it is 20.
- [ ] Back-date one in the database so `due_date` is in the past, then run
      `php artisan requests:flag-overdue`.
- [ ] Command prints `Backfilled N deadline(s); flagged N overdue request(s).`
- [ ] `overdue_at` is now set, and the SPA shows the breach on the detail screen.
- [ ] **Run the command a second time** — the same request is *not* re-flagged and no
      second notification fires. The `whereNull('overdue_at')` guard makes this exactly-once.
- [ ] A request due **today** is not flagged. It breaches only the following day.
- [ ] A `cancelled` or `archived` request is never flagged.
- [ ] The **`deadline_expired`** action (R08 → ministry oversight, mandatory reason) appears
      **only** on a flagged request, and only for R08. Verify it is absent before the
      sweep runs and present after.
- [ ] Executing it moves the request to stage 9 with status `in_review`.

---

## 8. Intake, attachments, notes

### 8.1 Intake

`POST /api/requests`, gated `request_intake,add` (R01–R06; **not R07**).

- [ ] R01 can open `/requests/create`; **R07 cannot** (redirect + 403 on
      `GET /api/requests/intake-options`).
- [ ] Required fields: `title`, `department_id`, `request_type_id`. Missing any → 422
      with an Arabic message.
- [ ] `decision_grade` is **required** whenever the chosen type has a threshold (all seven
      seeded types do) and must be an integer 1–100. Omit it → 422
      `درجة القرار مطلوبة لهذا النوع من الطلبات.`
- [ ] The department dropdown offers only **active** departments **with a code** —
      deactivate one in the admin screen and confirm it disappears.
- [ ] Reference number format is `YYYY-DEPT-000001`. Create two in ENG and one in FIN:
      ENG increments to `-000002` while FIN starts its own `-000001`. Counters are
      **per department, per year**.
- [ ] New request is status `new`, stage 1, `submitted_at` set.
- [ ] The timeline already has one row (action `intake`) before anyone touches it.

### 8.2 Attachments

Limits: `pdf,doc,docx,jpg,jpeg,png`, 20 MB each, **max 10** per intake.

- [ ] A valid PDF uploads and appears in the detail screen with its label.
- [ ] A `.txt` or `.exe` is rejected — check **both** the browser (the intake view mirrors
      the limits client-side) **and** the API directly with curl. The server must reject
      independently of the browser.
- [ ] A file over 20 MB is rejected.
- [ ] Attaching 11 files at intake → 422.
- [ ] Attachments are **not** publicly reachable: copy the stored path and try to fetch it
      from the web root. It must not resolve — files live on the private disk.
- [ ] R06 can view notes/attachments but **cannot add** (`notes_attachments,add` is R01–R05).

### 8.3 Notes

- [ ] R01–R05 can add a note; R06 and R07 can read but not add.
- [ ] Body over 5000 characters → 422.
- [ ] An internal note (`is_internal`) is visibly marked as such.

---

## 9. Approval queues and e-signature

Six queues, each bound to one stage and one role.

| Route | Screen | Stage | Role |
|---|---|---|---|
| `/approvals/reviewer` | `reviewer_approval` | 2 | R02 |
| `/approvals/committee-head` | `committee_head_approval` | 7 | R03 |
| `/approvals/admin-manager` | `admin_manager_approval` | 8 | R05 |
| `/approvals/ministry` | `ministry_approval` | 9 | R06 |
| `/approvals/authority` | `authority_approval` | 10 | R07 |
| `/approvals/final` | `final_approval` | 11 | R07 |

- [ ] Each queue lists **only** requests at its own stage. Park requests at several
      stages at once and confirm no queue shows another's work.
- [ ] `cancelled` and `archived` requests appear in **no** queue.
- [ ] Approving without drawing a signature → refused. The signature is **required** on
      every one of the six levels, including the final archive step.
- [ ] Signature validation: PNG only, max 2 MB, between 2×2 and 2400×1200 px. Post a JPEG
      via curl → 422.
- [ ] After approving, the trail renders the signature image. It loads via the
      bearer-authenticated endpoint — a plain `<img src>` would carry no Authorization
      header, so confirm the image actually appears rather than showing a broken icon.
- [ ] **Signature cleanup on failure:** approve with a valid PNG but an invalid state
      (wrong stage, or a role you do not hold) → 422, and **no orphan file** is left in
      `storage/app/private/signatures`. Count the directory before and after.
- [ ] Fetching another request's approval signature by id → 404, not someone else's
      image.

---

## 10. Committees, meetings, agenda, voting, decisions

Committees and meetings both ride the `meetings` screen: view `*`, add R03/R04,
**edit R03 only**, delete R08 only.

### 10.1 Committees

- [ ] R03 creates a committee (`name_ar` required).
- [ ] R04 can create a committee (`add`) but **cannot edit** one (`edit` is R03 only) → 403.
- [ ] R03 adds members: `r03.head`, `r04.member1`, `r04.member2`, `r04.member3`. Mark
      `r03.head` as head.
- [ ] Adding the same user twice → 422 `هذا المستخدم عضو بالفعل في اللجنة.`
- [ ] R08 cannot delete a committee that has held a meeting → 422. Preserve, don't erase.

### 10.2 Meetings and agenda

- [ ] R03 schedules a meeting. **All four active members are auto-invited** as attendees —
      check the attendee list without adding anyone by hand.
- [ ] Add a request sitting at stage 7 to the agenda (use the search box — it matches
      reference number or title).
- [ ] Adding the same request twice is rejected.
- [ ] Reorder agenda items with the up/down buttons; the order persists after reload.
- [ ] Remove an agenda item.
- [ ] Mark attendance: set `member1`, `member2`, `member3` **attended**, and deliberately
      leave one member **not** attended for §10.3.

### 10.3 Voting — the eligibility rules

`DecisionEligibility` has exactly three rules, each with its own Arabic message. This is
the section most worth doing carefully.

- [ ] An attending member votes `approve` → 201.
- [ ] A member can **change** their vote before a decision is recorded (vote again, tally
      updates rather than creating a second vote).
- [ ] **A committee member who did NOT attend** is refused:
      `التصويت مقصور على الأعضاء المسجل حضورهم في هذا الاجتماع.` Standing membership does
      not grant a vote on a sitting you missed.
- [ ] **A non-member** (e.g. R05) is refused: `التصويت مقصور على أعضاء هذه اللجنة.`
      Note R05 also lacks `decisions,add`, so check the 403 comes first — then test with
      `multi.role` removed from the committee to isolate the membership rule.
- [ ] **The pending-votes worklist never lies.** As each account, open `/decisions` →
      "awaiting my vote". Every item listed must be votable, and an item the vote endpoint
      would refuse must not be listed. This is the one invariant `DecisionEligibility`
      exists to hold — the worklist query and the vote guard are the same three conditions.

### 10.4 Recording the decision

`decisions,approve` — **R03 only**.

- [ ] **Zero votes** → 422 `لا توجد أصوات مسجلة على هذا البند بعد.`
- [ ] **Tie** (1 approve / 1 reject) → 422 `التصويت متعادل، لا يمكن حسم القرار تلقائياً.`
      No `Decision` row is written and the request does not move.
- [ ] **2 approve / 1 reject** → R03 records it → request moves **stage 7 → 8**, status
      `decided`, and a level-2 `Approval` row is written with the signature.
- [ ] **Majority `defer`** → request **stays at stage 7**, status `deferred`, ready to
      be put on a later agenda.
- [ ] **Majority `reject`** → maps to the `cancel` action → status `cancelled`, stage stays 7.
- [ ] Once a decision exists, voting closes:
      `تم تسجيل قرار هذا البند بالفعل، لا يمكن التصويت بعد الآن.`
- [ ] The agenda item disappears from every member's pending worklist.
- [ ] A meeting with a recorded decision **cannot be deleted** → 422.
- [ ] R04 attempting to record → 403 (`decisions,approve` is R03 only), even though R04 voted.

### 10.5 Decisions register

- [ ] `/decisions` register lists the recorded decision with its tally (2/1/0), committee,
      meeting date and who decided.
- [ ] Filters narrow it: outcome, committee, date range, and search by reference or title.
- [ ] R08 exports the register as xlsx and pdf; **every other role gets 403** on
      `/api/decisions/export` while still seeing the register on screen.
- [ ] The export is **not paginated** — a register with more rows than one page still
      exports in full.

---

## 11. Notifications

Six event types. Defaults: in-app on for all; email on for the four "do something" events;
**SMS off everywhere** (it costs per send, so it is opt-in).

| Event | in_app | email | sms | Recipients |
|---|---|---|---|---|
| `request_created` | ✅ | ❌ | ❌ | next-stage actors (not the creator) |
| `stage_changed` | ✅ | ❌ | ❌ | the creator only |
| `action_required` | ✅ | ✅ | ❌ | next-stage actors (not creator, not actor) |
| `request_overdue` | ✅ | ✅ | ❌ | creator + current-stage actors |
| `meeting_scheduled` | ✅ | ✅ | ❌ | the attendee rows just written |
| `decision_recorded` | ✅ | ✅ | ❌ | creator + committee members |

- [ ] R01 creates a request → **R02** gets `action_required` (R02 owns stage 1→2);
      R01 does **not** get told about their own action.
- [ ] R02 forwards it → **R01 (the creator)** gets `stage_changed`; the next actor gets
      `action_required`.
- [ ] The bell badge in the topbar shows the unread count and refreshes on its own within
      **60 seconds** (polling, no websockets — don't expect it to be instant).
- [ ] Opening the bell shows the 5 newest; `/notifications` shows the full paginated list.
      Dismissing in one place updates the other immediately (shared store).
- [ ] "Mark all read" clears the badge.
- [ ] **Preferences:** turn `email` off for `action_required`, trigger it, confirm only the
      in-app notification arrives (check `storage/logs/laravel.log` or Mailpit for mail).
- [ ] **Mute an event entirely** (all three channels off) → nothing is delivered at all.
- [ ] The preferences screen always lists **all six** event types, even ones you have never
      changed.
- [ ] **SMS:** turn `sms` on for an event. With `SMS_DRIVER=log`, delivery writes to the
      log. Clear the account's phone number → the SMS channel is dropped at resolution time
      rather than failing in the queue worker.
- [ ] **Cross-user scoping:** every notification endpoint is scoped to the caller. Take
      another user's notification id and try to mark it read → it must not work.
- [ ] Saving preferences for an unknown `event_type` → 422.

---

## 12. Audit log

- [ ] Seeding writes **no** audit rows. After `migrate:fresh --seed`, the viewer is empty.
- [ ] Creating and updating a request, approving, voting and deciding all appear.
- [ ] Expanding a row shows an old/new diff of **only the changed fields**.
- [ ] A **no-op save** (submit the edit form without changing anything) records nothing.
- [ ] Update a user's password via the Users screen → the audit row shows `********`, not
      the hash, on **both** sides of the diff.
- [ ] Deleting a record stores what was lost in `old_values`, and the trail survives the
      record's deletion (the morph columns have no foreign key on purpose).
- [ ] Filters work: by user, action, model type, record id, date range.
- [ ] The model filter offers a fixed registry, not just models that happen to have rows.
- [ ] Passing a raw class string as the `model` filter is **rejected** — the API never
      takes a class name from the client.
- [ ] There is **no** write endpoint. `POST`/`PUT`/`DELETE` on `/api/audit-logs` → 405/404.
- [ ] R06 or R07 exports the log; R01 gets 403 while still being able to read it.

---

## 13. Dashboard and reports

Both read one service, so a tile and an exported file can never disagree.

- [ ] Dashboard shows total, pending, completed, completion %, SLA breaches and average
      cycle time against real data.
- [ ] Total = completed + abandoned + pending. Pending is computed as the remainder, so
      the three always add up.
- [ ] `overdue` counts only work that is flagged **and still open** — archive an overdue
      request and watch the breach count drop.
- [ ] **Cache invalidation:** note the total, create a request, reload the dashboard.
      The new request appears **immediately** (a 5-minute TTL alone would look like a bug).
- [ ] Breakdown bars by status, stage, department (top 10) and a 12-month created-vs-completed
      trend all render without a charting library.
- [ ] A status with zero requests still shows an explicit 0 rather than vanishing.
- [ ] Reports filters (department, type, status, date range) narrow both the table and the
      summary, and the summary matches the rows.
- [ ] Export as **xlsx** — opens in Excel, sheet is RTL when the locale is `ar`.
- [ ] Export as **pdf** — **Arabic text is joined and shaped correctly**, not disconnected
      left-to-right letterforms. This is the single reason mPDF is the dependency; if this
      check fails, someone swapped the PDF writer.
- [ ] The downloaded filename survives the round trip, including Arabic characters.
- [ ] Trigger an export you lack permission for and confirm you see a **readable error
      message**, not a silently failed download (blob error bodies are decoded back to JSON).

---

## 14. Admin screens (R08)

- [ ] **Users:** create a user, assign roles, edit, toggle active, delete. A user you
      deactivate cannot log in (§3).
- [ ] **Note:** the Users screen cannot set `phone` — the field is not accepted by the
      store/update requests even though the column exists. Test accounts get theirs from
      the seeder. Users set their own on the notification preferences screen.
- [ ] **Departments:** create a nested tree; a department with children or users
      **cannot be deleted** → deactivate instead.
- [ ] **Roles & permissions:** toggle a permission, save, then sign in as that role and
      confirm the change takes effect on **both** the sidebar and the API. Restore it
      afterwards, or re-run `ScreenRolePermissionSeeder`.
- [ ] **Settings:** create/edit/delete a key/value pair. Values are plain text by design —
      expect no type validation.
- [ ] **Templates:** create a bilingual template; `code`, `name_ar` and `body_ar` are
      required, English optional.

---

## 15. Backup, user guide, i18n

### 15.1 Backup (R08 only)

- [ ] **Prerequisite:** `mysqldump` is not on this host's PATH — `BACKUP_MYSQLDUMP_PATH`
      in `.env` must point at it (e.g. `C:\xampp\mysql\bin\mysqldump.exe`, single-quoted;
      dotenv rejects backslashes inside double quotes).
- [ ] Create a snapshot from `/backup`; the row shows size and completion time.
- [ ] The zip contains `database.sql` plus `files/attachments/` and `files/signatures/`.
- [ ] Download it (`backup,export`); delete it (`backup,delete`).
- [ ] **Break it on purpose:** set a bad `BACKUP_MYSQLDUMP_PATH` and create a snapshot →
      a red **`failed`** row appears with the reason, rather than a 500 vanishing into the
      log. Restore the correct path afterwards.
- [ ] `php artisan backup:run` prints `Created <file> (<N> KB); pruned <N> expired snapshot(s).`
- [ ] `php artisan schedule:list` shows `backup:run` at 01:30 and
      `requests:flag-overdue` at 00:05.
- [ ] **There is no restore button, deliberately** — the screen says so. Restoring is a
      server-side operation performed with the system stopped.

### 15.2 User guide

- [ ] R08 publishes an article (`code`, `title_ar`, `body_ar` required); every other role
      can read and print it.
- [ ] A **draft** (`is_active = false`) is hidden from readers and visible to R08.
- [ ] Duplicate `code` → 422, but an article may keep its own code when edited.
- [ ] **XSS check:** as R08, paste `<script>alert(1)</script>` and
      `<img src=x onerror=alert(1)>` into an article body. Sign in as R01 and confirm both
      render as **literal text**. Bodies are plain-text paragraphs, never `v-html` — this
      is the one screen every role lands on, so HTML there would be a stored-XSS surface
      aimed at the whole organisation.

### 15.3 RTL, i18n and print

- [ ] Toggle Arabic ↔ English. `<html dir>` flips between `rtl` and `ltr`.
- [ ] Sidebar, tables, forms and modals all mirror correctly. Look specifically for
      anything pinned to the wrong side — the layout uses logical properties
      (`margin-inline-start`), so a physical `left`/`right` in a new component shows up here.
- [ ] No untranslated keys (raw `some.key.name` strings) on any screen in either locale.
- [ ] **Print preview every screen.** The global `@media print` block releases `.shell`'s
      `height:100vh`/`overflow:hidden`; without it you get page one and nothing else.
      Confirm multi-page content actually paginates — the request detail, the decisions
      register and the audit log are the ones worth checking.

---

## 16. Regression and sign-off

```bash
vendor/bin/phpunit         # expect 80 tests green
vendor/bin/pint --test     # touched files clean
cd frontend && npm run build
```

- [ ] Full suite green.
- [ ] `npm run build` succeeds.
- [ ] `php artisan migrate:status` — nothing pending.

### Defect log

| # | Section | Account | What happened | Expected | Severity |
|---|---|---|---|---|---|
| 1 | | | | | |
| 2 | | | | | |
| 3 | | | | | |

---

## Appendix — reference data

**Roles:** R01 موظف Employee · R02 المقرر Reviewer · R03 رئيس اللجنة Committee Head ·
R04 عضو اللجنة Committee Member · R05 مدير الشؤون الإدارية Admin Manager ·
R06 وزارة الحكم المحلي Ministry · R07 المدير العام/العميد Director/Dean ·
R08 مدير النظام System Admin

**Departments:** ABS (root) › ADM, ENG, FIN, REP, CMT

**Request types** (all with `decision_grade_threshold` = 10):
PROM ترقية 15d · LEAV إجازة 7d · ALLW علاوة 10d · SECD انتداب 15d · GRIV تظلم 20d ·
TRNS نقل 15d · EOSV إنهاء خدمة 20d

**Statuses:** new · in_review · incomplete · ready · in_meeting · decided · approved ·
final_approved · **archived** (terminal) · returned · rejected · **cancelled** (terminal) ·
deferred

**Stages:** 1 receive_from_municipality · 2 requirements_check · 3 reviewer_review ·
4 observations · 5 ministry_endorsement · 6 forward_to_committee · 7 receive_from_committee ·
8 approval_by_authority · 9 local_governance_ministry · 10 competent_authority ·
11 final_approval_archiving

**Source of truth:** roles/permissions `database/seeders/ScreenRolePermissionSeeder.php` ·
workflow `database/seeders/WorkflowTransitionSeeder.php` + `app/Services/WorkflowService.php` ·
vote eligibility `app/Services/DecisionEligibility.php` · stage scope `STAGE_PLAN.md`
