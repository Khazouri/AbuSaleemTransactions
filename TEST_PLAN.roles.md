# Role test plan — Abu Saleem Transactions

A manual QA script organised **by the person doing the work**, not by feature. One section per
system role: what they should see, what they must be able to do, and — just as important — what
the system must refuse them.

> **Arabic version:** [TEST_PLAN.roles.ar.md](TEST_PLAN.roles.ar.md) — same content, and the copy
> to hand to an actual tester, since the UI is Arabic-first.
>
> **Companion:** [TEST_PLAN.md](TEST_PLAN.md) is the feature-oriented script — one section per
> subsystem instead of one per role. Both were brought up to date together in October 2026 (the
> decision wizards, «المهام المعلقة», the single committee path); use that one to prove a feature
> works end to end and this one to prove the permission matrix is real.

**Why by role.** The permission matrix is 36 screens × 12 roles, and the approval chain is
single-role by design — each approval grant row names exactly one role. Clicking through as the
System Admin proves almost nothing: R08 holds all seven actions on all 36 screens, which is the
exact opposite of what the matrix encodes — and it holds **none** of the five approval roles, so
it cannot walk the approval chain at all. The lifecycle also cannot be walked by one person:
reaching `مكتمل ومغلق` needs nine different people acting in order. This plan makes each of them
do their part.

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
| 2 | `r02.reviewer@abusaleem.test` | R02 Reviewer (المقرر) | REP | Stage 5 (the قيد), the rapporteur seat, scheduling and the agenda, the post-decision records; also **r01's assigned manager** (see the note below) |
| 3 | `r03.head@abusaleem.test` | R03 Committee Head | CMT | Stage 9 decisions, minutes approval, convening |
| 4 | `r04.member1@abusaleem.test` | R04 Committee Member | CMT | Voter |
| 5 | `r04.member2@abusaleem.test` | R04 Committee Member | CMT | Voter |
| 6 | `r04.member3@abusaleem.test` | R04 Committee Member | CMT | Voter — **the third seat is what makes a 2-1 plurality reachable**; a tie is refused outright |
| 7 | `r05.manager@abusaleem.test` | R05 Admin Manager | ADM | Stage 10 approval, and filing on another employee's behalf — Stage 87 moved the stage-4 registration onto R12 |
| 8 | `r06.ministry@abusaleem.test` | R06 Ministry | ABS | Stage 11; holds `export` on reports, registers and the audit log |
| 9 | `r07.director@abusaleem.test` | R07 Director / Dean | ABS | Stage 12; **no intake access at all** |
| 10 | `r08.sysadmin@abusaleem.test` | R08 System Admin | ADM | Administration; keeps `admin@abusaleem.test` free as a spare |
| 11 | `multi.role@abusaleem.test` | R03 **+** R04 | CMT | Permissions must be a **union**, never an intersection |
| 12 | `inactive.user@abusaleem.test` | R01, `is_active = false` | FIN | Must be refused at login *and* refused as a workflow actor |
| 13 | `r09.secretary@abusaleem.test` | R09 Committee Secretary | CMT | **Stage 96 — a retained login with no seeded duty.** Everything it held went back to R02 |
| 14 | `r10.diwan@abusaleem.test` | R10 Diwan Deputy | ABS | **Stage 96 — a retained login with no seeded duty.** The Diwan route is retired |
| 15 | `r11.legal@abusaleem.test` | R11 Legal Officer | CMT | The only role that may record [D] Art. 21's pre-meeting legal review |
| 16 | `r12.hr@abusaleem.test` | R12 HR Manager | HR | Prepares the employment file and registers at stage 4; a bounded, non-controlling reach into the study at stages 2 and 5; the `hr_director` committee seat (votes); records execution and archives the service file |

> **The manager link matters.** `r01.employee@`'s `manager_id` points at `r02.reviewer@`. Stage 2
> (`مراجعة الطلب من المدير المباشر`) is gated on *"the actor is صاحب العلاقة's own live manager"*,
> **not** on a role — so r02 acts there as a manager, not as المقرر, and every other account must be
> refused, **R08 included**: the admin may use that step only when the employee has no live manager
> at all (none assigned, deactivated or deleted), and never on a file it filed or is the subject of.
> This is the one gate in the system that is not role-based; test it deliberately.

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

### 0.6 Where every action is taken — Pending Tasks and the wizards

No page carries loose action buttons any more. Read this once; every role section assumes it.

- **«المهام المعلقة» (Pending Tasks, `/my-tasks`)** is in every role's sidebar and lists whatever
  is waiting on the signed-in person, in groups: requests awaiting your action, awaiting your
  approval, your requests needing completion, committee candidates, legal reviews, meeting dates
  awaiting your answer, meeting duties, votes, minutes to sign, after-the-decision work, open
  records, appeals, and (R08) overdue requests. Clicking a task opens the screen that owns the act,
  with its wizard already open.
- **The request wizard** — the one «اتخاذ القرار» (*Make a decision*) button on the request page.
  Four steps: Review → Checks → Choose an action → Confirm. Its acts are grouped under *This stage's
  decision · After the decision · The file's records · The file's content*. The page's five tabs
  are read-only.
- **The meeting wizard and the agenda-item wizard** — «اتخاذ الإجراء» on a meeting or on an agenda
  item: the date answer, adopting the agenda, convening, the minutes, closing; the vote and the
  recorded result.
- **The appeal wizard and the appeal filing wizard** — on the Appeals screen.

So in the sections below, *"`approve` is offered"* means it is listed in the wizard's Choose step;
*"not offered"* means it is absent there; and an act that is the person's own but blocked right now
is listed under **«غير متاح بعد» (Not available yet) with the reason the endpoint itself would give**.
The five approval "screens" are no longer pages: they are grant rows on the Roles & Permissions
grid, and the approval work arrives in Pending Tasks under «بانتظار اعتمادك».

**The seat rule.** Seven of the Meetings Management screens appear only for someone who holds a
**committee seat**, and the five seats are bound to roles (chair R03 · legal R11 · hr_director R12 ·
ministry_delegate R04 · rapporteur R02). A fresh seed has no committee, so until setup step 0 of the
relay nobody but R08 sees those screens. Each role's section A gives both counts.

---

## 1. The relay — one request, nine people

Run this first. It produces the request every later section needs, and it is the only way to see
that the hand-offs work. Do not shortcut a row by acting as R08: the whole point is that no single
person can walk this alone.

Sign in as the actor in each row, do the action, sign out. Record the request's `reference_number`
when it appears at step 4 — later sections call it **REQ-A**. Unless a row says otherwise, the
actor finds the file in **«المهام المعلقة»** and acts from the wizard it opens (§0.6).

| # | Actor | Where | Action | Request lands at |
|---|---|---|---|---|
| 0 | **R08 — setup, once** | الاجتماعات | Create the committee and fill its five seats: chair `r03.head@`, legal `r11.legal@`, HR `r12.hr@`, civil-service delegate `r04.member1@`, rapporteur `r02.reviewer@`. Record its identity card with a quorum rule, then remove R08's own seatless row from the roster (creating a committee adds its creator as a member, and the quorum counts every member) | The five seated accounts gain the `إدارة الاجتماعات` group; the committee has exactly five members |
| 1 | R01 employee | إرسال الطلب (`/requests/create`) | Fill, attach the (required) documents, submit | Stage 2 `مراجعة الطلب من المدير المباشر`, status `قيد المراجعة`; receipt `PM-RCV/…` |
| 2 | R02 **as manager** | المهام المعلقة → request wizard | Checks: validity of every attachment. Choose: «موافقة وإحالة» (`forward`) | Stage 4 `الاستلام والتسجيل`, status `موجّه إلى الموارد البشرية` — one click; stage 3 is never visited |
| 3 | R12 HR manager | المهام المعلقة → request wizard | Checks: prepare the employment file. Choose: «تسجيل الاستلام» (`register`) | Stage 5 `فحص استيفاء المتطلبات`, status `قيد المراجعة` — delivered to be checked, **no reference number yet** |
| 4 | R02 reviewer | المهام المعلقة → request wizard | Checks: the jurisdiction test **and** gate 1. Choose: `approve` | Stage 9 `استلام الطلب من اللجنة`, status `تم التسجيل` — **the `PM-COM/YYYY/NNNN` reference number is granted here**, and REQ-A is now on the committee's pending list (stages 6–8 are off the path) |
| 5 | R02 reviewer | الطلبات المرشحة → «فتح الملف» | «الإحالة للمراجعة القانونية» | Status `تحت المراجعة القانونية` |
| 6 | **R11 legal** | المهام المعلقة → request wizard | Checks: the legal-basis card. Choose: verdict `سليم قانونيًا وجاهز للعرض` | Status `جاهزة` — back on the pending list |
| 7 | R02 reviewer | الاجتماعات → «جدولة اجتماع» | Schedule this month's meeting, picking REQ-A from the pending list | Meeting `بانتظار تأكيد الموعد`; REQ-A leaves the pending list |
| 8 | R03, R11, R12, R04 | المهام المعلقة → meeting wizard | Each chooses «قبول الموعد» for themselves (the rapporteur who proposed the date is already counted) | Meeting `مجدول` once the fourth accepts |
| 9 | R02 reviewer | جدول الأعمال | Edit the presentation memo, order the agenda | — |
| 10 | R03 head | Meeting wizard | «اعتماد جدول الأعمال», then «مباشرة الاجتماع» | Meeting convened; the agenda is locked |
| 11 | R02 or R03 | Meeting page | Mark attendance | — |
| 12 | R03 head | مباشرة الاجتماع | Tick [D] Art. 85's nine study steps in order | Voting opens |
| 13 | R03 + R11 + R12 + R04 | المهام المعلقة «بانتظار تصويتك» → item wizard | Cast votes (the rapporteur does not vote) | — |
| 14 | R03 head | Item wizard | «تسجيل النتيجة» (`موافقة`) with all four [D] Appendix 27 parts | Stage 10 `اعتماد (حسب الصلاحيات)`, status `بانتظار اعتماد البلدية`; decision `PM-DEC/…` |
| 15 | R02, then R03, then the attendees | Meeting wizard | «إعداد المحضر» → «اعتماد المحضر» → every present attendee «التوقيع على المحضر» | Minutes `معتمد` |
| 16 | R02 or R03 | Meeting wizard | «إغلاق الاجتماع» | The meeting is read-only |
| 17 | R05 manager | المهام المعلقة «بانتظار اعتمادك» → request wizard | `approve` | Stage 11 `وزارة الحكم المحلي`, status `بانتظار الاعتماد المركزي` |
| 18 | R06 ministry | المهام المعلقة → request wizard | `approve` | Stage 12 `الاعتماد النهائي والأرشفة`, status `معتمدة نهائياً` |
| 19 | R02 or R03 | Request wizard, «ما بعد القرار» | Record [D] Art. 103's soundness checklist | — |
| 20 | R07 director | المهام المعلقة → request wizard | `approve` | Status `قيد التنفيذ` |
| 21 | R12 HR manager | Request wizard, «ما بعد القرار» | Attach [D] Appendix 70 evidence, then «إثبات التنفيذ» | Status `منفذة` |
| 22 | R02 reviewer | Request wizard | «أرشفة ملف اللجنة» | — |
| 23 | R12 HR manager | Request wizard | «أرشفة ملف الخدمة» | — |
| 24 | R02 reviewer | Request wizard | «الإقفال» — [D] Appendix 47's twelve-point audit | Status `مكتمل ومغلق` |

