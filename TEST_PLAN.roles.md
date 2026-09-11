# Role test plan — Abu Saleem Transactions

A manual QA script organised **by the person doing the work**, not by feature. One section per
system role: what they should see, what they must be able to do, and — just as important — what
the system must refuse them.

> **Arabic version:** [TEST_PLAN.roles.ar.md](TEST_PLAN.roles.ar.md) — same content, and the copy
> to hand to an actual tester, since the UI is Arabic-first.
>
> **Companion:** [TEST_PLAN.md](TEST_PLAN.md) is the older feature-oriented script. It covers
> Stages 1–27 only and predates the `Transaction`→`Request` rename, the Track I diagram-alignment
> redesign (12 stages, not 14), roles R09–R11, and everything in Tracks J and K. Where the two
> disagree, **this file is current**.

**Why by role.** The permission matrix is 33 screens × 11 roles, and the approval chain is
single-role by design — each approval screen names exactly one role. Clicking through as the
System Admin proves almost nothing, because R08 holds all seven actions on all 33 screens and so
sees every approval queue at once, which is the exact opposite of what the matrix encodes. The
lifecycle also cannot be walked by one person: reaching `مكتمل ومغلق` needs at least nine
different people acting in order. This plan makes each of them do their part.

**Where the expected behaviour comes from.** The process standard is the pair of documents indexed
at `docs/employee-committee-lifecycle/README.md` — `دليل إجراءات لجنة شؤون الموظفين` (114 Articles
plus 78 appendices, cited below as **[D]**) and its companion 21-stage detailed flow (**[E]**).
Article and appendix citations in the expected-result lines point there.

**How to use it.** Work a role section top to bottom. Every step has a checkbox and an explicit
expected result. Record failures in §14 rather than fixing them mid-pass — a half-fixed system
invalidates every step after it. §1 is the relay that produces the data the other sections need,
so run it first.

---

## 0. Before you start

### 0.1 Bring the system up

```bash
# Backend (repo root)
composer install
php artisan migrate                          # must report nothing pending
php artisan db:seed                          # roles, departments, stages, transitions, screens, matrix
php artisan db:seed --class=TestUserSeeder   # the 15 accounts in 0.2

# Serve
composer dev                                 # or php artisan serve / Homestead at http://abusaleem.test

# Frontend (from frontend/)
npm install
npm run dev                                  # Vite at http://localhost:5173
```

- [ ] `php artisan migrate:status` shows every migration `Ran`, nothing pending.
- [ ] `GET /api/ping` returns `{"message":"pong", ...}`.
- [ ] The SPA loads and redirects to `/login`.
- [ ] **A queue worker is running** — `php artisan queue:work` in its own terminal.
      `QUEUE_CONNECTION` is `database`, so without a worker **no notification is ever delivered**
      and every step below that expects a bell badge will silently fail. This is the single most
      common false failure in a manual pass.

`TestUserSeeder` is deliberately **not** called from `DatabaseSeeder`: a production seed must not
mint fifteen known-password logins. It is idempotent, and re-running it **resets** any role you
changed by hand.

### 0.2 The 15 test accounts

Password for all of them: `password`. All have a phone set (needed for the SMS-channel check; the
phone **cannot** be set from the Users screen, only by this seeder).

When the API runs with `APP_ENV=local`, the login screen shows a one-click picker for exactly this
list — served by `GET /api/dev/test-users`, which 404s in every other environment.

