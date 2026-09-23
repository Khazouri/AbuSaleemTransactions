# Agent handoff notes

Shared scratchpad between Codex and Claude Code for this repo. See the
"Cross-agent handoff" section in [AGENTS.md](AGENTS.md) for the rules:
newest entry on top, prune once resolved, durable facts go in AGENTS.md
instead.

**This file holds only the newest ~20 entries.** Older ones are moved, unedited,
into [agent-notes/](agent-notes/) as one file per month (`agent-notes/2026-08.md`
and so on) by `node scripts/archive-agent-notes.mjs`. CLAUDE.md imports this file
into every session, so keeping it short keeps every session's context free. When
code or a doc says "see AGENT_NOTES.md" and the entry is not here, search
`agent-notes/`.

**Unfinished or unclear points go in [OPEN_ITEMS.md](OPEN_ITEMS.md).** Any
point left open, deferred, ambiguous, or waiting on a decision — including the
"Open items" of a stage's completion note — must also be added there as its
own entry (what is open, why, what would close it). Check that file at the
start of a session, and mark an item resolved there when you close it.

Format:

```
### YYYY-MM-DD — <Codex|Claude> — <short title>
What happened / what's left / what to watch out for. 2-4 sentences.
```

---
### 2026-09-23 13:27 EET — Claude — Agent tooling: notes archived, four project skills, locale-parity hook

User request: improve how Claude Code works in this repo. **AGENT_NOTES.md had reached 1.3 MB (~340K tokens)**
and CLAUDE.md imports it into every session, which is more than a whole 200K context window. With the user's explicit
OK, `scripts/archive-agent-notes.mjs` moved all but the newest 20 entries **verbatim** into `agent-notes/2026-07.md`,
`2026-08.md` and `2026-09.md` (223 = 20 + 203, checked byte-for-byte). AGENTS.md now has an "Archive, don't prune"
rule. Added four skills in `.claude/skills/` (`plan-stage`, `verify-and-commit`, `new-screen`, `cpanel-release`), which
turn the AGENTS.md procedures into checklists, plus `scripts/check-locale-parity.mjs` (2011 keys each side, in parity),
run by a new Stop hook. Also: dated `graphify-out/20*/` snapshots are git-ignored and untracked, and `.git-ftp-ignore`
is fixed. git-ftp matches shell globs, so `frontend/src/.*` only ever skipped dotfiles and the SPA source was being
uploaded. No application code changed, so PHPUnit and Pint are unaffected; nothing was run against MySQL. **Open item
raised:** the console's `db:seed` ("Re-seed reference data") resets the permission matrix despite its
non-destructive label, and there is no safe path for adding a new screen's grants on production (OPEN_ITEMS.md).

---
### 2026-09-21 22:30 EET — Claude — The filer attaches only at stage 1

User follow-up to the entry below: the filer may attach **only while the request is at stage 1**
(`receive_from_municipality`), i.e. before it reaches the manager. Intake auto-hops past stage 1, so in practice the
filer attaches with the intake itself and again only after a return (`return_to_employee`, `return_missing_docs`).
One condition in `Request::attachmentRight()`; the executor and HR carve-outs are unchanged. **Decision put to the
user and taken: a committee "completion required" gap cannot be filled at the committee stage by anyone** — the
committee defers or the file goes back through a return. Consequence worth knowing: Appendix 25's voting freeze in
`AttachmentController::store()` is now unreachable by any permitted uploader (no one allowed to attach can reach a
file with an open vote); it stays as a backstop and `StudySequenceTest` says so. Five fixtures moved to stage 1 (their
subjects are folder derivation, file types, classification — not the stage) and Stage 91's loop test was rewritten to
pin the refusal. Full suite **735 / 4709**, Pint clean, build passes, no migration.

---
### 2026-09-21 22:05 EET — Claude — Filer-only attachments + filer re-submit complete

Built per the plan below. One migration (`workflow_transitions.requires_creator`), applied to the real MySQL, and
`WorkflowTransitionSeeder` reseeded (confirmed by query: `submit` = `required_role_id null, requires_creator true`;
the R08 override untouched). Full suite **734 tests / 4709 assertions** (baseline 729/4684: +5 new in
`FilerAttachmentsAndReturnTest`), Pint clean on every touched file, `npm run build` passes (`dist` reverted), no
locale change. The rule is `Request::attachmentRight()` (`filer` | `executor` | `hr_service_file` | null), read by
`AttachmentController::store()` and exposed as `can_attach` on `RequestResource`, which gates both upload widgets.

**Deviation from the plan, deleted rather than kept:** the planned `NotificationDispatcher::actorsForStage()`
creator branch was dead code — every caller either sends the filer the owner's `stage_changed` notice and excludes
them from the duplicate prompt, or already unions the owners in. The actual notification fix is the row losing its
R01 role, so bystanders are no longer prompted; a test pins both halves. **Four pre-existing tests needed
legitimate updates**, each commented in place: an R08 upload (now the admin is the filer), Stage 91's officer
completing a gap (now refused, and the filer closes it), R12's `other` إفادة at registration (now refused; a
service-file row is accepted), and a stranger R01 at intake getting **404, not 422**, which is the privacy leak
closed. **Open item:** R02's `cancel` row at intake still shows every returned file to every R02 (OPEN_ITEMS.md).

---
### 2026-09-21 21:30 EET — Claude — Implementation plan: only the filer attaches; the manager returns with a note; the filer re-submits

User request: "attaching files can only be done by the request submitter; the submitter's manager reviews the
request and files and sends it back if it needs modification or documents are missing, adding a note". Scope put to
the user and answered: submitter-only **everywhere**, with exactly two carve-outs — (1) execution proof
(Appendix 70) by a `meeting_outputs,approve` holder while the file is `in_execution`, and (2) R12 uploading a
`service_file`-section document at `receive_and_register` (Stage 98's duty; the agenda gate demands those rows be
genuinely attached). No R08 override. Stage 91's staff-completion upload path is deliberately closed: completion now
goes back to the filer through a return.

**The manager half already exists** — `return_to_employee` at `direct_manager_review` requires a reason, lands on
`returned`, and Art. 101's moment-8 notice quotes it. What was missing is the return leg: `submit` is **R01-gated**,
so an on-behalf filer (R02/R05, Stage 95) could not re-submit, and — a real privacy leak found while checking — a
returned request sat at a stage whose outbound row names R01, so `RequestVisibility`'s assignment clause showed it to
**every** active R01 and `actorsForStage()` prompted every R01. Fix: a new `workflow_transitions.requires_creator`
flag (one migration), the `submit` row becomes `required_role_id = null, requires_creator = true`, and the three
readers of rule rows learn it: `WorkflowService::actorMayUse()` (actor must be the filer), `RequestVisibility` (a
creator-gated row grants no assignment visibility — the filer already sees their own file; without this a null-role
row would match EVERYONE via its `whereNull(required_role_id)` branch), `NotificationDispatcher::actorsForStage()`
(prompts the filer) and `RequestResponsibilityService` (Appendix 17 party `employee`). The intake auto-hop is
role-independent (`applySystemTransition`) and unaffected; the R08 `submit` override stays.

**Upload rule** lives once in `AttachmentController::store()` (403, Arabic); the SPA hides the widgets by the same
predicate. **Verify:** new feature tests (filer allowed / R02+R08 refused; both carve-outs and their bounds; the full
return→upload→re-submit loop incl. an on-behalf filer; the returned file no longer visible or notified to other R01s),
the pre-existing upload fixtures moved to the filer (legitimate updates), full PHPUnit, Pint, `npm run build` (revert
`dist`), locale parity, migration + `WorkflowTransitionSeeder` reseed against the real MySQL.

---
### 2026-09-21 20:40 EET — Claude — Stage 101 complete (the consultation and information layer) — Track N finished

Built per the plan below. **No migration.** Full suite **729 tests / 4684 assertions** (baseline 719/4653: +10 new
in `ConsultationLayerTest`, and no pre-existing test needed changing — the check that this was additive), Pint
clean on every touched PHP file, `npm run build` passes (`frontend/dist` reverted), locale parity **2011 keys each
side** (+1), `ScreenRolePermissionSeeder` reseeded against the real MySQL and confirmed by query
(`legal_review.can_view` = R02, R03, R08, R11; `meeting_agenda.can_add` = R02, R03, R08, R11), no pending migration.
**No live HTTP smoke run** — the behaviour is covered by feature tests only.

**The finding worth keeping: seven of the thirteen cells were already true, and the gap analysis was wrong about
them.** It was written before Stage 100 and never re-derived. الرئيس المباشر مطلع on row 1 was met by
`actorsForStage` sending `request_created` to the subject's manager (my first test asserted it and passed before
any code changed); HR مشارك on rows 6, 7, 9 and «دعم معلومات» holds because R12's `meeting_outputs,approve` puts it
in `$isCloser`, which shows it every registered file. **That last one is an accident of an unrelated grant** — a
future change to the execution tier would silently take HR out of four rows — so those cells are pinned by tests
rather than by a clause of their own. Adding a second, explicit clause would have doubled a rule that already
holds, which is the more expensive way to get the same protection.

**Built.** (1) `RequestVisibility::$isHrStudyCoOwner` is one clause over three stage ids instead of one:
`direct_manager_review`, `requirements_check`, `observations` — rows 2 and 4 are pre-قيد, where R12 saw nothing.
Bounded to exactly those stages: `administrative_routing` stays a 404 (a test pins the bound). (2) R12 joins
`requestCreated` (row 1). (3) `request_notice_copy` + `RequestNoticeCopyNotification`, sent from
`NotificationDispatcher::requestNotice()` to the subject's manager and active R12 users (row 14). Hooking the one
method both the observer and Stage 100's manual `notices/issue` call means neither can skip it. The copy names the
moment and the tracking number and **never the notice body** (Art. 102), carries no `issued_by`, excludes the actor
and the subject, and is in-app only. `EmployeeNoticeRegister` is unaffected: it reads `request_notice` rows only,
and `EmployeeNoticeTest` still passes. (4) `legal_review,view` → R03 (row 6). (5) `meeting_agenda,add` → R11 (row
7), the whole tier; `edit` stays R02/R03, and a test asserts R11 does not gain it.

**Test plans.** `TEST_PLAN.roles.md`/`.ar.md` gained four checks each (R03 queue read, R11 memo, two for R12) and
one R12 refusal was **rewritten, not added**: «open a request at any stage other than `observations` → 404» was
already false after Stage 92 and is now bounded to unregistered files outside stages 2, 5 and 7. Appendix A's two
cells changed; checkbox parity **359 each side**. **R03's counts were corrected to the live database — 24 screens /
21 sidebar** (the plan said 23 for both the section and the table, and 20/22 in the multi-role check); the rest of
that table remains stale in ways this stage did not cause, and its own note says so.

**Two residues, both in OPEN_ITEMS.md.** (1) A manager whose only role is R09/R10/R11 can see the file at
`receive_and_register` but not attach: الرئيس المباشر is a relationship, and writing rides `notes_attachments,add`.
No such manager exists in the seeded data; bypassing the screen-permission middleware for one relationship is a
larger change than one cell justifies. (2) The copy is in-app only. Also fixed while there: AGENTS.md still said
«Tracks A–L (Stages 1–84) are all built».

**Track N is complete — Stages 94–101, every one of Appendix 6's 41 populated cells has an implementation.**
Nothing in STAGE_PLAN.md is unbuilt. Whoever picks up next should read OPEN_ITEMS.md; the four open items there
are the real backlog.

---
### 2026-09-21 19:45 EET — Claude — Implementation plan: Stage 101 (the consultation and information layer — Appendix 6's مشارك/مطلع cells)

The last Track N stage. **No migration, no new screen, no new endpoint** — every mechanism is one Stages 47, 68,
87 and 100 already established. Cell-by-cell against the live code (not the gap analysis, which predates Stage
100's widening of R12's reach): note and attachment writes are `notes_attachments,add` + `RequestVisibility::canView`,
so a party «participates» in a file iff it holds that grant *and* can see the file.