- [ ] The relay completes and REQ-A reaches `مكتمل ومغلق`.
- [ ] **No step from 1 to 24 was performed by R08.** Step 0 is configuration, not process. If you
      had to fall back to the admin to move the request, that step is a defect — log it in §15
      naming the role that should have been able to act.
- [ ] **Every actor found their step in «المهام المعلقة»** without being told the request's id, and
      the task left their list the moment they acted.
- [ ] R01 received in-app notifications at several of these points, but not all 24: [D] Art. 101
      names twelve notifying moments, and the purely internal steps are deliberately silent.

**Branch worth running once.** Repeat the relay with a request whose type has a `decision_grade`
**below** its type's threshold. At step 17 the file must skip stage 11 entirely and land straight on
stage 12 with status `معتمدة نهائياً`, and R06 must then see nothing for it in their pending tasks.
This is the ministry-bypass branch, and it is easy to break without noticing.

---

## 2. R01 — الموظف / Employee

**Identity.** [D] Art. 9 (ب)'s الموظف صاحب الطلب. They start the file and they are its audience;
they never move it. R01 owns exactly one workflow transition (`submit`), which the system fires
for them at intake and which they use again only to re-submit a file returned to them — so a
correct R01 session is offered **nothing under «قرار هذه المرحلة»** while the file sits with anyone
else.

Sign in as `r01.employee@abusaleem.test`.

### A. What they must see

- [ ] **12 sidebar entries** (14 screens from `GET /api/screens`; `تفاصيل الطلب` and
      `الملاحظات والمرفقات` are returned but hidden from the menu because they need a request id).
- [ ] The twelve include **`المهام المعلقة`** and **`متابعة طلباتي`**, both top-level beside
      `إرسال الطلب`.
- [ ] **No approval screen at all**, and **no `إدارة الاجتماعات` group** — R01 can hold no seat.
- [ ] No `المستخدمون`, `الإدارات والأقسام`, `أنواع الطلبات`, `الأدوار والصلاحيات`,
      `الإعدادات العامة`, `القوالب والنماذج`, `النسخ الاحتياطي` or `الصيانة والنشر`.
- [ ] The request list shows **only requests they filed or that are about them**. Note another
      employee's request id from the relay and open `/requests/{that id}` directly → **404**, not
      an empty page.

### B. What they must be able to do

**B1 — File a request ([D] Arts. 15–16).**

- [ ] إرسال الطلب has two steps (data and documents, then review and submit). Choosing a type
      reveals the **required-documents checklist** in two groups — `مستندات أساسية مشتركة` and
      `مستندات خاصة بالنوع` ([D] Appendix 57). Conditional items carry their own qualifier ("بحسب
      الموضوع", "عند الحاجة"), mandatory ones are marked (مطلوب), and the employment-record
      documents are **not** asked of the employee — HR supplies them at stage 4.
- [ ] Every attachment upload **must say which listed document it is** (or «مستند آخر»); the
      file's [D] Appendix 14 folder is derived from that choice. An unclassified upload is refused —
      "يمنع حفظ الملفات بصورة عشوائية دون تصنيف". Submitting with a (مطلوب) document missing is
      refused too.
- [ ] What is typed is saved as a **draft** automatically: leave the page and the request is
      under «مسودات لم تُرسل بعد», to resume or discard.
- [ ] There is **no «صاحب العلاقة» picker** — R01 files for themselves only, and posting another
      employee's `subject_user_id` → **422**.
- [ ] On success the screen shows an **intake receipt** `PM-RCV/YYYY/NNNNNN` and states in as many
      words that this is a receipt and **not** a قيد with the committee.
- [ ] The new request's `reference_number` is **null** — no `PM-COM/...` number yet. [D] Art. 15 is
      explicit that handing a request to the direct manager "لا يعد قيدًا", and Art. 20 grants the
      رقم إشاري only after completeness is established (relay step 4), when the submitter receives a
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

- [ ] **`متابعة طلباتي`** lists the requests they filed or that are about them, each with its
      current step, who has it now, the next action and the date the step is expected to finish.
      Search (reference, receipt number or subject) and the scope switch (قيد الإجراء · منتهية ·
      الكل) narrow it; «عرض مسار الطلب» shows the timeline and the notices sent.
- [ ] A file **returned** to them (`return_to_employee`, `return_missing_docs`) appears in
      `المهام المعلقة` under «طلباتي المطلوب استكمالها». From the wizard they «إرفاق مستند» and
      then «تقديم الطلب»; the file re-enters the same path and no other R01 ever saw it.
- [ ] They can attach **only while the file is at stage 1** — with the intake itself and after a
      return. While it sits with the manager, HR or the committee, «إرفاق مستند» is not available
      and the upload endpoint refuses.
- [ ] The detail screen shows `المسؤول الحالي` and `الإجراء التالي المطلوب` ([D] Appendices 17/18)
      and **neither is ever empty**, at any stage, including right after submission.
- [ ] Both fields also appear as columns in the request list.
- [ ] The timeline shows each step with its date, actor, الجهة and any linked document
      ([D] Art. 100).
- [ ] The time card ([D] Appendix 71) shows T1–T10; segments that have not both happened read as
      empty, **not** as zero.
- [ ] The stage-timeliness indicator shows `ضمن المدة` / `قرب تجاوز المدة` / `متأخرة` /
      `تأخير حرج` — never a raw number with no label.
- [ ] Attachments preview in-browser for PNG/JPG/PDF; DOC/DOCX download. (The intake form itself
      takes PDF and images only; a later «إرفاق مستند» also accepts DOC/DOCX.)
- [ ] Notes can be read on the الملف tab, and added from the wizard («محتوى الملف» → «إضافة
      ملاحظة»). No tab carries an edit control of its own.

**B4 — Notices ([D] Art. 101).**

- [ ] After the relay, the المخرجات tab's notices register lists the moments the employee was notified
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

- [ ] On a request they created, filing a written withdrawal succeeds — from the wizard, «سجلات
      الملف» → «تقديم طلب سحب». A second one while the first is undetermined is refused, and is
      listed under «غير متاح بعد» with that reason.
- [ ] Attempting the same on **someone else's** request is refused — the endpoint requires the
      actor to be the request's own creator, not merely someone with the grant.
- [ ] R01 **cannot determine** the outcome of their own withdrawal; that is the rapporteur's call
      (§3 B7).

**B6 — Appeal a decided request ([D] Arts. 75–79).**

- [ ] التظلمات lists only their own appeals, and **no row of theirs carries «اتخاذ القرار»** — an
      appellant never acts on their own appeal.
- [ ] Filing runs through the four-step filing wizard (الطلب → أسباب التظلم → التأكيد →
      المستندات). Against a **decided** request it succeeds and captures تاريخ العلم به، أسباب
      الاعتراض، الطلب النهائي; supporting documents are uploaded in the last step and live on the
      appeal.
- [ ] Filing against a request that is **not yet decided** is refused.
- [ ] Filing against **another employee's** request is refused.
- [ ] Filing a **second** appeal on the same request is refused **unless** a new-facts declaration
      is supplied ([D] Art. 75 pt 2).
- [ ] The appeal's `القرار محل التظلم` is filled in automatically from the request's latest
      decision — it is not typed by the appellant, and a value supplied by hand is ignored.

### C. What they must be refused

- [ ] Every approval level: nothing ever appears under «بانتظار اعتمادك», and
      `GET /api/approvals/reviewer` with R01's token → **403**.
- [ ] `GET /api/users`, `/api/departments`, `/api/screen-role-permissions`, `/api/settings`,
      `/api/templates`, `/api/backups`, `/api/maintenance` → **403** each.
- [ ] On their own request at stage 2 the wizard offers **nothing under «قرار هذه المرحلة»** — the
      direct manager acts there, not the submitter — and `POST /api/requests/{id}/transition`
      with `forward` is refused.
- [ ] Exporting: `GET /api/reports/requests/export` and `/api/registers/{code}/export` → **403**
      (export is R06/R07 only). The reports and registers screens themselves are readable, and
      list only the files R01 may open.
- [ ] `POST /api/requests/{id}/legal-reviews` (recording a legal review) → **403**.
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
the قيد at stage 5, the committee's rapporteur seat (scheduling, the agenda, the minutes), every
backward exception, the post-decision registers (referral, return, soundness, the committee-file
archive, closure), and the appeal's formal and legal review. In this seed r02 is **also**
`r01.employee@`'s direct manager, so they appear twice in the relay wearing different hats; keep
the two apart when reading results.

Sign in as `r02.reviewer@abusaleem.test`.

### A. What they must see

- [ ] **14 sidebar entries** (17 screens from the API) before they hold a seat; **21 entries**
      (24 screens) once seated as rapporteur.
- [ ] **No approval screen in the sidebar.** The level-1 work arrives in `المهام المعلقة` under
      «بانتظار اعتمادك», and `GET /api/approvals/ministry` → **403**.
- [ ] `المراجعة القانونية` and `المخرجات` are visible **with or without a seat** (they follow the
      role); recording a review is refused — see C.

### B. What they must be able to do

**B1 — Act as the direct manager (stage 2).**

- [ ] REQ-A appears in `المهام المعلقة` under «طلبات بانتظار إجرائك» as «موافقة وإحالة».
- [ ] In the wizard's Checks step, record **document validity** for every attachment ([D] Appendix
      31): nine checks each, only the seal and copy-matches-original may be غير منطبق, and the
      result per document is computed (سليم / محل شك).