| # | Email | Role(s) | Dept | What this account exists to prove |
|---|---|---|---|---|
| 1 | `r01.employee@abusaleem.test` | R01 Employee | ENG | Files requests and appeals. **Moves no request** — R01 owns exactly one transition (`submit`), and the system fires it for them |
| 2 | `r02.reviewer@abusaleem.test` | R02 Reviewer (المقرر) | REP | Stages 5–7 plus every backward exception; also **r01's assigned manager** (see the note below) |
| 3 | `r03.head@abusaleem.test` | R03 Committee Head | CMT | Stage 9 decisions, minutes approval, convening |
| 4 | `r04.member1@abusaleem.test` | R04 Committee Member | CMT | Voter |
| 5 | `r04.member2@abusaleem.test` | R04 Committee Member | CMT | Voter |
| 6 | `r04.member3@abusaleem.test` | R04 Committee Member | CMT | Voter — **the third seat is what makes a 2-1 plurality reachable**; a tie is refused outright |
| 7 | `r05.manager@abusaleem.test` | R05 Admin Manager | ADM | HR-route registration, stage 8, stage 10 |
| 8 | `r06.ministry@abusaleem.test` | R06 Ministry | ABS | Stage 11; holds `export` on reports, registers and the audit log |
| 9 | `r07.director@abusaleem.test` | R07 Director / Dean | ABS | Stage 12; **no intake access at all** |
| 10 | `r08.sysadmin@abusaleem.test` | R08 System Admin | ADM | Administration; keeps `admin@abusaleem.test` free as a spare |
| 11 | `multi.role@abusaleem.test` | R03 **+** R04 | CMT | Permissions must be a **union**, never an intersection |
| 12 | `inactive.user@abusaleem.test` | R01, `is_active = false` | FIN | Must be refused at login *and* refused as a workflow actor |
| 13 | `r09.secretary@abusaleem.test` | R09 Committee Secretary | CMT | Registration on the committee-secretary route; agenda preparation |
| 14 | `r10.diwan@abusaleem.test` | R10 Diwan Deputy | ABS | Registration on the Diwan route |
| 15 | `r11.legal@abusaleem.test` | R11 Legal Officer | CMT | The only role that may record [D] Art. 21's pre-meeting legal review |

> **The manager link matters.** `r01.employee@`'s `manager_id` points at `r02.reviewer@`. Stage 2
> (`مراجعة الطلب من المدير المباشر`) and stage 3 (routing) are gated on *"the actor is this
> submitter's own manager"*, **not** on a role — so r02 acts there as a manager, not as المقرر, and
> every other account except R08 (the documented fallback) must be refused. This is the one gate in
> the system that is not role-based; test it deliberately.

Pre-existing: `admin@abusaleem.test` / `password` (R08). Leave it alone, so you always have a
working admin if a test suspends account #10.

### 0.3 Resetting between passes

Full reset: `php artisan migrate:fresh --seed && php artisan db:seed --class=TestUserSeeder`. This
destroys every request, meeting, decision, appeal, audit row and backup. To restore only the
accounts and their roles, re-run `TestUserSeeder` alone.

### 0.4 The two enforcement layers — check both, every time

Every "must be refused" step below has two halves, and a pass needs both:

1. **The UI** — the Vue router guard and the `v-can` directive hide screens and buttons. This is
   **UX only**. A hidden button is not a secure button.
2. **The API** — `CheckScreenPermission` returns **403** on the screen grant, and `WorkflowService`
   independently re-checks the stage, the role and the actor's active status **under a row lock**,
   returning **422** for a workflow rule the caller may not use.

So *"as R04 the button is absent"* is half a test. The other half is *"and POSTing to that endpoint
with R04's bearer token returns 403 or 422"*. Wherever a step says **refused**, do both.

### 0.5 How to read a role section

Each of §2–§12 has the same four parts:

- **Identity** — who this role is in [D], and the one line that defines their authority.
- **A. What they must see** — sidebar contents and screen counts.
- **B. What they must be able to do** — numbered scenarios, each ending in a checkable result.
- **C. What they must be refused** — the segregation-of-duties checks. These matter more than B:
  a missing capability is an inconvenience, a missing refusal is a compliance failure.

---

## 1. The relay — one request, nine people

Run this first. It produces the request every later section needs, and it is the only way to see
that the hand-offs work. Do not shortcut a row by acting as R08: the whole point is that no single
person can walk this alone.

Sign in as the actor in each row, do the action, sign out. Record the request's `reference_number`
when it appears at step 5 — later sections call it **REQ-A**.

