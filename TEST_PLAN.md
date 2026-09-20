# Test plan — Abu Saleem Transactions

A manual QA script for the whole system, organised **by feature**, covering Tracks A–L
(Stages 1–84).

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

**How to use it.** Work top to bottom — later sections assume the request built in §4/§5 still
exists. Every step has a checkbox and an explicit expected result. Record failures in §16
rather than fixing them mid-pass; a half-fixed system invalidates the steps after it.

**Ground truth this plan is written against** (re-derive with the script in §16 if in doubt):

| | |
|---|---|
| Workflow stages | **12** |
| Roles | **11** (R01–R11) |
| Screens | **33** |
| Request statuses | **39** |
| Notification event types | **11** |
| Approval levels | **5** |
| Seeded test accounts | **15** |

---

## 1. Setup

### 1.1 Bring the system up

```bash
# Backend (repo root)
composer install
php artisan migrate            # must report nothing pending
php artisan db:seed            # reference data: roles, departments, stages, screens, matrix
php artisan db:seed --class=TestUserSeeder   # the fifteen accounts below

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
      without one **no notification in §12 is ever delivered** and the failure looks like a bug
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
This destroys all requests, meetings, decisions, appeals, audit rows and backups. For a partial
reset, re-running `TestUserSeeder` alone restores the accounts and their roles without touching
request data.

> **Do not run `db:seed --class=ScreenRolePermissionSeeder` on an install whose permissions have
> been customised.** It resets the entire matrix to its defaults, discarding every grant an
> administrator changed through the Roles & Permissions screen.

### 1.3 Test accounts

All passwords are `password`. All have a `phone` set (needed for §12's SMS checks — note the
phone **cannot** be set through the Users screen, only by this seeder or by the user's own
notification-preferences screen).

| # | Email | Role(s) | Dept | Purpose |
|---|---|---|---|---|
| 1 | `r01.employee@abusaleem.test` | R01 | ENG | Submits. Manager is #2. |
| 2 | `r02.reviewer@abusaleem.test` | R02 | REP | المقرر — registration, gates, minutes, closure. |
| 3 | `r03.head@abusaleem.test` | R03 | CMT | Committee chair — convenes, decides, approves minutes. |
| 4 | `r04.member1@abusaleem.test` | R04 | CMT | Votes. |
| 5 | `r04.member2@abusaleem.test` | R04 | CMT | Votes. |
| 6 | `r04.member3@abusaleem.test` | R04 | CMT | Votes — three members exist so a 2-1 plurality is reachable. |
| 7 | `r05.manager@abusaleem.test` | R05 | ADM | HR route + approval level 3. |
| 8 | `r06.ministry@abusaleem.test` | R06 | ABS | Ministry — approval level 4, exports. |
| 9 | `r07.director@abusaleem.test` | R07 | ABS | Final approval — level 5. |
| 10 | `r08.sysadmin@abusaleem.test` | R08 | ADM | Admin. Second admin, so you can suspend one safely. |
| 11 | `multi.role@abusaleem.test` | R03+R04 | CMT | Proves `screenPermissions()` **unions** across roles. |
| 12 | `inactive.user@abusaleem.test` | R01 | FIN | `is_active = false` — must be refused at login *and* as an actor. |
| 13 | `r09.secretary@abusaleem.test` | R09 | CMT | Committee secretary — agenda preparation. |
| 14 | `r10.diwan@abusaleem.test` | R10 | ABS | Diwan route at `receive_and_register`. |
| 15 | `r11.legal@abusaleem.test` | R11 | CMT | العضو القانوني — the only role that records a legal review. |

- [ ] All fifteen appear in the Users screen as R08, and #12 shows as inactive.
- [ ] Signed in as #1, the Users screen is not in the sidebar and `/users` is refused.

### 1.4 The login-screen test-account picker

The picker is gated by the **backend**, not the build: `GET /api/dev/test-users` answers 404
unless `APP_ENV=local`. The same `dist/` therefore shows it against a local API and hides it
against a real one, and no known-password address is ever compiled into the bundle.

- [ ] With `APP_ENV=local`, the login screen shows the picker and one click signs you in.
- [ ] `curl http://abusaleem.test/api/dev/test-users` returns the fifteen accounts, and the
      payload contains **no password hash**.
