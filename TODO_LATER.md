# Todo later — decided, not scheduled

Everything this repo knows it has **not** scheduled. Work that *is* scheduled lives in
[STAGE_PLAN.md](STAGE_PLAN.md), including Track M — the fifteen lifecycle-diagram findings that
became stages.

Three groups: provisions earlier tracks declined outright, process decisions that must be taken
before any code is worth writing, and platform items outside this system's stage plan.

Nothing here is a bug report. Each entry is something a human has to decide, or has already
decided to leave alone — which is the point of writing them down rather than letting the next
session rediscover them.

---

## Provisions with no stage

Every stage in `STAGE_PLAN.md` is built, but a handful of provisions were deliberately declined
along the way and belong to nobody. They are listed here so they are decided rather than
inherited.

*(Moved verbatim from `STAGE_PLAN.md`'s former "Work with no stage" section.)*

- **[D] Art. 84's session-opening acts** — see the note under Stage 82.
- **[D] Appendix 46** — the immutable post-approval copy of an approved محضر and its versioned
  amendment. Also what Appendix 66's tenth prohibition needs. Flagged by Stage 78, taken by
  nothing since; it is document versioning, not a gate, and deserves its own stage.
- **Appendices 13, 42 and 62** — the precedents, preliminary-decisions and risk registers.
  Each was declined deliberately by an earlier stage (13 is "اختياري" in its own text).
- **[D] Art. 54's seniority register** and its annual publication — still tagged ❌ in the
  compliance matrix with no owner.
- **Appendix 40 item 10** (أكثر أسباب النقص) — blocked on a structured shortfall vocabulary
  that does not exist; whichever stage introduces one should take this at the same time.
- **Appendices 43, 67 and 74** — routed to Stage 83 by the matrix but outside its Source line,
  so that stage declined them explicitly.

---

## Decisions for the process owner — the diagram vs. [D]

From the [F]/[G] lifecycle-diagram gap analysis (see Track M in `STAGE_PLAN.md` for the findings
that became stages, and for what [F] and [G] are).

[D] (دليل إجراءات لجنة شؤون الموظفين) is authoritative, so in each of these **the system is
currently correct on the manual and divergent from the published poster.** Each needs a human
decision about what staff were actually promised — none is a defect to fix.

- **C1 — No acknowledgement at submission.** [G] lists «إرسال إشعار للموظف باستلام الطلب» as a
  system action *and* «إشعار باستلام الطلب بنجاح» as an output. The submitting employee receives
  nothing: `NotificationDispatcher::requestCreated()` excludes the actor by construction and
  resolves recipients from the *post-hop* stage, so the only recipient is the submitter's line
  manager — and **if the employee has no `manager_id`, or an inactive one, nobody is notified at
  all.** The Art. 101 observer fires nothing either, because status `new` is deliberately excluded
  from `EmployeeNoticeService::STATUS_MOMENTS`. This matches [D]: Art. 101's twelve moments begin
  at القيد, and Stage 79 recorded explicitly that an intake acknowledgement would be "an addition
  to [D] rather than one of the twelve moments."

  *Net effect:* the window between submitting and being registered — the manager review plus the
  administrative routing, so potentially days — is entirely silent, and the employee's only
  acknowledgement is a number on screen they may not have kept.

- **C2 — «رقم مرجعي» wording.** [G] calls the stage-1 output a رقم مرجعي. The system deliberately
  issues a **receipt** (`PM-RCV/…`) and says on screen that it is *not* a قيد, per Art. 15's
  «ولا يعد مجرد تقديم الطلب إلى الرئيس المباشر قيدًا»; the real رقم إشاري (`PM-COM/…`) arrives at
  registration. The system is right on [D], but the poster's wording will have employees quoting
  the wrong number, and the two series look alike enough to confuse.

- **C3 — Duplicate matched by type, not subject.** [G] promises a check for «عدم وجود طلب مشابه
  قيد المعالجة». `DuplicatePolicy` follows [D] Appendix 16 and matches on **creator + request type
  only** — the free-text title is deliberately not compared. Consequence, already a known open
  item: an employee with two genuinely different matters of the same type cannot file the second.
  That is the appendix's own consequence as written, but stricter than the poster implies.

---

## Platform items — outside this system's stage plan

Set aside from the gap analysis by scope, as infrastructure rather than process. All four are
absent from the codebase. **P2 bites today.**

- **P1 — Two-factor login.** [G] sub-step 1 offers «أو عبر التحقق الثنائي». No 2FA/OTP/TOTP exists
  anywhere; login is email + password only.

- **P2 — Password reset.** No self-service reset exists — no route, no controller, no screen, only
  Laravel's untouched broker stub in `config/auth.php`. A user who forgets their password needs an
  R08 admin to change it through the Users screen. Compounding it, the login failure message tells
  users to contact الدعم الفني, which is P4. Related, same area: Sanctum tokens never expire
  (`config/sanctum.php` → `'expiration' => null`), there is no account lockout beyond
  `throttle:6,1` per IP, and the only password rule is `min:8`.

- **P3 — Document encryption at rest.** [G] promises «تشفير وحفظ المستندات المرفوعة». Files are
  written as plaintext to a private, non-public disk with hash-based filenames; protection is
  access control only. No `Crypt::` call or `encrypted` cast anywhere in `app/`.

- **P4 — Technical-support channel.** [G]'s footer promises «التواصل مع وحدة الدعم الفني عبر
  النظام». No support controller, route, screen or ticket model exists. The only mention in the
  codebase is two hard-coded login-error strings carrying no phone number, email, link or named
  contact.

---

## Adding to this file

Give each entry a stable id within its group (`C4`, `P5`, …), say what the source asks for, what
the system does instead, and **why it was left** — an entry without its reasoning becomes a
backlog item somebody silently "fixes" against the standard. When one of these does get an owner,
move it into `STAGE_PLAN.md` as a stage rather than leaving it in both files.