**Already true, by accident — pinned with tests, not rebuilt.** الرئيس المباشر مطلع on row 1 (`requestCreated`
already goes to the subject's manager through `actorsForStage`); الرئيس المباشر مشارك on row 3 (Stage 98's clause
plus `notes_attachments,add` for R01–R07); الموارد البشرية مشارك on rows 6, 7 and 9 and «دعم معلومات» — R12 holds
`meeting_outputs,approve`, so `$isCloser`'s `reference_number` clause shows it every registered file. That last one
rides an unrelated grant, so the tests are what stop a later change to `meeting_outputs` silently dropping HR.

**Built.** (1) `$isHrStudyCoOwner` in `RequestVisibility` widens from the single `observations` stage to
`{direct_manager_review, requirements_check, observations}` — rows 2 and 4 are pre-قيد, where R12 sees nothing today.
One edited clause, not a new one. (2) R12 joins `requestCreated` recipients (row 1). (3) New event
`request_notice_copy` (in-app only, the `stage_changed` defaults) + `RequestNoticeCopyNotification`, sent from
`NotificationDispatcher::requestNotice()` — the one method both the automatic observer and Stage 100's manual
`notices/issue` call — to the subject's manager and to active R12 users (row 14). The copy carries the tracking
number and the moment's title only, **never the notice body**: Art. 102 limits notice content to what صاحب العلاقة
needs, and the copy is not addressed to them. (4) `legal_review,view` gains **R03** (row 6 اللجنة مطلع). (5)
`meeting_agenda,add` gains **R11** (row 7 العضو القانوني مشارك) — full memo edit, not `legal_opinion` alone; still
bounded by the committee seat via `meeting.member`, which Stage 99 already tied to R11.

**Two calls put to the user and answered yes-with-defaults; do not re-litigate.** اللجنة مطلع is the R03 queue view
only: R04 would be listed queue rows it cannot open (the Stage 47/68 dead end), members read the opinion at study
time through the agenda-item context, and the committee has no identity before an agenda to push a notification to.
R11's memo grant is the whole `add` tier because field-level gating buys nothing for a cell that says only «مشارك».

**Deliberately not built.** A manager whose only role is R09/R10/R11 holds no `notes_attachments,add`, so cannot
attach at `receive_and_register` — الرئيس المباشر is a relationship, not a role, and bypassing the screen-permission
middleware for one relationship is a larger change than this cell justifies. Recorded as an open item, not glossed.

**Verify.** New `tests/Feature/ConsultationLayerTest.php` covering all thirteen cells; full PHPUnit (baseline 719
tests / 4653 assertions); Pint on touched files; `npm run build` (revert `frontend/dist`); locale key parity;
`ScreenRolePermissionSeeder` reseed against the real MySQL confirmed by query. TEST_PLAN.roles.md/.ar.md Appendix A
gains the two changed cells (checkbox parity kept). STAGE_PLAN, OPEN_ITEMS and the gap analysis close with it.

---
### 2026-09-21 18:36 EET — Claude — Stage 100 complete (الإشعار ownership, الأرشفة, execution oversight — Appendix 6 rows 13–15)

Built per the plan below. One migration (six `requests` columns) applied to the real MySQL; the
`ScreenRolePermissionSeeder` reseed confirmed by query (`meeting_outputs,add` = R12 [+R08];
`notes_attachments,add` now includes R06/R07). Full suite **719 tests / 4653 assertions** (baseline
711/4625: +8 new in `NoticeArchiveOversightTest`, the rest fixture updates), Pint clean on every touched
file, `npm run build` passes (`frontend/dist` reverted), locale parity **2010 keys each side**. **No live
HTTP smoke run** — the three routes were confirmed registered, the behaviour by feature tests only.

**Row 15.** Two archive records, each written by its owner through its own endpoint while the file stands
on a closable status and is not closed: `archive/committee-file` (`meeting_outputs,edit`, المقرر) and
`archive/service-file` (`meeting_outputs,add`, which gated no route and is now R12's alone). Closure
(`RequestClosureService::refusalReason()`) refuses until the committee file is archived and — only when the
file carries a recorded decision, the same condition Appendix 48 cond. 8 uses — the service file too. The
closure card's `file_storage_location` is **gone from the request side** (the appeal closure keeps its own,
untouched); Appendix 47 #12 is derived. `ClosureRegister` prints both locations, falling back to a
pre-Stage-100 closure's single location under the committee file. `reopen()` clears both records
**unconditionally**, not inside the `closed_at` guard — `not_approved` is both closable and reopenable, so a
file can be archived and reopened without ever being closed.

**Row 14.** `POST requests/{r}/notices/issue` (`meeting_outputs,edit`) issues Art. 101's notice for the
moment the file's **latest status-history row** represents, through the same `momentFor()` the observer
uses (new `EmployeeNoticeService::currentMoment()`), so a hand-issued notice cannot describe a state the
automatic one wouldn't. Payload carries `issued_by`; the register shows it. Refused (422) for a state Art. 101
doesn't name, or an inactive/absent صاحب العلاقة. The automatic send is untouched.

**Row 13.** One `RequestVisibility` clause: a user with an `approvals.approved_by_user_id` row on a file sees
it while it is `in_execution`/`executed`/`execution_suspended`/`completed_closed`. R06/R07 joined
`notes_attachments,add` (R05 already held it) so supervision can be written on the file.

**Three pre-existing tests used R06/R07 as "the role with no `notes_attachments,add`"** and now get 404
(visibility) instead of 403 — switched to R10, a retained login with no duty since Stage 96, commented in
place. The closure fixtures gained `Tests\ClosesRequests::archiveFiles()`, inserted before each request close.

