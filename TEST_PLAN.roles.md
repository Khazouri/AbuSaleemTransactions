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

**Why by role.** The permission matrix is 35 screens × 12 roles, and the approval chain is
single-role by design — each approval screen names exactly one role. Clicking through as the
System Admin proves almost nothing, because R08 holds all seven actions on all 35 screens and so
sees every approval queue at once, which is the exact opposite of what the matrix encodes. The
lifecycle also cannot be walked by one person: reaching `مكتمل ومغلق` needs at least ten
different people acting in order. This plan makes each of them do their part.

**Where the expected behaviour comes from.** The process standard is the pair of documents indexed
at `docs/employee-committee-lifecycle/README.md` — `دليل إجراءات لجنة شؤون الموظفين` (114 Articles
plus 78 appendices, cited below as **[D]**) and its companion 21-stage detailed flow (**[E]**).
Article and appendix citations in the expected-result lines point there.

**How to use it.** Work a role section top to bottom. Every step has a checkbox and an explicit
expected result. Record failures in §15 rather than fixing them mid-pass — a half-fixed system
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
php artisan db:seed --class=TestUserSeeder   # the 16 accounts in 0.2

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
mint sixteen known-password logins. It is idempotent, and re-running it **resets** any role you
changed by hand.

### 0.2 The 16 test accounts

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
| 7 | `r05.manager@abusaleem.test` | R05 Admin Manager | ADM | Stage 10 approval only — Stage 87 moved the HR-route registration onto R12, and the stage-8 committee handover went to R09 (Stage 86) and then to R02 (Stage 96) |
| 8 | `r06.ministry@abusaleem.test` | R06 Ministry | ABS | Stage 11; holds `export` on reports, registers and the audit log |
| 9 | `r07.director@abusaleem.test` | R07 Director / Dean | ABS | Stage 12; **no intake access at all** |
| 10 | `r08.sysadmin@abusaleem.test` | R08 System Admin | ADM | Administration; keeps `admin@abusaleem.test` free as a spare |
| 11 | `multi.role@abusaleem.test` | R03 **+** R04 | CMT | Permissions must be a **union**, never an intersection |
| 12 | `inactive.user@abusaleem.test` | R01, `is_active = false` | FIN | Must be refused at login *and* refused as a workflow actor |
| 13 | `r09.secretary@abusaleem.test` | R09 Committee Secretary | CMT | **Stage 96 — a retained login with no seeded duty.** Everything it held went back to R02 |
| 14 | `r10.diwan@abusaleem.test` | R10 Diwan Deputy | ABS | **Stage 96 — a retained login with no seeded duty.** The Diwan route is retired |
| 15 | `r11.legal@abusaleem.test` | R11 Legal Officer | CMT | The only role that may record [D] Art. 21's pre-meeting legal review |
| 16 | `r12.hr@abusaleem.test` | R12 HR Manager | HR | Registration on the HR route (replaces r05.manager@ there); a bounded, non-controlling reach into stage 7 (`observations`) |

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

Each of §2–§13 has the same four parts:

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
| 4 | R12 HR manager | Request detail | `register` | Stage 5 `فحص استيفاء المتطلبات`, status `قيد المراجعة` — delivered to be checked, **no reference number yet** |
| 5 | R02 reviewer | Request detail | Record the jurisdiction test **and** the intake gate, then `approve` with a signature | Stage 6 `مراجعة المقرر`, status `تم التسجيل` — **and the `PM-COM/YYYY/NNNN` reference number is granted here, not at intake** |
| 6 | R02 reviewer | Request detail | `forward` | Stage 7 `إبداء الملاحظات` |
| 7 | R02 reviewer | Request detail | `forward` | Stage 8 `تحويل الطلب للجنة`, status `جاهزة` |
| 8 | R02 reviewer | Request detail | `forward` | Stage 9 `استلام الطلب من اللجنة`, status `في الاجتماع` — Stage 96 returned this hop to المقرر |
| 9 | R02 reviewer | المراجعة القانونية | Send the file to legal review | Status `تحت المراجعة القانونية` |
| 10 | **R11 legal** | المراجعة القانونية | Record the review, verdict `سليم قانونيًا وجاهز للعرض` | Status `جاهزة` |
| 11 | R02 or R03 | الطلبات المرشحة | Nominate | Status `مرشح للجنة` |
| 12 | R03 head | الاجتماعات | Create a committee (R03 as head + the three R04 members), schedule a meeting | — |
| 13 | R02 or R03 | جدول الأعمال | Add REQ-A to the agenda | — |
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
      that step is a defect — log it in §15 naming the role that should have been able to act.
- [ ] R01 received in-app notifications at several of these points, but not all 23: [D] Art. 101
      names twelve notifying moments, and the purely internal steps are deliberately silent.

**Branch worth running once.** Repeat the relay with a request whose type has a `decision_grade`
**below** its type's threshold. At step 18 the file must skip stage 11 entirely and land straight on
stage 12 with status `معتمدة نهائياً`, and R06 must then see nothing for it in their queue. This is
the ministry-bypass branch, and it is easy to break without noticing.

---

## 2. R01 — الموظف / Employee

**Identity.** [D] Art. 9 (ب)'s الموظف صاحب الطلب. They start the file and they are its audience;
they never move it. R01 owns exactly one workflow transition (`submit`), and the system fires that
one for them at intake — so a correct R01 session has **no workflow buttons anywhere**.

Sign in as `r01.employee@abusaleem.test`.

### A. What they must see

- [ ] **20 sidebar entries** (22 screens from `GET /api/screens`; `تفاصيل الطلب` and
      `الملاحظات والمرفقات` are returned but hidden from the menu because they need a request id).
- [ ] **No approval screen at all.**
- [ ] No `المستخدمون`, `الإدارات والأقسام`, `الأدوار والصلاحيات`, `الإعدادات العامة`,
      `القوالب والنماذج`, `النسخ الاحتياطي` or `الصيانة والنشر`.
- [ ] The request list shows **only requests they created**. Note another employee's request id
      from the relay and open `/requests/{that id}` directly → **404**, not an empty page.

### B. What they must be able to do

**B1 — File a request ([D] Arts. 15–16).**

- [ ] استلام الطلب shows the type picker; choosing a type reveals the **required-documents
      checklist** in two groups — `مستندات أساسية مشتركة` and `مستندات خاصة بالنوع` ([D] Appendix
      57). Conditional items carry their own qualifier ("بحسب الموضوع", "عند الحاجة").