- [ ] Set `APP_ENV=production`, clear the config cache, reload: the panel is gone and the
      endpoint 404s (not 403 — a 403 would confirm the path exists).

---

## 2. Permissions and navigation

`screen_role_permissions` is the single source of truth, read by **both** the API middleware
(`screen.permission:<code>,<action>`) and the Vue router guard. The property under test is that
they always agree.

### 2.1 Sidebar per role

Sign in as each account and count sidebar entries. The API returns more screens than the
sidebar shows, because `request_details` and `notes_attachments` carry `:id` in their route and
are filtered out of the menu — so **sidebar = API − 2** for every role.

| Role | Screens via API | Sidebar entries |
|---|---|---|
| R01 | 21 | 19 |
| R02 | 22 | 20 |
| R03 | 22 | 20 |
| R04 | 21 | 19 |
| R05 | 22 | 20 |
| R06 | 22 | 20 |
| R07 | 21 | 19 |
| R08 | **33** | 31 |
| R09 | 20 | 18 |
| R10 | 20 | 18 |
| R11 | 20 | 18 |

- [ ] Each role's sidebar count matches the table.
- [ ] Only R08 sees the admin block (Users, Departments, Roles & Permissions, Settings,
      Templates, Backup, Maintenance).
- [ ] The **Meetings** group renders as a collapsible section containing eight screens;
      `decisions` sits **outside** it as a flat top-level entry.
- [ ] `multi.role@` sees the **union** of R03's and R04's screens — not the intersection. This
      is the only account that would notice that regression.

### 2.2 Guard and API agree

- [ ] As R01, type `/users` in the address bar: the router guard blocks navigation and you land
      somewhere sensible rather than on a blank screen.
- [ ] As R01, call `GET /api/users` with your bearer token: **403**, not 200 and not 404.
- [ ] As R08, open Roles & Permissions, revoke `requests → view` from R01, save. Sign in as
      R01: the Requests screen has left the sidebar and the API refuses it. Restore the grant.

### 2.3 `view`, `export` and `approve` are different privileges

- [ ] As R01, open Reports: it renders, and there is **no** export button.
- [ ] As R01, call `GET /api/reports/requests/export?format=xlsx`: **403**.
- [ ] As R06, the same call returns an `.xlsx` file.
- [ ] As R06, `GET /api/decisions/export` also succeeds (Stage 84 added this tier — it used to
      be R08-only, inconsistently with every other export).
- [ ] As R04, `GET /api/decisions/export`: **403**.

### 2.4 Deletion is R08-only

- [ ] As R05, the Departments screen shows no delete control, and `DELETE /api/departments/{id}`
      returns 403.
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

## 4. Intake, numbering and the قيد point

This is where [D] Art. 15's rule bites: handing a request to the direct manager **is not a
registration**. The employee gets a *receipt*; the reference number is granted later.

- [ ] As R01, open Request Intake. Pick a type — the **required-documents checklist** appears,
      split into "الأساسية المشتركة" and "الخاصة بالنوع", with conditional items marked.
- [ ] Attach a PDF and a PNG; each upload requires you to classify it into one of Appendix 14's
      folders. There is no default — an unclassified upload is refused.
- [ ] Submit. The success screen shows an **intake receipt** of the form `PM-RCV/2026/000001`
      and states plainly that this is a receipt, not a قيد.
- [ ] The new request's `reference_number` is **null**, and its stage is
      `direct_manager_review` — intake auto-hops there in the same transaction.