**Open items.** (1) **The employee_notified checkbox is still an attestation** in the execution and closure
cards; deriving it from the register was considered and left — the closure moment's own notice fires only
after closure, and a rule for "notified of the result" is a decision, not a lookup. (2) **`meeting_outputs`
screen stays invisible to R05–R07** — it is meeting-picked and they hold no seat; supervision lives on the
request workspace. (3) **Stage 101 is the last Track N stage.** Scripting gotcha re-hit: backslashes in a
node heredoc collapse (`\` → `\`), so `use App\...` replacements silently matched nothing — imports were
added with the editor.

---

### 2026-09-21 18:10 EET — Claude — Implementation plan: Stage 100 (الإشعار ownership, الأرشفة, execution oversight — Appendix 6 rows 13–15)

Three ownerless duties get a holder. **One migration (six columns on `requests`), one grant reseeded,
two grants widened, no new screen, no new status.** (Clock note: the machine reads 18:10 while the
Stage 99 entry below is stamped 18:40 — the stamps drifted ahead again; position is the ordering.)

**Row 15 — الأرشفة split into its two مسؤول halves.** Six columns: `committee_file_location/_archived_by_user_id/_archived_at`
(المقرر «مسؤول ملف اللجنة») and `service_file_location/...` (الموارد البشرية «مسؤول ملف الخدمة»), each written by
its own endpoint while the file sits on one of Art. 37's closable statuses and is not yet closed (overwrites —
the Stage 78 precedent). Committee file rides `meeting_outputs,edit` (R02+R03, the المقرر grant every Art.
30/103/105 act already rides). Service file rides **`meeting_outputs,add`, which gates no route today**
(checked) and is reseeded `['R12']` — the same move Stage 99 made with `meeting_minutes,edit`. Closure refuses
until the committee file is archived, and the service file too **when the file carries a recorded decision** —
the exact condition Appendix 48 condition 8 already uses for `service_file_updated`, so a Path 3 pre-committee
عدم اختصاص is not asked to archive a service file nothing touched. The closure card's free-text
`file_storage_location` goes; Appendix 47 #12 stays derived, now from the two records. **Track K scope
decision (1) is restated, not crossed:** ملف الخدمة lives outside this app, so HR's record is where the
service-file copy was filed, attested by the party that owns it — not a model of the file.

**Row 14 — المقرر «مسؤول إجرائيًا» for الإشعار.** Art. 101's notices stay automatic (Stage 79's observer is
right to make them unforgettable); what was missing is a human who answers for them. New `POST
requests/{r}/notices/issue` (`meeting_outputs,edit`) issues Art. 101's notice for the file's **current**
state — the moment the latest status row represents, via the same `EmployeeNoticeService::momentFor()` the
observer uses — for the cases the automatic send cannot cover (an employee reactivated after the notice fired,
a notice to be repeated on request). The notice carries `issued_by`, and the register on the request shows it,
so the file records who stood behind a notice rather than only that the system sent one. Refused when the
current state is not one of Art. 101's twelve, or صاحب العلاقة has no active account.

**Row 13 — جهة الاعتماد «إشراف حسب الاختصاص».** One bounded `RequestVisibility` clause: a user who signed an
`approvals` row on a file keeps sight of it through execution (`in_execution`, `executed`,
`execution_suspended`, `completed_closed`). «حسب الاختصاص» read as *the files they approved*, which is the
approval ledger itself — no role list. R06/R07 join `notes_attachments,add` (R05 already holds it) so
supervision can be recorded on the file, bounded by that same visibility. `meeting_outputs,view` is **not**
widened: that screen is meeting-picked and the approvers hold no committee seat, so it would open onto an
empty picker.

**Verify:** new `tests/Feature/NoticeArchiveOversightTest.php`, a `archiveFiles()` helper on
`Tests\ClosesRequests` for the closure fixtures (a legitimate update — closure now requires the archive), the
full suite (baseline 711/4625), Pint, `npm run build` (revert `frontend/dist`), locale parity, migration +
`ScreenRolePermissionSeeder` reseed against the real MySQL.

---

### 2026-09-21 18:40 EET — Claude — Stage 99 complete (the committee's own acts — Appendix 6 rows 8–11)

Built per the plan below. One migration (`meetings.agenda_adopted_*`, `meeting_minutes.legal_review_*`),
applied to the real MySQL, and a `ScreenRolePermissionSeeder` reseed confirmed by query. Full suite
**711 tests / 4625 assertions** (baseline 704/4590: +7 new, and only fixture updates elsewhere), Pint
clean on every touched file, `npm run build` passes (`frontend/dist` reverted), locale parity **1998 keys
each side** (+10), `TEST_PLAN.roles.md`/`.ar.md` at **358 checkboxes each**.

**Agenda adoption is a gate, not a timestamp.** `POST meetings/{m}/agenda/adopt` (new `meeting_agenda,
approve` tier, R03) is one-shot and refused on an empty agenda. Before it, `updateItemState` and
`updateStudySequence` refuse (`MeetingController::AGENDA_NOT_ADOPTED`, Art. 84) — voting inherits that
through the study sequence it already requires. After it, add/remove/reorder/apply-order refuse
(`AGENDA_ALREADY_ADOPTED`) **except adding an `emerging` item**; `updateAgendaItem` (priority/time) stays
open. Deliberately **not** a readiness exception, so convene means what it meant before. Only two
fixtures needed `agenda_adopted_at` (`StudySequenceTest`, `MeetingLiveRunnerTest`) — every other suite
writes the study sequence directly through `RunsStudySequence`, which bypasses the gate by design.

**R11 is now a sitting member.** `meeting_live,add`, `decisions,add` and `meeting_minutes,add` gained R11
(the last because signing rides it — an attending legal member without it would hold an unsignable
row and stall the محضر). `meeting_minutes,edit` **gated no route** before this stage (checked); it now
gates `POST meetings/{m}/minutes/legal-review` and is `['R11']` — R03 lost a grant that did nothing. The
note is optional («عند الحاجة»), draft-only, overwrites, and never blocks review. `seat=legal` now
requires the R11 role (`StoreCommitteeMemberRequest`); `CommitteeSeatRosterTest` seated an R04 there and
was updated.

**The zero-signature hole is closed by refusal, not by trusting Appendix 8.** The quality gate made it
nearly unreachable, but every attendee marked absent under a count-0 quorum passed all sixteen checks
— the new test builds exactly that and gets the 422. `review()`'s `isEmpty()` auto-approve branch and
its notification call are deleted; `minutesApproved` now fires only from `sign()`.

**Not done: no live HTTP smoke run this session** — covered by the new feature tests only.

**Open items.** (1) The **R09 bullet under row 8** in the gap analysis was already stale (Stage 96);
annotated, not rewritten. (2) **Appendix A in the role test plans is still stale outside the four rows
re-derived here** — Stage 96's open item stands; derive the whole table from the DB next time.
(3) Adoption is recorded by the chair on the committee's behalf; there is no per-member vote on the
agenda, because [D] Art. 12 (أ) 3 gives اعتماد جدول الأعمال to the chair and Appendix 6's «وفق
النظام» points at the system's own rule. (4) Stages **100** and **101** remain; neither blocks the other.

---

### 2026-09-21 17:52 EET — Claude — Implementation plan: Stage 99 (the committee's own acts — Appendix 6 rows 8–11)

Four gaps, each tied to a named cell. **One migration (six columns), grant changes, no new screen.**

**Row 8 — اعتماد تنظيمي of the agenda.** Art. 12 (أ) 3 gives the chair «مراجعة واعتماد جدول الأعمال قبل
الاجتماع» and Art. 84 lists «اعتماد جدول الأعمال» among what happens «قبل مناقشة أول بند». Nothing records
it today. New `meetings.agenda_adopted_at` + `agenda_adopted_by_user_id`, set by `POST
meetings/{m}/agenda/adopt` on a new `meeting_agenda,approve` tier seeded `['R03']` (the `decisions`
add/approve split again). Refused on an empty agenda and when already adopted. **Two consequences make it
more than a timestamp:** (1) deliberation waits for it — `updateItemState` and `updateStudySequence` refuse
until the agenda is adopted, which is Art. 84's own order; voting inherits the gate through the study
sequence it already requires. (2) the adopted agenda is fixed — add/remove/reorder/apply-order are refused
afterwards, **except adding an `emerging` item**, which by definition arises at the sitting. Metadata edits
(`updateAgendaItem`: priority/time) stay open. Not added to readiness/convene: Art. 12 says «قبل
الاجتماع» but the gate that matters is the one before deliberation, and a readiness exception would change
what convene means for every existing fixture for no sourced gain.

**Row 9/10 — R11 as a sitting member.** `meeting_live,add` and `decisions,add` gain R11. Nothing else is
needed on the voting side: `DecisionEligibility` checks seat + attendance, never a role.

**Row 11 — R11 «مراجعة عند الحاجة» on the محضر.** New `meeting_minutes.legal_review_note` + who/when,
written by `POST meetings/{m}/minutes/legal-review` while the محضر is a draft. Rides `meeting_minutes,edit`,
which **gates no route today** (checked) — reseeded `['R11']`. Optional by the cell's own «عند الحاجة»:
it never blocks review. R11 also joins `meeting_minutes,add`, because signing rides that tier and an
attending legal member would otherwise hold a signature row they cannot sign — stalling the محضر forever.

**Row 11 — اعتماد داخلي with zero signatures.** `review()`'s `$signerUserIds->isEmpty()` branch approves
outright. Appendix 8's gate makes it nearly unreachable (no attendance → «إثبات الحضور» fails), but not
provably (all absent + a count-0 quorum). The branch is deleted and replaced with a refusal: the محضر is
the committee's only when attending members confirm it.

**The R11 ↔ `legal` seat binding.** `StoreCommitteeMemberRequest` refuses `seat=legal` for a user
without R11. Stage 45 left the seat role-free; that is the gap the stage names. Only
`CommitteeSeatRosterTest` seats `legal` (as R04) — a legitimate fixture update.

**Verify:** new `tests/Feature/CommitteeOwnActsTest.php` (adopt: permission, empty, twice; deliberation
refused before adoption through both endpoints; freeze with the emerging exception; R11 votes and posts a
note when seated+attended; legal note recorded only by R11 and only on a draft; legal seat refused to a
non-R11; zero-signer approval refused), the full suite (baseline 704/4590), Pint on touched PHP,
`npm run build` (revert `frontend/dist`), locale parity, migration + `ScreenRolePermissionSeeder` reseed
against the real MySQL.

---

### 2026-09-21 07:15 EET — Claude — Stage 98 complete (تجهيز الملف الوظيفي — the largest missing مسؤول)

Built per the plan below. **One migration, one service, one endpoint, and one narrowing of an
existing rule.** No new stage, no new status, no seeder change, no new permission. Full suite **704
tests / 4590 assertions** green (baseline 694/4554: +10 new tests, and **exactly one** pre-existing
test changed), Pint clean on every touched PHP file, `npm run build` passes and `frontend/dist` was
reverted, locale parity **1988 keys each side** with 23 added per side, and the migration ran clean
against the real MySQL.

**The duty MOVED; it was not added.** Appendix 6 row 3 gives الموارد البشرية the ملف الوظيفي as
مسؤول and gives الموظف a literal `—`, yet `DocumentCompletenessService::refusalForSubmission()` ran
at intake — so the **employee** had to attach the employment-record extracts the municipality itself
holds before the request could be created. `refusalForSubmission()` now reads
`mandatorySubmitterDocuments()`, the same matrix minus the `service_file` section, and HR answers
for that section at `receive_and_register` before it may register the file. **`refusalForRequest()`
is deliberately unchanged**: the agenda gate and the readiness exception still demand every
mandatory row, so what the committee may be shown is identical — the file is assembled by two
parties at two moments, which is what row 3 describes.

**The mapping was already in the repo, which is the whole reason this is small.**
`RequestTypeSeeder::commonDocuments()` says it in its own comment — «The seven service-record
extracts below are literally الملف الوظيفي; only the first and last of the nine basics are not» —
and Stage 80 seeded that distinction as each row's `section`, which `RequestType::documentOptions()`
already exposes. So الملف الوظيفي is exactly the `service_file` rows. No new vocabulary, no second
list to keep in step, and the keys are the same `IntakeGateService::documentKey()` slugs the
submitter's picker offers and Stage 78's gate answers under.

**Measured, not estimated: exactly ONE mandatory row moves off the submitter per type** (البيانات
الوظيفية — the only unconditional one of the seven). Counted live against the seeded data:
ALLW/APPT/CTRC/EOSV 1→0, LEAV 4→3, SETL 6→5, CONF/GRIV/PROM 7→6, PEVG/SECD 11→10, TRNS 13→12. **The
four types [D] covers with the shared basics only therefore now require no mandatory document from
the employee at all** — every one of the nine basics is either conditional or an employment-record
extract. That reads startling and is exactly what row 3 says; the documents are still demanded, by
HR, before registration.

**Deliberately an action on an existing stage**, per the stage's own instruction.
`receive_and_register` is where R12 already holds the `register` row (Stage 96 left it the one
registering party), so the gate sits on the hop HR already performs; a `workflow_stages` row of its
own would renumber the chain, which is Stage-57 territory.

**The endpoint rides `notes_attachments,add`, not `edit`, and that distinction is the row-3/row-4
boundary.** R12 holds `add` (Stage 87); `edit` is R02's alone after Stage 84, and taking it would
have handed HR فحص اكتمال ملف اللجنة — row 4's cell. `add` also reaches R01, so the
interested-party refusal is **load-bearing rather than defensive**: row 3 gives الموظف a `—`, so
neither the filer nor صاحب العلاقة may attest to their own employment file
(`actorIsAnInterestedParty()`, the Stage 84/95 predicate). Proven live, not only by test.

**`controlGateRefusal()` answers for a non-`approve` action for the first time.** It short-circuited
on `$action !== 'approve'`; `register` is now answered before that branch, so all three readers pick
it up unchanged — `ApprovalController` never sees `register` (not an approval level), but
`detailResource()`'s preview filter does, which is what keeps the SPA from offering a button the
endpoint refuses. **Worth knowing: none of the four control gates is enforced inside
`WorkflowService::transition()`** — they live at the HTTP boundary, so a fixture calling the service
directly walks past all of them. That is why the predicted 10-call-site blast radius was zero: every
existing `'register'` test calls the service, not the endpoint. The new test file walks it over HTTP.

**المقرر «مطلع» needed no code, and that is a finding rather than a skip.** `register` moves the
file to `requirements_check`, where R02 is the actor, so `NotificationDispatcher::stageChanged()`
already fires `action_required` at exactly the moment preparation completes. A seventh notification
event would have announced the same fact twice.

**الرئيس المباشر «مشارك» is half built, and the half is stated rather than glossed.** One bounded
`RequestVisibility` clause keeps the file in **صاحب العلاقة's own** manager's sight while it sits at
`receive_and_register` — the manager holds a manager-gated row at the two stages before that and
none at this one, so without it the file dropped out of their view at exactly the moment they are
meant to be contributing. Same shape as Stage 87's `$isHrStudyCoOwner`, read against the subject
rather than the filer per Stage 95. **Whether they may *attach* still depends on their role holding
`notes_attachments,add`**, because الرئيس المباشر is a `users.manager_id` relationship and not a
role a screen grant can name. That is the same question Stage 101 has to answer for HR on rows 2, 4,
6 and 7, so it belongs there rather than being half-answered here.

**The frontend generalises Stage 78's panel rather than duplicating it.** `IntakeGatePanel.vue` now
takes four props (`endpoint`, `attestationField`, `grant`, `copy`), all defaulted to Stage 78's
values so **that call site is byte-unchanged** — rows 3 and 4 are genuinely the same form asked by
two parties about two slices of one matrix. One locale key was renamed for uniformity
(`controlGates.intake.factsVerified` → `.attestation`) and a `controlGates.employmentFile.*` block
added; both locales verified at 1988 keys with zero on-one-side-only.

**One pre-existing test needed a legitimate update, not a regression fix.**
`RequestDraftTest::test_appendix_57_completeness_is_enforced_against_the_drafts_own_files` used
`ALLW`, whose single mandatory row was البيانات الوظيفية — so after this stage it can no longer
produce a completeness refusal at all. Moved to `LEAV` (3 submitter rows) and rewritten to cover
them all, with the reason commented in place. Everything else in the suite was untouched.

Smoke-tested end to end over real HTTP against Homestead with the seeded `r12.hr@`/`r01.employee@`
accounts: `register` was refused with «لا يجوز تسجيل المعاملة قبل تجهيز الملف الوظيفي للموظف من قبل
إدارة الموارد البشرية.»; the card returned **the seven service-record rows** with البيانات الوظيفية
the only unconditional one; HR recorded it (`prepared_by: مدير الموارد البشرية التجريبي`, refusal
null) and the same `register` then landed `requirements_check` / `in_review`; and the employee — who
is both filer and صاحب العلاقة — was refused the attestation on their own file. Deleted the fixture
request, its stage log, status-history row and 3 audit rows, removed the 3 jobs the run queued and
revoked **only** this session's two tokens by id — database confirmed back to **5 requests / 2
tokens / 12 pre-existing jobs**.

**Docs.** STAGE_PLAN marks 98 built and rewrites the Track N status line (the track's one hard
dependency, 95 before 98 and 101, is now discharged; 99/100/101 remain and none blocks another).
`gap-analysis-appendix-6.md` (git-ignored) moves row 3 from ❌ to ✅ with the original finding kept
verbatim underneath, since it records what the duty looked like while the wrong party held it.
**Also corrected while there: a stale count Stage 97 left behind** — that stage resolved row 5's
contradicted القيد inline but never updated the verdict table above it, which still read
«contradicted | 1 | 5 القيد». Now 12/15 implemented, 0 contradicted. Exactly the drift this
appendix's own headline warns about, found because this stage had to touch the same table.

**Open items for whoever builds Stage 99–101.** (1) **الرئيس المباشر's attach grant** — see above;
Stage 101 owns it, and it is the same shape of question for four other cells. (2) **The employment
file is prepared once and is not re-verified**, unlike the agenda gate which reads live coverage — a
service-file document deleted after preparation would leave the card claiming an assembly the file
no longer has. Deliberate and consistent with Stage 78's own attestation-vs-live split (the card is
an attestation and cannot regress; `refusalForRequest()` is the live check, and it still runs before
the agenda), but a stage that wants HR's card to regress needs the agenda gate's shape, not this
one's. (3) **There is no "correct the preparation" refusal** — the endpoint overwrites, matching
Stage 78's revise behaviour; a reopen clears it (`RequestController::reopen()`), which is what stops
a second lap registering on the first lap's assembly. (4) **Nothing asks HR to actually attach the
seven documents** — the card answers `present` for a row no file covers, exactly as Stage 78's gate
does, and only `refusalForRequest()` at the agenda demands real coverage. Making HR's own card
demand attachments rather than attestations would be Appendix 70's shape (Stage 76's execution
evidence), and is a decision, not an oversight.

---
### 2026-09-21 05:30 EET — Claude — Implementation plan: Stage 98 (تجهيز الملف الوظيفي — the largest missing مسؤول)

Appendix 6 row 3 gives **الموارد البشرية** the ملف الوظيفي as **مسؤول**, الرئيس المباشر as **مشارك** and
المقرر as **مطلع**, and gives الموظف a literal `—`. Today the row has zero implementation *and* the duty
is discharged by the wrong party: `DocumentCompletenessService::refusalForSubmission()` runs at intake,
so the **submitter** assembles Appendix 57's mandatory documents — including the employment-record
extracts the municipality itself holds — before the request can be created at all. This stage moves the
duty rather than adding a second one.

**The mapping is already in the repo, which is why this is small.** `RequestTypeSeeder::commonDocuments()`
says it in its own comment — «The seven service-record extracts below are literally الملف الوظيفي; only
the first and last of the nine basics are not» — and Stage 80 seeded that distinction as each row's
`section` (`service_file`), which `RequestType::documentOptions()` already exposes and Stage 95 already
reads. So **الملف الوظيفي = the `service_file`-section rows of this type's Appendix 57 matrix**. No new
vocabulary, no second list to keep in step.

**1. The submitter stops being asked for it.** `refusalForSubmission()` skips `service_file` rows. Of the
seven, exactly one (`البيانات الوظيفية`) is unconditional, so today an employee filing any request is
refused until they upload a statement of their own employment data — the municipality's own record.
**`refusalForRequest()` is deliberately NOT narrowed**: the agenda gate and the readiness exception keep
checking every mandatory row. Completeness for the committee is unchanged; it is now assembled by two
parties at two moments, which is what row 3 describes.

**2. HR prepares it at the stage HR already holds.** New `EmploymentFilePreparationService`,
`GATED_STAGE = receive_and_register` / `GATED_ACTION = register` — the hop Stage 96 left R12 as the one
registering party of. **Deliberately an action on an existing stage**, per the stage's own instruction: a
new `workflow_stages` row renumbers the chain, which is Stage-57 territory. One answer per `service_file`
row plus an attestation, reusing Stage 78's own three-answer vocabulary (`present|not_applicable|missing`),
its conditional rule (Appendix 57's own qualifier is what makes «لا ينطبق» honest — an unconditional row
cannot be waived) and its derived-from-attachments override (a document in the file outranks anything
anyone recorded about it, in both directions — Art. 104's «ليست إجراءات شكلية»). New
`requests.employment_file` (json) + `employment_file_prepared_by_user_id` + `_at`, mirroring the
`intake_gate` columns exactly.

**Endpoint rides `notes_attachments,add`, not `edit`.** R12 holds `add` (Stage 87); `edit` is R02's alone
after Stage 84, and taking it would hand HR فحص اكتمال ملف اللجنة — row 4's cell, not row 3's. `add` also
covers R01, so the **interested-party refusal is load-bearing here**, not defensive: row 3 gives الموظف a
`—`, so neither the filer nor صاحب العلاقة may attest to their own employment file
(`actorIsAnInterestedParty()`, the Stage 84/95 predicate).

**3. The gate.** `RequestController::controlGateRefusal()` currently short-circuits on
`$action !== 'approve'`; it widens to answer for `register` too. All three readers pick it up unchanged —
`register` is not an approval level so `ApprovalController` never reaches it, but `detailResource()`'s
preview filter does, which is what keeps the SPA from offering a button the endpoint refuses.

**4. المقرر «مطلع» needs no new code, and that is the finding rather than a skip.** `register` moves the
file to `requirements_check`, where R02 is the actor, so `NotificationDispatcher::stageChanged()` already
fires `action_required` to R02 at exactly the moment preparation completes. A seventh notification event
would announce the same fact twice.

**5. الرئيس المباشر «مشارك»** — one bounded `RequestVisibility` clause: صاحب العلاقة's own active manager
keeps the file while it sits at `receive_and_register`, so they can follow it and contribute a document HR
is missing. Same shape as Stage 87's `$isHrStudyCoOwner` and Stage 47's SAL clause. **The remaining half is
flagged, not silently built**: whether the manager may *attach* still depends on their role holding
`notes_attachments,add`, because الرئيس المباشر is a `users.manager_id` relationship and not a role a screen
grant can name. That is the same question Stage 101 has to answer for HR on rows 2/4/6/7, so it belongs
there rather than being half-answered here.

**Blast radius, measured rather than estimated:** exactly 10 `'register'` call sites across 5 test files
(`DirectManagerRoutingTest`, `ReferenceAssignedNotificationTest`, `RequestTrackingTest`,
`UnifiedNumberingTest`, `WorkflowServiceTest`). A new `PassesControlGates::prepareEmploymentFile()` supplies
the record once, the `passIntakeGate()`/`supplyRequiredDocuments()` precedent.

**Verify:** new `tests/Feature/EmploymentFilePreparationTest.php` — a submission missing a `service_file`
row is accepted while one missing a non-`service_file` mandatory row is still refused; `register` refused
until prepared, refused on a `missing`, refused on an unanswered row; a conditional row waivable and the
unconditional one not; a covered row derived `present` over a spoofed `missing`; the filer and صاحب العلاقة
both refused the attestation while R12 records it; the manager can open the file at that stage; and the
agenda gate still demands every mandatory row, so nothing was actually dropped. Then the full PHPUnit suite
(baseline 694/4554), Pint on touched PHP, `npm run build` (then reverting `frontend/dist`), locale parity,
and the migration against the real MySQL.

---
### 2026-09-21 03:20 EET — Claude — Stage 95 complete (صاحب العلاقة — the person a request is about)

Built per the plan below. **One migration, one model hook, and the ~20 sites that read the filer where
they meant the employee.** No new screen, no new stage, no new status. Full suite **694 tests / 4554
assertions** green (baseline 684/4511: +10 new tests, and **exactly one** pre-existing assertion
changed), Pint clean repo-wide, `npm run build` passes and `frontend/dist` was reverted, locale parity
**1971 keys each side** with five added per side, checkbox parity **356 each** across
`TEST_PLAN.roles.md`/`.ar.md`, and the migration plus the `ScreenRolePermissionSeeder` reseed ran clean
against the real MySQL.

**⚠ The predicted fixture sweep never happened, and the reason is the whole design.** The stage's own
text warns of "a sweep on Stage 84's scale — every fixture assuming creator ≡ subject". It is not one,
because `subject_user_id` is **nullable, backfilled to the creator, and defaulted by a model hook**, so
every existing row and every existing fixture is *correct* rather than merely unbroken. Read sites then
use the column directly with no `??` — which matters because the visibility scope, Appendix 16's search
and the tracking scope are all SQL, and a `COALESCE` in each would have bought nothing.

**The hook is `saving`, not `creating`, and that is load-bearing.** `creating` alone passed 683 of 684
tests; the one that failed (`UnifiedNumberingTest`) assigns `created_by_user_id` *after* `create()`, as
several fixtures and the reopen path do, so the subject stayed null and the manager gate refused
everyone. `saving` closes that, **with an explicit null guard rather than a bare `??=`**: a
partially-selected model (the restricted eager loads several registers use) carries neither column, and
assigning null there marks the attribute dirty and would wipe a real subject on the next save.

**The gate is stated in THREE places and all three had to move together.** `WorkflowService::
actorIsSubjectsActiveManager()` is the one the stage names, but `RequestVisibility::apply()` restates it
in SQL (so that manager can *open* the file) and `NotificationDispatcher::subjectsActiveManager()`
restates it again (so that manager is the one *told*). Moving one and not the others produces the
failure this codebase keeps refusing — the prompt names one manager, the button works for another, and
the file 404s for whoever it actually belongs to. **Proven live over HTTP, not only by test:** R02 filed
a CTRC for `r01.employee@` (whose seeded manager *is* R02), and R02 was offered `forward` and used it.
Before this stage that gate read the **filer's** manager — R02 has none — so the file would have stalled
at `direct_manager_review` with an empty action list for everyone.

**Art. 101/102 name صاحب العلاقة, so the notice audience split in two.** `requestNotice()` (Art. 101's
twelve moments) and `decisionRecorded()`'s exclusion (Art. 102 keeps the tally from صاحب العلاقة) now
resolve the **subject alone** — both articles name the employee the matter concerns, not the clerk. The
progress news — `stageChanged()`, `requestOverdue()`, `referenceAssigned()` — resolves **subject ∪
creator**, uniqued, because either alone is wrong: only-subject loses the clerk sight of work they
filed, only-creator leaves the employee hearing nothing. `creatorOf()` became `subjectOf()`/`ownersOf()`
over one shared `activeUser()`. `EmployeeNoticeRegister` and Stage 75's computed `notice_status` follow
the subject, since both are about the person those notices were addressed to.

**Appendix 16's search is a correctness fix, not a courtesy.** `DuplicatePolicy`'s own docblock said "a
request's creator *is* its رقم الموظف" — the sentence this stage removes — and the appendix's words are
«البحث **برقم الموظف** وموضوع المعاملة». Left alone, a clerk who filed one promotion could never file
the next employee's: the open-file check matched on the clerk. Shipping the picker without this would
have shipped a broken feature. Both callers (`store()`, `duplicateCheck()`) pass the resolved subject,
and the lookup falls back to the caller when someone without the grant asks about another employee.

**Separation of duties widened rather than moved.** The five self-action refusals — `WorkflowService`'s
two `approve` blocks plus `recordJurisdictionTest()`, `recordIntakeGate()` and `reopen()` — said «لا
يجوز لمقدّم الطلب». Appendix 19's rule names مقدم الطلب, but صاحب العلاقة attesting to the completeness
of their own file is the worse case, so each now refuses **both** parties through one predicate per
class (`WorkflowService::actorIsAnInterestedParty()` for the approve chain, which two endpoints reach;
`RequestController`'s own for the three gate recorders). `GateAuthorshipTest`'s two message assertions
are the single legitimate update this stage made to a pre-existing test.

**Who may name someone else: `request_intake`'s previously unused `approve` tier, seeded
`['R02','R05']`.** The same move Stage 92 made for `meeting_outputs` — an unused action tier on the
screen that owns the domain, rather than a new screen or a role-code check in a controller. R02 and R05
are exactly the two roles besides R01 that already hold `request_intake,edit`, i.e. the pair that
seeder's own Stage 88 comment calls "the roles that actually compose intakes". **R12 was deliberately
left out**: it holds no `request_intake,add` at all, so granting it on-behalf without filing would be
incoherent — widening is one seeder line. Without the grant, `subject_user_id` must equal the caller or
the submission is refused (422 on `subject_user_id`, verified live); the picker is only shown to someone
who would pass.

**The picker's options ride `intakeOptions()`, which the intake screen already calls** — no new endpoint
and no new route. `committees/user-options` (the existing narrow user lookup) was **deliberately not
reused**: Stage 92's membership gate zeroes `meetings,can_view` for anyone with no committee seat, so an
R05 who sits on none would 403 on it. Verified live: R02 gets `may_file_for_others: true` with 16
options, R01 gets `false` with none.

**One real SQL bug the suite caught, worth knowing.** Rewriting the task inbox's self-exclusion as
`whereNot(a = x OR b = x)` is **NULL-unsafe** — a request with no creator yields `NOT (NULL OR NULL)` =
NULL and drops out of the queue entirely, which emptied `ApprovalChainTest`'s reviewer queue. Restored to
the original's explicit shape, once per column (`whereNull(...)->orWhere(..., '!=', ...)`). Three-valued
logic, not Laravel.

**Display sweep, mechanical but not cosmetic:** the eight `'employee' => …->createdBy?->name` sites (six
registers, `AgendaOrderingService`'s Appendix 24 row, `DecisionDraftComposer`'s `{{employee_name}}`),
`PresentationMemoCompiler`'s Art. 22 الموظف/جهة عمله, and `MeetingController::agendaItemContext()`'s [C]
§6 بيانات الموظف tab — which showed the committee the **clerk's** email, phone, department and manager —
plus its الطلبات السابقة query. Every restricted select that now resolves `subject` gained
`subject_user_id`; that is the same restricted-eager-load gotcha Stages 52 and 74 already recorded, and
it fails silently (a null relation, not an error). `MeetingMinutesCompiler`'s `$note->createdBy` is a
discussion-note author and is **not** in scope.

**Kept on purpose.** Nothing renames `created_by_user_id` and not one read of it was removed — "who
filed this" stays a separately recorded fact, which is what lets both names appear side by side on the
workspace when they differ (and neither when they do not, so an ordinary request looks exactly as it
did). `RequestDraft` keeps `created_by_user_id` as its only owner — a draft is the composer's working
state, not a file about anyone yet — and gained one nullable field so a half-composed on-behalf intake
survives a refresh.

**Live smoke, then a clean teardown.** Against Homestead: the picker offered by grant only; R01 refused
naming a colleague («لا يجوز تقديم طلب نيابة عن موظف آخر.»); R02 filed for `r01.employee@` and the
workspace reported `subject: موظف تجريبي` / `created_by: مقرر تجريبي`; the **subject**, who did not file
it, found it on «متابعة طلباتي» and opened it (200); and the forward went through on the subject's
manager. Deleted the fixture request, its attachment and stored file, 3 stage logs, 3 status-history
rows and 3 audit rows, revoked **only** this session's three tokens by id (173–175, leaving the two
pre-existing ones) and removed the two queued jobs naming the deleted request — database confirmed back
to **5 requests / 2 tokens / 12 pre-existing jobs**, with all 5 rows carrying `subject == creator`.

**Docs.** STAGE_PLAN marks 95 built and records that 98 and 101 are now unblocked — the track's only
hard dependency. `gap-analysis-appendix-6.md` (git-ignored) moves row 1's ❌ "deepest finding in the
matrix" to ✅ and rewrites row 2's mechanism paragraph. `TEST_PLAN.roles.md`/`.ar.md` gained the two
changed Appendix A cells (`vae` → `vaeA` for R02/R05 on `request_intake`) and a rewritten R05 check that
walks the on-behalf path end to end.

**Open items for whoever builds Stage 98 or 101.** (1) **The subject is a `users` row, so a request can
only be about someone with an account** — fine today (Track K scope decision (1) puts the employment
record outside this app), but a stage that wants to file for a non-user employee needs a different
answer, not a nullable widening of this column. (2) **`subject_user_id` is immutable after intake** —
there is no "correct the صاحب العلاقة" endpoint, matching every other one-shot intake field; correcting
one means refiling. If that proves too sharp, it is a small PATCH riding `request_intake,approve`, not a
change to the column. (3) **Appendix 6 row 1's two «مطلع» cells are still unimplemented** — الرئيس
المباشر is informed only incidentally (the intake auto-hop's action prompt happens to reach them) and
الموارد البشرية hears nothing at filing; both are Stage 101's. (4) **The R05 grant is narrow by
decision** — R12 files nothing today, so HR cannot raise a file about an employee even though Appendix 6
row 3 makes HR the party that assembles it; Stage 98 should decide whether that row implies
`request_intake,add`+`approve` for R12 rather than inheriting this stage's narrowness as a constraint.

---

### 2026-09-21 00:40 EET — Claude — Implementation plan: Stage 95 (صاحب العلاقة — the person a request is about)

Track N's one hard dependency (95 blocks 98 and 101), and the stage its own text says deserves its own
session. **Scope: one migration + one model hook + the ~20 sites that currently read the filer where
they mean the employee. No new screen, no new stage, no new status.**

**The mechanism that makes the ⚠ blast radius evaporate.** The stage warns to expect a fixture sweep on
Stage 84's scale — "every fixture assuming creator ≡ subject". It does not have to be one: the column is
**nullable and defaults to the creator**, so a `creating` model hook (`subject_user_id ??=
created_by_user_id`) plus a one-line backfill makes every existing row and every existing fixture
*correct by construction* rather than merely unbroken. Read sites then use `subject_user_id` directly
with no `??` fallback, which keeps the SQL clauses (visibility, the duplicate search, the tracking
scope) as simple as they are today. The alternative — nullable with a coalescing fallback at ~20 read
sites — costs a `COALESCE` in every query for the same answer.

**The gate is the Done-when, and it is enforced in three places that must agree.**
`WorkflowService::actorIsCreatorsActiveManager()` is the one the stage names, but it is not the only
copy: `RequestVisibility::apply()` re-states the same rule in SQL (`request_creators.manager_id =
$actor->id`) so the manager can *see* the file, and `NotificationDispatcher::creatorsActiveManager()`
re-states it again so the manager is *told*. Moving one and not the others produces exactly the failure
this codebase keeps refusing: the notification names one manager, the button works for another, and the
file 404s for whoever it actually belongs to. All three move together.

**Art. 101/102 are about صاحب العلاقة by name, so the employee notices follow the subject.**
`requestNotice()` (Art. 101's twelve moments) and `decisionRecorded()`'s exclusion (Art. 102 — the tally
must not reach صاحب العلاقة) resolve the **subject alone**. `stageChanged()`/`requestOverdue()`/
`referenceAssigned()` resolve **subject ∪ creator**, uniqued: the clerk who filed keeps the progress of
their own work, and the employee it is about stops being silent. `EmployeeNoticeRegister::for()` and
`RequestClosureService`'s computed `notice_status` (which reads `createdBy?->is_active` to decide
whether the employee is reachable) follow the subject for the same reason.

**Appendix 16's duplicate search is a correctness fix, not a courtesy.** `DuplicatePolicy`'s own docblock
says "a request's creator *is* its رقم الموظف" — that sentence is precisely the assumption this stage
removes, and the appendix's own words are «البحث **برقم الموظف** وموضوع المعاملة». Left alone, a clerk
who files a PROM for employee A is then refused filing a PROM for employee B, because the open-file check
matches on the clerk. Shipping the picker without this ships a broken feature, so `priorRequests()` takes
a subject id and both callers (`store()`, `duplicateCheck()`) pass the resolved subject.

**Separation of duties widens rather than moves.** The five self-action blocks — `WorkflowService`'s two
`approve` refusals, `recordJurisdictionTest()`, `recordIntakeGate()`, `reopen()`, and
`PendingTaskCollector`'s queue exclusion — currently say «لا يجوز لمقدّم الطلب». Appendix 19's rule is
about مقدم الطلب, but صاحب العلاقة attesting to the completeness of their *own* file is the worse case,
so each becomes creator **OR** subject. Never lazy about a separation-of-duties rule.

**Who may name a subject other than themselves: `request_intake`'s unused `approve` tier, seeded
`['R02','R05']`.** Same move Stage 92 made for `meeting_outputs` — an unused action tier on the screen
that already owns the domain, rather than a new screen or a role-code check in a controller. R02 and R05
are exactly the two roles besides R01 that hold `request_intake,edit`, i.e. the pair that seeder's own
Stage 88 comment calls "the roles that actually compose intakes"; R01 is the employee and R03/R04/R06
approve rather than file. Deliberately narrow: widening is one seeder line, and R12 is left out because
it holds no `request_intake,add` at all, so granting it on-behalf without filing would be incoherent.
Without that grant `subject_user_id` must equal the caller or the submission is refused — the server is
the enforcement, the picker is only shown to someone who would pass.

**The picker's options ride `intakeOptions()`, which the intake screen already calls** — no new endpoint
and no new route. `committees/user-options` (the existing narrow user lookup) is deliberately NOT reused:
Stage 92's membership gate zeroes `meetings,can_view` for anyone with no committee seat, so an R05 who
sits on no committee would 403 on it.

**Display sweep, mechanical:** the eight `'employee' => $x->createdBy?->name` sites (six registers,
`AgendaOrderingService`, `DecisionDraftComposer`) and `PresentationMemoCompiler`'s الموظف/جهة عمله become
the subject — those columns are literally صاحب العلاقة, and today they print the clerk.
`MeetingMinutesCompiler`'s `$note->createdBy` is a discussion-note author and is **not** in scope.
`RequestResource`/`RequestDetailResource` gain a `subject` block; the SPA shows صاحب العلاقة only when it
differs from the filer, so an ordinary self-filed request looks exactly as it does today.

**Deliberately NOT touched:** `RequestDraft` keeps `created_by_user_id` as its only owner (a draft is the
composer's own working state, not a file about anyone yet) — `SaveRequestDraftRequest` gains one nullable
field so a half-composed on-behalf intake survives a refresh, and nothing else about drafts changes.
Nothing renames `created_by_user_id` or removes a single read of it: "who filed this" stays a real,
separately-recorded fact.

**Verify:** new `tests/Feature/RequestSubjectTest.php` — the backfill/creating-hook making an unset
subject equal the creator; the manager gate answering to the *subject's* manager and refusing the
creator's, through the transition endpoint AND the visibility gate AND the action-required notification;
Art. 101's notice reaching the subject and not the clerk while the tally still excludes the subject; the
Appendix 16 search matching per subject so a clerk can file the same type for two employees; the intake
refusing a foreign subject without the grant and accepting it with; the duplicate-check lookup and the
tracking screen both following the subject; a self-filed request behaving exactly as before. Then the
full PHPUnit suite (baseline 684 tests / 4511 assertions), Pint on touched PHP, `npm run build` (then
revert `frontend/dist`), locale key parity, `php artisan migrate` against the real MySQL, and a
`ScreenRolePermissionSeeder` reseed — ⚠ which resets the whole matrix on a live install.

---
### 2026-09-20 23:55 EET — Claude — Stage 96 complete (R09/R10 folded back into the matrix's parties)

Built per the plan below. **No migration, no new endpoint and no service logic** — seeded data plus
comments, which is the check that this is a re-seed rather than a feature. Full suite **684 tests /
4511 assertions** green (baseline 684/4520: same test count, −9 assertions where three-iteration
loops collapsed to one), Pint clean on all fifteen touched PHP files, `npm run build` passes and
`frontend/dist` was reverted, locale parity **1966 keys each side** with nothing added or removed,
checkbox parity **353 each** across `TEST_PLAN.roles.md`/`.ar.md`, and `php artisan migrate` reports
nothing to migrate.

**Real-database check, by query rather than assumption.** All four seeders re-run against the real
MySQL: **12 stages, 49 transitions** (was 55 — two retired `route_to_*` rows, two `register` rows,
two `cancel` rows), **0 rows pointing at a missing stage**, one route out of
`administrative_routing`, one `register` row (R12 + `routed_to_hr`), both committee hops R02,
`cancel` at `forward_to_committee` R02 and at `receive_and_register` R12 alone. **No file is
stranded by the collapse**: the real database holds **zero** requests in `routed_to_diwan` or
`routed_to_committee_secretary` — checked, not assumed. Smoke-tested over real HTTP as
`r09.secretary@`: 12 screens, `legal_review` genuinely absent; the one token that run minted was
revoked and the database is back to its 2 pre-existing tokens and 5 requests.

**Two cleanups are load-bearing and must not be "simplified" away.** Exception rows survive the
generic delete at the top of `WorkflowTransitionSeeder::run()`, so the two retired `route_to_*` rows
and the superseded `cancel` rows each need their own targeted delete — without them an existing
database keeps offering a manager three routes, two of which nobody can then register. Both are
pinned by tests that fail if the delete is dropped: `DirectManagerRoutingTest` walks each retired
route and each retired registrar, and `CommitteeHandoverTest` puts the pre-Stage-96 R09 cancel row
back by hand before re-seeding, because a fresh database never holds the stale row and seeding twice
would prove nothing.

**Scope judgment calls, recorded so they are not re-litigated.** (1) **Both** `forward` hops moved,
not only the one the Build bullet's line number points at: Stage 86 moved both, and Appendix 6 gives
جدولة/عرض الملف to مقرر اللجنة as a single مسؤول. The second hop lands on **R02**, not its
pre-Stage-86 R05 — R05 is a tier of the approving column, not the secretariat. (2) **R02 was NOT
added to `meetings_dashboard`**: its `add`/`edit` grants gate no route at all (only `view` appears
in `routes/api.php`), so a role there is decoration; R09 was simply removed. `committee_candidates`
and `meeting_agenda` needed a removal only, since R02 already held both tiers on each. (3) **R09/R10
came out of `notes_attachments,add`** although the Build bullet does not list it — that grant's own
seeder comment bounds it to "all three of R12/R10/R09 hold a `register` row at
`receive_and_register`", which stops being true here. (4) The single remaining route stays an
**exception** with `requiresComment: false`: `administrative_routing` already showed nothing under
the normal-actions heading, so nothing regressed, and moving it into `$transitions` is impossible
anyway — that array's single-role tuple shape cannot express a manager gate.

**Kept on purpose.** The `routed_to_diwan` / `routed_to_committee_secretary` **status rows**, their
`workflow.actions.route_to_*` locale keys, and `PeriodicReportService`'s `awaiting_administration`
bucket — a request that already took one of those hops must still render its own timeline
(legacy-status precedent, same as `archived`). Both **role rows** stay too, with their descriptions
rewritten to say they carry no duty, so existing logins, audit rows and historical stage logs keep
resolving a role. `RequestResponsibilityService::ROLE_TO_PARTY`'s now-unreachable R09/R10 entries
stay, annotated; `PermissionSeeder` (the legacy, unread catalogue) was left alone.

**`NotificationTest` needed a different hop, not a role swap** — worth knowing, because this is the
second time it has moved. Its two fixtures walk a transition whose actor and next actor must be
different parties, or there is no handoff left to assert. Stage 86 moved them to
`reviewer_review → observations` precisely because `observations` had become R09's; Stage 96 gives
R02 the whole pre-committee chain back, which makes that hop same-role again — and
`actorsForStage()` excludes the actor. They now walk
`forward_to_committee → receive_from_committee`: R02 moves it, R03 acts next.

**Test blast radius: six files, every change a legitimate update.** `CommitteeHandoverTest` is Stage
86's own file, inverted in place — its re-seed-cleanup cases are the most valuable thing in it,
because this stage re-creates exactly the hazard they pin. `DirectManagerRoutingTest`'s three-route
and three-registrar cases collapse to one each and gained the retired-row assertions.
`WorkflowServiceTest`'s happy-path walk, actor maps and seeded-shape counts moved (non-exception
rules 13 → 11, cancel rows 14 → 12, register rows 3 → 1). `RequestDetailTest` expects `forward`
first at `observations` again, and `HumanResourcesSeatTest` / `NotificationTest` as above.

**Docs.** STAGE_PLAN marks 96 built and its Track N line lists it. `TEST_PLAN.roles.md`/`.ar.md` had
§10/§11 rewritten (both roles are now "a retained login with no seeded duty", and the sections are
almost entirely refusals — **that is the test**: if either can still act, a grant or a row survived
the fold), plus the account table, the relay (now **nine** people, not ten), four Appendix A rows and
Appendix B. `TEST_PLAN.md`/`.ar.md` and `USER_GUIDE.ar.md` had their routing/stage tables corrected —
note that several of those cells were **already stale** (stage 8 still read R05, i.e. pre-Stage-86;
the guide still gave the HR route to R05, i.e. pre-Stage-87). `compliance-matrix.md` and
`gap-analysis-appendix-6.md` (git-ignored) record the structural finding as closed.

**⚠ The per-role screen-count table is stale for reasons this stage did not cause, and now says so.**
It reports seeded-matrix counts, but the membership gate hides the seven `meetings_management`
screens from anyone with no committee seat — an unseated R09 sees **12 screens / 10 entries**, which
is what the live smoke run returned, not the 19/17 the matrix implies. The seeder also now holds
**36** screens, not the 35 the appendix claims. A note under the table states both; only the four
rows this stage changed were re-derived.

**Open items.** (1) **`request_types.default_administrative_route` (Stage 56) is now vacuous** and was
deliberately left alone: with one route, ten of the twelve seeded types suggest a destination that no
longer exists, so the badge never renders and the Request Types admin screen still offers three
values. Narrowing `RequestType::ADMINISTRATIVE_ROUTES` is a decision about Stage 56's own mechanism
that this stage's Build bullet does not make, and a stage restoring a destination would have to undo
it — `DirectManagerRoutingTest` pins the consequence rather than hiding it, and `USER_GUIDE.ar.md`
dropped the now-uniform suggestion column. (2) **A whole-file Appendix A re-derivation is owed** to
whoever next touches the role test plans; the methodology is in that file's own header and the drift
predates this stage. (3) R09/R10 remain in `TestUserSeeder` and `PermissionSeeder`; if a later stage
decides the logins are not worth keeping, both are one line each.

---

### 2026-09-20 23:20 EET — Claude — Implementation plan: Stage 96 (R09/R10 folded back into the matrix's parties)

Track N decision 3, already taken (2026-09-20) and not re-litigated here: **R09 (أمين سر اللجنة)
and R10 (وكيل الديوان) have no column in Appendix 6** — both are diagram-alignment inventions
(AGENT_NOTES 2026-08-28, sourced from a municipality infographic, not from [D]) — so they keep
their logins and stop holding duties belonging to a column they do not have.
**Scope: seeded data + comments + tests. No migration, no new endpoint, no service logic.**

1. **`WorkflowTransitionSeeder`** — R09's two `forward` rows (`observations → forward_to_committee`,
   `forward_to_committee → receive_from_committee`, both given to R09 by Stage 86) return to
   **R02**. The Build bullet names one line number; it points at the second of the pair, but Stage
   86 moved both and the goal ("no duty the matrix assigns to a column is held by a role without
   one") covers both — Appendix 6 gives جدولة/عرض الملف to مقرر اللجنة as a single مسؤول.
   `cancel` at `forward_to_committee` follows by that seeder's own stated rule (cancellation belongs
   to whoever moves the stage), R09 → R02, and its **targeted delete flips `!= R09` → `!= R02`** —
   exception rows survive the generic delete at the top of `run()`, so without that flip the
   superseded R09 row lives on beside its replacement.
2. **Routing collapses to one route.** `$routingActions` keeps only `route_to_hr`; the two retired
   exception rows (`route_to_diwan`, `route_to_committee_secretary`) need their **own targeted
   delete** for the same is_exception reason. `$registrations` becomes the single
   `['R12', 'routed_to_hr']` pair (those rows are `is_exception=false`, so the generic delete
   sweeps the other two), and `cancel` at `receive_and_register` narrows from R12/R09/R10 to R12.
3. **Statuses retire from the seeded map, rows kept** (legacy-status precedent, same as `archived`):
   `routed_to_diwan` / `routed_to_committee_secretary` stay in `RequestStatusSeeder` and stay in
   `PeriodicReportService`'s `awaiting_administration` bucket, and
   `workflow.actions.route_to_diwan|route_to_committee_secretary` stay in both locale files — a
   request that already took one of those hops still renders its own timeline.
4. **`WorkflowStageSeeder`** — the indicative `responsible_role_id`: stage 8 R09 → **R02**, stage 9
   R09 → **R03** (its pre-Stage-86 value). This field names who the file is sitting with, and
   `RequestResponsibilityService::partyFromStage()` falls back to it for a stage with no rule.
5. **`ScreenRolePermissionSeeder`** — R09 out of `meeting_agenda`, `committee_candidates` (R02
   already holds both tiers on each, so this is a removal, not a move), `legal_review` view+edit,
   and `meetings_dashboard`. **R02 is deliberately NOT added to `meetings_dashboard`**: its
   `add`/`edit` grants gate no route at all (verified — only `view` appears in `routes/api.php`), so
   adding a role there would be decoration. R09/R10 also come out of `notes_attachments,add`: that
   grant's own seeder comment bounds it to "all three of R12/R10/R09 hold a `register` row at
   `receive_and_register`", which stops being true here.
6. **`RoleSeeder`** — R09/R10 rows stay (logins are kept by decision 3); their descriptions, which
   describe duties they no longer hold, are rewritten to say so.
7. **Comments only:** `RequestVisibility`, `MeetingController`, `PresentationMemoController`,
   `routes/api.php` each cite R09 in prose that this stage falsifies.

**Deliberately NOT touched, flagged rather than silently widened:** `request_types
.default_administrative_route` (Stage 56's advisory badge) becomes **vacuous** — with one route,
ten of the twelve seeded types suggest a destination that no longer exists, so the badge simply
stops rendering. Narrowing `RequestType::ADMINISTRATIVE_ROUTES` is a decision about Stage 56's own
mechanism that this stage's Build bullet does not make, and a future stage restoring a destination
would have to undo it. Recorded as an open item. Also left alone: `PermissionSeeder` (the legacy,
unread catalogue) and `RequestResponsibilityService::ROLE_TO_PARTY`'s now-unreachable R09/R10 rows.

**Test blast radius, all legitimate updates rather than regression fixes.**
`CommitteeHandoverTest` is Stage 86's own test file and is inverted in place — its re-seed-cleanup
cases are the most valuable thing in it, because this stage re-creates exactly the stale-row hazard
they pin. `DirectManagerRoutingTest`'s three-route and three-registrar cases collapse to one.
`WorkflowServiceTest`'s happy-path walk, actor maps and seeded-rule-shape assertions move R09 → R02
and `['R12','R09','R10']` → `['R12']`. **`NotificationTest` needs a different hop, not a role
swap:** its two fixtures walk `reviewer_review → observations` as R02 precisely because Stage 86
made R09 the next actor there; with `observations` back to R02 the actor and the next actor are the
same role and `actorsForStage()` excludes the actor, so they move to
`forward_to_committee → receive_from_committee` (R02 acting, R03 next).

**Verify:** full PHPUnit (baseline 684 tests / 4520 assertions), Pint on touched PHP,
`npm run build` (then revert `frontend/dist`), locale key parity, reseed
`WorkflowStageSeeder` + `WorkflowTransitionSeeder` + `ScreenRolePermissionSeeder` + `RoleSeeder`
against the real MySQL and confirm the row shape by query; `php artisan migrate` — expected nothing
to migrate.

---


### 2026-09-20 22:10 EET — Claude — Stage 97 complete (القيد back to مقرر اللجنة)

Built per the plan below. **No migration, no new endpoint, and no service logic** — the behaviour
change is two seeded values, which is the check that `applyRule()`'s destination-status keying did
what its docblock promised: the قيد moved twice (Stage 70 → 2026-09-18 → here) without that method
being edited. Full suite **684 tests / 4520 assertions** green (baseline 684/4520: one test added,
one redundant one deleted), Pint clean on every touched PHP file (the repo-wide `--test` failure is
the same `scripts/build-guide-pdf.php` drift as before), `npm run build` passes and `frontend/dist`
was reverted, locale parity **1966 keys each side** with no key added or removed (values only),
checkbox parity **361 each** across `TEST_PLAN.roles.md`/`.ar.md`, and `php artisan migrate` reports
nothing to migrate.

**Real-database check, by query rather than assumption:** all three `register` rows now set
`in_review`, `requirements_check → approve` sets `registered`, and `decisions,can_approve` is held by
**R03 and R08 only**. Both seeders were re-run against the real MySQL. Note the standing hazard:
`ScreenRolePermissionSeeder` resets the whole matrix, so any grant customised through the Roles &
Permissions screen on that database was reset too — none is known, but on a live install apply the
one-cell change by hand instead.

**What flipped.** `register` lands on Art. 38 code **04** (تحت فحص الاكتمال) and mints nothing;
R02's approve is the قيد (code **06** + `PM-COM`) with بوابة 1 guarding the same hop, so the gate is
*before* the قيد again — and it needed no deadlock workaround, because both now sit where R02 can
act. The three gate-1 refusal strings, `controlGates.intake.title`, `requestDetail.awaitingRegistration`,
`intake.receiptNotice`, `workflow.actions.register` (both locales) and USER_GUIDE.ar.md were
restored, and Art. 101 moment 3's body is back to «باكتمال ما طُلب استكماله» — that wording was
softened *only because* moment 3 fired at re-registration, and it fires at the completeness hop
again, so the claim is true again. That closes the "known imprecision" the 09-18 note recorded.

**Kept on purpose:** the `reference_assigned` notice and the `Attachment` classification work from
the same 09-18 commit — neither depended on where the قيد sits. The notice follows the *allocation*,
so it simply fires on the approve hop now; its five tests were re-pointed at that hop, and one
(the "mid-pipeline file mints on approve" case) was deleted as it had become the main path. A new
assertion pins that `register` announces nothing.

**R02 out of `decisions,approve`** (the smallest item): `WorkflowService` had always refused an R02
outcome (every committee outcome row requires R03), so the grant passed the 403 gate and 422'd — a
capability that only looked real. New `DecisionRegisterTest` case pins R03-yes / R02-no. **The trade
is real and recorded:** `compliance-matrix.md`'s Appendix 45 moved ✅ → ⚠ (تسجيل النتيجة re-opens),
Part B headline **62/20 → 61/21**, with the reading of Appendix 6 row 10's «توثيق» as documentation
rather than the tally stated as the assumption it rests on.

**Docs (git-ignored, local only):** `source-detailed-flow-verbatim.md` stage 07 divergent →
compliant (tally 18·2·1 → **19·1·1**, flipped in this same change per the Stage 94 note),
`compliance-matrix.md` Appendix 6 note + Appendix 45 + headline, `gap-analysis-appendix-6.md` row 5
marked resolved. STAGE_PLAN.md marks 97 built and corrects the Track N line that said only 94 was.
Stage 97 was taken **ahead of 96**, which its own text allows.

**In-flight data:** a file numbered at `register` under the old rule keeps that number (the mint is
null-only, Art. 99) and simply re-stamps `registered` on approve; a file at `requirements_check`
unnumbered now mints on approve, which is the intended path. No backfill. The real database holds
5 requests, 1 of them already numbered; that one keeps its number and nothing was changed on any of them.

**Open items.** (1) `RequestResponsibilityService`/notice copy still says nothing about *who* the
قيد belongs to on screen — Appendix 6 row 5 is now true in code but the request card does not label
it; Stage 98's HR step is the natural place to make the pre-قيد hand-offs legible. (2) The
`register` action's label is once again «تسجيل الاستلام», and R12/R10/R09 all still perform it —
collapsing three routes to one is Stage 96, untouched here.

---

### 2026-09-20 21:00 EET — Claude — Implementation plan: Stage 97 (القيد back to مقرر اللجنة)

Reverses the 2026-09-18 19:40 move, per Track N decision 2 (already taken; not re-litigated here).
**Scope: seeded data + copy + tests, no service logic.** `WorkflowService::applyRule()` keys the
mint off the destination status, so the whole behavioural change is two seeder fields.

1. **Seeder** — the three `register` rows in `WorkflowTransitionSeeder` set `in_review` (Art. 38
   code 04) instead of `registered`; `requirements_check → approve` keeps `registered` (code 06) and
   is again the hop that mints. Rewrite the two comments that claim the opposite.
2. **Copy** — restore Appendix 63 بوابة 1 «قبل القيد»: the three `IntakeGateService` refusal strings
   (and its docblock NOTE), `controlGates.intake.title`, `requestDetail.awaitingRegistration`,
   `intake.receiptNotice`, `workflow.actions.register` in both locales; Art. 101 moment 3's
   `documents_completed` body back to «باكتمال» (that moment fires on the completeness hop again, so
   the claim is true again). Stale comments in `WorkflowService`, `RequestStatusSeeder`,
   `ArtifactNumberGenerator`, `EmployeeNoticeService`, `RequestDetailView.vue`.
3. **Kept on purpose:** the `reference_assigned` notice (added by that same commit, keyed off the
   allocation not a stage, so it now simply fires on the approve hop) and its tests' logic — only
   the hop the tests walk changes.
4. **Smallest item** — `decisions,approve` loses R02 → `['R03']`. Gates one route; no test exercises
   R02 on decisions, so add one that pins the 403.
5. **Tests** — flip the assertions commit 8d4c01a flipped (ControlGateTest ×4 strings,
   DirectManagerRoutingTest, UnifiedNumberingTest ×3, WorkflowServiceTest walk,
   ReferenceAssignedNotificationTest, HumanResourcesSeatTest / any test asserting a number after
   `register`). Discover the rest by running the full suite, not by guessing.
6. **Docs** — `compliance-matrix.md` Appendix 45 ✅ → ⚠ and Appendix 6 row 5 / Art. 20 rows;
   `source-detailed-flow-verbatim.md` stage 07 → compliant (same commit, per the Stage 94 note);
   USER_GUIDE.ar.md §1.3/§4.4/§4.5/glossary and both TEST_PLAN.roles files (relay step 4↔5);
   STAGE_PLAN Stage 97 marked built.
7. **In-flight data:** a file numbered at `register` under the old rule keeps its number (mint is
   null-only, Art. 99); it just re-stamps `registered` on approve. No backfill; a file sitting at
   `requirements_check` unnumbered would now mint on approve — the intended path.

**Verify:** full PHPUnit (baseline 684 tests / 4520 assertions), Pint on touched PHP, `npm run build`
(then revert `frontend/dist`), locale key parity, reseed `WorkflowTransitionSeeder` +
`ScreenRolePermissionSeeder` against the real DB and confirm rows by query; `php artisan migrate` —
expected nothing to migrate.

---

### 2026-09-20 EET — Claude — Stage 94 (Appendix 6 RACI re-derived cell by cell) — TRACK N opened

**Docs only. No PHP, Vue, migration or seeder was touched, so no PHPUnit/Pint/`npm run build` run
is claimed or warranted** — running them would prove nothing about this change. The only tracked
files this touches are `STAGE_PLAN.md` and this note; `docs/` is git-ignored, so the analysis and
the two matrix corrections stay local, as every file in that folder does. (The `graphify-out/*`
churn in `git status` predates this session and is not mine.)

**The finding that started it: `compliance-matrix.md` carried Appendix 6 as ✅, and the ✅ did not
hold — for two different reasons, both worth internalising because neither is about this appendix.**
Stage 84 (2026-09-11) checked **four rows of fifteen**, honestly and well, and the ✅ it earned was
then read as covering all fifteen. **And one of the four it fixed had since regressed**: the
2026-09-18 قيد move took registration off مقرر اللجنة — precisely the row Stage 84 corrected — while
that row's own ✅ note went on *citing القيد as evidence for the ✅*. So: **(1) a ✅ on a
matrix-shaped appendix only ever means "checked at the grain someone chose that day", and (2) a ✅
can rot without anyone touching its row.** When a stage moves a mechanism, re-check every appendix
that cites it, not only the one its Source line names. Both lessons are now in the headline note.

**New `docs/employee-committee-lifecycle/gap-analysis-appendix-6.md`** — the first per-cell
re-derivation, all **41 populated cells** (15 rows × 7 columns; the other 64 are a literal `—`),
each verified at file:line against the live seeders/services rather than read off the matrix.
Score: **مسؤول implemented 10/15**, absent 3 (تجهيز الملف الوظيفي · الإشعار · الأرشفة), contradicted
1 (القيد), half-live 1; **~13 of ~26** non-مسؤول cells have no implementation. Shape of the result:
**the system enforces accountability tightly and consultation barely** — every R with a home is
enforced, often double-enforced, while almost every C and I cell is decorative.

**Four findings worth knowing before touching this area.** (1) **صاحب العلاقة does not exist as a
field** — `requests` carries only `created_by_user_id`, so the person a request is *about* is always
its filer, and `actorIsCreatorsActiveManager()` therefore routes a file filed on an employee's
behalf to *the clerk's* manager, silently. Four rows rest on that column. (2) **تجهيز الملف الوظيفي
has zero implementation and its duty is discharged by the wrong party** — `DocumentCompletenessService`
runs at submission, so the *submitter* assembles the documents on a row that gives الموظف a `—`.
(3) **R02 holds `decisions,approve` but every committee outcome row in `workflow_transitions`
requires R03** (`:107`, and `:443/458/466/474/492/536/553`, all verified `$roles['R03']`), so an R02
passes the 403 and then 422s; it works only on the appeal branch, which never touches
`WorkflowService` — which is why no test caught it, and no test anywhere exercises R02 on decisions.
(4) **R09/R10 have no column in this appendix at all** (diagram-alignment inventions, AGENT_NOTES
2026-08-28), yet R09 holds three duties the matrix gives مقرر اللجنة as one مسؤول.

**Three decisions were taken by the user on this analysis — recorded so they are not
re-litigated.** (1) Conform on **every cell**, not only the accountable ones. (2) **القيد returns to
مقرر اللجنة**, reversing 2026-09-18 — and note Art. 20 («بعد ثبوت اكتمال الملف») and Appendix 6
*agree*, so that one change broke both and the revert closes both, restoring Appendix 63's بوابة 1
to its place before the قيد. (3) **R09/R10 fold back into the matrix's parties**, keeping their
logins and losing duties belonging to a column they do not have.

**One judgment call made rather than asked, and one interpretation recorded rather than assumed.**
The approving side is a **single column** while the system has three tiers behind it (R03 → R05 →
[R06] → R07); no cell says there may be only one, so **the chain is NOT collapsed** — collapsing it
would be Stage-57-scale blast radius the source never asks for. And row 10's المقرر «توثيق» is read
as **documentation, not the tally** (Appendix 45 uses a different phrase, «تسجيل النتيجة», for
recording the result), which is what lets R02 come out of the dead `decisions,approve` grant without
leaving that cell unimplemented — R02 already holds `meeting_minutes,add`. If a later reader takes
توثيق as the tally instead, that inverts the smallest item in Stage 97.

**Also corrected:** the Appendix 6 row ✅ → ⚠ and the Part B headline **63/19 → 62/20**. That
headline moved 62/20 → 63/19 *earlier the same day* for a stale ⚠ (النموذج 12); it now moves back
for the opposite failure, a stale ✅. Both moves are recorded side by side in the note under the
table, deliberately — the pair is more instructive than either alone.

**New Track N (Stages 94–101)** in STAGE_PLAN.md, with 94 done. **There is exactly one hard
dependency: 95 (صاحب العلاقة) blocks 98 and 101**, because both act on the person a request is
about; everything else is preference and is written as preference, so nobody inherits a constraint
that was never real. I originally wrote "96 before 97" as a dependency and **corrected it** — 97 is
a status swap on two seeded rows and does not care how many rows sit beside them; folding R09/R10
out first only shrinks its diff. **Stage 95 deserves its own session**: it touches the identity of
every request in the system, with a fixture sweep on Stage 84's scale.

**Open items.** (1) Appendix 45's row stays ✅ but **will need ⚠ when Stage 97 lands**, since
reverting R02 out of `decisions,approve` re-opens تسجيل النتيجة — the trade is deliberate and is
written into Stage 97's own entry, not left to be discovered. (2) `source-detailed-flow-verbatim.md`
stage 07 is tagged divergent for the قيد; Stage 97 flips it to compliant, and whoever does it should
flip it in the same commit rather than leaving a third file to drift. (3) The analysis cites
`compliance-matrix.md` **by row, not by line**, on purpose — that file shifts, and an earlier draft
of this very document already had a stale `:374` in it after one edit.

---

### 2026-09-20 EET — Claude — Gap analysis vs. [D] + [E] recorded; two gaps closed; three stale doc rows corrected

Built per the plan below. **No migration** — `referral_authority` has existed since Stage 50 and the
grant is seeded data, which is the check that both fixes close a hole rather than add a capability.
Full suite **684 tests / 4520 assertions** green (was 682/4493 — exactly this change's +2 tests),
Pint clean on all six touched PHP files, `npm run build` passes with `AgendaItemDecisionPanel` picking
up the change in its existing chunk (then reverted the tracked `frontend/dist`), locale key-parity
verified programmatically (**1966 keys each side, zero on-one-side-only** — no new copy, the one key
this needed already existed), and the `ScreenRolePermissionSeeder` reseed ran **twice** against the
real MySQL/Homestead database, stable at 12 rows / 9 `add` holders both times, with `php artisan
migrate:status` reporting nothing pending.

**The analysis is at `docs/employee-committee-lifecycle/gap-analysis-D-and-E.md`** (git-ignored,
local-only, so it will not show in `git status`). It re-derives every finding against the live code
rather than reading `compliance-matrix.md`, and the headline is that **the process shape is right** —
every structural demand [D] makes (Art. 10 (أ)'s roster، Art. 45's six questions، Art. 21's legal
review، Arts. 89–91's structured decision، Art. 98's registers، Art. 106's KPIs، Art. 101's twelve
moments، Appendix 63's gates، Arts. 34–37's closure) is built and enforced. What is left clusters into
ten items, four of which need a **decision** rather than a patch.

**⚠ The record had drifted in both directions, and three rows were corrected as part of this.**
(1) `source-detailed-flow-verbatim.md`'s stage 07 still tagged the قيد **compliant**, citing
`ArtifactNumberGenerator` minting "at the `requirements_check → approve` completeness outcome" — a hop
that has minted nothing since 2026-09-18. Now tagged divergent, with the tally moved 19·1·1 → **18·2·1**.
(2) `compliance-matrix.md`'s **النموذج 12** row still read "no structured 4 parts, no decision number →
Stages 70, 74" although both stages had long shipped; moved ⚠→✅, Part B headline 62/20 → **63/19**.
That is the failure mode this table is most prone to and the headline block now says so: the 2026-09-12
recount fixed *arithmetic* drift but re-tallied the tags as written without re-checking each against the
code, so **a ⚠ whose note names a closing stage is unverified until that stage is confirmed unbuilt**.
(3) **[E]'s 8 decision gates and 7 supplementary diagrams were tagged nowhere at all** — grepping the
matrix for بوابات القرار or الصور المكملة returned zero, so nothing in this repo had ever been checked
against them. Added as new **Parts C.2 and C.3**; they are not a restatement of the stages but the
branch conditions, and two of them fail in ways the stage rows do not surface (gate 2 enforced out of
order, gate 7 on the wrong input).

**Fix A — every receiving body can now record the إفادة.** `notes_attachments.add` gained **R09 (أمين
سر اللجنة) and R10 (وكيل الديوان)**, by the same bound and for the same reason Stage 87 added R12: all
three hold a `register` row at `receive_and_register`, so two of the three could accept a file and then
attach nothing to it, against [E] stage 03's «تستكمل ما يقع ضمن اختصاصها من بيانات وإفادات».
Deliberately walked **over real HTTP** in the new test — the gap was in the screen-permission
middleware, so a direct service call would have passed the whole time. Visibility needed nothing:
`RequestVisibility` already derives from the transition rows, which is why the test passed first run.
The test also pins the bound — R07, holding no `register` row there, still gets a **403**, so this
widened two seats rather than the screen.

**Fix B — a referral must now name where it is going.** [D] **Art. 26 (د)** and [E] **13D** both require
عدم الاختصاص to «تحدد الجهة أو المسار الإداري المختص»; `Decision.referral_authority` was **nullable**,
so a committee could hand the matter on without saying to whom. New
`DecisionStructureRules::REFERRING_OUTCOMES` (`no_jurisdiction`, `refer_other_body`, `appeal_refer`) +
one check in `firstProblem()`, which is read by **both** `record()` and `recordAppealDecision()`, so an
ordinary and an appeal decision cannot be held to different drafting standards. `structuredPayload()`
gained the field — it was not reaching the rules at all.

**Two design points in Fix B worth not re-litigating.** (1) **It is NOT a `required_if` in
`StoreDecisionRequest`**, which is what the plan first said: requiredness depends on the **tallied**
outcome, which the FormRequest cannot see — that is the documented reason `DecisionStructureRules`
exists at all. (2) **`refer_other_body` was deliberately NOT added to `REASONED_OUTCOMES` or
`SUBSTANTIVE_OUTCOMES`**, which the plan also proposed. Art. 91's reasoned cases are *refusals*, and a
referral is not one — adding it would have demanded an Appendix 28 `refusal_reason_code` for a
non-refusal; and Art. 26 lists a referral among التأجيل's reasons, so it does not dispose of the matter
and is rightly excused الوقائع/السند. **The actual bug was only that the field those exclusions point
at was optional**, so making it required is what makes `SUBSTANTIVE_OUTCOMES`' own comment ("referrals
carry their own structured fields instead") true. `legal_opinion` is excluded for the same reasoning —
it goes to the committee's own legal member (R11), not out to a body that needs naming.

**One frontend bug this exposed and fixed in passing.** `AgendaItemDecisionPanel.vue` gates which
structure fields it shows on the predicted outcome — its own comment says "showing only the relevant
fields is what keeps the form from asking for a سند on a تأجيل" — but the referral input was
**unconditional**, so it asked every decision, including an `approve` that refers to nobody, and was
optional on the two that must answer. Now a `needsReferral` computed mirroring its three siblings,
with `required` on the input. The record button's `:disabled` was left alone deliberately: none of the
three sibling conditional fields gate it either, and a disabled button with no explanation is worse
than the endpoint's own Arabic 422.

**Four pre-existing tests needed legitimate expectation updates, not regression fixes** — every one
builds a deliberately-incomplete referral payload, which is exactly what this change makes incomplete.
`RecordsStructuredDecisions` gained the field for the three referring outcomes (the case its own
docblock anticipated: "a later stage that changes what a decision must carry updates one helper"), and
three `DecisionStructureTest` cases were updated in place with a comment each. One of those is worth
knowing: `test_an_unmeasurable_operative_clause_...` now carries `referral_authority` in its `$base` so
the referral rule cannot be what refuses it — which also **pins the ordering**, since the
unmeasurable-clause check fires first.

**Open items, all recorded in the analysis with their reasoning.** (1) **The approval path is
hardcoded and it is the largest gap** — Art. 92 requires جهة الاعتماد determined before the matter is
presented and Appendix 58 forbids a default («ولا يجوز ملء خانة جهة الاعتماد بصورة افتراضية إذا لم
يحددها التشريع»), but the branch reads `decision_grade` vs. the type threshold while **four** recorded
answers to that exact question (Art. 45 Q5/Q6 on `jurisdiction_test`, Appendix 22's
`approving_body`/`requires_central_approval`) drive nothing. Sharpest form: **the system checks that
the محضر names an approving body, then routes to a different one.** Ownerless since Stage 57; the
decision — which input wins, and what happens when two disagree — is the process owner's, and the code
behind any answer is small. (2) **Art. 38's code 04 (تحت فحص الاكتمال) has no status at all**, and code
06 (مستوفية ومقيدة) is set on *arrival* at the completeness check — a file reads "complete and
registered" while its completeness is unverified. That is the 2026-09-18 call, listed so it is not
re-opened by accident. (3) **An adjourned meeting is structurally unrepresentable** (Appendix 67): no
`ended_at`, no adjourned status, closing refuses unless every item is resolved, and an item the
committee never opened is byte-identical to one just presented — plus `agenda_deadline` is compared to
nothing. (4) **Appendix 46's immutable محضر copy cannot exist** — `meeting_minutes.meeting_id` is
`unique()`, and `request_corrections` has no `meeting_minutes_id`, so a typo on an approved محضر keeps
printing. (5) **Art. 67's leave gate does not exist** — every `LEAV` request walks the full pipeline,
mitigated only by a checklist row asking the submitter to justify it.

---

### 2026-09-20 EET — Claude — Implementation plan: gap analysis vs. [D] + [E], and the two unambiguous fixes it found

User request, twice: a gap analysis of the system against the attached sources — first **[E]**
(المسار التفصيلي المعتمد: 21 stages, 8 decision gates, 7 supplementary diagrams), then **[D]** (the
full 142-page، 114-Article، 78-appendix دليل إجراءات لجنة شؤون الموظفين). Both are already this
repo's declared standard (AGENTS.md) and Track K existed to make the system identical to them, so
this is a re-derivation against the live code rather than a first comparison — and the existing
record has drifted in both directions.

**Scope: docs first, then exactly two code fixes.** (1) Write the analysis to
`docs/employee-committee-lifecycle/gap-analysis-D-and-E.md`. (2) Fix the stale rows the re-derivation
found: `source-detailed-flow-verbatim.md`'s stage-07 row still tags the قيد compliant citing the
`requirements_check → approve` hop, which stopped minting anything on 2026-09-18; `compliance-matrix.md`'s
النموذج 12 row still reads "no structured 4 parts, no decision number" although Stage 74 built the
parts and Stage 70 built the numbers; and **[E]'s 8 gates and 7 diagrams are tagged nowhere at all** —
grepping the matrix for بوابات القرار or الصور المكملة returns zero, so nothing in this repo was ever
checked against them. (3) Close the two gaps that are unambiguous against the source text and need no
decision from the process owner.

**Fix A — [E] stage 03: two of the three receiving bodies cannot record the إفادة.** [E] stage 03 has
the concerned body «تستكمل ما يقع ضمن اختصاصها من بيانات وإفادات» before referring to المقرر, but
`ScreenRolePermissionSeeder`'s `notes_attachments.add` is `[R01,R02,R03,R04,R05,R12]` — **R09 (أمين سر
اللجنة) and R10 (وكيل الديوان) are absent**, while both hold `register` at `receive_and_register`. They
can accept a file and then attach nothing to it. R12 was added for this exact reason by Stage 87; the
other two were never added. One seeder line.

**Fix B — [E] 13D / [D] Art. 26 (د): the competent body need never be named.** عدم اختصاص must «تحدد
الجهة أو المسار الإداري المختص», but `Decision.referral_authority` is `nullable` and `refer_other_body`
sits outside `DecisionStructureRules::SUBSTANTIVE_OUTCOMES`, so it requires neither الوقائع nor السند
either. `DecisionStructureRules`' own comment claims referrals "carry their own structured fields
instead (… or Stage 50's `referral_authority`)" — a structure it does not enforce. Making it required
for the two referring outcomes is what makes that comment true.

**Deliberately NOT built here, and each needs a decision rather than a patch** — recorded in the
analysis, not silently skipped: the hardcoded approval path (Art. 92 + Appendix 58 require جهة الاعتماد
determined per-matter from the legal text; **four recorded answers exist and routing reads none of
them**, it reads `decision_grade`); Art. 38's absent code 04 and the قيد's timing (the user's own
2026-09-18 call); Appendix 67's adjourned meeting, which is structurally unrepresentable (no `ended_at`,
no adjourned status, and closing refuses unless every item is resolved); Appendix 46's immutable محضر
copy (`meeting_minutes.meeting_id` is `unique()`, so a second version is impossible by construction);
and Art. 67's leave gate (every `LEAV` request walks the full eleven-stage pipeline).

**Verification plan**: two new feature tests — R09/R10 can attach at `receive_and_register`; a
`no_jurisdiction` and a `refer_other_body` decision are each refused without a named body and accepted
with one — plus the full PHPUnit suite (baseline **682 tests / 4493 assertions**), Pint on every touched
file, and a `ScreenRolePermissionSeeder` reseed against the real MySQL/Homestead database. **No
migration** — `referral_authority` already exists and the grant is seeded data.

---