- [ ] Every attachment upload **requires a file section** ([D] Appendix 14's thirteen folders).
      Submitting an attachment without one is refused — "يمنع حفظ الملفات بصورة عشوائية دون تصنيف".
- [ ] On success the screen shows an **intake receipt** `PM-RCV/YYYY/NNNNNN` and states in as many
      words that this is a receipt and **not** a قيد with the committee.
- [ ] The new request's `reference_number` is **null** — no `PM-COM/...` number yet. [D] Art. 15 is
      explicit that handing a request to the direct manager "لا يعد قيدًا", and Art. 20 grants the
      رقم إشاري only after completeness is established (relay step 5), when the submitter receives a
      `reference_assigned` notice naming the superseded receipt and the new reference.
- [ ] The request appears at stage 2 `مراجعة الطلب من المدير المباشر`, not stage 1.

**B2 — The duplication rule ([D] Appendix 16).**

- [ ] With REQ-A still open, start a **second request of the same type**. The intake screen warns
      before you fill the form, and submitting is **refused**, naming the open file to attach the
      documents to instead — "لا تنشأ معاملة جديدة، بل تلحق المستندات بالمعاملة القائمة".
- [ ] A request of a **different type** is accepted while REQ-A is open.
- [ ] Once every prior file of that type is closed, filing again **requires a classification**. The
      picker greys out `تظلم` and `إعادة عرض`; choosing either is refused and points at the real
      route (an appeal, or re-presentation on the existing file). `واقعة جديدة` and
      `استكمال لقرار سابق` are accepted.

**B3 — Follow their own file.**

- [ ] The detail screen shows `المسؤول الحالي` and `الإجراء التالي المطلوب` ([D] Appendices 17/18)
      and **neither is ever empty**, at any stage, including right after submission.
- [ ] Both fields also appear as columns in the request list.
- [ ] The timeline shows each step with its date, actor, الجهة and any linked document
      ([D] Art. 100).
- [ ] The time card ([D] Appendix 71) shows T1–T10; segments that have not both happened read as
      empty, **not** as zero.
- [ ] The stage-timeliness indicator shows `ضمن المدة` / `قرب تجاوز المدة` / `متأخرة` /
      `تأخير حرج` — never a raw number with no label.
- [ ] Attachments preview in-browser for PNG/JPG/PDF; DOC/DOCX download.
- [ ] Notes can be added and read.

**B4 — Notices ([D] Art. 101).**

- [ ] After the relay, the detail screen's notices card lists the moments the employee was notified
      (استلام في المسار الرسمي، إدراج بجدول الأعمال، صدور النتيجة، بدء التنفيذ، الإقفال …), each
      with its moment number.
- [ ] The bell badge in the topbar matches the unread count; opening the dropdown clears it.
- [ ] **No notice contains the vote tally.** [D] Art. 102 excludes مداولات اللجنة and كيفية تصويت كل
      عضو from anything sent to صاحب العلاقة — the result notice quotes
      "للأسباب المثبتة في القرار المعتمد" and stops. Committee members get the tally; the employee
      must not.
- [ ] Notification preferences (in-app / email / SMS per event) save and are honoured — mute an
      event, trigger it, confirm nothing arrives.

**B5 — Withdraw a request ([D] Appendix 68).**

- [ ] On a request they created, filing a written withdrawal succeeds.
- [ ] Attempting the same on **someone else's** request is refused — the endpoint requires the
      actor to be the request's own creator, not merely someone with the grant.
- [ ] R01 **cannot determine** the outcome of their own withdrawal; that is the rapporteur's call
      (§3 B7).

**B6 — Appeal a decided request ([D] Arts. 75–79).**

- [ ] التظلمات lists only their own appeals.
- [ ] Filing an appeal against a **decided** request succeeds and captures تاريخ العلم به،
      أسباب الاعتراض، الطلب النهائي, plus supporting documents uploaded as follow-up.
- [ ] Filing against a request that is **not yet decided** is refused.
- [ ] Filing against **another employee's** request is refused.
- [ ] Filing a **second** appeal on the same request is refused **unless** a new-facts declaration
      is supplied ([D] Art. 75 pt 2).
- [ ] The appeal's `القرار محل التظلم` is filled in automatically from the request's latest
      decision — it is not typed by the appellant, and a value supplied by hand is ignored.

### C. What they must be refused

- [ ] Every approval queue: the screen is absent, and `GET /api/approvals/reviewer` with R01's token
      → **403**.
- [ ] `GET /api/users`, `/api/departments`, `/api/roles-permissions`, `/api/settings`,
      `/api/templates`, `/api/backups`, `/api/maintenance` → **403** each.
- [ ] The request detail screen offers **no workflow action buttons** on their own request at stage
      2 — the direct manager acts there, not the submitter.
- [ ] Exporting: `GET /api/reports/requests/export` and `/api/registers/{code}/export` → **403**
      (export is R06/R07 only). The reports and registers screens themselves are readable.
- [ ] `POST /api/legal-reviews` (recording a legal review) → **403**.
- [ ] `PATCH /api/requests/{id}/close` → **403**.
- [ ] Opening another employee's appeal, or previewing its attachment → **404**.

> **Resolved by Stage 84 — this is now a negative check, not an open question.** R01 used to hold
> `notes_attachments,edit`, and both the [D] Art. 45 jurisdiction test
> (`PATCH /requests/{id}/jurisdiction-test`) and the [D] Appendix 63 intake gate
> (`PATCH /requests/{id}/intake-gate`) rode it with no further check — so an employee could answer
> the completeness attestation that decides whether their own file may be registered. Three sources
> say otherwise: Appendix 45's صلاحية الموظف has no editing capability at all, Appendix 6's RACI
> leaves the الموظف column empty for both فحص اكتمال ملف اللجنة and القيد, and Appendix 19 forbids
> مقدم الطلب from being معتمد الطلب. Two layers now enforce it, and both are worth testing:
>
> - [ ] `PATCH /requests/{id}/jurisdiction-test` and `.../intake-gate` as **R01 on their own
>       request** → **403** (the grant is gone). `PATCH /requests/{id}/financial-impact` → **403**
>       too; that is the same grant and a deliberate consequence, not an oversight.
> - [ ] The same two as an account holding **R02 that created the request** → **422**, quoting
>       «لا يجوز لمقدّم الطلب…». This second layer catches an officer filing on someone's behalf,
>       whom the permission alone would let through.
> - [ ] As an **R02 who did not create it** → **200**, and the recorded card now names them:
>       `control_gates.intake.recorded_by` and `control_gates.intake.jurisdiction_test.recorded_by`
>       are both populated on screen.

---

## 3. R02 — المقرر / Reviewer

**Identity.** [D] Art. 13 (ب)'s مقرر لجنة شؤون الموظفين — the busiest role in the system. R02 owns
the whole pre-committee stretch (stages 5–7), every backward exception, the post-decision registers
(referral, return, execution, closure), and the appeal's formal and legal review. In this seed r02
is **also** `r01.employee@`'s direct manager, so they appear twice in the relay wearing different
hats; keep the two apart when reading results.

Sign in as `r02.reviewer@abusaleem.test`.

### A. What they must see

- [ ] **21 sidebar entries** (23 screens from the API).
- [ ] Exactly one approval screen: `اعتماد المقرر`. No other approval queue is visible, and
      `GET /api/approvals/ministry` → **403**.
- [ ] `المراجعة القانونية` is visible (they dispatch to it) but recording a review is refused —
      see C.

### B. What they must be able to do

**B1 — Act as the direct manager (stages 2–3).**

- [ ] On REQ-A at stage 2, `forward` is offered and works → stage 3.
- [ ] `return_to_employee` requires a reason; submitting without one is refused and **no status
      changes** ([D] Art. 10 — no withholding without a written reason).
- [ ] At stage 3 all three routes are offered — `route_to_hr`, `route_to_diwan`,
      `route_to_committee_secretary` — and the request type's **suggested** route is badged on one
      of them. The badge is advisory: the other two are still clickable and all three work.
- [ ] Sign in as any other non-admin account and try `forward` on the same request at stage 2 →
      **refused**. Only the submitter's own manager (or R08) may act there.

**B2 — Requirements check (stage 5) — the قيد gate.**

- [ ] Before anything is recorded, `approve`, `declare_no_jurisdiction` and `reject_formally` are
      **all refused**, and the buttons are not offered.
- [ ] `return_missing_docs` **is** available even then — a file with missing documents cannot
      honestly be classified yet, so [D] Art. 19's استكمال loop stays open.
- [ ] Record [D] Art. 45's **six-question jurisdiction test**; a partial answer set is refused —
      the classification is not final until all six are answered.
- [ ] Record the **intake gate** ([D] Appendix 63 بوابة 1): one answer per seeded required document
      plus the facts attestation. An unanswered item or a `مفقود` answer is refused by name. An item
      the source marks conditional may be answered `لا ينطبق`; an unconditional one may **not** —
      try waiving one and confirm it is refused naming the document.
- [ ] With both recorded, `approve` **with a signature** succeeds → stage 6, status `تم التسجيل`,
      and the request is granted `PM-COM/YYYY/NNNN` — the قيد, which is المقرر's own act.
- [ ] `approve` **without** a signature is refused (approval level 1 requires one).
- [ ] Send the file back with `return_missing_docs`, then walk it forward again through the whole
      chain: on the second pass through stage 5 the reference number is **unchanged** — [D] Art. 99
      gives one number for the file's whole life.

**B3 — The two non-registration outcomes at stage 5.**

- [ ] `declare_no_jurisdiction` (self-loop) requires a reason → status `عدم اختصاص`.
- [ ] `reject_formally` (self-loop) requires a reason → status `مرفوضة`.
- [ ] Neither mints a reference number.

**B4 — Stages 6 and 7.**

- [ ] Stage 6: `forward` → stage 7. `reject_review` (reason required) → back to stage 5.
- [ ] Stage 7: `forward` → stage 8, status `جاهزة`. `request_edit` (reason required) → back to
      stage 6.
- [ ] `cancel` is available at every open stage they own and always requires a reason.

**B5 — Dispatch to legal review ([D] Art. 21).**

- [ ] From المراجعة القانونية or the request detail, send a stage-9 file to legal review → status
      `تحت المراجعة القانونية`.
- [ ] Attempting to **record** the review itself → **403**. Dispatching is the coordinating act
      ([D] Appendix 6's RACI: العضو القانوني is مسؤول, المقرر only منسق).

**B6 — The post-decision registers (all on `meeting_outputs,edit`).**

- [ ] **Approval referral** ([D] Art. 30): record the outward leg (تاريخ الإحالة، رقم كتاب الإحالة،
      الجهة المحال إليها), then later the result (تاريخ ورود النتيجة، رقم قرار الاعتماد، الملاحظات).
      رقم قرار الاعتماد is required only when the outcome is an approval.
- [ ] **Return from the approving body** ([D] Art. 94): record a return with its kind and reason. A
      reason code the source classes as شكلية cannot be recorded as موضوعية — try the mismatch and
      confirm it is refused quoting the correct classification.
- [ ] While a return is open, the **next approver is blocked** — have R05 attempt `approve` through
      both the approval queue and the request detail; both must be refused with no approval row
      written.
- [ ] Resolving a **شكلية** return re-refers to the same body without moving the stage; resolving a
      **موضوعية** return sends the file back to stage 9 with status `أعيد فتحه لإعادة العرض`, and
      **the earlier decision row is still there, unchanged** ([D] Art. 94 — the approved محضر is
      never quietly edited).
- [ ] A second round accumulates: the first return is still readable after the second is recorded.

**B7 — Art. 103, 105, execution and closure.**

> **Stage 84 gave R02 the committee-preparation work it had never held.** [D] Appendix 45's
> صلاحية المقرر is "القيد · الفحص · **إنشاء الاجتماع** · **إدارة جدول الأعمال** · تسجيل النتيجة ·
> المحاضر · المتابعة", and R02 held none of the four in bold. Walk each:

- [ ] **Schedule a meeting** for a committee this account sits on, and **send invitations**, add
      attendees and mark attendance ([D] Art. 15 (أ) أولًا 12-13، ثانيًا 1).
- [ ] Scheduling for a committee this account does **not** sit on → **422**, naming `committee_id`.
      An R08 account may still do it.
- [ ] **Build the agenda**: add, reorder, apply [D] Art. 83's computed order, and remove items.
- [ ] **Generate and edit a presentation memo** ([D] Art. 22) — the derived fields recompute while
      the authored ones survive a regenerate.
- [ ] **Nominate a candidate request**, and **request completion** on one ([D] Art. 15 (أ) أولًا 6).
- [ ] **Generate the محضر draft** ([D] Art. 15 (أ) ثانيًا 9 / ثالثًا 1).
- [ ] **Record a decision** once the vote has resolved ([D] Art. 15 (أ) ثانيًا 6 — تسجيل نتيجة
      التصويت). The outcome is computed from the votes, so confirm it cannot be overridden.
- [ ] Post to the live discussion feed ([D] Art. 15 (أ) ثانيًا 5 — تدوين المناقشات).

- [ ] **Soundness checklist** ([D] Art. 103): record it before R07 may refer to execution. Eight of
      the twelve are **derived from real state** — submit the payload with `minutes_signed: "no"`
      and confirm it comes back `نعم` from the record, not from what you sent.
- [ ] R07's `approve` at stage 12 is refused until the checklist is recorded, and refused again if
      any attested answer is `لا`.
- [ ] **Suspension** ([D] Art. 105): suspend a file in the approval window. The **status** changes
      to `موقوفة لمراجعة قانونية` and the **stage does not** — confirm the timeline gains no stage
      entry. `approve` is then refused everywhere.
- [ ] Lifting the suspension is **refused until R11 records a legal review dated after it** — a
      review from before the doubt was raised does not count.
- [ ] **Execution** ([D] Appendix 70): a bare "تم التنفيذ" is refused — "لا يكفي أن تقول الجهة
      المنفذة (تم التنفيذ)؛ يجب إرفاق دليل التنفيذ". Nominating one of the request's own attachments
      as typed evidence lets it through; nominating an attachment belonging to a **different**
      request is refused.
- [ ] A request flagged with a financial impact cannot be executed until the financial-referral
      check is `نعم` ([D] Art. 97).
- [ ] **Closure** ([D] Appendix 47/48): a `deferred` request is refused with the appendix's own
      words. A single `لا` on the twelve-point audit refuses and quotes the failed question back.
      `لا ينطبق` is accepted where the path genuinely has no such step.
- [ ] Closure works from all three of [D] Art. 37's final paths — `منفذة`, `غير موافق عليها`, and
      `عدم اختصاص` — including a pre-committee عدم اختصاص that never rode an agenda.
- [ ] **Visibility:** R02 can open a `معتمدة نهائياً` / `قيد التنفيذ` / closable request **they did
      not create**. This is the check that the closure and execution screens are usable at all.

**B8 — Lifecycle records ([D] Appendices 30/31/53/60/68).**

- [ ] Record a **document conflict**; agenda insertion for that request is then refused —
      "فلا تعرض المعاملة قبل معالجة التعارض". Resolving requires all three fields (الجهة المخاطبة،
      المستند المعتمد، التصحيح) — an empty resolve returns all three refusals at once.
- [ ] Record **document validity** ([D] Appendix 31): only the two checks the source qualifies may
      be answered غير منطبق; waiving الجهة المصدرة is refused by name. A `لا` records a finding and
      **blocks nothing**.
- [ ] Record a **correction memo** ([D] Appendix 53): a substantive kind is refused and points at
      the reopen route; a material one is recorded, and **approval is refused to its own author**
      ([D] Appendix 19's separation rule). The original decision's title and reference are untouched.
- [ ] Record a **special case** ([D] Appendix 60): وفاة and انتهاء الخدمة block closure until the
      legal effect is determined; النقل and فقدان مستند block nothing.
- [ ] **Determine a withdrawal**: before any decision exists it may be granted (file closes as
      `ملغاة` with the reason quoted into the history); once a decision exists `granted` is not
      offered at all and only `تثبيت فقط` remains, which changes no status ([D] Appendix 69 — the
      decision is neither deleted nor erased).

**B9 — Appeals (Track J).**

- [ ] Appeals filed by **other people** are visible (R02 holds `appeals,edit`), unlike R01 who sees
      only their own.
- [ ] **Formal verification**: record صفة المتظلم / القرار محل التظلم / عدم التكرار; a failing
      verdict requires a reason and closes the appeal as `مرفوض شكلياً`. Verifying twice is refused.
- [ ] Verifying **their own** appeal is refused (self-action block).
- [ ] **Jurisdiction test** ([D] Art. 77): answering anything other than "the committee is
      competent" terminates the appeal as `عدم اختصاص` — test the disciplinary-board answer and one
      other.
- [ ] **Legal review checklist** ([D] Art. 75 pt 4): all five questions required together; recording
      moves the appeal to `المراجعة القانونية`, which is the state Stage 63 requires before it can
      be nominated.
- [ ] **Execute the outcome** after the committee decides, and **close** the appeal — closing
      releases the hold that was keeping the original request from closing.
- [ ] **Reopen** a closed appeal, or re-present a concluded request — both require one of the
      enumerated reasons; a plain "I disagree" is refused by validation.

### C. What they must be refused

- [ ] Any approval queue other than `اعتماد المقرر` → **403**.
- [ ] Recording a legal review (`POST /api/legal-reviews`) → **403** (R11 only).
- [ ] **Casting a vote**, or declaring a conflict of interest → **403** (`decisions,add` is R03 +
      R04). This is the sharp edge of Stage 84 and the one not to "tidy": R02 may *record* the
      tallied result but never *vote* on it — [D] Art. 16 (أ) 2 forbids المقرر voting unless قرار
      التشكيل says otherwise, which is the committee record's own `rapporteur_votes` flag, not a
      permission. R02 recording a decision and R02 voting must stay on opposite sides of this line.
- [ ] **Deferring** a candidate, or returning one to study → **422**, not 403: R02 passes the screen
      permission and `WorkflowService`'s own R03 role on those transitions refuses. Two independent
      layers, and the 422 is what proves the second one is real.
- [ ] Approving minutes → **403** (`meeting_minutes,approve` is R03 only — [D] Art. 12 (أ) 13).
      Convening a meeting → **403** ([D] Art. 12 (أ) 1, 5). Advancing an item's state in the live
      runner → **403** ([D] Art. 12 (أ) 9-10).
- [ ] Every administration screen → **403**.
- [ ] Approving a request **they created themselves** → refused, at every entry point.

---

## 4. R03 — رئيس اللجنة / Committee Head

**Identity.** [D] Art. 10 (أ)'s رئيس اللجنة. Everything in the sitting is theirs: convening it,
running it, recording the binding decision, and approving the محضر. R03 is also the one role that
can override a readiness exception, so its refusals are the ones that matter most.

Sign in as `r03.head@abusaleem.test`.

### A. What they must see

- [ ] **21 sidebar entries** (23 screens), including the whole `إدارة الاجتماعات` group.
- [ ] Exactly one approval screen: `اعتماد رئيس اللجنة`.

### B. What they must be able to do

**B1 — Committees.**

- [ ] Create a committee, add the three R04 members plus themselves as head, and set the five named
      seats ([D] Art. 10's roster: رئيس، قانوني، مدير الموارد البشرية، مندوب الخدمة المدنية، مقرر).
- [ ] Assigning `chair` forces the head flag. Two members cannot hold the same seat — try it and
      confirm the refusal. Several members with **no** seat is fine.
- [ ] Record the committee's **identity card** ([D] Appendix 65): formation decision number and
      date, legal basis, minutes-approval body, and the quorum / majority / tie-break rules **in the
      text's own words alongside the structured form**.
- [ ] **With no rules recorded**, readiness reports the quorum as `غير مثبت` and blocks convening
      with `قواعد اللجنة غير مثبتة`. This is deliberate: [D] Appendix 64 forbids the system
      inventing a quorum — "ولا يجوز للدليل إنشاء نسبة نصاب أو أغلبية من تلقاء نفسه". A committee
      showing a computed quorum it was never given is a **defect**.
- [ ] Record `أكثر من نصف الأعضاء` on a four-member committee → required quorum is **3**, not 2.
      (`لا يقل عن نصف` on the same committee is 2. The comparator is what distinguishes them.)
- [ ] Deleting a committee that has meetings is refused; deactivate instead.

**B2 — Scheduling ([D] Arts. 23–24).**

- [ ] The five-step wizard runs: requests → details → members/invitations → review/agenda →
      approve. Only the last step writes anything.
- [ ] The meeting number is **assigned by the system** as `PM-MTG/YYYY/NN` — the field is not
      typed. Post a `meeting_number` by hand and confirm it is ignored.
- [ ] Committee members are auto-invited; extra invitees can be added.
- [ ] Invitations are sent, and each attendee's RSVP (`مؤكد` / `معتذر` / `لم يرد`) can be recorded,
      stamping the response time.

**B3 — The agenda ([D] Arts. 83–85, Appendices 24/25).**

- [ ] Add employee-request items, administrative items (which need a subject, having no request) and
      appeal items.
- [ ] A request whose legal review has **not** passed cannot be added — confirm the refusal.
- [ ] A request with an **unresolved document conflict** cannot be added.
- [ ] Declaring an item `أولوية عالية` requires **both** one of [D] Appendix 33's five grounds and
      a written justification — "لا تعتبر المعاملة مستعجلة لمجرد طلب صاحبها ذلك". Clearing the
      ground while leaving the level high must also be refused.
- [ ] Drag-and-drop reorder works and persists.
- [ ] `تطبيق الترتيب` rewrites the agenda into [D] Art. 83's computed order — deferred items first,
      then legal-deadline items, then urgent, then ready by readiness date.
- [ ] Leaving the agenda in a **different** order raises the readiness exception
      `الترتيب يخالف القاعدة` until a justification is written ([D] Appendix 24 — "ولا يجوز استخدام
      الأولوية لتجاوز ترتيب المعاملات دون مبرر إداري موثق").

**B4 — Readiness and convening ([D] Art. 84).**

- [ ] جاهزية الاجتماع shows the four percentages, the quorum, and an **exceptions-only** list.
- [ ] Convening is **blocked** while any exception stands.
- [ ] R03 may convene anyway **with a written reason** — this is the override, and it must be
      recorded, not silent.
- [ ] Convening **freezes** the committee's voting rules onto the meeting: edit the committee's
      quorum afterwards and confirm the held meeting still reports the rule it was convened under.
- [ ] **Adopt the agenda** ([D] Art. 84, Appendix 6 row 8) — refused on an empty agenda, one-shot,
      and **403** for every other role. Before adoption, advancing an item or ticking the study card
      is refused; after it, adding a non-emerging item, removing one, or reordering is refused, while
      an **emerging** item may still be added.

**B5 — Running the sitting ([D] Art. 85, Appendix 25).**

- [ ] Mark attendance. Advance an item through `معروض → مناقشة → تصويت → اتخاذ القرار → مكتمل`.
- [ ] The nine-step **study card** is enforced **as a sequence**: marking a step before its
      predecessor is refused, naming the missing step.
- [ ] **Voting is refused until the sequence completes** — and the members' "awaiting my vote"
      worklist must be **empty** at the same moment. The screen and the endpoint must never disagree.
- [ ] Once a vote exists, un-ticking a step is refused, and **attachments and the presentation memo
      are frozen** for that request ([D] Appendix 25 — "ولا يجوز استمرار تعديل الوقائع أو المستندات
      بعد بدء التصويت"). Confirm the same upload succeeds on a different, unvoted request.

**B6 — Recording the decision.**

- [ ] With three members voting 2-1, the decision records the majority outcome.
- [ ] A **tie** is refused and **no decision row is written**. A zero-vote item is refused too.
- [ ] Every decision requires [D] Appendix 27's four parts (الموضوع، الوقائع، السند، المنطوق) and
      [D] Art. 90's instrument (قرار / توصية / رأي) — the instrument is pre-filled from the legal
      card but the recorder may change it.
- [ ] A منطوق of "اتخاذ اللازم." alone is refused; "اتخاذ اللازم نحو إحالة الملف إلى الإدارة
      القانونية خلال أسبوع." is accepted. Same for "لعدم الاستحقاق" as the entire reasoning.
- [ ] A **deferral** requires [D] Art. 34's four mandatory fields; "تأجيل للمراجعة" with nothing
      stated is refused ([D] Appendix 29).
- [ ] A **refusal** requires one of [D] Appendix 28's reason codes plus real substantiation.
- [ ] `عدم اختصاص` and `إعادة للدراسة` are available as committee outcomes and land on their own
      statuses.
- [ ] The seven [D] Appendix 59 decision formulas are offered as drafting templates, and a draft
      pulls the request's real reference number into the text rather than leaving dots.

**B7 — Minutes ([D] Art. 28, Appendix 8).**

- [ ] Generate the محضر; it carries the meeting, attendance with each attendee's seat, the quorum
      rule applied, and per item: the facts summary, documents reviewed, legal basis, the vote
      tally, the decision, and any dissenting opinion with its reason.
- [ ] **Approving the محضر runs [D] Appendix 8's sixteen checks.** Generate it, then change the
      agenda, then try to approve → **refused**, because the frozen snapshot no longer matches the
      sitting. Regenerating clears it. This is the check that catches an approved محضر describing a
      meeting that did not happen.
- [ ] A meeting with **no recorded attendance** cannot have its محضر approved — attendance proves
      neither حضور nor صحة انعقاد.
- [ ] After approval, one signature row exists per present attendee; the last signature flips the
      محضر to `معتمد`.
- [ ] **The meeting cannot be closed** while any agenda item is unresolved, or while the محضر is
      not `معتمد`.

**B8 — Also available to R03**

- [ ] Everything in §3 B6–B8 (registers, execution, closure, lifecycle records) — R03 shares
      `meeting_outputs,edit` with R02.
- [ ] Nominate, defer, return-to-study and request-completion on committee candidates.

### C. What they must be refused

- [ ] Any approval queue other than `اعتماد رئيس اللجنة` → **403**.
- [ ] Recording a legal review → **403** (R11 only).
- [ ] Every administration screen → **403**.
- [ ] Verifying or deciding an **appeal's** formal stage → **403** (`appeals,edit` is R02 + R08;
      R03's role in an appeal is the committee vote and decision, not its administration).
- [ ] Approving a request they created themselves → refused.
- [ ] Voting on an item where they have **declared a conflict of interest** → refused, and they are
      also blocked from that item's discussion feed.

---

## 5. R04 — عضو اللجنة / Committee Member

**Identity.** [D] Art. 12 (أ)'s أعضاء اللجنة. They deliberate and vote. The interesting thing about
R04 is how narrow it is next to R03: same screens, far fewer verbs — so most of this section is §C.

Sign in as `r04.member1@abusaleem.test`.

### A. What they must see

- [ ] **20 sidebar entries** (22 screens) — the meetings group is fully visible.
- [ ] **No approval screen.**

### B. What they must be able to do

- [ ] Open a meeting and its agenda, and read any request on the agenda **even though they did not
      create it and hold no workflow role on it** — the live runner's quick-info tabs (summary,
      employee, study, attachments, previous requests, notes) must all load, and an attachment must
      stream rather than 404.
- [ ] Post discussion notes during a sitting.
- [ ] **Cast a vote**, but only when all of these hold: they are a member of that meeting's
      committee, they are marked as having **attended**, they have not declared a conflict, and the
      study sequence is complete.
- [ ] Declare a **conflict of interest** on an agenda item. Afterwards voting **and** the discussion
      feed are both refused for that item, and the declaration appears in the compiled محضر.
- [ ] Generate the محضر draft, and **sign** their own signature row.
- [ ] Post to the live discussion feed on an agenda item.

### C. What they must be refused

These are the segregation-of-duties checks; do both halves of each.

> **Stage 84 moved four of these out of section B.** [D] Art. 13 (أ) gives a committee member
> studying, discussing and voting, and Appendix 45's صلاحية أعضاء اللجنة is "الاطلاع على الملفات ·
> الاطلاع على جدول الأعمال · تسجيل الحضور · المشاركة في الاجتماع" — no convening, no agenda, no
> memo. R04 previously held all four. If any of the next four checks passes, the matrix has drifted
> back.

- [ ] **Create a committee, or schedule a meeting** → refused (`meetings,add` is R02 + R03).
      الدعوة is the chair's under Art. 12 (أ) 1 and إنشاء الاجتماع is المقرر's under Appendix 45.
- [ ] **Nominate a candidate request**, or defer / return-to-study / request-completion it →
      refused (`committee_candidates` is R02 + R03 on both tiers).
- [ ] **Generate or edit a presentation memo** → refused. [D] Appendix 6's RACI makes إعداد مذكرة
      العرض مقرر اللجنة's own responsibility; R04 held this only because Stage 46 read "a member
      acting as مقرر" into the grant.
- [ ] **Add, reorder or remove an agenda item** → refused (`meeting_agenda` is R02 + R03).
- [ ] **Add committee members**, add attendees, mark attendance, send invitations, or edit a
      meeting → refused (`meetings,edit` is R02 + R03).
- [ ] **Convene a meeting** → refused (`meeting_readiness,edit` is R03 only).
- [ ] **Advance an item's state** in the live runner → refused (`meeting_live,edit` is R03 only).
- [ ] **Record a decision** → refused (`decisions,approve` is R03 only). Voting is `decisions,add`
      and is allowed; recording the tallied outcome is not.
- [ ] **Approve the محضر** → refused (`meeting_minutes,approve` is R03 only). Generating and signing
      are allowed.
- [ ] **Close or execute a request**, record an approval return, a suspension, or any lifecycle
      record → refused (`meeting_outputs,edit` is R02 + R03).
- [ ] **Defer, return-to-study or request-completion** on a candidate → refused
      (`committee_candidates,edit` is R02 + R03). Nominating is allowed.
- [ ] Record a legal review → **403**.
- [ ] Every approval queue → **403**.
- [ ] Every administration screen → **403**.
- [ ] Vote on an item in a meeting whose committee they are **not** a member of → refused.
- [ ] Vote when marked **absent** → refused.
- [ ] Vote **after** the decision has been recorded → refused.

---

## 6. R05 — مدير إدارة الشؤون الإدارية / Admin Manager

**Identity.** The administrative-authority approver at stage 10 (`اعتماد (حسب الصلاحيات)`), and
**only** that, since Stage 87. Before it, R05 also registered the HR route (stage 4) and handed the
file to the committee (stage 8) — both moved off this role: stage 8 to R09 (أمين سر اللجنة) at
Stage 86 and on to R02 at Stage 96, and stage 4 to R12 (مدير إدارة الموارد البشرية) at Stage 87, once [F]'s own إدارة الموارد
البشرية mentions were traced to a role of that name rather than left conflated with this one. If a
build older than Stage 87 is under test, R05 will still hold the stage-4 registration — check
`WorkflowTransitionSeeder` before assuming this section describes the running database.

Sign in as `r05.manager@abusaleem.test`.

### A. What they must see

- [ ] **21 sidebar entries** (23 screens).
- [ ] Exactly one approval screen: `اعتماد مدير الإدارة`.

### B. What they must be able to do

- [ ] **Stage 10** `اعتماد (حسب الصلاحيات)`: `approve` **with a signature** from the
      `اعتماد مدير الإدارة` queue → stage 11, status `بانتظار الاعتماد المركزي`.
- [ ] The same approval also works from the request-detail screen, and the two paths agree — both
      write one approval row, and doing it twice is refused.
- [ ] **The ministry-bypass branch:** on a request whose `decision_grade` is below its type's
      threshold, the same `approve` lands directly on stage 12 with status `معتمدة نهائياً`,
      skipping R06 entirely.
- [ ] `cancel` (reason required) at stage 10 only.
- [ ] File a request on someone's behalf — R05 holds `request_intake` view/add, **and since
      Stage 95 its `approve` tier**, which is what actually lets them name a صاحب العلاقة other
      than themselves. Confirm the intake screen shows the «صاحب العلاقة» picker, that a request
      filed for another employee reports that employee as صاحب العلاقة and R05 as مقدّم الطلب,
      and that the **subject's own** direct manager — not R05's — is the one who can forward it.

### C. What they must be refused

- [ ] **Register a file routed to HR, the Diwan, or the committee secretary** → refused for all
      three. R05 holds no `register` row at `receive_and_register` any more — confirm this lands as
      a **404** opening the file, not merely a 422 on the transition, since R05 has lost visibility
      into that stage along with the role, the same way R05 lost `forward_to_committee` at Stage 86.
- [ ] **`forward` a request out of stage 8** (`تحويل الطلب للجنة`) → refused; that hop is R02's (R09's between Stages 86 and 96).
- [ ] Any approval queue other than `اعتماد مدير الإدارة` → **403**.
- [ ] **Record the jurisdiction test, the intake gate, or correct the financial-impact flag** →
      **403**. All three ride `notes_attachments,edit`, which is R01 + R02 only; R05 holds `add`.
- [ ] Approve at stage 10 while an **approval return is open** on that request → refused, through
      both the queue and the detail screen, with no approval row written.
- [ ] Approve at stage 10 on a request **they created themselves** → refused.
- [ ] Close or execute a request → **403**. Record a committee decision → **403**.
- [ ] Every administration screen → **403**.
- [ ] Export anything (`reports`, `registers`, `audit-logs`, `requests`) → **403**; R05 may read
      all four but carry none of them out.

---

## 7. R06 — وزارة الحكم المحلي / Ministry

**Identity.** [D] Art. 31's central approval tier, and Art. 14's مندوب الخدمة المدنية. Two things
about this role are load-bearing and easy to get wrong: it must see **only** the files that
actually reached it, and its committee seat must never substitute for this separate approval.

Sign in as `r06.ministry@abusaleem.test`.

### A. What they must see

- [ ] **21 sidebar entries** (23 screens).
- [ ] Exactly one approval screen: `اعتماد وزارة الحكم المحلي`.

### B. What they must be able to do

- [ ] **Stage 11**: `approve` **with a signature** → stage 12, status `معتمدة نهائياً`.
- [ ] `cancel` at stage 11 with a reason.
- [ ] **Export**, which is what distinguishes R06/R07 from everyone else:
      - [ ] `التقارير` → xlsx **and** PDF. Open the PDF and confirm the **Arabic is shaped and
            joined**, not reversed disconnected letterforms.
      - [ ] `السجلات الرسمية` → any of [D] Art. 98's twelve registers exports.
      - [ ] `سجل التدقيق` → exports.
      - [ ] `القرارات والتوصيات` → exports. **Stage 84** gave `decisions,export` the same R06/R07
            tier every other export already had; before that it was R08-only because the grant key
            was simply absent. Not a widening of what R06 may see — `decisions,view` is `'*'`, so
            they already read these rows, tallies included, on screen.
      - [ ] The requests list exports.
- [ ] Read all twelve registers and confirm each carries its own columns: register 1 shows **both**
      numbers (receipt and قيد), register 2 keeps a shortfall the file has since cleared (with a
      `لا` in the still-incomplete column), register 6 has [D] Appendix 12's thirteen columns,
      register 11 carries [D] Art. 34's five deferral fields.
- [ ] Read the performance screens: [D] Art. 106's **twelve** indicators in the article's own order,
      [D] Appendix 10's early-warning alerts (each naming **the party the file is waiting on**, not
      just a day count), and the three periodic reports (`دوري`, `شهري`, `سنوي`).
- [ ] Confirm an indicator with nothing to measure reports **empty, not a confident zero**.

### C. What they must be refused

- [ ] Any approval queue other than the ministry's → **403**.
- [ ] A request that **never reached stage 11** and that they did not create → **404** on open.
      Approving is not enough of a reason to read the whole pipeline.
- [ ] **Adding a note or an attachment** → **403**. R06 holds `notes_attachments,view` only.
- [ ] **A committee member's own actions** — voting, declaring a conflict of interest, generating or
      signing the محضر → **403** each. R06 sits on the committee as [D] Art. 14's delegate but holds
      no `decisions,add` or `meeting_minutes,add` grant of its own.
- [ ] Record a committee decision, close a request, or record a legal review → **403** each.
- [ ] Every administration screen → **403**.
- [ ] **The committee-seat check ([D] Art. 14).** Seat the R06 account as `مندوب الخدمة المدنية` on
      the committee and let it vote to approve. Then confirm the request **still** goes to stage 11
      for the ministry's separate approval when its grade requires it, and **still** bypasses when
      it does not. Their vote must change neither. A ministry delegate's committee vote that
      silently satisfies the central approval is a serious compliance failure.

---

## 8. R07 — المدير العام / العميد / Director

**Identity.** The municipality's final approving authority. R07's defining trait is what is missing:
**they have no intake screen at all** — the dean approves, they do not do data entry — and since
Stage 57 they hold exactly one approval checkpoint, not two.

Sign in as `r07.director@abusaleem.test`.

### A. What they must see

- [ ] **19 sidebar entries** (21 screens).
- [ ] Exactly one approval screen: `الاعتماد النهائي والأرشفة`.
- [ ] **No `استلام الطلب` entry**, and `POST /api/requests` → **403**. This is deliberate; confirm
      it rather than filing it as a missing feature.
- [ ] There is **no `اعتماد السلطة المختصة`** screen anywhere. It was removed with the stage it
      belonged to; if it appears, the seeder's deletion did not run.

### B. What they must be able to do

- [ ] **Stage 12** (a self-loop): `approve` **with a signature** → status `قيد التنفيذ`.
- [ ] The same export set as R06 — reports, registers, audit log, requests list.
- [ ] Read the performance indicators and the periodic reports.
- [ ] Receive an escalation notification when a file goes `حرج` ([D] Appendix 38's third rung
      reaches رئيس اللجنة and the السلطة المختصة).

### C. What they must be refused

- [ ] `approve` at stage 12 **before** [D] Art. 103's soundness checklist has been recorded by R02
      or R03 → refused. The file is prepared by one hand and referred to execution by another.
- [ ] `approve` when any attested soundness answer is `لا` → refused, quoting the failed question.
- [ ] `approve` while a **suspension** ([D] Art. 105) is open → refused.
- [ ] Any approval queue other than the final one → **403**.
- [ ] Adding a note or attachment → **403** (view only).
- [ ] Filing a request → **403**.
- [ ] Every administration screen → **403**.
- [ ] Approving a request they created → refused (they cannot create one, but check the guard holds
      if an admin creates one on their behalf).

---

## 9. R08 — مدير النظام / System Admin

**Identity.** The only role that holds all seven actions on all 35 screens. R08 is a **support**
role: use it to configure the system and to unblock, not to walk the process. Most of this section
is about the administration screens nobody else can reach.

Sign in as `r08.sysadmin@abusaleem.test` (leave `admin@abusaleem.test` untouched as a spare).

### A. What they must see

- [ ] **33 sidebar entries** (35 screens) — including all five approval queues, which is exactly
      why R08 is useless for testing segregation of duties.

### B. What they must be able to do

**B1 — Users.**

- [ ] Create, edit, deactivate and delete a user; assign multiple roles; assign a **manager**.
- [ ] The manager picker never offers the user being edited as their own manager.
- [ ] Deactivating a user immediately refuses them at login **and** as a workflow actor.
- [ ] **The phone number cannot be set here** — only `TestUserSeeder` writes it. Confirm the field
      is absent rather than present-and-ignored.

**B2 — Departments and request types.**

- [ ] Create a child department under the tree.
- [ ] **Deleting a department that still has children or users is refused**; deactivating it is the
      supported route ([preserve, don't erase]). This pattern recurs for other master data —
      committees behave the same way.
- [ ] On `أنواع الطلبات`, edit a type's **service level** and its **ministry-escalation grade**;
      a request filed afterwards picks up the new due date.
- [ ] **Deleting a type that any request already uses is refused** (422, naming the reason);
      deactivating it removes it from the intake picker while old requests keep their type.
- [ ] Add a row to a type's **required-documents** list with a condition, and confirm it appears on
      the intake checklist as a qualifier; leave the condition blank and confirm no empty chip shows.

**B3 — Roles and permissions.**

- [ ] The matrix grid shows 35 screens × 12 roles × 7 actions.
- [ ] Revoke `اعتماد` from R02 on `اعتماد المقرر`, then sign in as R02: the queue is gone from the
      sidebar **and** `POST /api/approvals/reviewer/{id}` returns 403. Restore it afterwards.
- [ ] Grant R04 `meeting_agenda,edit`, confirm R04 can now add an agenda item, then revoke it and
      confirm they cannot. Changes must take effect on the next request, with no re-seed.

**B4 — Settings, templates, guide.**

- [ ] Settings key/value rows save and are read back.
- [ ] Create a template with category `decision`; it then appears in the committee's decision
      drafting picker for R03/R04. A template left inactive does not.
- [ ] Create a user-guide article; while it is a **draft** it is invisible to every role without
      `user_guide,edit` and visible to R08. Confirm article bodies render as **plain text** — HTML
      typed into a body must not execute, since this screen is editable and read by everyone.

**B5 — Backup.**

- [ ] Run an on-demand backup; it appears in the list with a size and a download link.
- [ ] Download it and confirm the archive opens.
- [ ] **There is no restore button.** That is by design — restoring is a server-side operation. The
      screen says so; confirm the copy is present rather than treating the absence as a gap.
- [ ] Break the dumper path deliberately (a bad `BACKUP_MYSQLDUMP_PATH`) and confirm the run is
      recorded as **failed with its reason on screen**, not swallowed as a 500.
- [ ] The nightly schedule lists `backup:run` (`php artisan schedule:list`).

**B6 — The maintenance console.**

- [ ] The screen shows the environment probe: PHP version, `max_execution_time` (with a warning
      under 120s), whether subprocesses (`proc_open`) are available **and why not** if they are not,
      pending migration count, config-cache staleness, queue depth, writable paths.
- [ ] Each command shows its **exact argv verbatim** — someone about to drop every table should be
      able to read the command, not trust a label.
- [ ] `php artisan migrate` runs in-process and works even where subprocesses are disabled.
- [ ] A shell command (composer/npm) is refused **with a reason** on a host that forbids
      subprocesses, rather than failing blank.
- [ ] **There is no free-text command box.** The client sends a command *code* from a fixed
      allowlist and nothing else. If a free-text field exists, that is a critical defect — it turns
      an operations screen into a remote shell.
- [ ] Send an off-allowlist string (`rm -rf /`, `migrate; whoami`) through the API → refused, and
      **no run row is recorded**.
- [ ] `migrate:fresh` and `migrate:rollback` additionally require the `approve` action **and** the
      caller echoing back the confirmation phrase the API dictates. Try both halves missing.
- [ ] One run at a time: a second concurrent run is refused outright rather than queued.
- [ ] The run history shows a preview per row and the full output on the detail view; clearing the
      history works.
- [ ] With the console switched off (`MAINTENANCE_CONSOLE_ENABLED=false`), the diagnostics endpoint
      **still answers** with `enabled: false` and an empty command list, while running a command is
      refused. A screen that can say "this is disabled" beats a bare 403.

**B7 — Audit log.**

- [ ] Every write in the relay is recorded with actor, action, model, and an old/new diff.
- [ ] A no-op save records **nothing**.
- [ ] Password fields are **redacted** on both sides of a diff.
- [ ] Filters by user, action, model and date range narrow the list; a raw class name supplied as
      the model filter is rejected.
- [ ] Export works (R08 and R06/R07 hold it).

**B8 — The override role in the workflow.**

- [ ] R08 can act at a manager-gated row when a submitter has no assigned manager (the documented
      fallback).
- [ ] R08 can route a file flagged `deadline_expired` to ministry oversight with a mandatory reason.

### C. What they must be refused

- [ ] **Approving a request they created themselves** → refused. The self-approval block is in
      `WorkflowService`, not in the permission matrix, so even R08 must be caught by it. Create a
      request as R08, walk it to an approval checkpoint, and confirm.
- [ ] The unauthenticated bootstrap page (`GET /api/maintenance/bootstrap`) must **404** whenever
      the console is already reachable the normal way, and must 404 for a missing, wrong, or
      too-short token. Every refusal is a 404 — a 403 would confirm the path exists.

---

## 10. R09 — أمين سر اللجنة / Committee Secretary

**Identity.** **A retained login with no seeded duty.** The diagram-alignment redesign created R09
as one of three administrative routing destinations and as the agenda secretary; Stage 96 folded
every one of those duties back into مقرر اللجنة (R02), because [D]'s الملحق السادس — the RACI
matrix this system is being conformed to — has no أمين سر اللجنة column at all and gives the
agenda, the legal-review dispatch, the candidate worklist and the handover into the committee to
المقرر as a single مسؤول.

The account is kept so existing logins, audit rows and historical stage logs still resolve a role.
**This section is therefore almost entirely refusals, and that is the test:** if R09 can still do
any of them, a seeded grant or a transition row survived the fold.

Sign in as `r09.secretary@abusaleem.test`.

### A. What they must see

- [ ] **10 sidebar entries** (12 screens), observed live: `dashboard`, `requests`, `my_tasks`,
      `appeals`, `decisions`, `reports`, `registers`, `audit_log`, `notifications`,
      `user_guide` — plus `request_details` and `notes_attachments`, which the API returns and
      the menu hides because they need a request id.
- [ ] **No approval screen**, **no `إرسال الطلب`**, **no `المراجعة القانونية`** — Stage 96
      narrowed `legal_review,view` to R02 + R11.
- [ ] **No meetings group at all.** Two layers do this and both should be understood: Stage 96
      removed R09 from those screens’ `add`/`edit` tiers, and the membership gate hides the whole
      group from anyone holding no committee seat — which R09 does not. Seat R09 on a committee and
      the group appears read-only; every action in C is still refused.

### B. What they must be able to do

- [ ] Read the request list, the registers, the reports and the audit log.
- [ ] Set their own notification preferences.

### C. What they must be refused

- [ ] **Register any file at stage 4**, on any route → refused. The single `register` row is R12's
      and is status-gated on `موجّه إلى الموارد البشرية`.
- [ ] **`cancel` at stage 4** → refused; that row is R12's too.
- [ ] **`forward` out of stage 7 or stage 8** → refused. Both hops are R02's again.
- [ ] **Dispatch a file to legal review** → **403** (`legal_review,edit` is R02 only) — and the
      screen is not even reachable, which is the stronger check.
- [ ] **Nominate, defer, return-to-study or request-completion** on a candidate → **403**.
- [ ] **Build the agenda** — add, reorder or remove an item, apply the computed order, or generate a
      presentation memo → **403**.
- [ ] **Add a note or an attachment** → **403**. The `notes_attachments,add` grant went with the
      `register` row it was bounded to.
- [ ] Every approval queue and every administration screen → **403**.

---

## 11. R10 — وكيل الديوان / Diwan Deputy

**Identity.** **A retained login with no seeded duty**, for the same reason as R09: [D]'s الملحق
السادس has no وكيل الديوان column, and the receiving party it does name is الموارد البشرية / شؤون
الموظفين — R12. Stage 96 collapsed `administrative_routing` from three routes to one and retired
the Diwan route with it. The `موجّه إلى وكيل الديوان` **status row is kept** (legacy-status
precedent, the same treatment `archived` gets) so a historical file still renders; nothing produces
it any more.

Sign in as `r10.diwan@abusaleem.test`.

### A. What they must see

- [ ] **10 sidebar entries** (12 screens) — the same footprint as R09.
- [ ] **No approval screen**, **no `إرسال الطلب`**, **no meetings group**.

### B. What they must be able to do

- [ ] Read the request list, the registers, the reports and the audit log.
- [ ] Set their own notification preferences.

### C. What they must be refused

- [ ] **Register any file at stage 4** → refused. Run this explicitly: it is the clearest proof that
      the Diwan route is gone rather than merely unused.
- [ ] **`cancel` at stage 4** → refused.
- [ ] File a request → **403**.
- [ ] Any committee action at all — nominate, agenda, vote, decide, minutes → **403**.
- [ ] Close or execute a request, or add a note or an attachment → **403**.
- [ ] Every approval queue, every administration screen, and any export → **403**.

---

## 12. R11 — العضو القانوني / Legal Officer

**Identity.** [D] Art. 14 (ب)'s العضو القانوني. R11 is the only role that may **record** the
pre-meeting legal review, and [D] is explicit about the limit of that authority: the opinion
"لا يحل محل مداولة اللجنة أو تصويتها، كما لا يمنح العضو القانوني سلطة منفردة في قبول الطلب أو رفضه".
Both halves need testing — the exclusive power, and its boundary.

Sign in as `r11.legal@abusaleem.test`.

### A. What they must see

- [ ] **18 sidebar entries** (20 screens).
- [ ] `المراجعة القانونية` is present, and its queue lists **only** files currently handed to legal
      review.
- [ ] **No approval screen**, **no `استلام الطلب`**.

### B. What they must be able to do

- [ ] **Open a file in their queue that they did not create and hold no workflow role on.** This is
      the first thing to check: if it 404s, the queue is a dead end and nothing else in this section
      can be tested.
- [ ] The review form pre-fills the request type's **legal basis** ([D] Appendix 21) for the six
      types the appendix actually names, and leaves it **empty** for the other six rather than
      inventing a citation. Check one of each.
- [ ] Record [D] Appendix 22's **بطاقة السند القانوني** — eight fields, including
      `نوع الاختصاص` (قرار / توصية / رأي / **دراسة فقط**) and `الاعتماد المركزي` as a **three-value**
      answer (نعم / لا / **يحتاج إلى تحقق**), not a yes/no.
- [ ] Record each of the five verdicts and confirm where each sends the file:
      - `سليم قانونيًا وجاهز للعرض` → status `جاهزة`, and the file may be put on an agenda.
      - `مسألة قانونية تستوجب العرض مع بيانها` → **also permits** the agenda; the matter is presented
        *with the issue stated*. A note is required.
      - `يحتاج إلى استكمال مستند` / `يحتاج إلى إيضاح` / `ملاحظة على الاختصاص` → status
        `مطلوب استكمال`, agenda insertion refused. A note is required for each.
- [ ] Recording a blocking verdict **without a note** is refused.
- [ ] **The re-review loop**: after a blocking verdict, the file is corrected, dispatched again, and
      a second review is recorded. Both rounds stay readable in the file, and the agenda gate reads
      only the **latest**.
- [ ] Record a review **after** an [D] Art. 105 suspension — this is what unblocks R02/R03's lift.
      Confirm the lift is refused before this review exists and permitted after.
- [ ] Committee members can read the recorded opinion at study time ([D] Art. 21 requires it).
- [ ] **As a sitting member (Stage 99, Appendix 6 rows 9–11)** — seated in the committee's
      `العضو القانوني` seat and marked attended: post to the item's discussion feed, **vote**, sign the
      محضر, and record a legal note on the **draft** محضر (refused once it is under signature). The
      seat itself is refused to anyone without R11.

### C. What they must be refused

- [ ] **Dispatch a file to legal review** → **403**. Recording is `legal_review,add` (R11 only);
      dispatching is `legal_review,edit` (R02 only, since Stage 96). Test both directions: R11 cannot dispatch, R02
      cannot record.
- [ ] **Declare عدم اختصاص on the request itself** — R11's `ملاحظة على الاختصاص` verdict must
      **not** set the request's status to `عدم اختصاص` or terminate it. That decision stays with the
      committee (or with R02 at stage 5). This is [D] Art. 14 (ب)'s limit, and it is the single most
      important refusal in this section.
- [ ] Record a decision → **403** (voting is permitted when seated and attended — see B).
- [ ] Add an agenda item, convene, or approve minutes → **403**.
- [ ] Close or execute a request → **403**.
- [ ] Add a note or attachment → **403** (view only).
- [ ] File a request → **403**.
- [ ] Every approval queue and every administration screen → **403**.

---

## 13. R12 — مدير إدارة الموارد البشرية / HR Manager

**Identity.** [F] names إدارة الموارد البشرية twice — the `route_to_hr` registration destination,
and co-owner of the study at stage 7 (`observations`) — and neither belonged to a role of that name
until Stage 87: the registration destination sat with R05 (مدير إدارة الشؤون الإدارية, a different
administrative role), and the study had no HR party at all. R12 is that missing seat, and its reach
into `observations` is deliberately narrow: read the file and add a note, never move it. Every
rule out of that stage is R02's — `forward` returned to it at Stage 96, and `request_edit`/`cancel`
never left.

Sign in as `r12.hr@abusaleem.test`.

### A. What they must see

- [ ] **18 sidebar entries** (20 screens) — one more than R09/R10, which lost `المراجعة القانونية`.
- [ ] **No approval screen**, **no `إرسال الطلب`**.

### B. What they must be able to do

- [ ] **Register an HR-routed file** (stage 4, status `موجّه إلى الموارد البشرية`) → stage 5, and
      this is what mints the request's رقم إشاري (see Appendix D) — it replaces r05.manager@ here.
- [ ] `cancel` at stage 4 with a reason.
- [ ] **Open a request sitting at stage 7 (`observations`)** that they did not create and hold no
      registration role on — a reach no other non-committee role has.
- [ ] **Add a note** on that same request (`notes_attachments,add`).
- [ ] Read the request list, the registers, the reports and the audit log.
- [ ] **Execute a request they did not create**, naming إدارة الموارد البشرية as the executing body
      ([D] Appendix 70 — Stage 92: [F] step 10's "who executed" question, now on its own
      `meeting_outputs,approve` tier alongside R02/R03, not the `edit` tier those two hold). **Close**
      the same request afterward. This is the check that the acting grant now matches the party the
      execution record names, when HR itself is that party.

### C. What they must be refused

- [ ] **Register a file routed to the Diwan or to the committee secretary** → refused. Run this
      explicitly: it is the clearest proof the routing choice is enforced and not decorative.
- [ ] **`forward`, `request_edit`, or `cancel` a request at `observations`** → refused for all
      three. Co-ownership of the study is read-and-contribute, never stage control.
- [ ] **Open a request at any stage other than `observations`** that they are not the registrar or
      creator for → **404**. This is the check that the reach is bounded to one stage, not "anywhere
      past intake".
- [ ] File a request → **403**.
- [ ] Any committee action — nominate, agenda, vote, decide, minutes → **403**.
- [ ] **Record an approval return, a suspension, or the Art. 103 execution-soundness checklist** →
      **403**. These stay `meeting_outputs,edit`, which R12 does not hold — only `execute`/`close`
      moved onto the new `approve` tier R12 shares with R02/R03.
- [ ] Every approval queue and every administration screen → **403**.
- [ ] Export anything → **403**.

---

## 14. Cross-role checks

These do not belong to any one role, and each one has broken at least once in this system's
history. Run them after the role sections.

### 14.1 Multiple roles are a union, never an intersection

Sign in as `multi.role@abusaleem.test` (R03 **+** R04).

- [ ] The sidebar shows **20 entries** (22 screens) — R03's set, since R04 adds nothing R03 lacks.
- [ ] `اعتماد رئيس اللجنة` is visible.
- [ ] They can **both** vote (`decisions,add`, R03 + R04) **and** record a decision
      (`decisions,approve`, R03 only). If recording is refused, permissions have regressed to an
      intersection.

### 14.2 An inactive user is refused twice

Sign in attempt as `inactive.user@abusaleem.test`.

- [ ] Login is refused with its **own message**, not a generic "bad credentials".
- [ ] Re-activate them, mint a token, deactivate them again, then use that token to attempt a
      workflow transition → refused by `WorkflowService`, which re-checks the actor's active status
      independently of the login gate.

### 14.3 Nobody approves their own work

- [ ] For each of R02, R03, R05, R06, R07 and **R08**: create a request as that account, walk it to
      the checkpoint that account owns, and confirm the approval is refused. Check both entry points
      (the approval queue and the request detail screen).

### 14.4 Login throttle

- [ ] `POST /api/auth/login` is rate-limited at **six attempts per minute**. Signing in as seven
      accounts in quick succession — which the one-click test-user picker makes easy — returns 429
      on the seventh. Confirm the UI reports it as rate limiting and not as a wrong password.

### 14.5 Visibility is a 404, not an empty list

- [ ] As any role, open a request id you have no relationship to → **404**.
- [ ] The exceptions, each of which must work: R11 on a file in legal review; R02/R03 on a file in
      the approval, execution or closable states; a user in the **Salaries** department (`SAL`) on
      any request flagged with a financial impact; and every user on requests they created.

### 14.6 Notifications

- [ ] The bell badge count matches the unread list, and marking all read clears it.
- [ ] Each of the [D] Art. 101 moments fires once and only once for the right person.
- [ ] Muting an event type stops delivery on that channel only.
- [ ] A user with no phone number silently drops the SMS channel rather than queueing an
      undeliverable message.
- [ ] An escalation reaches the right rung: `أصفر` the current holder, `أحمر` R02 + R05,
      `تأخير حرج` R03 + R07 ([D] Appendix 38). The same rung is never announced twice for the same
      file, but a worsening delay climbs.

### 14.7 Language, direction, theme and print

- [ ] Switch to English and back. Every screen's labels change; nothing renders as a raw key like
      `meetingsUnit.minutes.title`.
- [ ] In Arabic the layout mirrors fully — sidebar, tables, form fields. Nothing is clipped.
- [ ] **Command lines and log output on the maintenance screen stay left-to-right** even in Arabic;
      an RTL-mirrored shell command is unreadable and uncopyable.
- [ ] Toggle dark mode; check every screen in both themes. Any element that stays light-on-light or
      dark-on-dark is a hard-coded colour and a defect.
- [ ] Print a decisions register and a guide article from dark mode — the print output must be ink
      on white, not light grey on an unrendered background.

### 14.8 Audit trail

- [ ] Every action in §1's relay is attributable in `سجل التدقيق` to the account that performed it,
      with a timestamp and an old/new diff.
- [ ] The audit log itself cannot be edited or deleted by anyone, including R08.

---

## 15. Defect log and sign-off

Record every failure here as you hit it. Do not fix mid-pass.

| # | Role | Section | What you did | What you expected | What happened | Severity |
|---|---|---|---|---|---|---|
| 1 | | | | | | |
| 2 | | | | | | |
| 3 | | | | | | |
| 4 | | | | | | |
| 5 | | | | | | |

**Severity guide.** *Critical* — a role can do something the standard forbids (approve their own
work, register a file routed elsewhere, run an arbitrary command, read another employee's file).
*Major* — a role cannot do something the standard requires. *Minor* — wording, layout, or a missing
convenience.

### Sign-off

| Role | Tester | Date | Result | Notes |
|---|---|---|---|---|
| R01 Employee | | | ☐ pass ☐ fail | |
| R02 Reviewer | | | ☐ pass ☐ fail | |
| R03 Committee Head | | | ☐ pass ☐ fail | |
| R04 Committee Member | | | ☐ pass ☐ fail | |
| R05 Admin Manager | | | ☐ pass ☐ fail | |
| R06 Ministry | | | ☐ pass ☐ fail | |
| R07 Director | | | ☐ pass ☐ fail | |
| R08 System Admin | | | ☐ pass ☐ fail | |
| R09 Committee Secretary | | | ☐ pass ☐ fail | |
| R10 Diwan Deputy | | | ☐ pass ☐ fail | |
| R11 Legal Officer | | | ☐ pass ☐ fail | |
| R12 HR Manager | | | ☐ pass ☐ fail | |
| §1 relay | | | ☐ pass ☐ fail | |
| §14 cross-role | | | ☐ pass ☐ fail | |

---

## Appendix A — the seeded permission matrix

Generated from `ScreenSeeder` and `ScreenRolePermissionSeeder`. This is the **starting** matrix;
R08 can change any cell from the Roles & Permissions screen, so re-derive it after any change.
Re-derived in full for Stage 87 (not just an appended column) — the previous version had already
drifted, missing `request_tracking` (Stage 89) entirely; regenerating from the live seeders is what
caught it, which is the reason the file's own instruction is to re-derive rather than hand-edit.

Letters: `v` view · `a` add · `e` edit · `d` delete · `A` approve · `p` print · `x` export ·
`·` no access at all.

| Screen | R01 | R02 | R03 | R04 | R05 | R06 | R07 | R08 | R09 | R10 | R11 | R12 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `dashboard` — لوحة التحكم الرئيسية | vp | vp | vp | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `requests` — الطلبات | vp | vp | vp | vp | vp | vpx | vpx | vaedApx | vp | vp | vp | vp |
| `request_intake` — إرسال الطلب | vae | vaeA | va | va | vaeA | va | · | vaedApx | · | · | · | · |
| `request_details` — تفاصيل الطلب | vpx | vpx | vpx | vpx | vpx | vpx | vpx | vaedApx | vpx | vpx | vpx | vpx |
| `notes_attachments` — الملاحظات والمرفقات | va | vae | va | va | va | v | v | vaedApx | v | v | v | va |
| `request_tracking` — متابعة طلباتي | vp | vp | vp | vp | vp | vp | · | vaedApx | · | · | · | · |
| `appeals` — التظلمات | vap | vep | vp | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `meetings_dashboard` — لوحة قيادة الاجتماعات | vp | vp | vaep | vap | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `committee_candidates` — الطلبات المرشحة | vp | vaep | vaep | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `legal_review` — المراجعة القانونية | p | vep | p | p | p | p | p | vaedApx | p | p | vap | p |
| `meetings` — الاجتماعات | vp | vaep | vaep | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `meeting_agenda` — جدول الأعمال | vp | vaep | vaeAp | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `meeting_readiness` — جاهزية الاجتماع | vp | vp | vaep | vap | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `meeting_live` — مباشرة الاجتماع | vp | vap | vaep | vap | vp | vp | vp | vaedApx | vp | vp | vap | vp |
| `decisions` — القرارات والتوصيات | vp | vp | vaAp | vap | vp | vpx | vpx | vaedApx | vp | vp | vap | vp |
| `meeting_minutes` — المحاضر | vp | vap | vaAp | vap | vp | vp | vp | vaedApx | vp | vp | vaep | vp |
| `meeting_outputs` — المخرجات | vp | veAp | vaeAp | vap | vp | vp | vp | vaedApx | vp | vp | vp | vAp |
| `reviewer_approval` — اعتماد المقرر | · | vA | · | · | · | · | · | vaedApx | · | · | · | · |
| `committee_head_approval` — اعتماد رئيس اللجنة | · | · | vA | · | · | · | · | vaedApx | · | · | · | · |
| `admin_manager_approval` — اعتماد مدير الإدارة | · | · | · | · | vA | · | · | vaedApx | · | · | · | · |
| `ministry_approval` — اعتماد وزارة الحكم المحلي | · | · | · | · | · | vA | · | vaedApx | · | · | · | · |
| `final_approval` — الاعتماد النهائي والأرشفة | · | · | · | · | · | · | vA | vaedApx | · | · | · | · |
| `users` — المستخدمون | · | · | · | · | · | · | · | vaedApx | · | · | · | · |
| `departments` — الإدارات والأقسام | · | · | · | · | · | · | · | vaedApx | · | · | · | · |
| `request_types` — أنواع الطلبات | · | · | · | · | · | · | · | vaedApx | · | · | · | · |
| `roles_permissions` — الأدوار والصلاحيات | · | · | · | · | · | · | · | vaedApx | · | · | · | · |
| `settings` — الإعدادات العامة | · | · | · | · | · | · | · | vaedApx | · | · | · | · |
| `reports` — التقارير والإحصائيات | vp | vp | vp | vp | vp | vpx | vpx | vaedApx | vp | vp | vp | vp |
| `registers` — السجلات الرسمية | vp | vp | vp | vp | vp | vpx | vpx | vaedApx | vp | vp | vp | vp |
| `audit_log` — سجل التدقيق | v | v | v | v | v | vx | vx | vaedApx | v | v | v | v |
| `notifications` — الإشعارات | ve | ve | ve | ve | ve | ve | ve | vaedApx | ve | ve | ve | ve |
| `templates` — القوالب والنماذج | · | · | · | · | · | · | · | vaedApx | · | · | · | · |
| `backup` — النسخ الاحتياطي | · | · | · | · | · | · | · | vaedApx | · | · | · | · |
| `maintenance` — الصيانة والنشر | · | · | · | · | · | · | · | vaedApx | · | · | · | · |
| `user_guide` — دليل الاستخدام | vp | vp | vp | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |

**Screen counts per role** (API total / sidebar total — `request_details` and `notes_attachments`
are returned by the API but hidden from the menu because they need a request id).

> **These are SEEDED-MATRIX counts, and the API can return fewer.** The membership gate hides the
> seven `meetings_management` screens from anyone holding no committee seat, so an unseated R09,
> R10, R11 or R12 sees 12 screens / 10 entries, not 19 / 17. The table below also carries drift
> from stages after 87 — the seeder now has **36** screens, not 35 — which Stage 96 did not
> re-derive; only the four rows it changed were corrected against the live matrix.

| Role | API | Sidebar | Approval screen |
|---|---|---|---|
| R01 Employee | 22 | 20 | — |
| R02 Reviewer | 23 | 21 | `اعتماد المقرر` |
| R03 Committee Head | 23 | 21 | `اعتماد رئيس اللجنة` |
| R04 Committee Member | 22 | 20 | — |
| R05 Admin Manager | 23 | 21 | `اعتماد مدير الإدارة` |
| R06 Ministry | 23 | 21 | `اعتماد وزارة الحكم المحلي` |
| R07 Director | 21 | 19 | `الاعتماد النهائي والأرشفة` |
| R08 System Admin | 35 | 33 | all five |
| R09 Committee Secretary | 19 | 17 | — |
| R10 Diwan Deputy | 19 | 17 | — |
| R11 Legal Officer | 20 | 18 | — |
| R12 HR Manager | 20 | 18 | — |
| multi.role (R03+R04) | 23 | 21 | `اعتماد رئيس اللجنة` |

---

## Appendix B — the twelve lifecycle stages

`responsible_role` below is **indicative** — it tells the UI who normally holds the file. Authority
to actually move it comes from the transition's own required role, which is why stages 2–4 show
none: their gate is "the submitter's own manager" or "whichever of three registrars matches the
route", and neither is expressible as a single role.

| # | Code | Arabic | Normally held by | Target days |
|---|---|---|---|---|
| 1 | `receive_from_municipality` | استلام الطلب من البلدية | R01 | — |
| 2 | `direct_manager_review` | مراجعة الطلب من المدير المباشر | the submitter's manager | 1 |
| 3 | `administrative_routing` | إحالة الطلب لأحد المسارات الإدارية | the submitter's manager | 2 |
| 4 | `receive_and_register` | الاستلام والتسجيل | R12 (Stage 96 retired the other two routes) | 3 |
| 5 | `requirements_check` | فحص استيفاء المتطلبات | R02 | 2 |
| 6 | `reviewer_review` | مراجعة المقرر وفق اللوائح | R02 | 3 |
| 7 | `observations` | إبداء الملاحظات (إن وجدت) | R02 (R12 co-owns, non-controlling) | 2 |
| 8 | `forward_to_committee` | تحويل الطلب للجنة القائمة | R02 | — |
| 9 | `receive_from_committee` | استلام الطلب من اللجنة | R03 | 3 |
| 10 | `approval_by_authority` | اعتماد (حسب الصلاحيات) | R05 | 2 |
| 11 | `local_governance_ministry` | وزارة الحكم المحلي | R06 | — |
| 12 | `final_approval_archiving` | الاعتماد النهائي والأرشفة | R07 | 1–2 |

Targets come from [D] Appendix 37 and are a **soft** SLA — never blocking. A stage with no target
shows no indicator rather than a fabricated "on target". The hard per-type deadline
(`due_date` / `overdue_at`) is a separate mechanism, swept nightly.

**Exception actions**, all requiring a written reason:

| Action | From → to | Who |
|---|---|---|
| `return_to_employee` | 2 → 1 | the submitter's manager |
| `return_missing_docs` | 5 → 1 | R02 |
| `declare_no_jurisdiction` | 5 → 5, and 9 → 9 | R02 at stage 5, R03 at stage 9 |
| `reject_formally` | 5 → 5 | R02 |
| `reject_review` | 6 → 5 | R02 |
| `request_edit` | 7 → 6 | R02 |
| `defer` | 9 → 9 | R03 |
| `conditional_approve` | 9 → 10 | R03 |
| `request_legal_opinion` | 9 → 9 | R03 |
| `refer_to_another_body` | 9 → 9 | R03 |
| `reject_by_committee` | 9 → 9 | R03 |
| `return_to_study` | 9 → 7 | R03 |
| `cancel` | self-loop at every open stage | the role holding that stage |
| `deadline_expired` | any open stage → 11 | R08 |

---

## Appendix C — the status dictionary

The vocabulary is [D] Art. 38's, extended where this system needs a state the article does not
itemise. Statuses a tester will meet most often, in roughly lifecycle order:

`جديد` · `قيد المراجعة` · `موجّه إلى الموارد البشرية` / `موجّه إلى وكيل الديوان` /
`موجّه إلى أمين سر اللجنة` · `تم التسجيل` · `ناقص` · `مرفوضة` · `عدم اختصاص` ·
`تحت المراجعة القانونية` · `مطلوب استكمال` · `جاهزة` · `في الاجتماع` · `مرشح للجنة` ·
`مدرج بجدول الأعمال` · `قيد المناقشة` · `مؤجلة` · `طلب رأي قانوني` · `أحيلت لجهة أخرى` ·
`غير موافق عليها` · `اعتماد مشروط` · `بانتظار اعتماد البلدية` · `بانتظار الاعتماد المركزي` ·
`أعيدت من جهة الاعتماد` · `موقوفة لمراجعة قانونية` · `معتمدة نهائياً` · `قيد التنفيذ` · `منفذة` ·
`مكتمل ومغلق` · `ملغاة` · `أعيد فتحه بموجب تظلم` · `أعيد فتحه لإعادة العرض` ·
`قرار مسحوب بموجب تظلم` · `قرار معدَّل بموجب تظلم`

**Terminal statuses** — no further ordinary workflow move is possible, and a non-creator's
assignment-based visibility stops: `ملغاة`, `مؤرشفة`, `غير موافق عليها`, `قيد التنفيذ`, `منفذة`,
`مكتمل ومغلق`, `قرار مسحوب بموجب تظلم`, `قرار معدَّل بموجب تظلم`.

---

## Appendix D — artefact numbering

Every number is minted **server-side**; none is typed by a user. If a screen offers a field for
one of these, that is a defect.

| Artefact | Format | Minted when |
|---|---|---|
| Intake receipt | `PM-RCV/YYYY/NNNNNN` | at submission — **not a قيد** |
| Request (رقم إشاري) | `PM-COM/YYYY/NNNN` | at the stage-5 `approve` (the قيد), once and only once for the file's whole life |
| Meeting | `PM-MTG/YYYY/NN` | when the meeting is created |
| Minutes | `PM-MIN/YYYY/NN` | at first generation, preserved across regenerations |
| Decision | `PM-DEC/YYYY/NNN` | when the decision is recorded |

---

*Generated from the seeders, routes and services in this repository, and from the process standard
indexed at `docs/employee-committee-lifecycle/README.md`. When a seeder changes, re-derive
Appendix A rather than editing it by hand.*