| # | Actor | Where | Action | Request lands at |
|---|---|---|---|---|
| 1 | R01 employee | استلام الطلب (`/requests/create`) | Fill and submit | Stage 2 `مراجعة الطلب من المدير المباشر`, status `قيد المراجعة` |
| 2 | R02 **as manager** | Request detail | `forward` | Stage 3 `إحالة الطلب لأحد المسارات الإدارية` |
| 3 | R02 **as manager** | Request detail | `route_to_hr` | Stage 4 `الاستلام والتسجيل`, status `موجّه إلى الموارد البشرية` |
| 4 | R05 manager | Request detail | `register` | Stage 5 `فحص استيفاء المتطلبات`, status `قيد المراجعة` |
| 5 | R02 reviewer | Request detail | Record the jurisdiction test **and** the intake gate, then `approve` with a signature | Stage 6 `مراجعة المقرر`, status `تم التسجيل` — **and the `PM-COM/YYYY/NNNN` reference number is granted here, not at intake** |
| 6 | R02 reviewer | Request detail | `forward` | Stage 7 `إبداء الملاحظات` |
| 7 | R02 reviewer | Request detail | `forward` | Stage 8 `تحويل الطلب للجنة`, status `جاهزة` |
| 8 | R05 manager | Request detail | `forward` | Stage 9 `استلام الطلب من اللجنة`, status `في الاجتماع` |
| 9 | R02 or R09 | المراجعة القانونية | Send the file to legal review | Status `تحت المراجعة القانونية` |
| 10 | **R11 legal** | المراجعة القانونية | Record the review, verdict `سليم قانونيًا وجاهز للعرض` | Status `جاهزة` |
| 11 | R03 or R09 | الطلبات المرشحة | Nominate | Status `مرشح للجنة` |
| 12 | R03 head | الاجتماعات | Create a committee (R03 as head + the three R04 members), schedule a meeting | — |
| 13 | R03 or R09 | جدول الأعمال | Add REQ-A to the agenda | — |
| 14 | R03 head | جاهزية الاجتماع | Convene | — |
| 15 | R03 + 3 × R04 | مباشرة الاجتماع | Mark attendance, complete [D] Art. 85's study sequence, cast votes | — |
| 16 | R03 head | مباشرة الاجتماع | Record the decision (`موافقة`) with all four [D] Appendix 27 parts | Stage 10 `اعتماد (حسب الصلاحيات)`, status `بانتظار اعتماد البلدية` |
| 17 | R03 + attendees | المحاضر | Generate, review/approve, then every present attendee signs | Minutes `معتمد` |
| 18 | R05 manager | اعتماد مدير الإدارة | `approve` with a signature | Stage 11 `وزارة الحكم المحلي`, status `بانتظار الاعتماد المركزي` |
| 19 | R06 ministry | اعتماد وزارة الحكم المحلي | `approve` with a signature | Stage 12 `الاعتماد النهائي والأرشفة`, status `معتمدة نهائياً` |
| 20 | R02 or R03 | Request detail | Record [D] Art. 103's soundness checklist | — |
| 21 | R07 director | الاعتماد النهائي | `approve` with a signature | Status `قيد التنفيذ` |
| 22 | R02 or R03 | المخرجات | Record execution and attach [D] Appendix 70 evidence | Status `منفذة` |
| 23 | R02 or R03 | Request detail / المخرجات | Close — [D] Appendix 47's twelve-point audit | Status `مكتمل ومغلق` |

- [ ] The relay completes and REQ-A reaches `مكتمل ومغلق`.
- [ ] **No step was performed by R08.** If you had to fall back to the admin to get past a step,
      that step is a defect — log it in §14 naming the role that should have been able to act.
- [ ] R01 received in-app notifications at several of these points, but not all 23: [D] Art. 101
      names twelve notifying moments, and the purely internal steps are deliberately silent.

**Branch worth running once.** Repeat the relay with a request whose type has a `decision_grade`
**below** its type's threshold. At step 18 the file must skip stage 11 entirely and land straight on
stage 12 with status `معتمدة نهائياً`, and R06 must then see nothing for it in their queue. This is
the ministry-bypass branch, and it is easy to break without noticing.

---