- [ ] Try to file a **second** request of the same type as the same employee: refused, naming
      the open file to attach the documents to instead (Appendix 16's non-duplication rule).
- [ ] File a request of a *different* type: accepted. That is what the rule actually permits.

---

## 5. The golden path — twelve stages

Walk one request end to end. The point is that **no single account can do it** — needing R08 to
get past any step is itself a defect.

| # | Stage | Actor | Action |
|---|---|---|---|
| 1 | `receive_from_municipality` | R01 | `submit` (automatic at intake) |
| 2 | `direct_manager_review` | #2 (the submitter's manager) | `forward` |
| 3 | `administrative_routing` | same manager | `route_to_hr` — Stage 96 retired the Diwan and committee-secretary routes |
| 4 | `receive_and_register` | R12 (HR) | `register` |
| 5 | `requirements_check` | R02 | gates, then `approve` — **this is the قيد** |
| 6 | `reviewer_review` | R02 | `forward` |
| 7 | `observations` | R02 | `forward` |
| 8 | `forward_to_committee` | R02 | `forward` |
| 9 | `receive_from_committee` | R03 | vote → record decision |
| 10 | `approval_by_authority` | R05 | `approve` |
| 11 | `local_governance_ministry` | R06 | `approve` |
| 12 | `final_approval_archiving` | R07 | `approve` → `in_execution` |

- [ ] Step 2 works as **#2 specifically** (R01's assigned manager), not merely as "some R02".
- [ ] A different R02 who is not this employee's manager is refused at step 2.
- [ ] Step 3: pick **Diwan**. Then attempt `register` as R05 (the HR registrar): **refused** —
      the status gate is real, not decorative.
- [ ] `register` as R10 succeeds. The status becomes `in_review`, *not* `registered`.
- [ ] Step 5: attempt `approve` before recording the Art. 45 jurisdiction test: **refused**.
- [ ] Record the jurisdiction test (all six questions) and the intake gate (§6), then `approve`.
      **Now** the reference number is minted — `PM-COM/2026/0001` — and the status becomes
      `registered`.
- [ ] Send the file back with `return_missing_docs`, then walk it forward through this same hop
      again: the reference number is **unchanged**. One number for life (Art. 99).
- [ ] Each hop writes both a stage-log row and a status-history row; the timeline on the request
      screen shows الجهة and any linked document per entry.
- [ ] Steps 5, 9, 10, 11 and 12 each require a **signature** and write an `Approval` ledger row —
      five levels, one per checkpoint.
- [ ] Skipping a level is impossible: as R07, try to approve while the request is still at stage
      10. Refused.
- [ ] An actor cannot approve their **own** request. Have #10 (R08) file a request and then try
      to approve it: refused at every level.

### 5.1 The ministry-bypass branch

- [ ] File a request whose type has a `decision_grade_threshold` above its grade. At stage 10,
      approving sends it **straight to stage 12**, skipping the ministry.
- [ ] Its status on arrival is `final_approved`, not `approved` — the bypass path stamps the
      correct terminal-ish status, which is the bug Stage 57 had to fix explicitly.
- [ ] A request at or above the threshold visits stage 11 first.

---

## 6. The four control gates

Appendix 63 requires the system itself to block progression at four points.

**Gate 1 — before the قيد.** At `requirements_check`:

- [ ] Every seeded required document must be answered. An unanswered one blocks `approve`.
- [ ] A document the source marks conditional ("بحسب الموضوع") may be answered **لا ينطبق**.
- [ ] An unconditional one may **not** — waiving it is refused, naming the document.
- [ ] The Appendix 20 facts attestation is required.
- [ ] **As R01, on your own request:** recording the jurisdiction test or the intake gate is
      **refused** (Stage 84 — Appendix 19's separation rule). The employee cannot attest to the
      completeness of their own file.
- [ ] Even an R02 who *created* the request on someone's behalf is refused, with the Appendix 19
      message. Send a **valid** payload when testing this — an invalid one fails validation
      first and you will wrongly conclude the block is missing.
- [ ] Both halves of the gate display **who recorded them and when**.

**Gate 2 — before the agenda.** Covered in §8.3.

**Gate 3 — before the minutes go for approval.** Covered in §8.6.

**Gate 4 — before closure.** Covered in §10.2.

---

## 7. Exceptions, SLA and escalation

- [ ] `return_missing_docs`, `reject_review`, `request_edit` and `cancel` each **require a
      reason**; submitting without one is refused, and the reason lands in the timeline.
- [ ] Exception actions render as visually secondary buttons, not as equal-weight alternatives
      to the normal action.
- [ ] A cancelled or archived request accepts no further workflow action.
- [ ] Set a request's `due_date` into the past, run `php artisan requests:flag-overdue`: it is
      flagged **once**. Run it again — nothing changes, and no second notification fires.
- [ ] The requests list shows a per-stage timeliness dot. Its four levels are Appendix 38's:
      **أخضر** within the allowance, **أصفر** *approaching* (still inside it), **أحمر** past it,
      **حرج** only when a legal deadline is also recorded.
- [ ] Run `php artisan requests:escalate-delays` on a file sitting past its stage target: the
      **current owner** is notified. Push it further: R02 + R05. Record a `legal_deadline` on it
      and re-run: it becomes حرج and reaches R03 + R07 — while still telling the earlier rungs.
- [ ] Re-run immediately: the same rung is **not** announced twice.
- [ ] Move the request to another stage: the ladder resets and the new stage can escalate again.

---

## 8. Committee and meetings

### 8.1 Committees

- [ ] As R08, create a committee and add members with their seats (chair / legal / hr_director /
      ministry_delegate / rapporteur). A seat cannot be filled twice.
- [ ] A committee with no recorded quorum rule shows **غير مثبت**, not a number. The system
      refuses to invent one (Appendix 64 forbids it).
- [ ] Record "أكثر من نصف الأعضاء" on a four-member committee: the required quorum is **3**, not
      2. This is the check that proves the invented `ceil(n/2)` is gone rather than relabelled.
- [ ] Deleting a committee that has meetings is refused.

### 8.2 Candidates and legal review

- [ ] The Candidate Requests screen lists files at `receive_from_committee` awaiting nomination,
      with waiting time, file completeness and proposed meeting.
- [ ] As R02, send a file to legal review. Its status becomes `under_legal_review`; its **stage
      does not move**.
- [ ] As R11, the file appears in the legal-review queue and opens successfully — R11 holds no
      workflow transition, so this proves the visibility widening works.
- [ ] As R11, record a verdict. `sound_ready` and `present_with_note` permit the agenda; the
      other three block and return the file. A blocking verdict requires a note.
- [ ] As R02, attempting to *record* a verdict: 403. As R11, attempting to *dispatch* a file to
      review: 403. The two write tiers are not interchangeable.

### 8.3 Agenda (gate 2)

- [ ] Adding an un-reviewed request to an agenda is **refused**.
- [ ] A request with an unresolved document conflict is refused, in the appendix's own words.
- [ ] Items reorder by **drag and drop**, and the order persists.
- [ ] The ordering panel computes Art. 83's rank (deferred → legal deadline → urgent → complete
      by readiness date → uncategorised). "Apply order" rewrites the agenda.
- [ ] Depart from the computed order without a justification: readiness reports
      `agenda_order_departs_from_rule` and **convening is blocked** until you record one.
- [ ] Priority has exactly **two** levels (عالية / عادية). A declared عالية requires both a
      ground from Appendix 33's five and a written مبرر.
- [ ] Add an **administrative** item (no request attached): it needs a subject, and it can never
      carry a decision.
- [ ] As R04, try to add an agenda item: **403** (Stage 84 — Art. 13 gives members study,
      discussion and voting, not authorship).
- [ ] As R02, add one: succeeds.

### 8.4 Readiness and convening

- [ ] The readiness screen shows four percentages, the quorum, and an **exceptions-only** list.
- [ ] A meeting with an incomplete file, an unconfirmed member or a pending invitation reports
      the matching exception and is **not ready**.
- [ ] Convening a ready meeting needs no reason. Convening a **not**-ready one requires a
      written override reason, and stores it.
- [ ] As R02, schedule a meeting for a committee you **do not sit on**: refused with
      "لا يجوز جدولة اجتماع للجنة لست عضوًا فيها." Schedule one for a committee you do sit on:
      succeeds.
- [ ] As R04, schedule a meeting: **403**.
- [ ] Convening freezes the voting rules onto the meeting. Edit the committee's quorum
      afterwards: the held meeting still reports the rules it was convened under.

### 8.5 Running the meeting, voting and decisions

- [ ] The live runner shows the current item, a timer, the attendee list and a discussion feed.
- [ ] Art. 85's nine-step study card blocks out-of-order ticks — marking a later step before its
      predecessor is refused, naming the missing step.
- [ ] Two steps are **derived, not tickable**: التصويت and إثبات النتيجة.
- [ ] عرض الرأي القانوني is غير منطبق on an item with no legal opinion.
- [ ] **Voting is refused until the sequence completes** — and the member's "awaiting my vote"
      worklist shows **zero rows** at the same moment. The two must agree.
- [ ] Complete the sequence: the vote succeeds and the worklist populates.
- [ ] A non-member cannot vote. A member marked absent cannot vote. A member who has declared a
      conflict of interest cannot vote **or** post to the discussion feed.
- [ ] Once a vote exists, uploading an attachment or regenerating the presentation memo for that
      item is refused (Appendix 25's material freeze) — while the *same* upload on a different,
      unvoted file succeeds.
- [ ] Record a decision with a 2-1 plurality: succeeds. A **tie** is refused rather than guessed,
      and so is a zero-vote tally.
- [ ] Every decision requires Appendix 27's four parts (موضوع / وقائع / سند / منطوق) and Art.
      90's instrument (قرار / توصية / رأي).
- [ ] A منطوق of only "اتخاذ اللازم" is refused; "اتخاذ اللازم نحو إحالة الملف … خلال أسبوع"
      is accepted. The rule tests **insufficiency**, not prohibition.
- [ ] A تأجيل requires Art. 34's four mandatory fields; a bare "تأجيل للمراجعة" is refused.
- [ ] A رفض requires a reason **code** plus specifics — bare "لعدم الاستحقاق" is refused.
- [ ] As R02 (المقرر), casting a vote is refused: Art. 16 forbids it.

### 8.6 Minutes (gate 3)

- [ ] Generate minutes: they compile attendance, the applied quorum rule, and per-item votes and
      decisions.
- [ ] **Change the agenda after generating, then try to approve:** refused. This is gate 3's
      real value — an approved محضر describing a sitting that did not happen.
- [ ] Regenerate, then approve: succeeds, and Appendix 8's sixteen checks are recorded.
- [ ] A meeting with **no recorded attendance** cannot have its minutes approved — it proves
      neither حضور nor صحة انعقاد.
- [ ] Approval creates one signature row per attendee marked present; the last signature flips
      the document to `approved` automatically.
- [ ] A non-signer cannot sign, and nobody can sign twice.
- [ ] A meeting cannot be closed until its minutes are approved.
- [ ] As R02, approving minutes: **403** — that is the chair's act (Art. 12).

---

## 9. Appeals (تظلم)

- [ ] As R01, file an appeal against a **decided** request you own. Appeals against an
      undecided request, or someone else's, are refused.
- [ ] A second appeal on the same file requires a **new-facts declaration**.
- [ ] Attach a document to the appeal — it lives on the appeal, not on the original request.
- [ ] As R02, run formal verification: a pass advances it; a fail requires a reason and closes
      it as `rejected`.
- [ ] The appellant cannot verify their own appeal.
- [ ] Record the jurisdiction test: a "committee" answer advances to `file_assembly`; any of the
      four non-committee answers terminates it as `outside_jurisdiction`.
- [ ] Record the Art. 75 legal review — all five questions are required together.
- [ ] Open the assembled **file**: it shows the original request, its presentation memo, the
      minutes excerpt, the decision, the appeal's own documents, and the in-app notification
      evidence. Email/SMS honestly report *no evidence available* rather than claiming delivery.
- [ ] Nominate the appeal onto a committee agenda — only possible once it has reached
      `legal_review`.
- [ ] Decide it with one of the five appeal outcomes. Every outcome requires a comment.
- [ ] Execute the outcome: `appeal_accept` and `appeal_partial_accept` make the **original**
      request terminal; `appeal_reject` leaves it untouched; `appeal_refer` is non-terminal;
      `appeal_redo` reopens the original at a stage you name.
- [ ] Close the appeal: it records the closure card and notifies the appellant.
- [ ] **The original request cannot be closed while an appeal against it is open.**
- [ ] Reopen a closed appeal: only for one of the six enumerated reasons. A plain "I disagree"
      is refused.

---

## 10. Execution, closure, suspension, reopen

### 10.1 Execution proof

- [ ] Claiming execution with **no evidence attachment** is refused: "لا يكفي أن تقول الجهة
      المنفذة (تم التنفيذ)؛ يجب إرفاق دليل التنفيذ."
- [ ] Nominate an attachment belonging to a **different** request: refused.
- [ ] النموذج 17's six executor checks: a single "لا" refuses, quoting the failed question back.
- [ ] On a request flagged as carrying a financial effect, the referral check must be "نعم" —
      `not_applicable` is refused (Art. 97).
- [ ] The seventh check (هل تم إرفاق مستند التنفيذ؟) is **derived** — spoof it as "لا" in the
      payload and it comes back "نعم" from real state.
- [ ] Art. 103's twelve-point soundness checklist is required before `in_execution`. Eight of its
      twelve are derived: spoof `minutes_signed: "no"` and it is overwritten from real state.

### 10.2 Closure (gate 4)

- [ ] All three of Art. 37's closable paths work: executed, not-approved, and outside-jurisdiction.
- [ ] Appendix 48's conditions each refuse **by name**: a deferred file says "لا يجوز إقفال
      معاملة مؤجلة.", a returned one says so in its own words, and so on.
- [ ] Appendix 47's twelve-point audit: a single "لا" refuses, quoting the question.
- [ ] Two of the twelve are **server-derived**, not asked — the appeal-path check and the archive
      location.
- [ ] `final_result_code` and `notice_status` are computed: spoof both in the payload and the
      real values come back.
- [ ] Closing twice is refused.
- [ ] As R04, closing: **403**. As R02 or R03: succeeds.

### 10.3 Suspension and reopen

- [ ] Suspend a file under Art. 105: the status becomes `execution_suspended`, the **stage does
      not move**, and no stage-log row is written.
- [ ] While suspended, `approve` is refused through **both** the transition endpoint and the
      approval queue, and **no `Approval` row is written** by the refused attempt.
- [ ] Lifting is refused until a legal review recorded *after* the suspension exists.
- [ ] The suspended file appears in R11's legal-review queue.
- [ ] Lift it: `fact_confirmed` restores the frozen status; `referred_to_committee` sends it back
      to `receive_from_committee`.
- [ ] Reopen a concluded request for one of the six enumerated reasons: it moves to the stage you
      name, and the **intake gate, jurisdiction test and soundness records are all cleared** so
      the new lap cannot pass on the previous lap's answers.
- [ ] The approval-return register is **not** cleared — earlier rounds stay readable.

---

## 11. Registers, reports and KPIs

- [ ] The Registers screen lists Art. 98's **twelve** registers in order, each with its own
      columns.
- [ ] Register 2 (الناقصة) keeps a row for a file whose shortfall has since been **cleared** —
      a register of نواقص whose rows vanish when fixed is a worklist, not a register. The row
      reads `still_incomplete: لا`.
- [ ] Register 6 carries Appendix 12's **thirteen** columns.
- [ ] Register 7 names its own request (reference and subject are populated, not blank).
- [ ] Register 11 carries Art. 34's five deferral fields.
- [ ] An unknown register code returns 404.
- [ ] Reports: the KPI tiles, the breakdown bars and the twelve-month trend all render, and the
      filters narrow them.
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

## 12. Notifications

Requires a running queue worker (§1.1).

- [ ] `NotificationSetting::EVENT_TYPES` offers **eleven** event types on the preferences screen,
      each with in-app / email / SMS toggles.
- [ ] Advancing a request notifies the creator **and** the next actor, on their enabled channels
      only.
- [ ] Mute an event entirely: nothing is delivered on any channel.
- [ ] Clear a user's phone: the SMS channel is dropped rather than queued undeliverably.
- [ ] The bell badge updates within a minute and the dropdown lists unread items.
- [ ] **Art. 101:** the employee is notified at each mapped moment. The same `registered` status
      reads as moment 1 the first time and moment 3 after a نواقص loop.
- [ ] A status row where from == to (an adjacent stage sharing a broad status) notifies **nobody**
      — it would announce a state the file never left.
- [ ] The actor is never notified about their own action.
- [ ] **Art. 102:** the committee's vote tally reaches members but **not** the employee. The
      employee's own notice quotes "للأسباب المثبتة في القرار المعتمد" and contains no tally.
- [ ] Only the two act-on-it moments quote the recorded reason; the others withhold it.
- [ ] The request screen shows the register of notices actually sent for that file.

---

## 13. Audit log

- [ ] Creating and updating a request writes audit rows with the actor, and the update row
      records **only the changed attributes**, old and new.
- [ ] A no-op save writes nothing.
- [ ] Password fields are redacted on both sides.
- [ ] `migrate:fresh --seed` writes **no** audit rows — bootstrap data is not user activity.
- [ ] The viewer filters by user, action, model and date range.
- [ ] A client-supplied raw class string is rejected; the filter speaks short registry keys.
- [ ] As R06, export the audit log. As R01: 403.

---

## 14. Admin, backup and maintenance

- [ ] Departments, Users, Roles & Permissions, Settings and Templates all CRUD correctly as R08.
- [ ] A department with children or users cannot be deleted — only deactivated.
- [ ] Assigning a user a manager works from the Users screen and survives a reload. (This was
      decorative for a while: the field was in the form but not in the API contract.)
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

## 15. Localisation, RTL, theme and print

- [ ] Switch to Arabic: `dir="rtl"` is set on `<html>` and the whole layout mirrors — sidebar,
      tables, form labels, icons.
- [ ] No screen scrolls horizontally in either direction at a normal window width.
- [ ] Every visible string is translated; no raw locale keys (`meetings.foo.bar`) leak through.
- [ ] Arabic text entered in a form round-trips correctly through save and reload — no `????`.
- [ ] Switch the theme to dark: every screen remains readable. Check the dashboard bars, the
      audit-log action badges and the decision outcome pills specifically — hard-coded colours
      hide there.
- [ ] Set the OS to dark with the app on "system": it follows, with no light flash on first
      paint.
- [ ] A stored signature image is still legible in dark mode (it is dark ink on white, and its
      background is deliberately not themed).
- [ ] Print a decisions register and a guide article: the dark palette flattens to ink-on-white
      and the whole page prints, not just the first screenful.

---

## 16. Regression and sign-off

Re-derive the counts rather than trusting this document:

```bash
# stages / roles / screens / statuses
php artisan tinker --execute="echo App\Models\WorkflowStage::count().' '.App\Models\Role::count().' '.App\Models\Screen::count().' '.App\Models\RequestStatus::count();"
# checkbox parity between this file and its Arabic twin
grep -c '^- \[ \]' TEST_PLAN.md TEST_PLAN.ar.md
```

- [ ] The counts match §0's table (12 / 11 / 33 / 39).
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

---

## Appendix — reference data

**The twelve stages, in order:** `receive_from_municipality` · `direct_manager_review` ·
`administrative_routing` · `receive_and_register` · `requirements_check` · `reviewer_review` ·
`observations` · `forward_to_committee` · `receive_from_committee` · `approval_by_authority` ·
`local_governance_ministry` · `final_approval_archiving`.

**The five approval levels:** `requirements_check` (1) · `receive_from_committee` (2) ·
`approval_by_authority` (3) · `local_governance_ministry` (4) · `final_approval_archiving` (5).

**The numbering series (Appendix 15 / النموذج 05):** `PM-RCV/YYYY/000001` intake receipt ·
`PM-COM/YYYY/0001` request · `PM-MTG/YYYY/01` meeting · `PM-MIN/YYYY/01` minutes ·
`PM-DEC/YYYY/001` decision.

**The eleven roles:** R01 موظف · R02 المقرر · R03 رئيس اللجنة · R04 عضو اللجنة ·
R05 مدير إدارة الشؤون الإدارية · R06 وزارة الحكم المحلي · R07 المدير العام / العميد ·
R08 مدير النظام · R09 أمين سر اللجنة · R10 وكيل الديوان · R11 العضو القانوني.

**The five approval screens:** `reviewer_approval` · `committee_head_approval` ·
`admin_manager_approval` · `ministry_approval` · `final_approval`. (There were six until Stage 57
deleted `authority_approval` along with the `competent_authority` stage.)