- [ ] Until every attachment is checked and none is محل شك, «موافقة وإحالة» is listed under «غير
      متاح بعد» with that reason and the endpoint refuses `forward`. A file with no attachments
      forwards freely.
- [ ] `forward` then lands the file **directly on stage 4** with status `موجّه إلى الموارد
      البشرية` — one click. Stage 3 is never visited and no route is chosen.
- [ ] `return_to_employee` requires a reason; submitting without one is refused and **no status
      changes** ([D] Art. 10 — no withholding without a written reason).
- [ ] Sign in as any other account — R08 included, since r01 has a live manager — and try
      `forward` on the same request at stage 2 → **refused**. Deactivate r02 and retry as R08:
      now it works (the unstick rule), and it is refused again on a file R08 itself filed.

**B2 — Requirements check (stage 5) — the قيد gate.**

- [ ] Before anything is recorded, `approve`, `declare_no_jurisdiction` and `reject_formally` are
      **all refused** by the endpoint and listed under «غير متاح بعد» with the reason.
- [ ] `return_missing_docs` **is** available even then — a file with missing documents cannot
      honestly be classified yet, so [D] Art. 19's استكمال loop stays open.
- [ ] Record [D] Art. 45's **six-question jurisdiction test**; a partial answer set is refused —
      the classification is not final until all six are answered.
- [ ] Record the **intake gate** ([D] Appendix 63 بوابة 1): one answer per seeded required document
      plus the facts attestation. An unanswered item or a `مفقود` answer is refused by name. An item
      the source marks conditional may be answered `لا ينطبق`; an unconditional one may **not** —
      try waiving one and confirm it is refused naming the document.
- [ ] With both recorded, `approve` succeeds → stage 9, status `تم التسجيل`, and the request is
      granted `PM-COM/YYYY/NNNN` — the قيد, which is المقرر's own act. The file is now on
      `الطلبات المرشحة`.
- [ ] **There is no signature step.** Confirm shows the تأشيرة and an optional note; the confirmed
      click is the record, and it writes approval level 1 on the الاعتمادات tab.
- [ ] Send the file back with `return_missing_docs`, then walk it forward again through the whole
      chain: on the second pass through stage 5 the reference number is **unchanged** — [D] Art. 99
      gives one number for the file's whole life.

**B3 — The two non-registration outcomes at stage 5.**

- [ ] `declare_no_jurisdiction` (self-loop) requires a reason → status `عدم اختصاص`.
- [ ] `reject_formally` (self-loop) requires a reason → status `مرفوضة`.
- [ ] Neither mints a reference number.

**B4 — Stages 6 to 8 (off the path since Stage 102).**

- [ ] Stage 5's `approve` lands the file directly on stage 9 (`استلام الطلب من اللجنة`), status
      `تم التسجيل`, and it appears on الطلبات المرشحة — the committee's pending list.
- [ ] No request can reach stages 6, 7 or 8: `reject_review`, `request_edit` and the three `forward`
      hops no longer exist, and the committee's `return_to_study` lands on stage 5.
- [ ] `cancel` is available at every open stage they own and always requires a reason.

**B5 — Dispatch to legal review ([D] Art. 21).**

- [ ] `الطلبات المرشحة` and `المراجعة القانونية` are lists only: a row opens the request wizard.
      From it, «الإحالة للمراجعة القانونية» sends a stage-9 file to legal review → status
      `تحت المراجعة القانونية`, and the file shows up in R11's `المهام المعلقة`.
- [ ] Attempting to **record** the review itself → **403**. Dispatching is the coordinating act
      ([D] Appendix 6's RACI: العضو القانوني is مسؤول, المقرر only منسق).

**B6 — The post-decision registers (all on `meeting_outputs,edit`).**

All of B6–B8 is recorded from the request wizard — «ما بعد القرار» and «سجلات الملف» — and a
record waiting for a ruling shows up in `المهام المعلقة` as its own task.

- [ ] **Approval referral** ([D] Art. 30): record the outward leg (تاريخ الإحالة، رقم كتاب الإحالة،
      الجهة المحال إليها), then later the result (تاريخ ورود النتيجة، رقم قرار الاعتماد، الملاحظات).
      رقم قرار الاعتماد is required only when the outcome is an approval.
- [ ] **Return from the approving body** ([D] Art. 94): record a return with its kind and reason. A
      reason code the source classes as شكلية cannot be recorded as موضوعية — try the mismatch and
      confirm it is refused quoting the correct classification.
- [ ] While a return is open, the **next approver is blocked** — as R05 the wizard lists `approve`
      under «غير متاح بعد» with the reason, and both `POST /api/requests/{id}/transition` and
      `POST /api/approvals/admin-manager/{id}` are refused with no approval row written.
- [ ] Resolving a **شكلية** return re-refers to the same body without moving the stage; resolving a
      **موضوعية** return sends the file back to stage 9 with status `أعيد فتحه لإعادة العرض`, and
      **the earlier decision row is still there, unchanged** ([D] Art. 94 — the approved محضر is
      never quietly edited).
- [ ] A second round accumulates: the first return is still readable after the second is recorded.

**B7 — Art. 103, 105, execution and closure.**

> **Stage 84 gave R02 the committee-preparation work it had never held.** [D] Appendix 45's
> صلاحية المقرر is "القيد · الفحص · **إنشاء الاجتماع** · **إدارة جدول الأعمال** · تسجيل النتيجة ·
> المحاضر · المتابعة", and R02 held none of the four in bold. Stage 97 then took تسجيل النتيجة back to
> the chair, and Stage 102 made scheduling R02's **alone**. Walk each:

- [ ] **Schedule the month's meeting** for the committee this account sits on — a five-step modal
      that picks its requests from the pending list. The five seats are invited automatically and
      nobody else can be added; afterwards **mark attendance** ([D] Art. 15 (أ) أولًا 12-13، ثانيًا 1).
- [ ] Scheduling for a committee this account does **not** sit on → **422**, naming `committee_id`.
      A second meeting in the same calendar month, or a meeting for a committee with an empty
      seat, is refused too.
- [ ] **Build the agenda**: add (from the pending list only), reorder, apply [D] Art. 83's computed
      order, and remove items — until the chair adopts it. After adoption only an item's priority
      and time can change.
- [ ] **Generate and edit a presentation memo** ([D] Art. 22) — the derived fields recompute while
      the authored ones survive a regenerate.
- [ ] **Propose a new date** after a member declines — every answer resets and the members are
      asked again — and **request completion** on a pending-list file ([D] Art. 15 (أ) أولًا 6).
- [ ] **Prepare the محضر draft** — «إعداد المحضر» in the meeting wizard, offered only once the
      meeting is convened and every item has an outcome ([D] Art. 15 (أ) ثانيًا 9 / ثالثًا 1).
- [ ] **Be told of every answer to the proposed date** — one `meeting_invitation_response`
      notification per accept or decline, the last acceptance saying the meeting is confirmed.
      What R02 no longer holds is recording the result — see C. ([D] Art. 15 (أ) ثانيًا 6 — تسجيل نتيجة
      التصويتis the source's wording; Stage 97 returned the act itself to the chair.)
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
- [ ] **Archive the committee file** ([D] Appendix 6 row 15) — «أرشفة ملف اللجنة», recording where
      it is kept. Closure is refused until it exists — and, when the file carries a committee
      decision, until R12 has archived the service file too. R02 archiving the **service** file →
      **403**.
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
- [ ] **Document validity is not المقرر's** ([D] Appendix 31, user decision 2026-09-26): it is the
      direct manager's gate at stage 2 (B1). On the المخرجات tab its section is read-only, and
      recording it as R02 on a file whose subject they do not manage → **403**.
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
      only their own. Each row with something to do carries «اتخاذ القرار», which opens the appeal
      wizard; the next step of each appeal is also a task in `المهام المعلقة`.
- [ ] **«العرض على اللجنة»** in the appeal wizard puts a `المراجعة القانونية` appeal on an open
      agenda of a committee R02 sits on. The agenda builder itself no longer offers it.
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

- [ ] Any approval level other than their own — `GET /api/approvals/committee-head`,
      `/admin-manager`, `/ministry`, `/final` → **403**.
- [ ] Recording a legal review (`POST /api/requests/{id}/legal-reviews`) → **403** (R11 only), and
      the five verdicts are not offered in the wizard.
- [ ] **Casting a vote**, or declaring a conflict of interest → **403** (`decisions,add` is R03,
      R04, R11 and R12). [D] Art. 16 (أ) 2 forbids المقرر voting unless قرار التشكيل says
      otherwise, which is the committee record's own `rapporteur_votes` flag, not a permission.
      **Recording the result** → **403** as well: `decisions,approve` is R03's alone since
      Stage 97, so the item wizard offers R02 neither act.
- [ ] **Deferring** a candidate, or returning one to study → **422**, not 403: R02 passes the screen
      permission and `WorkflowService`'s own R03 role on those transitions refuses. Two independent
      layers, and the 422 is what proves the second one is real.
- [ ] Approving minutes → **403** (`meeting_minutes,approve` is R03 only — [D] Art. 12 (أ) 13).
      Adopting the agenda → **403** (`meeting_agenda,approve`). Convening a meeting → **403**
      ([D] Art. 12 (أ) 1, 5). Advancing an item's state in the live runner → **403**
      ([D] Art. 12 (أ) 9-10).
- [ ] Every administration screen → **403**.
- [ ] Approving, or recording gate 1 on, a request **they filed or that is about them** → refused,
      at every entry point.

---

## 4. R03 — رئيس اللجنة / Committee Head

**Identity.** [D] Art. 10 (أ)'s رئيس اللجنة. Everything in the sitting is theirs: convening it,
running it, recording the binding decision, and approving the محضر. R03 is also the one role that
can override a readiness exception, so its refusals are the ones that matter most.

Sign in as `r03.head@abusaleem.test`.

### A. What they must see

- [ ] **14 sidebar entries** (17 screens) before they hold the chair seat; **21 entries**
      (24 screens) after, including the whole `إدارة الاجتماعات` group.
- [ ] **No approval screen in the sidebar.** Level 2 is recorded from the item wizard («تسجيل
      النتيجة»), and their other duties arrive in `المهام المعلقة` under «مهام الاجتماعات».

### B. What they must be able to do

**B1 — Committees.**

- [ ] On the committee R08 set up, **maintain the roster** (`meetings,edit`). **Creating** one is
      not the chair's — `POST /api/committees` → **403**, since `meetings,add` is R02's alone.
      Each of the five named seats is bound to a role, and seating someone without that role is
      refused ([D] Art. 10's roster: رئيس، قانوني، مدير الموارد البشرية، مندوب الخدمة المدنية، مقرر).
- [ ] Assigning `chair` forces the head flag. Two members cannot hold the same seat — try it and
      confirm the refusal. The committee form and the «إدارة الأعضاء» roster open in modals.
- [ ] Record the committee's **identity card** ([D] Appendix 65): formation decision number and
      date, legal basis, minutes-approval body, and the quorum / majority / tie-break rules **in the
      text's own words alongside the structured form**.
- [ ] **With no rules recorded**, readiness reports the quorum as `غير مثبت` and blocks convening
      with `قواعد اللجنة غير مثبتة`. This is deliberate: [D] Appendix 64 forbids the system
      inventing a quorum — "ولا يجوز للدليل إنشاء نسبة نصاب أو أغلبية من تلقاء نفسه". A committee
      showing a computed quorum it was never given is a **defect**.
- [ ] Record `أكثر من نصف الأعضاء` while the creator's seatless row is still on the roster (six
      active members — the quorum counts them all) → required quorum is **4**; with that row
      removed (five) it is **3**.
      (`لا يقل عن نصف` on six is 3 — the comparator is what distinguishes them.)
- [ ] Deleting a committee that has meetings is refused; deactivate instead.

**B2 — The meeting's date ([D] Arts. 23–24).**

- [ ] Scheduling is المقرر's: «جدولة اجتماع» is absent for R03, and `POST /api/meetings` → **403**.
- [ ] The meeting R02 scheduled appears in `المهام المعلقة` under «مواعيد اجتماعات بانتظار ردك».
      In the meeting wizard R03 chooses «قبول الموعد» or «الاعتذار عن الموعد» — **for themselves
      only**; nobody can record another member's answer.
- [ ] The meeting number is **assigned by the system** as `PM-MTG/YYYY/NN` — never typed.
- [ ] The meeting stays `بانتظار تأكيد الموعد` until every member has accepted. A decline keeps it
      there, and a date change by R02 resets R03's own answer along with everyone else's.

**B3 — The agenda ([D] Arts. 83–85, Appendices 24/25).**

- [ ] Add employee-request items through «إضافة بند», which searches the committee's **pending
      list** and nothing else. There are no administrative or emerging items; an appeal item
      arrives from the appeal wizard («العرض على اللجنة»).
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
- [ ] «مباشرة الاجتماع» in the meeting wizard shows the readiness verdict in its Checks step. While
      any exception stands, the submit button stays **disabled** until a reason is typed.
- [ ] R03 may convene anyway **with a written reason** — this is the override, and it must be
      recorded, not silent. A meeting still `بانتظار تأكيد الموعد` cannot be convened at all.
- [ ] Convening **freezes** the committee's voting rules onto the meeting: edit the committee's
      quorum afterwards and confirm the held meeting still reports the rule it was convened under.
- [ ] **Adopt the agenda** ([D] Art. 84, Appendix 6 row 8) — refused on an empty agenda, one-shot,
      and **403** for every other role. Before adoption, advancing an item or ticking the study card
      is refused; after it, adding, removing or reordering an item is refused, while an item's
      priority and time stay editable.

**B5 — Running the sitting ([D] Art. 85, Appendix 25).**

- [ ] Mark attendance. Advance an item through `معروض → مناقشة → تصويت → اتخاذ القرار → مكتمل`.
- [ ] The nine-step **study card** is enforced **as a sequence**: marking a step before its
      predecessor is refused, naming the missing step.
- [ ] **Voting is refused until the sequence completes** — and the members' "awaiting my vote"
      worklist must be **empty** at the same moment. The screen and the endpoint must never disagree.
- [ ] Once a vote exists, un-ticking a step is refused, and **attachments and the presentation memo
      are frozen** for that request ([D] Appendix 25 — "ولا يجوز استمرار تعديل الوقائع أو المستندات
      بعد بدء التصويت"). Regenerating the memo is the
      reachable half of this rule today — nobody who may attach can reach a file under vote.

**B6 — Recording the decision.**

- [ ] «تسجيل النتيجة» in the item wizard shows the outcome the votes produce before it is
      recorded; with members voting 2-1 it records the majority outcome, writes approval level 2
      when that outcome is موافقة, and numbers the decision `PM-DEC/YYYY/NNN`. No signature is asked.
- [ ] A **tie** is refused and **no decision row is written**. A zero-vote item is refused too.
      Both are listed under «غير متاح بعد» with the reason.
- [ ] Let a member vote while the wizard is open on Confirm: the stale outcome is refused
      («تغيّرت نتيجة التصويت منذ فتحت هذه النافذة…») and the slip refreshes to the true one.
- [ ] Every decision requires [D] Appendix 27's four parts (الموضوع، الوقائع، السند، المنطوق) and
      [D] Art. 90's instrument (قرار / توصية / رأي) — the instrument is pre-filled from the legal
      card but the recorder may change it.
- [ ] A منطوق of "اتخاذ اللازم." alone is refused; "اتخاذ اللازم نحو إحالة الملف إلى الإدارة
      القانونية خلال أسبوع." is accepted. Same for "لعدم الاستحقاق" as the entire reasoning.
- [ ] A **deferral** requires [D] Art. 34's four mandatory fields; "تأجيل للمراجعة" with nothing
      stated is refused ([D] Appendix 29).
- [ ] A **refusal** requires one of [D] Appendix 28's reason codes plus real substantiation.
- [ ] `عدم اختصاص` is available as a committee outcome and lands on its own status. `إعادة
      للدراسة` is not a vote outcome: it is the chair's act on a pending-list file, from the
      request wizard, and it lands the file on stage 5 with R02.
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
- [ ] «اعتماد المحضر» in the meeting wizard stays disabled until the reviewer's statement on the
      Checks step is ticked. Instead of approving, R03 may «إعادة المحضر للتعديل» with a reason —
      only then can the draft be prepared again.
- [ ] After approval, one signature row exists per present attendee, and each finds the محضر in
      `المهام المعلقة` under «محاضر بانتظار توقيعك». Signing is a confirmed click — **no drawn
      signature**. The last signature flips the محضر to `معتمد`.
- [ ] **The meeting cannot be closed** while any agenda item is unresolved, or while the محضر is
      not `معتمد`.
- [ ] **A closed meeting is read-only**: every further write on it — attendance, agenda, vote,
      decision, minutes — is refused (422), and the status control on the meeting page offers
      only cancel.

**B8 — Also available to R03**

- [ ] Everything in §3 B6–B8 (registers, execution, the committee-file archive, closure, lifecycle
      records) — R03 shares `meeting_outputs,edit` with R02.
- [ ] Defer, return-to-study and request-completion on a pending-list file — from the request
      wizard that «فتح الملف» opens on `الطلبات المرشحة`.
- [ ] **«العرض على اللجنة»** on an appeal at `المراجعة القانونية`, when seated on the committee:
      the wizard shows the appeal's status, appellant and original reference, and nothing else of
      it — R03 nominates, it does not administer the appeal.
- [ ] **Read the legal-review queue** (`GET /api/legal-reviews`, Stage 101 — [D] Appendix 6 row 6,
      اللجنة «مطلع») → 200, and open a queued file. Recording stays refused (§C); R04 gets **403** on
      the same queue.

### C. What they must be refused

- [ ] Any approval level other than their own (`GET /api/approvals/reviewer`, `/admin-manager`,
      `/ministry`, `/final`) → **403**.
- [ ] **Scheduling a meeting, or creating a committee** → **403** (`meetings,add` is R02's alone
      since Stage 102).
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

- [ ] As `r04.member1@`, seated as the civil-service delegate: **19 sidebar entries** (21 screens)
      — seven screens of the meetings group (no `المراجعة القانونية`, no `المخرجات`).
- [ ] As `r04.member2@`, who holds no seat: **12 entries** (14 screens) and **no meetings group at
      all** — the gate reads the seat, not the role — and a meeting opened by id answers **404**.
- [ ] **No approval screen.**

### B. What they must be able to do

Sections B and C are walked as `r04.member1@`, the seated member.

- [ ] **Answer the proposed date** — the meeting appears under «مواعيد اجتماعات بانتظار ردك», and
      «قبول الموعد» / «الاعتذار عن الموعد» in the meeting wizard record their own answer only.
- [ ] Open a meeting and its agenda, and read any request on the agenda **even though they did not
      create it and hold no workflow role on it** — the live runner's quick-info tabs (summary,
      employee, study, attachments, previous requests, notes) must all load, and an attachment must
      stream rather than 404.
- [ ] Post discussion notes during a sitting.
- [ ] **Cast a vote** from the item wizard — reached from «بانتظار تصويتك» or the Decisions
      screen's «بانتظار تصويتي» tab, and confirmed as a ballot slip — but only when all of these
      hold: the meeting is convened and its agenda adopted, they are a member of that meeting's
      committee, they are marked as having **attended**, they have not declared a conflict, and the
      study sequence is complete. Until then «التصويت» is listed under «غير متاح بعد» with the
      reason.
- [ ] Declare a **conflict of interest** on an agenda item, in the wizard's Checks step. Afterwards
      voting **and** the discussion feed are both refused for that item, and the declaration
      appears in the compiled محضر.
- [ ] Prepare the محضر draft, and **sign** their own signature row — a confirmed click from
      «محاضر بانتظار توقيعك», no drawing.
- [ ] Post to the live discussion feed on an agenda item.

### C. What they must be refused

These are the segregation-of-duties checks; do both halves of each.

> **Stage 84 moved four of these out of section B.** [D] Art. 13 (أ) gives a committee member
> studying, discussing and voting, and Appendix 45's صلاحية أعضاء اللجنة is "الاطلاع على الملفات ·
> الاطلاع على جدول الأعمال · تسجيل الحضور · المشاركة في الاجتماع" — no convening, no agenda, no
> memo. R04 previously held all four. If any of the next four checks passes, the matrix has drifted
> back.

- [ ] **Create a committee, or schedule a meeting** → refused (`meetings,add` is R02's alone).
      الدعوة is the chair's under Art. 12 (أ) 1 and إنشاء الاجتماع is المقرر's under Appendix 45.
- [ ] **Defer, return-to-study or request-completion** on a pending-list file → refused
      (`committee_candidates` is R02 + R03 on both tiers), and the request wizard offers none of
      them.
- [ ] **Generate or edit a presentation memo** → refused. [D] Appendix 6's RACI makes إعداد مذكرة
      العرض مقرر اللجنة's own responsibility; R04 held this only because Stage 46 read "a member
      acting as مقرر" into the grant.
- [ ] **Add, reorder or remove an agenda item** → refused (`meeting_agenda` is R02 + R03).
- [ ] **Add committee members**, mark attendance, or edit a meeting → refused (`meetings,edit` is
      R02 + R03). Answering the proposed date **for another member** → refused.
- [ ] **Convene a meeting** → refused (`meeting_readiness,edit` is R03 only).
- [ ] **Advance an item's state** in the live runner → refused (`meeting_live,edit` is R03 only).
- [ ] **Record a decision** → refused (`decisions,approve` is R03 only). Voting is `decisions,add`
      and is allowed; recording the tallied outcome is not.
- [ ] **Approve the محضر** → refused (`meeting_minutes,approve` is R03 only). Generating and signing
      are allowed.
- [ ] **Close or execute a request**, record an approval return, a suspension, or any lifecycle
      record → refused (`meeting_outputs,edit` is R02 + R03).
- [ ] **Adopt the agenda** → refused (`meeting_agenda,approve` is R03 only).
- [ ] Record a legal review → **403**.
- [ ] Every approval endpoint (`/api/approvals/…`) → **403**.
- [ ] Every administration screen → **403**.
- [ ] Vote on an item in a meeting whose committee they are **not** a member of → refused.
- [ ] Vote when marked **absent** → refused.
- [ ] Vote **after** the decision has been recorded → refused.

---

## 6. R05 — مدير إدارة الشؤون الإدارية / Admin Manager

**Identity.** The administrative-authority approver at stage 10 (`اعتماد (حسب الصلاحيات)`) — the
only workflow rule it holds since Stage 87, which moved the stage-4 registration to R12 (مدير إدارة
الموارد البشرية). Its one other capability is filing a request on another employee's behalf
(Stage 95). If a build older than Stage 87 is under test, R05 will still hold the stage-4
registration — check `WorkflowTransitionSeeder` before assuming this section describes the running
database.

Sign in as `r05.manager@abusaleem.test`.

### A. What they must see

- [ ] **12 sidebar entries** (15 screens) — R05 can hold no committee seat, so there is no
      meetings group.
- [ ] **No approval screen in the sidebar.** The fifteenth screen is the `admin_manager_approval`
      grant row, which has no page; the work arrives in `المهام المعلقة` under «بانتظار اعتمادك».

### B. What they must be able to do

- [ ] **Stage 10** `اعتماد (حسب الصلاحيات)`: the file is in `المهام المعلقة`; `approve` from the
      wizard → stage 11, status `بانتظار الاعتماد المركزي`. Confirm is a تأشيرة with an optional
      note — **no signature**.
- [ ] Opening the request from the requests list offers the same «اتخاذ القرار» and the same
      wizard, and the two entry points agree — one approval row is written, and approving twice is
      refused.
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

- [ ] **Register a file at stage 4** → refused. R05 holds no `register` row at
      `receive_and_register` — confirm this lands as a **404** opening a file it did not file, not
      merely a 422 on the transition, since R05 lost visibility into that stage along with the role.
- [ ] **Act at stage 2 on a request whose subject they do not manage** → refused; that step
      belongs to صاحب العلاقة's own manager, whatever role anyone else holds.
- [ ] Any approval level other than their own (`GET /api/approvals/reviewer`, `/committee-head`,
      `/ministry`, `/final`) → **403**.
- [ ] **Record the jurisdiction test, the intake gate, or correct the financial-impact flag** →
      **403**. All three ride `notes_attachments,edit`, which is R02's alone; R05 holds `add`.
- [ ] Approve at stage 10 while an **approval return is open** on that request → the wizard lists
      `approve` under «غير متاح بعد» with the reason, and both the transition endpoint and
      `POST /api/approvals/admin-manager/{id}` refuse it, with no approval row written.
- [ ] Approve at stage 10 on a request **they created themselves** → refused.
- [ ] Close or execute a request → **403**. Record a committee decision → **403**.
- [ ] Every administration screen → **403**.
- [ ] Export anything (`reports`, `registers`, `audit-logs`, `requests`) → **403**; R05 may read
      all four but carry none of them out.

---

## 7. R06 — وزارة الحكم المحلي / Ministry

**Identity.** [D] Art. 31's central approval tier, and Art. 14's مندوب الخدمة المدنية. In this system the
delegate's committee seat is bound to R04, so R06 itself never sits. Two things about this role
are load-bearing and easy to get wrong: it must see **only** the files that actually reached it,
and the committee's vote must never substitute for this separate approval.

Sign in as `r06.ministry@abusaleem.test`.

### A. What they must see

- [ ] **12 sidebar entries** (15 screens) — no meetings group: the civil-service delegate seat is
      bound to R04, not to this role.
- [ ] **No approval screen in the sidebar**; stage-11 files arrive in `المهام المعلقة` under
      «بانتظار اعتمادك».

### B. What they must be able to do

- [ ] **Stage 11**: `approve` from the wizard → stage 12, status `معتمدة نهائياً`. A confirmed
      click with an optional note — no signature.
- [ ] **Supervise what they approved** ([D] Appendix 6 row 13): a file R06 approved stays openable
      through execution (`قيد التنفيذ` · `منفذة` · `موقوفة لمراجعة قانونية` · `مكتمل ومغلق`), and a
      note can be added to it from the wizard.
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

- [ ] Any approval level other than the ministry's → **403**.
- [ ] A request that **never reached stage 11** and that they did not create → **404** on open.
      Approving is not enough of a reason to read the whole pipeline.
- [ ] **Adding an attachment** → refused: only the filer attaches. (Adding a *note* is allowed
      since Stage 100, but only on a file they can open — see B.)
- [ ] **A committee member's own actions** — voting, declaring a conflict of interest, preparing or
      signing the محضر → **403** each. R06 holds no `decisions,add` or `meeting_minutes,add` grant
      and no seat.
- [ ] Record a committee decision, close a request, or record a legal review → **403** each.
- [ ] Every administration screen → **403**.
- [ ] **The committee-seat check ([D] Art. 14).** Try to seat the R06 account as `مندوب الخدمة
      المدنية`: **refused** — the seat is bound to R04. Then confirm that the delegate's committee
      vote (cast by R04) changes nothing downstream: the request **still** goes to stage 11 for
      the ministry's separate approval when its grade requires it, and **still** bypasses when it
      does not. A committee vote that silently satisfies the central approval is a serious
      compliance failure.

---

## 8. R07 — المدير العام / العميد / Director

**Identity.** The municipality's final approving authority. R07's defining trait is what is missing:
**they have no intake screen at all** — the dean approves, they do not do data entry — and since
Stage 57 they hold exactly one approval checkpoint, not two.

Sign in as `r07.director@abusaleem.test`.

### A. What they must see

- [ ] **10 sidebar entries** (13 screens) — no meetings group, since no seat is bound to R07.
- [ ] **No approval screen in the sidebar**; stage-12 files arrive in `المهام المعلقة` under
      «بانتظار اعتمادك».
- [ ] **No `إرسال الطلب` and no `متابعة طلباتي`**, and `POST /api/requests` → **403**. This is
      deliberate; confirm it rather than filing it as a missing feature.
- [ ] There is **no `اعتماد السلطة المختصة`** screen anywhere. It was removed with the stage it
      belonged to; if it appears, the seeder's deletion did not run.

### B. What they must be able to do

- [ ] **Stage 12** (a self-loop): `approve` from the wizard → status `قيد التنفيذ`. A confirmed
      click — no signature.
- [ ] Keep sight of a file they approved through its execution, and add a note to it (Stage 100).
- [ ] The same export set as R06 — reports, registers, audit log, requests list.
- [ ] Read the performance indicators and the periodic reports.
- [ ] Receive an escalation notification when a file goes `حرج` ([D] Appendix 38's third rung
      reaches رئيس اللجنة and the السلطة المختصة).

### C. What they must be refused

- [ ] `approve` at stage 12 **before** [D] Art. 103's soundness checklist has been recorded by R02
      or R03 → refused, and listed under «غير متاح بعد» with that reason. The file is prepared by
      one hand and referred to execution by another.
- [ ] `approve` when any attested soundness answer is `لا` → refused, quoting the failed question.
- [ ] `approve` while a **suspension** ([D] Art. 105) is open → refused.
- [ ] Any approval level other than the final one → **403**.
- [ ] Adding an attachment → refused (only the filer attaches); a note on a file they never
      approved → **404**, since they cannot open it.
- [ ] Filing a request → **403**.
- [ ] Every administration screen → **403**.
- [ ] Approving a request they created → refused (they cannot create one, but check the guard holds
      if an admin creates one on their behalf).

---

## 9. R08 — مدير النظام / System Admin

**Identity.** The only role that holds all seven actions on all 36 screens. R08 is a **support**
role: use it to configure the system and to unblock, not to walk the process — it holds none of the
approval roles and no committee seat. Most of this section is about the administration screens
nobody else can reach.

Sign in as `r08.sysadmin@abusaleem.test` (leave `admin@abusaleem.test` untouched as a spare).

### A. What they must see

- [ ] **29 sidebar entries** (36 screens). The seven missing from the menu are `تفاصيل الطلب`,
      `الملاحظات والمرفقات` and the **five approval grant rows**, which have no page — they are
      rows on the Roles & Permissions grid only.
- [ ] The whole `إدارة الاجتماعات` group (nine screens) **without a seat** — R08 is exempt from
      the seat rule, because it is the role that builds the roster.
- [ ] `المهام المعلقة` never lists anything under «بانتظار اعتمادك» — R08 holds no approval role.
      The group it does get is «طلبات متأخرة بانتظار التصعيد».

### B. What they must be able to do

Every create/edit form on these screens opens **in a modal** from a button — never inline above
the table. Escape closes it; a click on the backdrop does not; a 422 is shown inside it.

**B1 — Users.**

- [ ] Create, edit, deactivate and delete a user; assign multiple roles; assign a **manager**.
- [ ] The manager picker never offers the user being edited as their own manager.
- [ ] Deactivating a user immediately refuses them at login **and** as a workflow actor.
- [ ] **The phone number cannot be set here** — the user sets it on their own notification
      preferences (and `TestUserSeeder` seeds it). Confirm the field is absent rather than
      present-and-ignored.
- [ ] The screen switches between **«جدول»** and **«حسب الإدارة»**. The department view shows the
      tree, each department's head card and its staff, with «إضافة موظف هنا».
- [ ] In that view «تعيين رئيساً» sets the head, «نقل إلى…» moves a person to another department,
      and dragging a person onto a tree node does the same (on a touch screen the grip is absent
      and the select still works).
- [ ] **The head is a label only.** It must be an active member of the department and is cleared
      when that person moves, is deactivated or is deleted — and nothing in the workflow reads it:
      the stage-2 step still belongs to each employee's own `manager_id`.

**B2 — Departments and request types.**

- [ ] Create a child department under the tree, and pick its head in the form.
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

- [ ] The matrix grid shows 36 screens × 12 roles × 7 actions — the five approval rows among
      them, although none of the five is a page.
- [ ] Revoke `اعتماد` from R02 on `اعتماد المقرر`, then sign in as R02: a stage-5 file no longer
      offers `approve` in the wizard, it leaves «بانتظار اعتمادك», **and**
      `POST /api/approvals/reviewer/{id}` returns 403. Restore it afterwards.
- [ ] Grant R04 `meeting_agenda,edit`, confirm R04 can now add an agenda item, then revoke it and
      confirm they cannot. Changes must take effect on the next request, with no re-seed.

**B4 — Settings, templates, guide.**

- [ ] Settings key/value rows save and are read back.
- [ ] Create a template with category `decision`; it then appears in the committee's decision
      drafting picker for R03 (the item wizard's «تسجيل النتيجة»). A template left inactive does not.
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

- [ ] **R08 only unsticks.** When صاحب العلاقة has **no live manager** (none assigned, deactivated
      or deleted), R08 may use the manager's rows at stage 2 — validity, `forward`,
      `return_to_employee`, `cancel`. While a live manager exists R08 is refused, like everyone
      else.
- [ ] R08 can route a file flagged overdue to ministry oversight — the task sits in
      `المهام المعلقة` under «طلبات متأخرة بانتظار التصعيد», and «تصعيد لانتهاء المهلة» takes a
      mandatory reason.
- [ ] Create the first committee and fill its five seats (relay step 0) — the one piece of
      committee work that is an administrator's.

### C. What they must be refused

- [ ] **Approving anything.** R08 holds none of the approval roles, so no `approve` is offered at
      any level and each `POST /api/approvals/{level}/{id}` is refused by `WorkflowService` — the
      grant on the approval rows is not enough. Add R02 to the account on the Users screen and
      `approve` appears at stage 5.
- [ ] **Approving a request they filed or are the subject of** → refused even with the role added.
      The self-approval block is in `WorkflowService`, not in the permission matrix. File a request
      as R08-plus-R02, walk it to stage 5, and confirm.
- [ ] **Unsticking their own file** → refused: the unstick rule never applies to a file R08 filed
      or is the subject of.
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
      group from anyone holding no committee seat — and no seat is bound to R09, so it cannot be
      given one.

### B. What they must be able to do

- [ ] Open `المهام المعلقة`: it loads and says nothing is waiting — R09 holds no duty that could
      put a task there.
- [ ] Read the request list, the registers, the reports and the audit log — each listing only
      the files R09 may open.
- [ ] Set their own notification preferences.

### C. What they must be refused

- [ ] **Register any file at stage 4**, on any route → refused. The single `register` row is R12's
      and is status-gated on `موجّه إلى الموارد البشرية`.
- [ ] **`cancel` at stage 4** → refused; that row is R12's too.
- [ ] **`approve` at stage 5** → refused. The hop onto the committee's pending list is R02's.
- [ ] **Dispatch a file to legal review** → **403** (`legal_review,edit` is R02 only) — and the
      screen is not even reachable, which is the stronger check.
- [ ] **Defer, return-to-study or request-completion** on a pending-list file → **403**.
- [ ] **Build the agenda** — add, reorder or remove an item, apply the computed order, or generate a
      presentation memo → **403**.
- [ ] **Add a note or an attachment** → **403**. The `notes_attachments,add` grant went with the
      `register` row it was bounded to.
- [ ] Every approval endpoint (`/api/approvals/…`) and every administration screen → **403**.

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

- [ ] Open `المهام المعلقة`: it loads and says nothing is waiting.
- [ ] Read the request list, the registers, the reports and the audit log.
- [ ] Set their own notification preferences.

### C. What they must be refused

- [ ] **Register any file at stage 4** → refused. Run this explicitly: it is the clearest proof that
      the Diwan route is gone rather than merely unused.
- [ ] **`cancel` at stage 4** → refused.
- [ ] File a request → **403**.
- [ ] Any committee action at all — schedule, agenda, vote, decide, minutes → **403**.
- [ ] Close or execute a request, or add a note or an attachment → **403**.
- [ ] Every approval endpoint (`/api/approvals/…`), every administration screen, and any export → **403**.

---

## 12. R11 — العضو القانوني / Legal Officer

**Identity.** [D] Art. 14 (ب)'s العضو القانوني. R11 is the only role that may **record** the
pre-meeting legal review, and [D] is explicit about the limit of that authority: the opinion
"لا يحل محل مداولة اللجنة أو تصويتها، كما لا يمنح العضو القانوني سلطة منفردة في قبول الطلب أو رفضه".
Both halves need testing — the exclusive power, and its boundary.

Sign in as `r11.legal@abusaleem.test`.

### A. What they must see

- [ ] **11 sidebar entries** (13 screens) before they hold the legal seat; **18 entries**
      (20 screens) after.
- [ ] `المراجعة القانونية` is present **with or without a seat**, and its queue lists **only** files
      currently handed to legal review. Each row opens the request wizard.
- [ ] **No approval screen**, **no `إرسال الطلب`**, no `متابعة طلباتي`.

### B. What they must be able to do

- [ ] **Open a file in their queue that they did not create and hold no workflow role on.** This is
      the first thing to check: if it 404s, the queue is a dead end and nothing else in this section
      can be tested. The same file is a task in `المهام المعلقة` under «بانتظار المراجعة
      القانونية».
- [ ] The wizard's Checks step pre-fills the request type's **legal basis** ([D] Appendix 21) for the six
      types the appendix actually names, and leaves it **empty** for the other six rather than
      inventing a citation. Check one of each.
- [ ] Record [D] Appendix 22's **بطاقة السند القانوني** — eight fields, including
      `نوع الاختصاص` (قرار / توصية / رأي / **دراسة فقط**) and `الاعتماد المركزي` as a **three-value**
      answer (نعم / لا / **يحتاج إلى تحقق**), not a yes/no.
- [ ] Record each of the five verdicts — offered in the Choose step under «رأيك القانوني» — and
      confirm where each sends the file:
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
      `العضو القانوني` seat: answer the proposed date for themselves; then, marked attended, post to
      the item's discussion feed, **vote** from the item wizard, sign the محضر (a confirmed click),
      and record a legal note on the **draft** محضر (refused once it is under signature). The seat
      itself is refused to anyone without R11.
- [ ] **Generate and edit the presentation memo** (Stage 101 — Appendix 6 row 7, «مشارك») — seated
      in the `العضو القانوني` seat, write the `legal_opinion` field on an agenda item's memo. Adding,
      reordering or removing agenda items stays refused (§C).

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
- [ ] Every approval endpoint (`/api/approvals/…`) and every administration screen → **403**.

---

## 13. R12 — مدير إدارة الموارد البشرية / HR Manager

**Identity.** [D] Appendix 6's الموارد البشرية / شؤون الموظفين column. R12 is the one receiving
party at stage 4, where it **prepares the employment file** before it may register the receipt
(Stage 98); it holds the `hr_director` committee seat and votes (Stage 102); it is the executing
body [D] names most often, so it records execution and closure (Stage 92) and archives the service
file (Stage 100). Its reach into the study at stages 2 and 5 is deliberately narrow: read the file
and add a note, never move it.

Sign in as `r12.hr@abusaleem.test`.

### A. What they must see

- [ ] **11 sidebar entries** (13 screens) before they hold the HR seat; **18 entries**
      (20 screens) after.
- [ ] `المخرجات` is present **with or without a seat** — it follows the role, because the
      executing body must reach it either way.
- [ ] **No approval screen**, **no `إرسال الطلب`**, no `متابعة طلباتي`.

### B. What they must be able to do

- [ ] **Prepare the employment file** (stage 4, status `موجّه إلى الموارد البشرية`) — the task is
      in `المهام المعلقة` as «تجهيز الملف الوظيفي والتسجيل». In the wizard's Checks step answer
      each employment-record document the type's matrix requires (مرفق / لا ينطبق / ناقص) and the
      attestation. `لا ينطبق` is accepted only for a conditional document, a document already
      attached is derived, and R12 may upload an employment-record document here — nothing else.
- [ ] **Register the receipt** → stage 5, status `قيد المراجعة`. Until the employment file is
      prepared, «تسجيل الاستلام» is listed under «غير متاح بعد» and the endpoint refuses. Registering
      mints **no** reference number — that is R02's `approve` (Appendix D).
- [ ] `cancel` at stage 4 with a reason.
- [ ] **As the `hr_director` seat**: answer the proposed date, post to the discussion feed, vote
      from the item wizard, and sign the محضر.
- [ ] **Archive the service file** ([D] Appendix 6 row 15) — «أرشفة ملف الخدمة», asked only when the
      file carries a committee decision. Closure is refused until it is recorded.
- [ ] **Open a request sitting at stage 5 (`فحص استيفاء المتطلبات`)** that they did not create and
      hold no registration role on — the study reach (Stage 102 moved it off stage 7, which is gone).
- [ ] **Add a note** on that same request (`notes_attachments,add`).
- [ ] **Open and annotate an unregistered file at stage 2 (`مراجعة المدير المباشر`) and stage 5
      (`فحص استيفاء المتطلبات`)** — Stage 101, Appendix 6 rows 2 and 4 («مشارك»). Registered files
      later on are also readable and annotatable, through the execution grant (rows 6, 7, 9).
- [ ] **Receive the informational notices** (Stage 101): a new-request notice when an employee files
      (row 1), and `Copy of the employee notice` each time an Art. 101 notice is sent (row 14). The
      copy names the moment only and never repeats the notice text (Art. 102). Mute it in
      notification preferences and confirm nothing else is muted.
- [ ] Read the request list, the registers, the reports and the audit log.
- [ ] **Execute a request they did not create**, naming إدارة الموارد البشرية as the executing body
      ([D] Appendix 70 — Stage 92: [F] step 10's "who executed" question, now on its own
      `meeting_outputs,approve` tier alongside R02/R03, not the `edit` tier those two hold). **Close**
      the same request afterward. This is the check that the acting grant now matches the party the
      execution record names, when HR itself is that party.

### C. What they must be refused

- [ ] **Register before the employment file is prepared, or with a `ناقص` answer on it** →
      refused. Run this explicitly: it is the proof that the stage-4 gate is enforced and not
      decorative.
- [ ] **`approve`, `return_missing_docs`, or `cancel` a request at `requirements_check`** →
      refused for all three. Co-ownership of the study is read-and-contribute, never stage control.
- [ ] **Open an unregistered request** at a stage outside stages 2 and 5 (for example
      `التوجيه الإداري`, stage 3) that they are not the registrar or creator for → **404**. The reach
      is bounded to the stages Appendix 6 names, not "anywhere before the قيد".
- [ ] File a request → **403**.
- [ ] Schedule a meeting, build the agenda, convene, record the result or approve the minutes →
      **403**. (Voting and signing are theirs once seated — see B.)
- [ ] **Record an approval return, a suspension, the Art. 103 execution-soundness checklist, or
      the committee-file archive** → **403**. These stay `meeting_outputs,edit`, which R12 does
      not hold — only `execute`/`close` ride the `approve` tier R12 shares with R02/R03, and the
      service-file archive rides `add`, which is R12's alone.
- [ ] Every approval endpoint (`/api/approvals/…`) and every administration screen → **403**.
- [ ] Export anything → **403**.

---

## 14. Cross-role checks

These do not belong to any one role, and each one has broken at least once in this system's
history. Run them after the role sections.

### 14.1 Multiple roles are a union, never an intersection

Sign in as `multi.role@abusaleem.test` (R03 **+** R04).

- [ ] The sidebar shows **14 entries** (17 screens) — R03's unseated set, since R04 adds nothing
      R03 lacks. Seated, it is 21 (24).
- [ ] They hold the `اعتماد رئيس اللجنة` grant through R03 — visible as a ticked row on the Roles
      & Permissions grid and in `auth.can()`, not as a sidebar entry.
- [ ] Seated as chair, they can **both** vote (`decisions,add`) **and** record the result
      (`decisions,approve`, R03 only). If recording is refused, permissions have regressed to an
      intersection.

### 14.2 An inactive user is refused twice

Sign in attempt as `inactive.user@abusaleem.test`.

- [ ] Login is refused with its **own message**, not a generic "bad credentials".
- [ ] Re-activate them, mint a token, deactivate them again, then use that token to attempt a
      workflow transition → refused by `WorkflowService`, which re-checks the actor's active status
      independently of the login gate.

### 14.3 Nobody approves their own work

- [ ] For each of R02, R03, R05 and R06: file a request as that account, walk it to the checkpoint
      that account owns, and confirm the approval is refused. Check both entry points — the wizard
      (where `approve` is not offered) and `POST /api/approvals/{level}/{id}` directly.
- [ ] **صاحب العلاقة is blocked exactly as the filer is.** Have R05 file a request naming
      `r02.reviewer@` as its subject. r02 has no manager in the seed, so R08 moves it past stage 2
      (the unstick rule — itself worth seeing); at stage 5, r02 is refused both gate 1 and
      `approve` on the file that is about them.

### 14.4 Login throttle

- [ ] `POST /api/auth/login` is rate-limited at **six attempts per minute**. Signing in as seven
      accounts in quick succession — which the one-click test-user picker makes easy — returns 429
      on the seventh. Confirm the UI reports it as rate limiting and not as a wrong password.

### 14.5 Visibility is a 404, not an empty list

- [ ] As any role, open a request id you have no relationship to → **404**.
- [ ] The exceptions, each of which must work: R11 on a file in legal review; R02/R03/R12 on a
      registered file in the approval, execution or closable states; a user in the **Salaries**
      department (`SAL`) on any request flagged with a financial impact; an approver on a file
      they approved, through its execution; the subject's manager while the file is at stage 4;
      and every user on requests they filed **or that are about them**.
- [ ] **Rows, not screens.** The requests list, the registers, the detailed report and the
      decisions register show each person only the rows they may open — except R06/R07, the export
      holders, who read a register whole.

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

### 14.9 Pending Tasks agrees with the endpoints

- [ ] `المهام المعلقة` is in **all twelve** roles' sidebars, and `GET /api/my-tasks` answers 200
      for every one of them.
- [ ] At each relay step the task is in the actor's list before they act and gone after, and it
      appears in the next actor's list without anyone refreshing a queue.
- [ ] **Nothing listed is refused.** Take one task from each of five different accounts and
      perform it: none answers 403 or 422 for a reason of permission. The inbox offers only what
      the endpoint will accept.
- [ ] **Nothing is listed for a bystander.** A file returned to its filer is in that filer's list
      only; a meeting's date is in its five members' lists only; a correction memo awaiting
      approval is never in its own author's list.

### 14.10 The wizard tells the truth

- [ ] For four blocked acts — `forward` before document validity, `register` before the
      employment file, `approve` before gate 1, closure before the archive — the reason under «غير
      متاح بعد» is word for word what the endpoint answers when called directly.
- [ ] No tab of the request page, no agenda or minutes screen and no appeals row carries an edit
      or submit control of its own: every write starts from «اتخاذ القرار» / «اتخاذ الإجراء».
- [ ] Two sessions on one act: the second to submit sees the refusal **inside** the wizard, which
      stays open, and nothing is recorded twice.
- [ ] A link that names an act — a task, or the execute/close cells on `المخرجات`
      (`?decide=<act>`) — opens the wizard on that act with no error, including on a file where
      the act is no longer available.

### 14.11 Forms open in modals

- [ ] On Departments, Users, Request Types, Settings, Templates, Meetings (the committee form,
      the scheduling steps, the member roster) and Appeals, the create/edit form opens in a modal
      from a button and is never rendered inline above the table. Escape closes it and returns
      focus to the button; a click on the backdrop does **not** close it.
- [ ] Filter bars stay inline on the page, and a validation error (422) is shown inside the modal
      that caused it.

### 14.12 Phones

- [ ] At 375px the sidebar is a drawer opened by a button, sliding in from the correct side in
      **both** languages, and no page scrolls sideways.
- [ ] At 375px every table row is a card: each value sits under its column's name, and the names
      follow the language.
- [ ] At 375px a wizard and a modal fit the screen and scroll inside themselves, with their
      buttons reachable.

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
Re-derived in full in October 2026, cell by cell from the two seeders: the previous table lacked
`my_tasks` altogether and had drifted in eight cells of `notes_attachments`, `meetings`,
`meeting_live`, `decisions`, `meeting_minutes` and `meeting_outputs` (Stages 100 and 102, and the
narrowing of `meeting_outputs,view`). That drift is the reason the file's own instruction is to
re-derive rather than hand-edit.

Letters: `v` view · `a` add · `e` edit · `d` delete · `A` approve · `p` print · `x` export ·
`·` no access at all.

| Screen | R01 | R02 | R03 | R04 | R05 | R06 | R07 | R08 | R09 | R10 | R11 | R12 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `dashboard` — لوحة التحكم الرئيسية | vp | vp | vp | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `requests` — الطلبات | vp | vp | vp | vp | vp | vpx | vpx | vaedApx | vp | vp | vp | vp |
| `request_intake` — إرسال الطلب | vae | vaeA | va | va | vaeA | va | · | vaedApx | · | · | · | · |
| `request_details` — تفاصيل الطلب | vpx | vpx | vpx | vpx | vpx | vpx | vpx | vaedApx | vpx | vpx | vpx | vpx |
| `notes_attachments` — الملاحظات والمرفقات | va | vae | va | va | va | va | va | vaedApx | v | v | v | va |
| `my_tasks` — المهام المعلقة | v | v | v | v | v | v | v | vaedApx | v | v | v | v |
| `request_tracking` — متابعة طلباتي | vp | vp | vp | vp | vp | vp | · | vaedApx | · | · | · | · |
| `appeals` — التظلمات | vap | vep | vp | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `meetings_dashboard` — لوحة قيادة الاجتماعات | vp | vp | vaep | vap | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `committee_candidates` — الطلبات المرشحة | vp | vaep | vaep | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `legal_review` — المراجعة القانونية | p | vep | vp | p | p | p | p | vaedApx | p | p | vap | p |
| `meetings` — الاجتماعات | vp | vaep | vep | vp | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `meeting_agenda` — جدول الأعمال | vp | vaep | vaeAp | vp | vp | vp | vp | vaedApx | vp | vp | vap | vp |
| `meeting_readiness` — جاهزية الاجتماع | vp | vp | vaep | vap | vp | vp | vp | vaedApx | vp | vp | vp | vp |
| `meeting_live` — مباشرة الاجتماع | vp | vap | vaep | vap | vp | vp | vp | vaedApx | vp | vp | vap | vap |
| `decisions` — القرارات والتوصيات | vp | vp | vaAp | vap | vp | vpx | vpx | vaedApx | vp | vp | vap | vap |
| `meeting_minutes` — المحاضر | vp | vap | vaAp | vap | vp | vp | vp | vaedApx | vp | vp | vaep | vap |
| `meeting_outputs` — المخرجات | p | veAp | veAp | p | p | p | p | vaedApx | p | p | p | vaAp |
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

**Screen counts per role** — what `GET /api/screens` returns, and how many of those the sidebar
shows. Three things take a screen out of the menu: `request_details` and `notes_attachments` need
a request id; the five approval rows have no page (`route` is null); and the seat gate removes
seven `meetings_management` screens (`meetings_dashboard`, `committee_candidates`, `meetings`,
`meeting_agenda`, `meeting_readiness`, `meeting_live`, `meeting_minutes`) from anyone who holds no
committee seat. `legal_review` and `meeting_outputs` follow the role instead, and R08 is exempt.

The seeder holds **36** screens. A fresh seed has no committee, so until one is set up every
account except R08 shows the *no seat* pair. Only the five seat-bound roles have a *seated* pair.

| Role | No seat: API / sidebar | Seated: API / sidebar | Approval grant row (no page) |
|---|---|---|---|
| R01 Employee | 14 / 12 | — | — |
| R02 Reviewer | 17 / 14 | 24 / 21 | `اعتماد المقرر` |
| R03 Committee Head | 17 / 14 | 24 / 21 | `اعتماد رئيس اللجنة` |
| R04 Committee Member | 14 / 12 | 21 / 19 | — |
| R05 Admin Manager | 15 / 12 | — | `اعتماد مدير الإدارة` |
| R06 Ministry | 15 / 12 | — | `اعتماد وزارة الحكم المحلي` |
| R07 Director | 13 / 10 | — | `الاعتماد النهائي والأرشفة` |
| R08 System Admin | 36 / 29 (exempt) | — | all five |
| R09 Committee Secretary | 12 / 10 | — | — |
| R10 Diwan Deputy | 12 / 10 | — | — |
| R11 Legal Officer | 13 / 11 | 20 / 18 | — |
| R12 HR Manager | 13 / 11 | 20 / 18 | — |
| multi.role (R03+R04) | 17 / 14 | 24 / 21 | `اعتماد رئيس اللجنة` |

---

## Appendix B — the twelve lifecycle stages

`responsible_role` below is **indicative** — it tells the UI who normally holds the file. Authority
to actually move it comes from the transition's own required role, which is why stages 1 and 2
show none: stage 1's `submit` belongs to the request's own filer and stage 2's rules to صاحب
العلاقة's own manager, and neither is expressible as a role. Eight stages are on the path today.

| # | Code | Arabic | Normally held by | Target days |
|---|---|---|---|---|
| 1 | `receive_from_municipality` | استلام الطلب من البلدية | the filer | — |
| 2 | `direct_manager_review` | مراجعة الطلب من المدير المباشر | the subject's manager | 1 |
| 3 | `administrative_routing` | إحالة الطلب لأحد المسارات الإدارية — off the path (the manager's `forward` lands on stage 4 since 2026-09-26) | — | — |
| 4 | `receive_and_register` | الاستلام والتسجيل | R12 | 3 |
| 5 | `requirements_check` | فحص استيفاء المتطلبات | R02 | 2 |
| 6 | `reviewer_review` | مراجعة المقرر وفق اللوائح — off the path (Stage 102) | — | — |
| 7 | `observations` | إبداء الملاحظات (إن وجدت) — off the path (Stage 102) | — | — |
| 8 | `forward_to_committee` | تحويل الطلب للجنة القائمة — off the path (Stage 102) | — | — |
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
| `return_to_employee` | 2 → 1 | the subject's manager |
| `return_missing_docs` | 5 → 1 | R02 |
| `declare_no_jurisdiction` | 5 → 5, and 9 → 9 | R02 at stage 5, R03 at stage 9 |
| `reject_formally` | 5 → 5 | R02 |
| `defer` | 9 → 9 | R03 |
| `conditional_approve` | 9 → 10 | R03 |
| `request_legal_opinion` | 9 → 9 | R03 |
| `refer_to_another_body` | 9 → 9 | R03 |
| `reject_by_committee` | 9 → 9 | R03 |
| `return_to_study` | 9 → 5 | R03 |
| `cancel` | self-loop at every open stage | whoever holds that stage (R02 at stage 1, the subject's manager at stage 2) |
| `deadline_expired` | any open stage → 11 | R08 |

`submit` (1 → 2) is the filer's own: the system fires it at intake, and the filer uses it again to
re-submit a file returned to them. A file still parked at stage 3 from before the one-click
forward leaves through `route_to_hr`, its manager's.

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
