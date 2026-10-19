# Agent handoff notes

Shared scratchpad between Codex and Claude Code for this repo. See the
"Cross-agent handoff" section in [AGENTS.md](AGENTS.md) for the rules:
newest entry on top, never edited; past 150 KB the oldest entries move
unedited to agent-notes-archive/. Durable facts go in AGENTS.md instead.

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
### 2026-10-19 14:20 EET — Claude — The legal member enters only a recommendation and جهة الاعتماد (complete)

Built per the plan below. Migration `add_recommendation_to_request_legal_reviews` is applied to the real MySQL, and the
columns were checked there. **Deviations:**
- `GET requests/{r}/legal-reviews` lost `legal_basis`, because its only reader was the deleted `LegalReviewCard.vue`;
- the bypass's Settings hint was reworded, since a review no longer takes a file off the pending list.

The Art. 105 suspension review rides the same endpoint, so it now records a توصية too (OPEN_ITEMS, with the guide).
Old rows keep their verdict, which the request page still shows.

Verified:
- `RequestLegalReviewTest` rewritten (RED 7/11, then GREEN); `ControlGateTest`'s three posts moved to the new shape;
- full suite 788 / 5011 + 23 / 165 green; Pint clean on the touched PHP;
- build passes (`dist` committed, production API URL in the bundle); parity 1842; `aislop ci` exit 0.

Not checked in a browser.

---
### 2026-10-19 13:30 EET — Claude — Implementation plan: the legal member enters only a recommendation and جهة الاعتماد

User: «القانوني enters his recommendation and approval destination only». Put to the user and answered (they approved
the plan):
- the legal member's form becomes a توصية (`approve`/`decline`) and جهة الاعتماد (`dean`/`minister`), with an
  optional note. Appendix 22's card and Art. 21's five verdicts leave the form;
- every recommendation returns the file to `ready`. «عدم موافقة» is advice and blocks nothing;
- the مقرر's results form seeds each request row's توصية and جهة الاعتماد from the latest legal review.

Plan:
- one migration adds `request_legal_reviews.recommendation`/`approving_authority` and makes `verdict` nullable.
  `permitsAgenda()` is true for any recommendation, and old rows keep their verdict rule;
- `StoreRequestLegalReviewRequest` and `RequestLegalReviewController::store()` change accordingly, and the store's
  bypass branch goes;
- the agenda item resource carries `legal_recommendation`. The agenda register and agenda ordering read the new
  columns;
- SPA: the wizard offers two recommendation choices with an authority select, `LegalReviewCard.vue` is deleted, and
  `MeetingWizard` seeds the results rows from the legal recommendation.

Kept: Settings' bypass, which still lets an unreviewed file onto the agenda, and the appeal legal review. Verify:
`RequestLegalReviewTest` rewritten (RED then GREEN), the other tests that post verdicts moved to the new shape, full
PHPUnit, Pint, migrate on the real DB, build (`dist` committed), parity, aislop ci.

---
### 2026-10-19 12:55 EET — Claude — Each «المهام المعلقة» category has a colour R08 picks in Settings (complete)

Built per the plan below; no migration. `SettingSeeder` was run on the real MySQL and wrote all 12 `task_color_*` rows,
checked with `Setting::taskColors()`. Only a `#rrggbb` hex is accepted (Arabic `value.regex` message), because the
page writes it into a CSS variable. The Settings card lists whichever `task_color_*` rows exist, so a new source needs
only a seeder line. Verified:
- the new `MyTasksTest` case went RED, then GREEN;
- full suite **789 / 5044 + 9 + 14** green; Pint clean on the touched PHP; build passes (`dist` committed, production API URL in the bundle);
- parity 1855; `aislop ci` exit 0.

Headless screenshots of My Tasks and Settings against a mocked API (no DB writes), ar light/dark and en dark: the
tints read in both themes, a late task keeps its red strip, there is no page overflow and there are no console errors.

---
### 2026-10-19 12:31 EET — Claude — Implementation plan: each «المهام المعلقة» category has a colour R08 picks in Settings

User: «My-tasks keep every task category background in a specific color that can be picked from the admin settings».
The user skipped the questions, so these are my calls:
- each of the 12 `PendingTaskCollector` sources gets a seeded setting `task_color_<code>` (a `#rrggbb` value,
  `firstOrCreate`, so a reseed never overwrites a picked colour). `Setting::valueRules()` takes only a hex for those
  keys;
- `collect()` adds `color` to each source in one query;
- `MyTasksView` tints every row with `color-mix()` into `--color-surface`, so the same hex works in both themes, and
  gives the row's start strip the full colour (overdue's danger strip still wins);
- `SettingsView` shows a colour picker per category and keeps those keys out of the generic table.

No migration. Verify: a new `MyTasksTest` case plus a Settings validation case (RED then GREEN), full PHPUnit, Pint,
build (`dist` committed), parity, aislop ci, `SettingSeeder` on the real DB.

---
### 2026-10-18 23:30 EET — Claude — The paper meeting, slice 2: the in-system sitting deleted, appeals back on the paper agenda (complete)

Built per the plan below. Migration `drop_the_in_system_sitting` is applied to the real MySQL; every dropped table and
column was checked empty first. `ScreenSeeder` and `WorkflowTransitionSeeder` were reseeded and checked by query:
- `receive_from_committee` holds R02 approve/defer/`reject_by_committee`, plus R03 cancel/`return_to_study` and R08
  `deadline_expired`;
- the three screens are gone, and `meeting_outputs` has route NULL.

`ScreenRolePermissionSeeder` was **not** re-run. Instead, `decisions` add/approve and `meeting_agenda` add were cleared
cell by cell.

Deviations and additions:
- roll call (`meeting_attendees.attended`, `PATCH …/attendees/{a}`) and `meetings.voting_rules_snapshot` went too:
  only the sitting wrote them;
- the inbox's `convene` task became **`record_results`** for the seated مقرر once the day has come;
- `SeatDutyGrant` lends only meetings_dashboard/meetings/meeting_agenda, and `final_approved` no longer prolongs a
  stand-in's grant;
- سجل المحاضر was kept, rewritten over meetings with a scanned محضر; سجل التنفيذ was removed (OPEN_ITEMS);
- **registers and Appendix 10's warnings keep their official numbers** (10–12 and 7–10), with gaps where items were
  removed. Both were numbered by position, so removing items shifted the rest. A test pins each;
- the closure checklist keeps Appendix 47's twelve questions, and #9 is always `not_applicable`;
- the time card's t7 ends at `results_recorded_at`, and t9 is null;
- the appeal dossier lost its memo and minutes sections, and `GET decisions/filters` lost `templates`.

Bug caught by a test agent, fixed here: my route cut ate a `*/`, which commented out both suspension routes. Route
URIs were diffed against HEAD afterwards, and only the intended ones are gone.

Verified:
- new appeal case in `PaperMeetingResultsTest` went RED, then GREEN;
- full suite **788 / 5038 + 14 + 9** (811 / 5203, was 974 / 6343). The −163 tests are the deleted sitting's test
  files and cases;
- Pint clean on every touched PHP file; build passes (`dist` committed, production API URL in the bundle);
- parity 1853; `aislop ci` exit 0 (98);
- headless walk on a scratch SQLite DB (`.env` untouched; API :8010, Vite :5175), en/ar × 375/1280 × dark/light:
  the appeal row shows «تظلم على: …» with قبول/رفض/تأجيل, has no authority select and requires its note, with no page
  overflow and no console errors. A real submit concluded the meeting, decided the appeal `appeal_reject`
  (`committee_presentation`), and sent the request to `approval_by_authority`.

Test rewiring was split across six agents, all briefed the same way; their reports were checked against the full run.

---
### 2026-10-18 19:55 EET — Claude — Implementation plan: the paper meeting, slice 2 (delete the in-system sitting; appeals back on the paper agenda)

Two questions were put to the user and answered. **Appeals go back on the paper agenda**: the مقرر's results form gets
appeal rows (قبول / رفض / تأجيل, with a required note and no جهة الاعتماد). Accept or reject records the appeal's
decision and moves it to `committee_presentation`, as the old `recordAppealDecision()` did. Defer leaves it at
`legal_review`, ready to be nominated again. **The old tables are dropped** in one migration:
- tables: `votes`, `meeting_discussion_notes`, `meeting_minutes`, `meeting_minute_signatures`, `presentation_memos`,
  `conflict_of_interest_declarations`;
- columns: `meetings.convened_*` and `readiness_override_reason`, `meeting_requests.study_sequence*`, the `requests`
  execution and soundness columns, and `attachments.execution_evidence_type`.

`decisions` keeps every column, because the register, the deferred register and the indicators read them.

What gets deleted:
- **Backend:**
  - routes and controllers: readiness/convene, item state, study sequence, discussion notes, item context and
    attachment, item duties, minutes, outputs page and execute, votes, `record()`, decision draft, conflicts, the
    pending-votes list and execution soundness, plus the close branch of `PUT meetings/{m}`;
  - services: the old services (DecisionEligibility, DecisionTally, structure/reasoning rules, draft composer, study
    sequence, readiness, minutes compiler/quality, memo compiler, output service, `RequestExecutionService`,
    `ExecutionSoundnessService`);
  - models, resources and Form Requests;
  - the two notifications and their events;
  - `MinutesRegister` and `ExecutionRegister`.
- **Seeders:**
  - `meeting_readiness`, `meeting_live` and `meeting_minutes` leave `ScreenSeeder`;
  - `meeting_outputs` keeps its grants but loses its page (route null, like the approval queues);
  - at `receive_from_committee`, the conditional / legal opinion / refer / no-jurisdiction rows are deleted, and
    approve, defer and `reject_by_committee` move to R02;
  - `DecisionController::ACTIONS` shrinks to those three.
- **SPA:** the four old views, the agenda-item wizard and panels, the study sequence, the soundness and execution forms,
  readiness/execution/decision-structure libs, the old wizard acts, the decisions «pending» tab and their locale keys.

Kept on purpose: agenda adoption, the manual close (its checklist loses the minutes and execution checks), the
candidate list's `return_to_study`, the decision templates and the old request statuses (history).

Verify:
- new appeal cases in `PaperMeetingResultsTest` (RED then GREEN);
- old-only test files deleted, and mixed ones trimmed or moved onto `HoldsPaperMeetings`;
- full PHPUnit, Pint, migrate on the real MySQL, reseed `ScreenSeeder`/`WorkflowTransitionSeeder` with targeted
  deletes;
- build (`dist` committed), parity, aislop ci, headless check of the results form with an appeal row.

The guide and test plans stay in OPEN_ITEMS. (Clock note: the machine reads 19:55, behind the entry below.)

---
### 2026-10-18 20:00 EET — Claude — The paper meeting, slice 1 (complete)

Built per the plan below. A CLI session wrote the results controller, Form Requests and duty/visibility code mid-way;
it was reviewed and kept. Two corrections:
- the authority-stage refusal lives in `blockReason()` + `ApprovalController`, not `controlGateRefusal()`, because the
  meeting-page decision reuses that gate;
- the inbox drops only the `committee-head` and `final` queues (the قيد queue stays).

**Deviations:**
- no `meetings,view` seeder change, because it is already `*`; the seat gate keeps it for R06/R07;
- a committee `reject` via the old `record()` now names an authority too;
- the minister's قرار is named «قرار الوزير - <reference>».

**The real MySQL had been wiped** before this work (every Homestead database empty, MySQL up since about 16:10 on
10-17, so likely a VM rebuild). The user ran `migrate --seed`. The migration is applied and the transitions are checked
by query: reject rows at both authority stages, no execution self-loop.

Verified:
- `PaperMeetingResultsTest` (5) and `AuthorityDecisionTest` (8) went RED, then GREEN;
- 21 older tests were updated in place with comments;
- full suite 974 / 6343, Pint clean, build (`dist` committed, production API URL in the bundle), parity 2246,
  `aislop ci` 97.

Headless walk on a scratch SQLite DB (env override, `.env` untouched; `FRONTEND_URL` override for CORS), 39 checks, no
console errors:
- print shows the agenda with blanks and hides the duty card. The walk found the card printing (two-root component),
  which is fixed;
- the results form at ar/en × 1280/375 × light/dark, with the authority pre-selected by grade;
- the dean decides via the inbox link;
- مكتب العميد declines with the قرار at 375;
- both archives close the file.

---
### 2026-10-18 13:55 EET — Claude — Implementation plan: the paper meeting, slice 1 (results entry, authority on the meeting page)

User: the committee meets on paper, and this replaces the in-system sitting. Put to the user and answered:
- the مقرر prints the agenda;
- after the meeting, the مقرر enters each item's توصية (موافقة / عدم موافقة / تأجيل), note and جهة الاعتماد, attaches
  the محضر, and submits once;
- the committee only recommends, and العميد or مكتب العميد (R06) decides every non-deferred file from the meeting's
  page. A minister file needs the ministry's قرار attached;
- the employee, their manager, HR and the مقرر hear the result;
- straight to archiving, with no execution leg, and the file closes itself once both archives are recorded;
- appeals leave agendas.

Slice 1 plan:
- migration: `meetings.minutes_file_*` and `results_recorded_*`;
- `POST meetings/{m}/results` (duty `record_results`);
- `POST meetings/{m}/agenda/{item}/authority-decision` (duty `authority_decision`), outside `meeting.member`;
- `reject_by_committee` → the authority stages, new R07/R06 `reject` rows, and the R07 execution self-loop deleted;
- `final_approved` is archivable, and `archive()` auto-closes;
- R02 is copied on the final notice;
- `convene`/`close` are no longer offered;
- SPA: print agenda, results form, authority form, and the decided list on the meeting page.

Slice 2 (next session) deletes the old sitting. Verify: new `PaperMeetingResultsTest`/`AuthorityDecisionTest` (RED
then GREEN), full PHPUnit, Pint, migrate, reseed transitions, build (`dist` committed), parity, aislop ci, headless walk.

---
### 2026-10-17 15:40 EET — Claude — جهة الاعتماد is العميد or الوزير, picked per request by the chair (complete)

Built per the plan below. Migration `requests.approving_authority` is applied to the real MySQL.

On the real DB, RoleSeeder, WorkflowStageSeeder, WorkflowTransitionSeeder and ScreenSeeder were reseeded and checked by
query:
- stage 10's approve and cancel rows are R07, and R05's are gone;
- R06 is «الوزير», with one holder (`r06.ministry@`).

**ScreenRolePermissionSeeder was NOT re-run**, because it resets every customised grant. Instead,
`admin_manager_approval` was patched cell by cell (R05 off, R07 view and approve). No live file sat at stages 10–11.

**Deviations:**
- The validator's `approval_by_authority` required rule is gone, since nothing resolves it by name now.
  `local_governance_ministry` joined `WorkflowStage::ANCHORS`, and reachability counts the committee → ministry edge the
  code takes.
- A request with no authority, decided before this change, keeps the dean's row.

Verified:
- the new `ApprovingAuthorityTest` (6) went RED, then GREEN; ten older tests were updated in place;
- full suite 963 / 6278 green, Pint clean, parity 2220, `aislop ci` exit 0 (97);
- build passes (`dist` committed, production API URL in the bundle).

Headless check against this branch's API, ar/en at 1280/375:
- on a grade-12 file the picker pre-selects الوزير, and the slip names the authority. There was no page overflow and no
  console error;
- a real submit with العميد picked moved the file to `approval_by_authority`;
- the fixtures, notifications and token were removed, and every count matched its baseline.

Found while there, not fixed: the template picker makes the decision form scroll sideways at 375px (OPEN_ITEMS).

---
### 2026-10-17 13:57 EET — Claude — Implementation plan: جهة الاعتماد is العميد or الوزير, picked per request by the chair

User: «جهات الاعتماد هو العميد و الوزير». The minister has one account, which مدير مكتب العميد operates. Put to the
user and answered:
- each request goes to one authority, in a single step;
- the chair picks the authority when recording the item's result, following the members' recommendation;
- the grade threshold only pre-selects the minister;
- the minister is a sole post (R06, named by R08);
- العميد still gives the execution step after the Art. 103 checklist.

R05 leaves the chain. The plan:
- a migration adds `requests.approving_authority` (`dean`|`minister`), required by `DecisionController::record()` on
  approve and conditional outcomes;
- `WorkflowService` sends the committee's approve to `local_governance_ministry` for a minister file. A dean file
  goes to `approval_by_authority`, whose approve row becomes R07 → `final_approval_archiving` + `final_approved`;
- `bypassesMinistryApproval()` is deleted;
- `SOLE_ROLES` gains `minister`, and the decision form gets an authority picker.

Verify: a new `ApprovingAuthorityTest` (RED then GREEN), full PHPUnit, Pint, migrate, the reseeds, build (`dist`
committed), parity and aislop ci.

---
### 2026-10-16 21:40 EET — Claude — The مقرر invites extra members, each with a صفة (complete)

Built per the plan below. Migration `meeting_attendees.guest_title` is applied to the real MySQL. **Deviation:**
`MeetingVisibility::apply()` admits guests only when a caller opts in (`withGuests`, routes `meeting.member:guests`:
index, show, duties, respond). `decisions,view` is `'*'` and rides plain `meeting.member`, so widening the scope for
everyone would have let a guest read decision drafts. A new `GuestInviteForm.vue` serves the wizard (step 3) and the
meeting page (modal). The agenda card is hidden when the payload has no agenda. Verified: new `MeetingGuestTest` (4,
RED then GREEN), full suite 957 green, Pint clean, build passes (`dist` committed, production API URL in the bundle),
parity 2214, `aislop ci` exit 0 (97). Not checked in a browser.

---
### 2026-10-16 21:00 EET — Claude — Implementation plan: the مقرر invites extra members, each with a صفة

User: «the مقرر can add additional unrequired members to the meeting but he needs to add their صفة». Put to the user
and answered: an extra member only answers the date, and only the five seats count toward Settings' acceptances.
This deliberately reverses Stage 102's «no ad-hoc attendees» for the مقرر. Plan:
- migration `meeting_attendees.guest_title` (nullable); a non-null value marks an extra member;
- `POST meetings` takes `guests[]` (`user_id`, `guest_title`), and `POST|DELETE meetings/{m}/guests` add or remove one
  while the meeting is `pending_confirmation`, for the مقرر only. An added guest gets the invitation;
- `respond()` and readiness's `pending_invitations` count seat rows only. Roll call refuses a guest, so a guest never
  signs, votes or counts toward the quorum. The minutes list guests apart from present/absent;
- `MeetingVisibility` lets a guest open the meetings they are invited to, the seat gate keeps `meetings,view` while a
  guest invitation is live, and `show()` withholds the agenda from a non-member;
- SPA: a guest step in the scheduling wizard, and a guest list with add/remove on the meeting page.

Verify: new `MeetingGuestTest` (RED then GREEN), full PHPUnit, Pint, migrate on MySQL, build (`dist` committed),
parity, aislop ci.

---
### 2026-10-16 20:55 EET — Claude — A Settings number sets how many members must accept a meeting's date (complete)

Built per the plan below; no migration. `Setting::valueRules()` gives `meeting_acceptances_required` its 2–5 rule in
both Settings Form Requests, while every other key stays free text. `SettingsView` keeps both typed settings out of the
generic table and saves them through one `saveOwn()`. The «every member» wording in `meetings.rsvp` and the respond
hints now reads «the required number». `CommitteeSinglePathTest`'s first test was rewritten for the new rule, and its
decline and notification tests set the threshold to 5. `SettingSeeder` was run on the real DB (`3`). Verified: the new
tests went RED, then GREEN; full suite 953 green; Pint clean; build passes (`dist` committed, production API URL in the
bundle); parity 2204; `aislop ci` exit 0. Not checked in a browser. The guide is not updated (OPEN_ITEMS).

---
### 2026-10-16 20:30 EET — Claude — Implementation plan: a Settings number sets how many members must accept a meeting's date

User: «a new input field that sets how many members need to accept the meeting in order for the date to be set (default
3)». Today a meeting turns `scheduled` only when all five attendees accept. Plan: a seeded setting
`meeting_acceptances_required` (`'3'`, `firstOrCreate`) and `Setting::meetingAcceptancesRequired()`.
`MeetingController::respond()` schedules the meeting once that many attendees are `confirmed`, and the مقرر's automatic
yes counts. The Settings Form Requests accept only 2–5 for that key, since 1 would mean the مقرر alone. `SettingsView`
gets a number card above the table. Unchanged: a date change resets every answer, and answers close once the meeting is
scheduled. Verify: `CommitteeSinglePathTest` (RED then GREEN), full PHPUnit, Pint, build (`dist` committed), parity,
aislop ci, seed the setting on the real DB.

---
### 2026-10-16 19:55 EET — Claude — A Settings checkbox lets the مقرر skip the legal review (complete)

Built per the plan below, with one addition the user chose mid-build: a blocking verdict used to move the file to
`completion_required`, off the pending list, so ignoring the verdict at the agenda alone was not enough. While the box
is ticked, `RequestLegalReviewController::store()` sends every verdict through `pass_legal_review`, back to `ready`. The
verdict and note are still recorded. `SettingsView` shows the checkbox in its own card and keeps that key out of the
generic table. No migration. `SettingSeeder` was run on the real MySQL (`legal_review_bypass` = `0`).

Verified:
- the new `RequestLegalReviewTest` case went RED, then GREEN;
- full suite 952 green, Pint clean, build passes (`dist` committed, production API URL in the bundle);
- parity 2202, `aislop ci` exit 0.

Not checked in a browser. The guide is not updated (OPEN_ITEMS).

---
### 2026-10-16 19:30 EET — Claude — Implementation plan: a Settings checkbox lets the مقرر skip the legal review

User: a checkbox in Settings lets the مقرر bypass the legal member's check, so a pending request goes straight onto the
agenda. Put to the user and answered: R08 ticks it on the Settings screen, and while it is ticked even a blocking
verdict (e.g. «غير سليم») does not stop the agenda. Plan:
- a seeded setting `legal_review_bypass` (off; `firstOrCreate`) and `Setting::enabled()`;
- `MeetingController::addAgendaItem()` skips the legal gate while it is on;
- `MeetingReadinessService` drops the `missing_legal_review` exception while it is on;
- `SettingsView` gets a checkbox card above the table.

Unchanged: the document-conflict gate, appeals, and sending a file to legal review. Verify: new `RequestLegalReviewTest`
cases (RED then GREEN), full PHPUnit, Pint, build (`dist` committed), parity, aislop ci, seed the setting on the real DB.

---
### 2026-10-16 18:52 EET — Claude — Rolled back: المقرر adds a request to the agenda without the legal member's approval

The user asked to roll back the change in the two entries below, so `4fbec9b` is reverted. The agenda again refuses a
request with no legal review that permits presentation. The readiness exception is `missing_legal_review` again, and
the guide, its PDF and `dist` are back to their `e0dd803` state. The code tree matches `e0dd803` exactly; only this
file differs. The two entries below are kept as history and no longer describe the system.

---
### 2026-10-14 12:35 EET — Claude — المقرر adds a request to the agenda without the legal member's approval (complete)

Built per the plan below; no migration and no seeder. `addAgendaItem()` now refuses only when the latest legal review
exists and blocks (`permitsAgenda() === false`). The readiness exception `missing_legal_review` became
`legal_review_blocks`, which catches a blocking verdict recorded after the item was scheduled. Its locale text changed
in both files. `USER_GUIDE.ar.md` (§4.9, §5.3, §5.4) calls the review optional, and the PDF was rebuilt. Verified: the
two rewritten `RequestLegalReviewTest` cases went RED then GREEN, full suite 951 / 6177, Pint clean, build passes
(`dist` committed, production API URL in the bundle), parity 2200, `aislop ci` exit 0 (97). Not checked in a browser.

---
### 2026-10-14 12:09 EET — Claude — Implementation plan: المقرر adds a request to the agenda without the legal member's approval

User: «المقرر should be able to add the request without the approval of العضو القانوني». Today
`MeetingController::addAgendaItem()` refuses a pending-list request whose latest legal review does not permit
presentation, including one never reviewed. Plan: a request with **no** review is admitted; a recorded blocking verdict
(the latest review is not `sound_ready`/`present_with_note`) still refuses, since that is an objection rather than a
missing approval. `MeetingReadinessService`'s `missing_legal_review` exception becomes `legal_review_blocks`, flagging
only items whose latest review blocks. Sending a file to the legal member stays available, just optional. Verify:
`RequestLegalReviewTest` cases updated (RED then GREEN), full PHPUnit, Pint, build (`dist` committed, locale text
changed), parity, aislop ci.

---
### 2026-10-12 11:35 EET — Claude — The workflow designer is off by default (complete)

Built per the plan below; no migration, seeder or locale change. **Turning it on:** `WORKFLOW_DESIGNER_ENABLED=true` in
`.env`, then clear the config cache (on cPanel, from the maintenance console). `phpunit.xml` sets it true, so every
designer test runs unchanged; `WorkflowDesignerDisabledTest` turns it off. **Ceiling:** turning it off does not move a
type already bound to a custom workflow back to Standard. The real DB has none (Standard v1 only, checked by query).
Verified: new test RED then GREEN, full suite 951 / 6176, Pint clean, build passes (`dist` committed, production API URL
in the bundle), parity 2200, `aislop ci` exit 0 (97). Not checked in a browser.

---
### 2026-10-12 11:00 EET — Claude — Implementation plan: the workflow designer is off by default

User: keep the visual designer, but as an option that is disabled by default, so the system runs as it did before it
(the real DB is on Standard v1 only, so no live file changes). Put to the user and answered: an `.env` flag
(`WORKFLOW_DESIGNER_ENABLED`, read as `config('app.workflow_designer')`, default false), and «off» hides everything.
`User::screenPermissions()` clears `workflow_designer` (screen, sidebar, router guard, API 403), `GET
requests/{r}/workflow` 404s and the request payload's flag hides «عرض مسار العمل», and Request Types gets an empty
`workflows` list while `StoreRequestTypeRequest` drops `workflow_id`. Tables and code stay. `phpunit.xml` turns the
flag on so the designer tests run unchanged. Verify: new `WorkflowDesignerDisabledTest` (RED then GREEN), full PHPUnit,
Pint, build (`dist` committed), parity, aislop ci.

---
### 2026-10-11 14:50 EET — Claude — A reopened appeal can be nominated again (complete)

Built per the plan below; no migration and no frontend change. `awaitsNomination()` is read in four places; the fourth,
missed by the plan, is the nominator's branch of `GET appeals/{a}/acts`. **Gotcha:** `latestOfMany()` joins
`meeting_requests` to itself, so a column-limited eager load must qualify its columns. `AppealsRegister`'s
`committeeAgendaItem:id,appeal_id` was a 500 («ambiguous column name») until it was qualified. **Behaviour change:**
`POST meetings/{m}/agenda` now refuses a second nomination of an appeal in one lap. Before, it took one silently.
Verified: the new `AppealActsTest` case (RED then GREEN), full suite 957 green, Pint clean, `aislop ci` exit 0. The same
session marked five OPEN_ITEMS entries resolved after finding them already closed on the real DB or in code.

---
### 2026-10-11 13:59 EET — Claude — Implementation plan: a reopened appeal can be nominated again

Closes the OPEN_ITEMS entry «Appeal `reopen` leaves the old agenda item». A reopened appeal still has its old agenda
item, so `AppealActs` and the inbox never offer `nominate` again. `POST meetings/{m}/agenda` would accept it, but the
plain `hasOne` might then hand execution the old decision. Fix: `Appeal::committeeAgendaItem()` becomes
`latestOfMany()`, and a new `Appeal::awaitsNomination()` (no item, or only items older than `reopened_at`) is read by
the offer, the inbox and the endpoint. The endpoint then also refuses a second nomination of an appeal that was never
reopened. The old item and its decision stay as history. Verify: a new `AppealActsTest` case (RED then GREEN), full
PHPUnit, Pint, aislop ci.

---
### 2026-10-11 12:50 EET — Claude — After-decision writes check RequestVisibility (complete)

Built per the plan below; no migration and no frontend change. `CheckRequestVisibility` (alias `request.visible`)
reads `{requestRecord}`, or `{agendaItem}`'s request on the meeting-scoped execute route; an appeal item has no
request and passes. It sits on 13 routes, the 12 named in the plan plus `approval-referrals/{referral}/result`.
**Behaviour change:** R02 can no longer reopen a file cancelled before the قيد, which it could not open either.
`GateAuthorshipTest`'s reopen fixture was a `completed_closed` file with no `closed_at`, a state only
`RequestClosureService::close()` can write and always stamps, so it gained `closed_at`. Verified: new
`AfterDecisionVisibilityTest` (RED then GREEN), full suite 957 green, Pint clean, `aislop ci` exit 0.

---
### 2026-10-11 12:23 EET — Claude — Implementation plan: after-decision writes check RequestVisibility

Closes the OPEN_ITEMS entry «Eleven request endpoints skip `RequestVisibility`». A grant holder can reach these endpoints
by request id on a file they cannot open: close, both archives, execution soundness, approval return record/resolve,
referral record/result, suspend/lift, reopen, plus `notices/issue` from the same block. Fix: a new `request.visible`
route middleware shaped like `meeting.member`. It goes after `screen.permission` on each of those routes, so the
403-then-404 order matches the existing inline checks. `MeetingOutputsController::execute()` has no `{requestRecord}`,
so it checks the agenda item's request inline. `reopen()`'s docblock is outdated: the closer clause already shows R02
every reopenable file that has a reference or closure. Verify: new feature test (RED then GREEN), full PHPUnit, Pint,
aislop ci.

---
### 2026-10-11 09:05 EET — Claude — Workflow designer slice 5: final-review fixes

Fixed with tests: C1 (a system hop's rule, `submit` at intake and `forward` at the manager, may not require a comment,
or intake would 500), I1 (every `DecisionController::ACTIONS` outcome must exist at the committee stage, since the vote
tally picks it), M2 (the Request Types refusal reads correctly for a new type or a move), M3 (AGENTS.md wording) and T4
(the re-key test asserts its index instead of skipping). M1, M4 and M5 are ceilings recorded in OPEN_ITEMS.

---
### 2026-10-11 08:46 EET — Claude — Workflow designer slice 5: built-ins editable and removable (complete)

Built per the plan below, on `feat/workflow-builtins`. No migration, seeder, route or screen.

What shipped:
- on a draft, every built-in step and outcome is edited through the slice 3 forms. Every one is deletable except the
  three anchors (`WorkflowStage::ANCHORS`), which show «مرحلة ثابتة» instead of a delete button;
- a rule left out of the PUT is deleted, and the actor checks apply to built-in rules too;
- three new validator errors: an unreachable anchor, `required_rule_missing` (`submit` / `forward` / `approve`) and
  `status_unmet`, a (stage, status) walk;
- a Request Types save can't re-key a document named by a published checklist (`StoreRequestTypeRequest::after()`);
- every delete in the designer opens an `AppModal` confirmation (built-in wording for a built-in rule), and a server
  refusal shows inside it.

**Deviations from the spec** (the plan's list):
1. Built-ins keep their relative order.
2. Built-in stages take no checklist, and built-in rules keep their label and action.
3. `required_status_id` is carried through the payload but not offered in the outcome form.
4. E2, E4, E5 and E10 are not new validator entries; each is already covered.
5. E1 still covers custom stages and anchors only.
6. The status walk models only statuses the rules stamp (`ponytail:`; see OPEN_ITEMS).
7. The Request Types guard reads published versions only (`ponytail:`).

**Rulings made during execution:**
- Task 2 dropped the plan's assertion that `final_approval_archiving` goes unreachable once `register` is deleted:
  Standard's R08 `deadline_expired` exceptions still reach it, and reachability rightly counts exception routes.
- **Writer bug, found by Task 3's done-when test.** `WorkflowDraftWriter::save()` deleted rules into a removed stage
  (its `to_stage_id` FK cascades) *before* `writeRules()` repointed them. So a rule redirected away from a step removed
  in the same save was lost (`submit` → HR while the manager step goes). The fix (in 4ef5f68) moves the stage-removal
  deletes after `writeRules()`; removed stages stay parked at `order_no + 1000` while the rest renumber.
- Task 3's test is a pinning test that could not go red first once Tasks 1–2 existed.
- Task 5: a built-in rule with no role and no flag would open the outcome form with an empty role select. That does
  not hold in practice: every seeded rule names a role, the manager or the filer, and the writer refuses such a rule.
- The deferred minors are in the ledger, waiting for the final review.

Verified:
- full suite **945 tests / 6142 assertions** in one process (slice 4: 909 + 14 + 9);
- Pint clean on the 10 touched PHP files; node tests 14/14; parity 2200; `aislop ci` 97 (exit 0);
- `php artisan migrate` on the real MySQL: nothing to migrate. No seeder was run;
- build (`dist` committed, production API URL in the bundle);
- `check-layout.mjs` (450 views, Homestead) shows only the known `/decisions` 1024px overflow and the R08
  `/requests/create` gap.

Headless walk against this branch's API on a **scratch SQLite database** (`.env` restored byte for byte afterwards; the
first token minted was id 1, so nothing reached MySQL), as R08 on `/workflows`, ar 1280 light:
- clone Standard, then delete «مراجعة الطلب من المدير المباشر». The dialog shows the refusal naming intake's rule into it;
- edit «تقديم الطلب»: the fixed-name hint shows and there are no name inputs. Point it at «الاستلام والتسجيل»: the
  report gives `status_unmet` there. Set «موجّه إلى الموارد البشرية»: clean;
- delete the manager step, stages 6–8 and `administrative_routing`, confirming each: the report stays clean;
- intake has no delete button and shows the anchor hint;
- deleting the built-in «إلغاء الطلب» at HR uses the built-in wording;
- publish: v1 retired;
- r01.employee files an ALLW request (API): it lands on `receive_and_register` / `routed_to_hr`, on v2;
- on a second draft, with the delete-stage dialog open, ar/en × 1280/375 × light/dark: no page overflow, and the dialog
  fits (357/357 at 375px). The only console error is the expected 422 behind the refusal. The draft was discarded.

---
### 2026-10-11 07:58 EET — Claude — Implementation plan: workflow designer slice 5 — built-ins editable and removable

On a draft, every built-in step can be edited (names, role, target) and every built-in outcome too (destination,
actor, stamped status, flags). Any of them can be deleted, except the three anchors (`receive_from_municipality`,
`receive_from_committee`, `final_approval_archiving`).

What stays locked:
- built-ins keep their relative order (`order_no` is read by the outputs screen, appeal redo and the progress bar);
- a built-in stage takes no checklist;
- a built-in rule keeps its from-stage, action and label;
- the actor checks now apply to every rule.

The validator gains:
- anchor reachability;
- `required_rule_missing` (spec E8/E9/E11: one normal `submit` at intake, `forward` at the manager, `approve` at
  approval_by_authority);
- `status_unmet`, a (stage, status) walk that catches a file arriving at HR without `routed_to_hr`.

Request Types refuses a save that re-keys a document a published checklist names. The SPA unlocks the forms and
confirms every delete.

No migration. Verify:
- new cases in `WorkflowDesignerTest`, `WorkflowValidatorTest` and `RequestTypeCrudTest`, including the spec's
  done-when: without `direct_manager_review`, intake and the dean both land on HR;
- full PHPUnit, Pint, build (`dist` committed), parity, aislop ci, and a headless walk on a scratch SQLite DB.

---
### 2026-10-10 22:32 EET — Claude — Workflow designer slice 4: step checklists, gate and wizard (complete)

Built per the plan below, on `feat/workflow-checklists`. One migration (`workflow_stages.checklist`,
`workflow_transitions.requires_checks`, `request_step_reviews`) is applied to the real MySQL, and
`request_step_reviews` exists there. No seeder was run.

What shipped:
- a custom step's checklist (yes/no with its blocking answer, attachment review, note, attestation), edited in the
  stage modal (now `wide`);
- outcomes marked «لا تُتخذ إلا بعد استكمال قائمة التحقق»;
- the gate (`StepChecklistService`, read first by `controlGateRefusal()`) and `PATCH requests/{r}/step-review`;
- E6 and W2 in the draft report;
- `StepChecklistPanel` as a decision-wizard check;
- «N بنود تحقق» on the canvas node.

**Deviations from the spec** (the plan's list):
- E12 is a 422 at PUT time, not a validator error.
- Item keys are server-minted (`c` + 10 characters) and stay stable across saves.
- Checklists and `requires_checks` exist only on custom steps and outcomes.
- The panel renders its own attachment rows instead of mounting `IntakeGatePanel`.
- `actorIsAnInterestedParty` stays private.
- A checked outcome gets its own refusal, `cannotCompleteOwnStep()`.
- The interested-party refusal is not widened to every custom outcome. That question is still the user's.
- Earlier laps' cards are kept but not shown.

**Rulings made during execution:**
- Deviation 7 stands.
- The plan's fixture for the attachment refusal test was reordered (answers first, then the files are deleted). The
  assertion is unchanged.
- `record()` replaces the whole answer map rather than merging. The panel always sends every answered item, and the
  docblock's «save part of it and come back» needs rewording.
- Each task's deferred minors are in the ledger, waiting for the final review.

Verified:
- full suite 909 + 14 + 9 (20 more than slice 3), Pint clean on every touched PHP file, node tests 14/14;
- build (`dist` committed, production API URL in the bundle), parity 2194, `aislop ci` 97 (exit 0);
- `check-layout.mjs` (450 views) shows only the known `/decisions` 1024px overflow and the R08 `/requests/create` gap.

Headless walk against this branch's API on a **scratch SQLite database** (checked by query: the server's token landed
there; MySQL's tokens, requests, versions and audit counts were unchanged afterwards):
- as R08, clone Standard and add «تدقيق مالي» (R05) after the manager, with three items: a yes/no that blocks on «لا»,
  a review of «طلب الموظف» (the one document every type lists), and an attestation;
- point the manager's «موافقة وإحالة» at the step, then add «اعتماد التدقيق» → «الاستلام والتسجيل», R05, checked;
- remove the items: E6, and publish is disabled. Re-add them: clean. Publish: v1 retired;
- r01.employee files an ALLW request (API); r02.reviewer forwards it in the wizard, and it lands on «تدقيق مالي»;
- as r05.manager, the wizard has a Checks step. The verdict radios stay disabled until the preview opens, and Escape
  closes the preview but leaves the wizard open. Saving «لا» gives the refusal, and Choose shows «اعتماد التدقيق» under
  «غير متاح بعد». Saving «نعم» passes, and the outcome moves the file to «الاستلام والتسجيل»;
- ar/en × 1280/375 × light/dark: no page overflow, no console errors, and the node shows its check count.

**Found in the walk, not fixed here:** at 375px the stage modal scrolls sideways *inside itself* (1342px in Arabic,
1914px in English) once a step has an attachment item. The checklist fieldset's default `min-inline-size: min-content`
meets the 84-option document select. See OPEN_ITEMS; `dist` must be rebuilt after the fix.

Also opened in OPEN_ITEMS:
- a custom step hides the file from the forwarding manager (not a slice 4 regression);
- items can't be reordered;
- earlier laps' cards are not shown.

The interested-parties item is resolved for checked outcomes.

**Final-review fixes**, each backend one with a test that failed first:
- **I1:** a reopen onto the step the file already sits on is now a new arrival (`StepChecklistService::arrival()` counts
  the four `reopenAtStage()` actions), so the previous lap's card no longer clears the gate.
- **I2:** `RequestStepReview` is audited, labelled «بطاقة التحقق في المرحلة».
- **I3:** the stage modal no longer scrolls sideways on a phone: 357/357 at 375px in ar and en, on scratch SQLite. The
  fieldset alone was not enough; the list item and the labels also needed `min-inline-size: 0`.
- **I4:** a new validator error, `checklist_no_way_out`, for a checklist step with no unchecked outcome that leaves it
  (a hold that stays on the step without ending the file does not count). `WorkflowValidatorTest`'s E6 fixture gained an
  unchecked return.
- **Minors:** `record()`'s docblock says the card is saved whole; a docblock moved back onto `payload()`; «N بنود تحقق»
  is pluralised; the unused `requiresChecksHint` is gone; choosing the filer clears `requires_checks`; the panel's verdict
  is one `role="status"` element; `gates()` says why it skips the type-specific override.
- **I5** (a Request Types edit can re-key a document a checklist names) is deferred to OPEN_ITEMS.

---
### 2026-10-10 21:12 EET — Claude — Implementation plan: workflow designer slice 4 — step checklists, gate and wizard

A custom step gets a checklist: yes/no (with the answer that blocks), attachment review (one Appendix 57 document,
judged per file after preview), note and attestation. Its outcomes can be marked `requires_checks`. One migration adds
`workflow_stages.checklist`, `workflow_transitions.requires_checks` and `request_step_reviews`, which holds one card
per request per arrival at the step. A return that re-arrives starts a fresh card; a self-loop does not.

`StepChecklistService` is read first by `RequestController::controlGateRefusal()`, so the endpoint, the hidden button
and `blocked_transitions` agree. `PATCH requests/{r}/step-review` records the card. It answers 403 unless the actor is
offered a checked outcome at that step, and `actorMayUse()` keeps the filer and صاحب العلاقة off every
`requires_checks` rule. The validator adds E6 (a checked outcome on a step with no checklist) and W2 (an attachment
item naming a document that a bound type lacks). A malformed item is a 422 at PUT time.

SPA: `ChecklistEditor` in the stage modal, a "waits on the checklist" box on the outcome modal, and
`StepChecklistPanel` as a decision-wizard check. Verify: new `StepChecklistGateTest`, cases in
`WorkflowDesignerTest`/`WorkflowValidatorTest`/`WorkflowViewTest`, node tests, full PHPUnit, Pint, migrate on MySQL,
build (`dist` committed), parity, aislop ci, and a headless walk on a scratch SQLite DB.

---
### 2026-10-10 20:27 EET — Claude — Workflow designer slice 3: draft editing and publish (complete)

Built per the plan below; one migration (`workflow_transitions.label_ar/label_en`, `request_types.workflow_id`, backfilled
to Standard), applied to the real MySQL (0 of 12 types without a workflow). **Deviations from the spec:**
- no `kind` or `position` columns: `custom_` on the code or action marks a custom row, and a step's place is its
  `order_no`;
- no Pinia store: each modal submit PUTs the whole draft;
- PUT upserts by code and id instead of delete-and-reinsert, so ids and type-specific rows survive;
- a non-draft write is a 422 on `version`, not a 403;
- validator errors cover only what this slice's locks leave breakable: unreachable custom step, stranded step, committee
  reached unregistered.

**Gotcha:** the writer renumbers with query updates; after the `+1000` park, `$model->update()` treats an unmoved stage as
clean and leaves it parked. **Gotcha:** `php -S` with Laravel's `server.php` must be started from `public/`.

Verified:
- full suite 889 + 14 + 9 (22 new tests), Pint clean, node tests 14/14;
- build (`dist` committed, production API URL in the bundle), parity 2165, `aislop ci` 97;
- `check-layout.mjs` (Homestead and main's API) shows only the known `/decisions` 1024px overflow and the R08
  `/requests/create` gap.

Headless walk against this branch's API on a **scratch SQLite database**, so the shared MySQL was untouched:
- clone Standard, then add «تدقيق مالي» (R05) after the manager: the report says unreachable;
- point the manager's «موافقة وإحالة» at it: stranded;
- add «اعتماد التدقيق» to «الاستلام والتسجيل»: clean, with one no-holder warning;
- publish: v1 retired;
- ar/en × 1280/375 × light/dark: the custom node is tinted in both themes, the Arabic label shows in English, there is
  no overflow and there are no console errors;
- a second draft was opened on a 375px phone in English dark mode, then discarded;
- Request Types offers the workflow select.

No tokens were left on the real DB. Open items: interested parties on custom outcomes (slice 4), no layout persistence,
and built-ins locked until slice 5. Four slice 1 items were resolved.

**Final-review fixes**, each with a test that failed first:
- **R01 refused as a custom outcome's actor.** Every account holds it, so it would show the file to everyone and let
  anyone move it. The outcome form no longer offers it.
- **Save and publish no longer race.** The writer re-checks the draft status under its row lock. Publish locks the
  version row, so it waits out a save and publishes what it checked. Discard re-checks under the workflow and version
  locks, so it can never delete a just-published version.
- **Deleting a step a built-in rule leads into** keeps that rule in the payload. The server names the stage whose rule
  to redirect first, and the refusal shows in its own alert instead of blanking the designer (browser-checked).

The review's minors are in OPEN_ITEMS («review leftovers»).

---
### 2026-10-10 19:35 EET — Claude — Implementation plan: workflow designer slice 3 — draft editing and publish

The designer screen gains drafts. Clone a version (`POST workflow-versions/{v}/clone`) or start a new named workflow
from one (`POST workflows`), then edit the draft:
- add, edit, move and delete custom steps (`custom_…` codes, minted by the server);
- add named custom outcomes (`custom_…` actions, an Arabic label, and a role, the manager or the filer as the actor);
- redirect a built-in rule into a custom step. Built-in steps and rules are otherwise locked until slice 5.

`PUT workflow-versions/{v}` takes the whole graph. `POST …/publish` refuses while `WorkflowVersionValidator` reports
errors (an unreachable custom step, a stranded step, the committee reached without registration), and otherwise
retires the old version and publishes in one transaction. `DELETE` removes a draft. Request types gain `workflow_id`
(nullable, backfilled to Standard), and intake files onto the type's workflow.

Runtime surfaces learn custom actions and stages: rule labels on the wizard and the timeline, party `role` and next
action `complete_step` at a custom stage, `custom_step` in My Tasks, the step count per version, and report stages
grouped by code. Verify: new `WorkflowDesignerTest`, `WorkflowValidatorTest` and `WorkflowDraftRuntimeTest`, node
tests, full PHPUnit, Pint, migrate on MySQL, build (`dist` committed), parity, aislop ci, and a headless walk that
builds and publishes a draft.

---
### 2026-10-10 18:16 EET — Claude — Workflow designer slice 2: read-only canvas (complete)

Built per the plan below. **Deviations:**
- The canvas opens at 85% zoom, on the request's stage or the top of the chain. It does not fit-to-view, because
  fitting 12 rows shrank the text past reading.
- Exception edges are dashed and unlabelled; the inspector names their actions.
- The request page loads the canvas async, so Vue Flow (~154 kB) is fetched only when «عرض مسار العمل» opens.

**Gotcha: Homestead serves the main checkout, not this worktree**, so browser-checking a branch needs
`php artisan serve` from the worktree plus a Vite instance with `VITE_API_BASE_URL` pointed at it.

Verified:
- full suite 867 + 14 + 9, Pint clean, the 6-case node test, build (`dist` committed), parity 2117, `aislop ci` 97;
- a headless walk against this branch's API, ar/en × 1280/375 × light/dark: 12 nodes with 3 unused aside, the canvas
  is `ltr` while node text is `rtl` in Arabic, there is no page overflow, and the inspector works by click and by
  keyboard. As R01, the request modal marks the current stage and `/workflows` bounces. There were no console errors;
- `check-layout.mjs` shows only the known `/decisions` 1024px overflow and the R08 `/requests/create` gap (OPEN_ITEMS);
- both screen seeders ran on the real DB. A cell-by-cell diff against a backup showed only the overdue
  `request_intake,approve` clear for R02/R05 (OPEN_ITEMS item resolved).

**Final-review fixes**, each proven RED then GREEN by a browser or node check:
- Backspace no longer deletes stages from the read-only canvas.
- The canvas adds no tab stops; the inspector's stage list is the keyboard path.
- A bad `?version=` error clears when a real version is picked.
- On phones, a tapped stage's details scroll into view.
- Reopening the request modal re-centres on the file's current stage.
- A stage that nothing reaches but whose rules let parked files leave (`administrative_routing`) sits aside as «خارج
  المسار», so the manager's one-click approve reads as the main path.

All 15 tokens this session minted were revoked.

---
### 2026-10-10 17:43 EET — Claude — Implementation plan: workflow designer slice 2 — read-only canvas

A new `workflow_designer` screen («مسارات العمل», `/workflows`, R08 only) draws any workflow version as a diagram
(`@vue-flow/core` 1.48.2, exact pin, the only new dependency). A request page gains «عرض مسار العمل», which opens the
version that request runs on with its current stage marked. API: `GET workflows`, `GET workflow-versions/{v}` and
`GET requests/{r}/workflow` (the request's own visibility rule, 404 elsewhere), all through one
`WorkflowVersionResource`. The layout is `lib/workflowGraph.js`, which is free of Vue and covered by `node --test`.
Nothing is editable yet. Verify: new `WorkflowViewTest`, the node test, full PHPUnit, Pint, build (`dist` committed),
parity, aislop ci, check-layout plus a headless walk, and both screen seeders on the real DB.

---
### 2026-10-10 16:09 EET — Claude — Workflow designer slice 1: stages belong to versions (complete)

Built on `feat/workflow-versions` (not merged, by the controller's ruling): `93bde1e` (workflows / workflow_versions
tables, `workflow_stages.workflow_version_id`, seeders write Standard v1 only), `fcf7359` (`down()` drops the FK before
the composite uniques), `eb5618f` (`cloneAsDraft` and the re-seed proof), `56bedcb` (stage codes resolve inside the
request's own version: `Request::stageByCode`, `systemRule`, `destinationStageId`, `store()`, suspension, approval return,
the `RequestVisibility` set, a per-version map in `MeetingOutputsResource`, `WorkflowStageCount` = published Standard),
`65b20fe` (`reopenAtStage` refuses another version; the redo-stage picker takes `?request_id=`, the Vue forms pass it).
**Deviations from the spec:** no `requests.workflow_version_id` or `workflow_transitions.workflow_version_id` (both
derived); `request_types.workflow_id`, stage `kind`/`checklist`/`position`, report grouping and a per-version stage
count are deferred to slices 3-4. **Real MySQL:** migrated and only the two workflow seeders re-run; 12 stages and 37
rules before and after, 0 stages without a version, 1 `workflow_versions` row. Verified: full suite 860 / 5555 plus
Maintenance 14 and Bootstrap 9, Pint clean on the slice's PHP, parity 2084, `aislop ci` exit 0. Four open items are in
OPEN_ITEMS.md. Final-review fixes: `TestRequestSeeder` is scoped to the published version, two pinning tests were added
(re-seed keeps a newer published version; the redo picker 404s for an unknown request), the report-grouping open item was
corrected and a version-delete-cascade item added.

---
### 2026-10-10 15:20 EET — Claude — Implementation plan: workflow designer slice 1 — every stage belongs to a version

Slice 1 adds `workflows` and `workflow_versions`, and `workflow_stages.workflow_version_id` (backfilled to a published
Standard v1). Stage code and order_no become unique per version. A transition's version is its from-stage's and a
request's is its current stage's, so neither gets a column. The seeders are scoped to `WorkflowVersion::seededStandard()`
so a re-seed never touches a version an admin published. There is no behaviour change: the existing suite must stay
green with no test edits. Verify: new `WorkflowVersioningTest`, full PHPUnit, Pint.

---
### 2026-10-10 15:30 EET — Claude — Request Types keeps each document's folder (`section`) (complete)

Built per the plan below; no migration. `StoreRequestTypeRequest` now validates `required_documents.*.section`
(nullable, one of `Attachment::FILE_SECTIONS`, Arabic message), and `RequestTypesView` carries the field through the
edit mapping, the blank row and the save payload with no UI control. Verified: the two new `RequestTypeCrudTest` cases
went RED then GREEN, full suite 853 + 14 + 9 green, Pint clean, build passes (`dist` committed, bundle holds the
production API URL), parity 2084, `aislop ci` exit 0.

---
### 2026-10-10 15:12 EET — Claude — Implementation plan: Request Types keeps each document's folder (`section`)

Saving a type on the Request Types screen strips every `required_documents` row's `section`: the Form Request has no
rule for it and the form never carries it. `EmploymentFilePreparationService` and `mandatorySubmitterDocuments()` read
that field, so HR's card silently loses its service-file rows after any edit. Fix: a `nullable|in:FILE_SECTIONS` rule
with an Arabic message, and the form round-trips the field untouched. Verify: two new `RequestTypeCrudTest` cases (RED
then GREEN), full PHPUnit, Pint, build (`dist` committed), parity, aislop ci.

---
### 2026-10-10 15:10 EET — Claude — A «مطلوب» checkbox per document row; HR checks every required one (complete)

Built per the plan below, with no migration and no seeder change. A row saved without the flag falls back to «no
condition = required» (`RequestType::isRequired()`), so no stored type changed meaning. HR's `register` now refuses
with `EmploymentFilePreparationService::REQUIRED_MISSING` naming each unattached required row, and the card lists them
(`missing_required`). HR's verdicts cover only the files answering a required row (`DocumentReview::only()`).
`applies()` is now «any service-file row or any required row». **Also fixed:** the intake form had blocked on
الملف الوظيفي rows the server stopped demanding at Stage 98, and `isMandatory()` now skips them.
`DocumentReviewTest`'s HR case had HR judge an untyped file, so it now attaches the required rows (updated in place).
**Gotcha:** `main-wt` also lacked the root `.env` (git-ignored), so `ExampleTest` (no APP_KEY) and one
PerformanceIndicatorTest case (no `APP_LOCALE=ar`) failed. It was copied from the main checkout.

Verified:
- 4 new tests went RED, then GREEN;
- full suite 874 green (851 + the 23 maintenance tests);
- Pint clean on touched files; build passes (`dist` committed); parity 2084; `aislop ci` exit 0 (97);
- nothing to migrate.

Not checked in a browser.

---
### 2026-10-10 14:24 EET — Claude — Implementation plan: a «مطلوب» checkbox per document row; HR checks every required one

User: «add a check box that define if an attachment is required; all required attachments are checked by hr». Put to
the user and answered: the box goes on each row of a request type's document matrix, and HR's card both requires every
required row to be attached and reviews only those files. Plan: no schema migration. Each `required_documents` row gains
a `required` boolean, and `RequestType::isRequired()` falls back to today's rule (no condition = required).
`DocumentCompletenessService::mandatoryDocuments()` reads it, which moves intake, the agenda gate and readiness together.
`EmploymentFilePreparationService` refuses `register` until every required row is covered, and
`DocumentReview` gains a key filter so HR judges only those files. المقرر's بوابة 1 is unchanged. SPA: the checkbox on
Request Types and a missing-documents list on HR's card. Verify: new tests (RED then GREEN), full PHPUnit, Pint, build
(`dist` committed), parity, aislop ci.

---
### 2026-10-09 19:22 EET — Claude — The committed dist had no API URL; rebuilt

The user's dev login showed «تعذّر الاتصال بالخادم» while Homestead was up. The cause was that the `main-wt` worktree
had no `frontend/.env*` (both git-ignored). The same gap meant `e7e58f8`'s committed `dist` had no
`VITE_API_BASE_URL` baked in, so the deployed SPA would have failed the same way. Both files were copied from
the main checkout, and `dist` was rebuilt (its bundle now contains `https://transactions.scco.ly/api`, as
`a8ad916`'s did). The gotcha is now in AGENTS.md. No source change. (Clock note: the machine reads 19:22, behind the
entry below; position gives the order.)

---
### 2026-10-09 20:25 EET — Claude — Every required input gets a red star and a highlighted outline (complete)

Built per the plan below. It touches frontend only, with no locale key and no PHP. `.required-mark` now lives in
`style.css`, and every label in `frontend/src` puts it next to its text. Stars and `required` were cross-checked
by script and now agree:
- 13 starred selects gained `required`;
- 13 required controls gained a star, among them login, Guide, Committee Roles, the date proposal, the soundness
  checks and a wizard's mandatory reason.

The text and the star share one `<span>`, because labels are flex/grid columns and a bare star dropped to its own
row. That was already happening on the intake screen. Verified: build passes (`dist` committed) and `aislop ci`
exits 0. Headless screenshots of `/login` in light and dark show the empty field outlined and the focused one
keeping its focus ring. No other screen was screenshotted.

---
### 2026-10-09 20:07 EET — Claude — Implementation plan: every required input gets a red star and a highlighted outline

User (/frontend-design): «every required input should have a red star and outline highlighted». Labels mark a
required field today with a plain, uncoloured « *» in ~60 places; only the intake screen has a red `.required-mark`.
Plan, frontend only: move `.required-mark` into `style.css`, swap every plain « *» for it, and give every
`:required` control that is still empty (`:invalid`) a danger-tinted outline plus a soft halo. It uses `outline`
and `box-shadow` instead of `border`, so no screen's scoped border rule can mask it. Verify: build (`dist`
committed), aislop ci, and a headless screenshot in both themes.

---
### 2026-10-09 20:20 EET — Claude — One branch per task, merged into main when done

User: «branch out for every new task and commit to the main branch». AGENTS.md (Conventions) and the
`verify-and-commit` skill now say: cut a fresh branch from `main` at the start of every task, commit there once
verified, then `git merge --no-ff` it into `main` and delete it (merge style chosen by the user). Pushing and PRs
still need an explicit request. Instruction change only; no code touched.

---
### 2026-10-09 19:15 EET — Claude — Notifications are sent instantly (complete)

Built per the plan below; no migration and no frontend change. `NotificationDispatcher::send()` now sends on `sync`
after commit, so in-app, email and SMS all go out in the request that caused them. **Trade-off:** a failing mail
transport now surfaces as a 500 after the act has committed, instead of as a failed job. **Not touched:** the jobs
already sitting in the real database's `jobs` table stay there until someone runs `php artisan queue:work
--stop-when-empty` or clears them. Verified: the new `NotificationTest` case (RED then GREEN), full suite 870 / 5652
green, Pint clean, `aislop ci` exit 0.

---
### 2026-10-09 19:00 EET — Claude — Implementation plan: notifications are sent instantly

User: «notifications should be send instantly». Cause: `SystemNotification` is `ShouldQueue` on the `database` queue,
so nothing reaches anyone until a `queue:work` worker runs, and the cPanel host has none. Plan: one line in
`NotificationDispatcher::send()`, `onConnection('sync')` before the existing `afterCommit()`. `SyncQueue` honours
after-commit, so a rolled-back act still announces nothing. Verify: a new `NotificationTest` case with the default
queue set to `database` (RED then GREEN), full PHPUnit, Pint, aislop ci.

---
### 2026-10-09 19:05 EET — Claude — Each attached file previewed and approved on both document gates (complete)

Built per the plan below. Migration `attachment_previews` is applied to the real MySQL, and the model is audited. Since
`DocumentReview` replaced the matrix answers, `IntakeGateService`/`EmploymentFilePreparationService` lost
`requiredDocuments()`/`derivedAnswers()`, and the panel's `required_documents` prop became `review_attachments`.

Two bugs found in the headless walk, both fixed:
- **Escape in the preview closed the whole decision wizard** (`AppModal` listens on `document`). `AttachmentLink` now
  captures Escape on `window`.
- **Saved verdicts came back blank.** A JSON resource lists a numeric-keyed array, so the panel seeds from
  `record.items` (now in AGENTS.md Gotchas).

Tests: matrix-only gate tests in four files were deleted, and fixtures with no files now send `documents: []`.

Verified:
- new `DocumentReviewTest` (7) went RED, then GREEN;
- full suite 870 / 5652 green; Pint clean; build passes (`dist` committed); parity 2081; `aislop ci` exit 0 (97);
- headless Chrome on a temporary request (ar/en 1280, ar/en 375): a row locked until its preview opened, Submit
  disabled until every row had a verdict, the rejection refusal shown, verdicts restored on reopen, no console errors;
- 27 previews gave 27 audit rows; the fixture was removed and every table count matched its baseline.

Two open items are in OPEN_ITEMS.md.

---
### 2026-10-09 18:23 EET — Claude — Implementation plan: each attached file previewed and approved on both document gates

User: in the document-verification step, each document is previewed and marked approved or not approved, and one
button at the end submits the form. Put to the user and answered:
- Both cards change: HR's «تجهيز الملف الوظيفي» and المقرر's «البوابة الأولى». Their rows become **every attached
  file**, replacing Appendix 57's required-document list.
- «غير معتمد» blocks the gate. A newer upload with the same `required_document_key` replaces the rejected file.
- The system records a preview itself: opening the full preview POSTs `…/attachments/{a}/previewed`, which writes an
  audited `attachment_previews` row (audit log only). A verdict on a file the actor never opened gets a 422.

Plan: a new `DocumentReview` service is shared by both gate services. `DocumentCompletenessService` is unchanged, so
the agenda still demands every mandatory matrix row, but HR's register no longer checks the service-file rows.
`IntakeGatePanel` shows the form inline, with an auto-ticked «تمت المعاينة» box, a verdict pair per file and one
submit button.

Verify: new tests RED then GREEN, full PHPUnit, Pint, migrate, build (`dist` committed), parity, aislop ci.

---
### 2026-10-09 17:55 EET — Claude — Hover/click preview for timeline and memo documents (complete)

Built per the plan below, with no migration and no locale change. The new route is
`appeals.original-documents.preview`, and `AppealAttachmentController::stream()` now serves both appeal routes.
`PresentationMemoCompiler` still emits no URL. A key document deleted after the memo was generated has no match in
the live runner and stays plain text there. Verified: the new AppealFileTest case and the timeline assertion went RED
then GREEN, the full suite is 868 / 5656 green, Pint is clean on the six touched PHP files, the build passes (`dist`
committed), parity is 2088, and `aislop ci` exits 0 (97). Headless Chrome hovered and clicked a timeline document on
request 51 (ar/en 1280, ar 375) with no console errors. **Not browser-checked:** the live runner's memo tab and the
appeal dossier, because the real DB has no fixture for them; the dossier's links are covered by the feature test.

---
### 2026-10-09 17:31 EET — Claude — Implementation plan: hover/click preview for timeline and memo documents

User follow-up: the timeline's documents and the presentation memo's key documents get the same `AttachmentLink`
preview. Plan, in three parts:
- Timeline: `RequestTimelineCompiler`'s attachment rows already carry `url`, and gain `mime_type`/`size_bytes`.
  `TimelineDocuments` uses `AttachmentLink` for an attachment row with a URL.
- Live runner: the stored memo stays URL-free, because a route baked into `presentation_memos.content` would go stale.
  The memo tab matches each key document by id to `context.attachments`, which already carry the agenda-item
  attachment route.
- Appeal dossier: a new `GET appeals/{appeal}/original-documents/{attachment}` (`appeals,view`,
  `Appeal::isVisibleTo`, and the attachment must belong to the appeal's original request). This is needed because an
  `appeals,edit` reader may not pass `RequestVisibility` on the original request. `AppealFileCompiler` adds that
  `preview_url` to `original_request.attachments` and `presentation_memo.derived.key_documents`.

Verify: AppealFileTest/RequestTimelineTest cases (RED then GREEN), full PHPUnit, Pint, build (`dist` committed),
parity, aislop ci, and a headless check. (Clock note: the machine reads 17:31, behind the entries below.)

---
### 2026-10-09 17:45 EET — Claude — Attachment preview on hover, full preview on click (complete)

Built per the plan below. `AttachmentLink.vue` is used on the request page, the decision wizard's Review step, the
live runner's attachments tab and the appeal documents. Their Preview/Download buttons, the request page's own modal
and two local helpers are gone, along with the dead keys `attachments.preview` and
`appeals.file.documents.previewFailed`. Names use `dir="auto"`, not `.ltr`, because stored names are now Arabic
document names. A PDF sheet fades at its foot, because Chrome's viewer ignores `view=Fit` and pages are not all A4, so
page 2 peeked in. Verified: build passes (`dist` committed), parity 2088, `aislop ci` exit 0 (97). Headless Chrome on
request 51 (ar/en 1280, ar 375) showed the sheet on hover, the dialog on click with focus on Close, and Escape closing it
without re-opening the sheet. There were no console errors. Not covered: intake and `FileUpload` (local files
have no `preview_url`), the timeline's document rows (no URL in that payload) and the memo's key-document lists.

---
### 2026-10-09 17:19 EET — Claude — Implementation plan: attachment preview on hover, full preview on click

User (/frontend-design): hovering an attachment's name shows a tooltip with a preview of it, and clicking the name opens a
popup with the full preview. Plan: one new `components/AttachmentLink.vue` that takes any payload carrying
`original_name`/`mime_type`/`preview_url`. Hovering with a mouse, or focusing with the keyboard, shows an A4-sheet
card with the image or the PDF's first page, fetched once through the bearer-aware `api`. A click opens a dialog with
the full preview and Download. A DOC/DOCX says it cannot be previewed and offers Download. It replaces the
Preview/Download buttons and the page-local modal on the request page, and the plain names in the decision wizard's
Review step, the live runner's attachments tab and the appeal documents. Frontend only, one new locale key. Verify:
build (`dist` committed), parity, aislop ci, and a headless hover/click check. (Clock note: the machine reads 17:19,
behind the entry below; position gives the order.)

---
### 2026-10-09 17:40 EET — Claude — The employee's grade data at intake, confirmed by HR (complete)

Built per the plan below. Migration `requests.entitled_grade`/`grade_date` is applied to the real MySQL. «درجة القرار» is now
«الدرجة الوظيفية» / "Job grade" in every label and Arabic message; the column and the ministry threshold are unchanged.
HR's card freezes the three values in `employment_file.grade` beside `grade_verified`, and `refusalReason()` lost its
never-passed `$answers`/`$assembled` parameters. A type with no `service_file` rows still skips HR's gate entirely (the
pre-existing early return), so it skips this confirmation too. No real file sat at `receive_and_register`. Verified: two new
tests (RED then GREEN), intake fixtures given the two fields, full suite 867 green, Pint clean, build passes (`dist`
committed), parity 2089, `aislop ci` exit 0. Not checked in a browser.

---
### 2026-10-09 16:51 EET — Claude — Implementation plan: the employee's grade data at intake, confirmed by HR

User: on the intake screen «درجة القرار» becomes «الدرجة الوظيفية», plus two new inputs «الدرجة الوظيفية المستحقة» (number) and
«تاريخ الدرجة» (date), all three checked by HR against the organisation's records. Put to the user and answered: both new
inputs are required, and HR confirms them on its «تجهيز الملف الوظيفي» card with a required checkbox that blocks `register`.
Plan: migration `requests.entitled_grade` (smallint) + `grade_date` (date), both nullable for existing rows;
`StoreRequest` requires them (1–100, a date not after today), drafts accept them. The `decision_grade` column and the
ministry threshold are unchanged, only its label. `PrepareEmploymentFileRequest` takes `grade_verified`, the record
freezes it, and `EmploymentFilePreparationService::refusalReason()` refuses until it is true. `IntakeGatePanel` shows
the three values and the checkbox on HR's card. Verify: new tests (RED then GREEN), intake fixtures updated, full
PHPUnit, Pint, migrate, build (`dist` committed), parity, aislop ci.

---
### 2026-10-07 22:25 EET — Claude — An attached file is named «document - employee number» (complete)

Built per the plan below. Migration `users.employee_number` (nullable, unique) is applied to the real MySQL.
`Attachment::displayName()` writes `original_name` at intake and on a later upload, and both refuse with
`Attachment::NO_EMPLOYEE_NUMBER` when the employee has none. The Users modal edits the number. The intake screen
blocks the review step with `intake.noEmployeeNumber`. `UserFactory` gives every fixture user a number, and
`TestUserSeeder` sets `T` + the phone suffix. On the real DB only that column was filled for the test accounts,
because re-running the seeder would reset their roles; **2 of 17 accounts there still have none** and cannot
file until R08 sets one. `AttachmentUploadTest`'s admin filer was given a number in place. Verified: new
`AttachmentNamingTest` (RED then GREEN), full suite 865 / 5622 with that one test updated, Pint clean, build
passes (`dist` committed), parity 2076, `aislop ci` exit 0. Not checked in a browser.

---
### 2026-10-07 21:48 EET — Claude — Implementation plan: an attached file is named «document - employee number»

User: «when a file is submitted its name is changed to the document name in the system - the employee number».
Put to the user and answered: a new `users.employee_number` (nullable, unique, set on the Users screen), with
**no fallback**: intake is refused (422 on `employee_number`) until an admin assigns one, the same shape as the
no-department refusal. An `other` file takes its label, or «مستند آخر» if it has none. Plan:
`Attachment::displayName()` builds «<Appendix 57 name> - <number>.<ext>» for both writers
(`RequestController::store()` and `AttachmentController::store()`, which also refuses with no number) and
overwrites `original_name`. The stored path stays a hash. Drafts and appeals are unchanged. Verify: a new
`AttachmentNamingTest` (RED then GREEN), full PHPUnit, Pint, migrate, build (`dist` committed), parity, aislop ci.

---
### 2026-10-07 21:20 EET — Claude — Several files at once, and a drop zone (complete)

Built per the plan below. It is frontend only, with no PHP and no backend change. `FileDropZone.vue` is used by
intake and by `FileUpload.vue`. `FileUpload` gives each file its own row (document type, folder, label) and posts
them one at a time. A refused file keeps its row with the server's reason. A file is previewed by opening its name
in a new tab, which replaces the single inline frame. `attachments.file`, `.chooseFile` and `.selectedPreview` gave
way to `dropZone`/`dropZoneBrowse`. The select in `FileUpload` also now inherits its text colour, because it was
invisible in dark mode. Verified: build passes (`dist` committed), parity 2074, `aislop ci` exit 0. A headless
Chrome run, with every write mocked so nothing reached the DB, covered three cases:
- dropping 2 PDFs and a .txt on intake gave 2 rows and 1 named rejection;
- the same drop on the request-list modal gave the same result, and submit stayed disabled until each row was classified;
- a 422 on the 2nd file kept the modal open with that row only, and the retry closed it.
There were no console errors. Not run: PHPUnit, because no PHP changed.

---
### 2026-10-07 20:55 EET — Claude — Implementation plan: several files at once, and a drop zone

User: «multiple files can be attached in the request as one go, and add a drop file zone». Intake already takes
several files; the request page's `FileUpload.vue` (returned file, RequestsView modal, the wizard's
`attach_document`, appeals) takes one. Plan: a new `components/FileDropZone.vue` (a `<label>` around a
`multiple` file input, with dragover/drop, emitting a `File[]`) replaces the bare input in both places.
`FileUpload` keeps a list of picked files, each with its own document type, folder and label, and uploads them
one by one to the unchanged single-file endpoint. A failed file keeps its row and error, and `uploaded` is
emitted once the list is empty, so the two callers that close on it no longer close mid-batch. Frontend only,
with no backend change. Verify: build (`dist` committed), parity, aislop ci, headless check.

---
### 2026-10-07 16:20 EET — Claude — العميد's request goes straight to HR (complete)

Built per the plan below. There is no migration, seeder or frontend change. `WorkflowService::skipManagerReviewForDean()`
runs inside both `applySystemTransition()` and `transition()`, and `systemRule()` was extracted from the former to
resolve the `forward` row. The timeline shows the skipped hop as a `forward` acted by the dean himself, because the
system takes it on his behalf. Verified: new `DeanRequestTest` (RED then GREEN), full suite 860 / 5613 green, Pint
clean, `aislop ci` exit 0 (97). Not checked in a browser.

---
### 2026-10-07 16:00 EET — Claude — Implementation plan: العميد's request goes straight to HR

User: «a request made by the عميد goes directly to hr». Plan: one private step in `WorkflowService`, run after
both the intake auto-hop (`applySystemTransition`) and a `submit` (re-submit after a return): when the file lands on
`direct_manager_review` and صاحب العلاقة (= the filer since 2026-10-07) holds R07, it takes that stage's own seeded
`forward` row on to `receive_and_register` + `routed_to_hr` in the same transaction. `stageChanged` is sent once for
the whole move, so a manager set on the dean's account is never prompted for a step that has already passed. No
migration, seeder or frontend change. Verify: new test (intake and re-submit), full PHPUnit, Pint, aislop ci.

---
### 2026-10-07 15:40 EET — Claude — The direct manager acts with an optional note only (complete)

Built per the plan below; no migration. `return_to_employee` and both manager `cancel` rows are `requires_comment =
false`, and `controlGateRefusal()` no longer gates `forward`. `PATCH …/attachments/{a}/validity`, its Form Request,
`WorkflowService::mayActAsSubjectsManager()`, `DocumentValidityPanel.vue` and the wizard's validity step are deleted.
The lifecycle panel lists only cards recorded before today. Three tests that pinned the required reason were updated
in place. Verified: new `DocumentIntegrityTest` case (RED then GREEN), full suite 834 + 9 + 14 green after those
updates, Pint clean, build passes (`dist` committed), parity 2075, `aislop ci` exit 0. **Not reseeded:** MySQL timed
out (OPEN_ITEMS.md).

---
### 2026-10-07 15:07 EET — Claude — Implementation plan: the direct manager acts with an optional note only

User: «the direct manager moves the request with an optional note only». Put to the user and answered: every
manager-gated row (`forward`, `return_to_employee`, `cancel` at `direct_manager_review`, plus `cancel` at
`administrative_routing`) becomes `requires_comment = false`, and Appendix 31's document-validity check stops gating
`forward` (reverses the 2026-09-26 decision). The record endpoint, `RecordDocumentValidityRequest`,
`DocumentValidityPanel.vue`, the wizard's validity step and `control_gates.document_validity` go with it. Recorded
cards stay readable in the lifecycle panel. Verify: DocumentIntegrityTest rewritten, full PHPUnit, Pint, build
(`dist` committed), parity, aislop ci, reseed `WorkflowTransitionSeeder`.

---
### 2026-10-07 14:35 EET — Claude — The dashboard shows only the files the reader can open (complete)

Built per the plan below; no migration. `DashboardController` sets `visible_to` unless the reader holds
`reports,export`, `ReportMetricsService::query()` applies `RequestVisibility` for it, and the payload's `scoped` flag
shows `dashboard.scopedNote`. **Known lag (`ponytail:` note):** a role or manager change does not bump the cache
generation, so a reader's scope can trail it by up to 5 minutes. The reports screen still shows whole-population KPIs.
Verified: new `DashboardReportTest` case (RED then GREEN), full suite 861 / 5618 green, Pint clean, build passes
(`dist` committed), parity 2093, `aislop ci` exit 0. Not checked in a browser.

---
### 2026-10-07 14:15 EET — Claude — Implementation plan: the dashboard shows only the files the reader can open

User: «specific users dashboard page should only show their info only». Put to the user and answered: everyone
without `reports,export` (R06/R07/R08 keep the organisation-wide figures) sees the dashboard computed over the files
`RequestVisibility` lets them open. Plan: `ReportMetricsService::query()` honours a server-set `visible_to` key, which
`DashboardController` adds (the client cannot send it, since `IndexReportRequest::filters()` whitelists), so the
cache key is per reader for free. The reports screen keeps its whole-population KPIs. The SPA labels a scoped
dashboard. Verify: a new DashboardReportTest case, full PHPUnit, Pint, build (`dist` committed), parity, aislop ci.

---
### 2026-10-07 13:40 EET — Claude — Intake refused for a filer with no department (complete)

Built per the plan below; no migration. `StoreRequest::after()` adds the refusal on `department_id` unless the filer's
department exists, is active and has a code. The intake screen shows `intake.noDepartment` and keeps the review step
disabled when the account has no department. An inactive one is caught only by the server, because the account
payload carries no `is_active`. Fixture filers in `RequestIntakeTest`, `DuplicateRequestTest`, `IntakeFidelityTest`,
`RequestSubjectTest` and `UnifiedNumberingTest` now get ADM. Verified: the new test went RED then GREEN, full suite
860 / 5610 green, Pint clean, build passes (`dist` committed), parity 2092, `aislop ci` exit 0. **Not checked:** how
many real accounts have no department, because MySQL timed out; such an account cannot file until R08 assigns one.

---
### 2026-10-07 13:15 EET — Claude — Implementation plan: intake refused for a filer with no department

User follow-up: a filer with no department is refused until an admin assigns one. `store()` refuses (422 on
`department_id`, Arabic) unless the filer's department exists and is active, the same bar the old dropdown rule set.
The intake screen shows the reason and blocks the review step. Verify: a new RequestIntakeTest case, fixture filers
given a department, full PHPUnit, Pint, build (`dist` committed), parity, aislop ci.

---
### 2026-10-07 13:00 EET — Claude — No department dropdown on intake (complete)

Built per the plan below; no migration. A new request's `department_id` is the filer's `users.department_id`, so a
filer with no department files with none, which the nullable column already allows. Drafts still store a
`department_id` that is never sent. Verified: the two updated `RequestIntakeTest` cases went RED then GREEN; full
suite 859 / 5603 green; Pint clean; build passes (`dist` committed); parity 2091; `aislop ci` exit 0. Not checked
in a browser.

---
### 2026-10-07 12:40 EET — Claude — Implementation plan: no department dropdown on intake

User: «remove department dropdown from request submition». Since صاحب العلاقة is always the filer, the request's
department is the filer's own `users.department_id` (null if none, as `requests.department_id` is nullable).
`StoreRequest` drops the `department_id` rule and `store()` ignores a posted value (an old client cannot fail),
`intakeOptions()` drops `departments`, and the SPA removes the select, its validation, payload field and locale keys;
the review step shows the account's department. Drafts keep their column. Verify: RequestIntakeTest updated,
full PHPUnit, Pint, build (`dist` committed), parity, aislop ci.

---
### 2026-10-07 12:10 EET — Claude — R08 cannot file or submit requests (complete)

Built per the plan below; no migration and no frontend file (the sidebar, router guard and `v-can` buttons all read
the cleared map). **Side effect, accepted:** the `submit` override was R08's only row at `receive_from_municipality`,
so `RequestVisibility` no longer shows the admin a file sitting there. That stage is the filer's alone anyway. Four
fixtures that used the admin as the filer or on a stage-1 file moved to an R01 filer or to `direct_manager_review`.
Verified: new `AdminCannotFileTest` (RED then GREEN), full suite 836 + 9 + 14 green, Pint clean, `aislop ci` exit 0
(97). **Not applied to the real database:** MySQL unreachable again (OPEN_ITEMS.md).

---
### 2026-10-07 11:40 EET — Claude — Implementation plan: R08 cannot file or submit requests

User: «system admin r8 should not be able to submit requests». Every account also holds R01 (Stage 103), so
removing R08's seeded `request_intake` grant alone changes nothing. Plan: `User::screenPermissions()`, the one map
the API middleware, router guard, sidebar and `v-can` all read, clears every `request_intake` action for an R08
holder, so the Roles & Permissions grid cannot grant it back. `WorkflowTransitionSeeder` drops R08's `submit`
exception (pushing someone else's returned file forward) with a targeted delete, since exception rows survive the
generic one. Kept: an R08 who filed before today still re-submits their own returned file through the creator row.
Verify: new test, full PHPUnit, Pint, aislop ci, reseed `WorkflowTransitionSeeder`. No frontend file changes.

---
### 2026-10-07 EET — Claude — Filing on another employee's behalf removed (complete)

Built per the plan below; no migration. `store()` always takes the caller as صاحب العلاقة, a posted `subject_user_id`
is ignored (not refused, so an old client cannot fail), `duplicate-check` searches the caller's own files, and
`intake-options` has no picker data. `subject_user_id` and every read of it stay, so a request filed for someone else
before today still routes to that employee's manager. `RequestSubjectTest`: the per-subject search test and the two
grant tests were replaced by `test_a_request_is_always_about_its_filer`. The guide, its PDF and the four test plans
were updated (checkbox counts unchanged; the `request_intake` Appendix A cells for R02/R05 are now `vae`). Verified:
full suite 857 green, Pint clean, build passes (`dist` committed), parity 2093, `aislop ci` exit 0 (97). **Not
applied to the real database:** Homestead's MySQL was unreachable, and the leftover grant is inert (OPEN_ITEMS.md).

---
### 2026-10-07 EET — Claude — Implementation plan: remove filing a request on another employee's behalf

User: «remove submitting a request for another user». Plan: Stage 95's on-behalf path goes, and صاحب العلاقة is always
the filer from now on. `StoreRequest` and `SaveRequestDraftRequest` drop `subject_user_id`, `RequestController::store()`
and `RequestLifecycleController::duplicateCheck()` always use the caller, and `intakeOptions()` drops
`may_file_for_others`/`subject_options`. `request_intake,approve` is reseeded empty. The SPA picker, its review row and
its locale keys are removed. **Kept:** the `subject_user_id` column, its model hook, and every read of it, because
existing rows may name a subject other than the filer. Verify: RequestSubjectTest updated, full PHPUnit, Pint, build
(`dist` committed), parity, aislop ci, reseed `ScreenRolePermissionSeeder`.

---
### 2026-10-04 22:40 EET — Claude — العميد is a single-holder post; R10 merged into R03 (complete)

Built per the plan below. There is no migration.

What changed:
- `CommitteeMember::SOLE_ROLES` (the five seats plus `dean` → R07) drives «المناصب الفردية», its route, `activeSeatHolder()` and the Users-screen exclusion. `seatRoleIds()` was renamed `soleRoleIds()`, and `RoleResource`'s `committee_seat` became `sole_post`.
- R08 names العميد, and the مقرر is refused.
- Nobody may hold two of these posts, and `SEAT_ROLES` keeps العميد off every committee.
- `RoleSeeder` renames R03, gives R10's holders R01, deletes any rule row requiring R10 (the FK would otherwise null it into "anyone") and deletes R10. `PermissionSeeder`'s legacy arrays dropped R10.
- Three tests that used R10 as "a role with no notes grant" now use R09.

Verified:
- 4 new `CommitteeRolesTest` cases failed first, then passed.
- Full suite 859 green; Pint clean; build passes (`dist` committed); parity 2096; `aislop ci` exit 0 (97).
- RoleSeeder and ScreenSeeder were reseeded on the real MySQL and checked by query: R10 is gone and `r10.diwan@` is R01.
- A live GET as r08 showed six rows, with `can_assign` true only for the مقرر and العميد.

Open items are in OPEN_ITEMS.md: the seeded second R03 holder, and the stale R10 sections of the test plans and the guide.

---
### 2026-10-04 21:56 EET — Claude — Implementation plan: العميد is a single-holder post; R10 merged into R03

User: «there can only be one user for each» of the five Art. 10 (أ) posts plus العميد. The five already have one
holder each (Stage 103). Put to the user and answered: «وكيل ديوان» is the chair, so R10 is retired into R03, and
R08 names العميد (R07) on the same page as the مقرر, with the same replace semantics. Plan: `CommitteeMember::SOLE_ROLES`
(the five seats + `dean` → R07) drives the page, the route, `activeSeatHolder()` and the Users-screen exclusion
(`soleRoleIds()`), while `SEAT_ROLES` still decides who sits on a committee, so العميد is never seated. A seat holder
cannot be named العميد, and the reverse is also refused. `RoleSeeder` renames R03 «رئيس اللجنة (وكيل ديوان البلدية)», gives
R10's holders R01 and deletes R10; `TestUserSeeder` drops `r10.diwan@`. The page is retitled «المناصب الفردية». No
migration. Verify: CommitteeRolesTest cases, full PHPUnit, Pint, build (`dist` committed), parity, aislop ci, reseed.

---
### 2026-10-04 21:40 EET — Claude — «أدوار اللجنة»: R08 names the مقرر, the مقرر names the other four (complete)

Built per the plan below. There is no migration. The new `committee_roles` screen (R02 + R08) has
`GET committee-roles` and `PUT committee-roles/{seat}`. Naming a holder takes the role from every other holder.
`UserController::store()` always adds R01, and the Users screen cannot grant a seat role
(`CommitteeMember::seatRoleIds()`). `Committee::seatRoleHolders()` fills all five seats at committee creation and at
scheduling. The add/remove-member endpoints and `StoreCommitteeMemberRequest` are deleted, and the meetings roster is
read-only. **One deviation from the plan:** the seats are *not* refilled at naming time, because that would pull a
former holder out of a sitting already under way, which the new holder was never invited to. Until the next
scheduling the former holder is a stand-in (`SeatDutyGrant`).

Tests that covered hand-seating were deleted or rewritten in place:
- deleted: the stand-in seat test in `CommitteeOwnActsTest` and in `SeatDutyGrantTest`, and two roster tests;
- rewritten: the two R02-via-Users tests in `SingleRapporteurTest`.

Verified:
- the new `CommitteeRolesTest` (7 tests) failed first, then passed;
- full suite 833 + 14 + 9 green;
- Pint clean; build passes (`dist` committed); parity 2095; `aislop ci` exit 0 (97);
- `check-layout.mjs`: 450 views, failing only on the existing `/decisions` overflow at 1024px;
- both screen seeders reseeded on the real MySQL;
- a live GET as r08 and r02 returned the right `can_assign` flags.

No live PUT was run, because it would strip roles from seeded accounts (e.g. multi.role@'s R03). Open items are in
OPEN_ITEMS.md: R05, unassigning, and the roster staying stale until the next scheduling.

---
### 2026-10-04 20:45 EET — Claude — Implementation plan: «أدوار اللجنة» — R08 names the مقرر, the مقرر names the other seats

User: «all the users start out as normal users; the system admin assigns the مقرر using a new page in the system
settings; the مقرر assigns the rest of the committee members on the same page». Put to the user and answered: role +
auto-seat (one active holder per seat role, every committee seats them, add/remove-member goes away); assigning
replaces the previous holder; R05 is out «for now»; the مقرر also names the chair (R03); the Users screen keeps its
picker for non-seat roles and always gives R01; seeded test accounts keep their roles. Plan: `User::activeSeatHolder()`,
`Committee::seatHolder()`/`seatRoleHolders()`, a new `committee_roles` screen + `CommitteeRoleController` (GET/PUT
`committee-roles/{seat}`), `StoreCommitteeMemberRequest` and the two `/members` routes deleted. No migration. Verify:
new `CommitteeRolesTest`, full PHPUnit, Pint, build (`dist` committed), parity, aislop ci, reseed the two screen seeders.

---
### 2026-10-03 10:20 EET — Claude — Test button: 5 random requests per reachable stage (complete)

Built per the plan below. The maintenance console's «توليد طلبات تجريبية» (`db:seed:test-requests`, debug-only) runs
`TestRequestSeeder`, which adds 40 requests (8 stages × 5) filed by random active R01 holders who have a manager. With
no such user it warns and does nothing, so "Seed test users" comes first. The rows are inserted at their stage: files
at committee or approval stages have no meeting, decision or Approval rows behind them. Verified: new
`TestRequestSeederTest` (RED then GREEN) and a MaintenanceConsoleTest case, full suite 855 / 5585 green, Pint clean,
`aislop ci` exit 0 (97). The catalog file now draws a non-blocking `file-too-large` warning. Not run against the real
database: clicking the button is left to the user.

---
### 2026-10-03 10:00 EET — Claude — Implementation plan: a test button that generates 5 random requests per stage

User: «make a test button that generates me 5 random request for each stage». Decisions put to the user and answered:
the requests are inserted directly at the stage (no gates walked), the button sits on the maintenance console, and
only reachable stages are covered (3 and 6–8 are skipped), so one click adds 8 × 5 = 40 requests. Plan: a new
`TestRequestSeeder` (a random active R01 holder with a manager as filer, a random type, PM-RCV for every request and
PM-COM from `receive_from_committee` on, one stage-log row and one status-history row, no notifications). It gets a
`debug_only` catalog entry `db:seed:test-requests` modelled on `db:seed:test-users`. No frontend, route or migration.
Verify: new `TestRequestSeederTest`, a MaintenanceConsoleTest case, full PHPUnit, Pint, aislop ci.

---
### 2026-10-03 09:20 EET — Claude — The employee sees their manager and department; «المسؤول الحالي» names the person (complete)

Built per the plan below; no migration. Login and `/auth/me` load `manager:id,name`, and the topbar's user menu shows
الإدارة and المدير المباشر (new `account.manager`). `responsibility.responsible.names` lists صاحب العلاقة's live manager
(via `WorkflowService::subjectsLiveManagerId()`, now public) or the active R12 holders, shown after the label on the
request page, the requests list and «متابعة طلباتي». R12 → `hr_department` fixed `receive_and_register`. **One addition
beyond the plan:** the manager check now also reads exception rows, so the two real files parked at the retired
`administrative_routing` stage (whose only exit is the manager-gated `route_to_hr`) read الرئيس المباشر instead of
«قسم شؤون الموظفين». Verified: 4 new `RequestResponsibilityTest` cases (RED then GREEN), full suite 957 green, Pint clean,
build passes (`dist` committed), parity 2088, `aislop ci` exit 0. A live GET as r01.employee@ confirmed the payloads. Not
checked in a browser.

---
### 2026-10-03 08:43 EET — Claude — Implementation plan: the employee sees their manager and department; «المسؤول الحالي» names the person

User: «the employee should be able to see his manager account name and his department», and at the direct manager / HR
director stage «clarify the name of the account». Plan: `/auth/me` and login also load `manager` (UserResource already
shapes it), and the topbar's user menu shows the department and the manager. `RequestResponsibilityService::for()` adds
`responsible.names`: صاحب العلاقة's live manager at the manager-gated stage (reusing
`WorkflowService::subjectsLiveManagerId()`, now public), and the active R12 holders when an R12 rule decides the party.
`ROLE_TO_PARTY` gains R12 → `hr_department`; without it `receive_and_register` read «قسم شؤون الموظفين». The three views
that render responsibility append the names. No migration. Verify: RequestResponsibilityTest cases, an auth/me case, full
PHPUnit, Pint, build (`dist` committed), parity, aislop ci.

---
### 2026-10-02 19:20 EET — Claude — The committee is a group the مقرر picks, not a department (complete)

Built per the plan below; no migration. `DepartmentSeeder` no longer seeds CMT and soft-deletes an existing row after
moving its users to ABS. The seven test accounts now sit in ABS/ENG/FIN/ADM. On the real MySQL, CMT (#6) is
trashed, it had 0 requests, and its 7 users moved. `refuseSecondMeetingInMonth()` is gone, and
`MeetingSchedulingWizardTest`'s cap test was inverted in place. `PendingTaskCollector::meetingsDue()` gives the active
R02 holder a `meeting_due` task per active committee with no live meeting dated this month. `CommitteeController`
store/addMember/removeMember now 422 unless the actor is `User::activeRapporteur()`. `store()` also stopped adding a
separate creator row: the creator is the مقرر, already seated. That closes most of the seatless-creator open item.
Three fixtures that seated as the chair now act as the مقرر. Verified: new `CommitteeIsAGroupTest` (RED then GREEN),
full suite 826 + 9 + 13 green, Pint clean, build passes (`dist` committed), parity 2087, `aislop ci` exit 0, guide PDF rebuilt. Not
checked in a browser.

---
### 2026-10-02 18:40 EET — Claude — Implementation plan: the committee is a group the مقرر picks, not a department

User: «لجنة شؤون الموظفين is not a department, it's a group that occasionally meets, picked by المقرر every month at
least once». Put to the user and answered: remove the CMT department, allow more than one meeting a month and remind the
مقرر of a committee with none this month, and only the مقرر picks members. Plan: `DepartmentSeeder` drops CMT and
soft-deletes an existing row (its users moved to ABS first); `TestUserSeeder` moves the seven CMT accounts to real
units. `MeetingController::refuseSecondMeetingInMonth()` is deleted. A new `meeting_due` inbox source goes to the active
R02 holder. `CommitteeController` store/addMember/removeMember refuse anyone but the مقرر (422), and the SPA buttons follow.
No migration. Verify: new `CommitteeIsAGroupTest`, full PHPUnit, Pint, build (`dist` committed), parity, aislop ci,
reseed the two seeders on the real DB.

---
### 2026-10-02 18:15 EET — Claude — Only the مقرر sets a meeting's date (complete)

Built per the plan below; no migration. `MeetingController::refuseUnlessRapporteur()` (422 on `scheduled_at`) compares
the actor with the committee's seated مقرر, in `store()` and in `update()`'s date-change branch. In `store()` it replaces
Stage 84's "seated, or R08" check, which became redundant once the مقرر is seated on every committee. R08 no longer
schedules, and the chair keeps the rest of `meetings,edit`. SPA: the schedule and propose-date buttons check
`auth.hasRole('R02')`. `CommitteeMeetingTest`'s R08 fallback assertion was inverted in place. Verified: the new
`SingleRapporteurTest` case went RED then GREEN, full suite 823 + 9 + 13 green, Pint clean, build passes (`dist`
committed), parity 2086, `aislop ci` exit 0. Not checked in a browser.

---
### 2026-10-02 18:00 EET — Claude — Implementation plan: only the مقرر sets a meeting's date

User: «مقرر is the only one who can set the date». Today R08 can schedule (it holds `meetings,add` and is exempt from
the membership check), and R02, R03 and R08 can all move the date through `PUT meetings/{m}` (`meetings,edit`). Plan:
one private check in `MeetingController`, used by `store()` and the date-change branch of `update()`: 422 unless the
actor is `User::activeRapporteur()`. The chair keeps the rest of `meetings,edit`. SPA: the schedule and propose-date
buttons show only to the R02 holder. Verify: new tests in `SingleRapporteurTest`, full PHPUnit, Pint, build, aislop ci.

---
### 2026-10-02 17:45 EET — Claude — One مقرر in the system, auto-seated on every committee (complete)

Built per the plan below; no migration. `User::activeRapporteur()` is the one active R02 holder; `UserController`
refuses a second on store/update/toggle-active (an inactive holder does not block, re-enabling one does).
`Committee::seatRapporteur()` keeps a still-valid holder and otherwise replaces the seat's occupant; it runs in
`CommitteeController::store()` and before `MeetingController::store()`'s membership check, which refuses with no مقرر.
`addMember` no longer accepts `seat=rapporteur`, so the R02 role rule there is gone. In the SPA, the add-member picker
drops that seat and the scheduling wizard no longer blocks on it. Two tests that pinned the old manual rule were updated
in place. Verified: new `SingleRapporteurTest` (6, RED then GREEN), full suite 822 + 9 + 13 green, Pint clean, build
passes (`dist` committed), parity 2086, `aislop ci` exit 0. The real DB already holds exactly one R02 (`r02.reviewer@`);
committee #16's seat is filled at its next scheduling. Not checked in a browser.

---
### 2026-10-02 17:20 EET — Claude — Implementation plan: one مقرر in the system, auto-seated on every committee

User: «there can be only one مقرر in the system and he is auto invited to every meeting»; put to the user and answered:
only an **active** R02 holder blocks another, and re-enabling a former مقرر is refused while another is active. Plan:
`UserController` store/update/toggle-active refuse (422) a state where two active users hold R02. A new
`Committee::seatRapporteur()` puts the current active R02 holder in the rapporteur seat (replacing a stale holder). It
runs on committee creation and again in `MeetingController::store()`, so every meeting invites the مقرر; with no مقرر,
scheduling is refused. Manual rapporteur seating via `addMember` is refused. No migration, no frontend change. Verify:
new feature test, full PHPUnit, Pint, aislop ci.

---
### 2026-10-02 17:00 EET — Claude — aislop scanner adopted (complete)

Built per the plan below. `.aislop/config.yml` (pinned 0.16.1, `failBelow: 85`, telemetry off) raises the score from
67 to **97 Healthy**, and `aislop ci` exits 0. What is left: 30 complexity warnings and two `hidden-fallback` warnings
on `firstError()` (intended). **`narrative-comment` is off too:** it flags the why-comments and was the only fixable
rule, which the hook calls "MUST fix". The Claude hook runs `npx --yes aislop@0.16.1` (no global binary is installed)
and takes about 20 s per edit. `async` would drop its output, and the Stop gate needs the per-edit hook to record which
files were touched. Codex gets the AGENTS.md section, not aislop's own rewrite of that file. Verified: the hook fired
live on an edit in this session. No PHP or frontend code changed, so PHPUnit, Pint and the build were not run.

---
### 2026-10-02 16:45 EET — Claude — Implementation plan: adopt the aislop scanner

User: «implement https://github.com/scanaislop/aislop». An untuned `npx aislop scan` gives 67/100, mostly from false
positives against this repo's own rules: 657 `ai-slop/meta-comment` hits are largely the mandated `// Stage N —`
markers, `react-hooks/rules-of-hooks` reads Pinia's `useXStore()` as a React hook, and `security/eval` reads
Puppeteer's `$$eval`. Put to the user and answered: the markers stay, so that rule goes off, and the scope is config +
agent hook. Plan: `.aislop/config.yml` (the false positives off; vendor, dist, graphify-out and storage excluded), the
project-scope Claude hook (`aislop hook install --claude --project`), and `aislop ci` added to AGENTS.md's Verified
checklist. No app code changes. Verify: the scan runs clean of those rules, and the hook fires on an edit.

---
### 2026-10-02 16:55 EET — Claude — A stand-in seat holder gets the seat's meeting duties while invited (complete)

Built per the plan below, with no migration, frontend or locale change. `SeatDutyGrant` lends the seat's role on the
seven meeting-duty screens through `User::screenPermissions()`. `WorkflowService::actorRoleIds()` adds it only for
requests on that live meeting, and both `availableTransitions()` and `transition()` read it. The grant starts with the
attendee row and ends once the meeting is cancelled, or completed with none of its files still in the approval cycle.
Two tests that pinned the old binding were updated in place (`CommitteeSeatRosterTest`, `CommitteeOwnActsTest`).
Verified: new `SeatDutyGrantTest` (6 tests, RED then GREEN); full suite 816 + 9 + 13 green; Pint clean on the touched
files. Residue is in OPEN_ITEMS.md: the grant is not shown anywhere, and a stand-in chair can edit the committee roster.

---
### 2026-10-02 16:33 EET — Claude — Implementation plan: a stand-in seat holder gets the seat's meeting duties while invited

User: «when المقرر invites someone to the meeting, that someone gets required role/permissions until all his meetings
have concluded and the meeting results are sent for approval and approved». Put to the user and answered: the invitee is
a **seat holder who lacks the seat's role** (no ad-hoc invitees — Stage 102 stands), and the grant is **the seat role's
meeting duties only**, not the whole role. Plan: `StoreCommitteeMemberRequest` keeps the role rule for `rapporteur`
(R02, the inviter) alone. A new `SeatDutyGrant` computes the grant live, with no table and no expiry job. A meeting
holds it while the user is its attendee in a non-rapporteur seat and the meeting is `pending_confirmation`/`scheduled`,
or `completed` with a request still in the approval cycle. `User::screenPermissions()` ORs in that role's rows on the
seven meeting-duty screens. `WorkflowService` adds the role only for requests on that live meeting, so a stand-in chair
records their own items' decisions and nothing else. Verify: new `SeatDutyGrantTest`, full PHPUnit, Pint.

---
### 2026-10-02 16:25 EET — Claude — Create/edit roles and per-screen switches on Roles & Permissions (complete)

Built per the plan below; no migration. `RoleController::store()/update()` sit behind `roles_permissions,add|edit`,
and only R08 holds those today. The client can't send a code: the server assigns `C01`, `C02`…, and an update changes
names and description only. In the view, the modal patches `roles` in place instead of calling `load()`, so unsaved
ticks on other tabs survive. The «مفعّل» switch clears all seven actions when off and sets view only when on.
Verified: new `RoleManagementTest` (5 tests, RED then GREEN), full suite 810 + 9 + 13 green, Pint clean on touched
files, build passes (`dist` committed), parity 2086. **Not checked in a browser**, and `check-layout.mjs` was not run.
Reseeding `ScreenRolePermissionSeeder` resets custom roles' grants along with everything else; that hazard predates this change.

---
### 2026-10-02 16:05 EET — Claude — Implementation plan: create/edit roles and per-screen switches on Roles & Permissions

User: «system profiles screen with the ability to create/edit each profile and enable/disable each profile feature»;
put to the user and answered: profile = role, feature = screen (an on/off switch per screen, the 7 action checkboxes
kept), and the existing `/roles` screen is extended rather than a new one added. Plan: `POST /roles`
(`roles_permissions,add`) and `PUT /roles/{role}` (`roles_permissions,edit`) with `Role\Store|UpdateRoleRequest`
(name_ar, name_en, description; Arabic messages). The server assigns custom codes `C01`, `C02`…, never taken from the
client — a `C` prefix so a future built-in R13 in `RoleSeeder`'s upsert cannot overwrite one. Codes are immutable and
there is no delete. In `RolesPermissionsView`, an `AppModal` form for new/edit role, and an «مفعّل» column per screen
(off clears all 7 actions, on sets view). No migration or seeder change. Verify: new `RoleManagementTest`, full
PHPUnit, Pint, build (`dist` committed), parity.

---
### 2026-10-02 13:45 EET — Claude — المقرر reaches the meetings screen before holding a seat (complete)

Built per the plan below. `User::hideMeetingsSectionIfUnseated()` now leaves `meetings,view` to a holder of
`meetings,add` (R02); the other six gated screens still wait for a seat, which forming a committee gives.
`GatedScreenSideEffectsTest`'s invariant was updated in place to expect exactly that one exception; a new
`MeetingVisibilityTest` case walks an unseated R02 from the committee list to a created committee to the opened section.
Verified: full suite 805 + 22 maintenance green, Pint clean on touched files. No frontend, seeder or locale change.

---
### 2026-10-02 13:30 EET — Claude — Implementation plan: المقرر reaches the meetings screen before holding a seat

User: «المقرر is the one able to create the meetings and invite its members and assign the meeting time; he should have
access to the meeting create/members screen». Cause: the seat gate (`User::hideMeetingsSectionIfUnseated()`) hides every
`meetings_management` screen from anyone with no committee seat, so an R02 not yet seated cannot reach `/meetings` to
form the first committee — only R08 could. Plan: a holder of `meetings,add` (R02 alone, Stage 102) keeps `meetings,view`
while unseated; nothing else in the section opens. `CommitteeController::store()` already seats the creator, which then
opens the rest. Existing committees R02 does not sit on stay hidden. Verify: MeetingVisibility/GatedScreenSideEffects
tests, full PHPUnit, Pint. No frontend change.

---
### 2026-10-02 13:30 EET — Claude — A committee-decided file is no longer an "open" duplicate (complete)

Built per the plan below; no migration. `DuplicatePolicy::priorRequests()` loads `committee_settled` (a decision with
an outcome other than `defer`/`legal_opinion`), and `isConcluded()` treats such a file as concluded unless its status
puts it back before the committee. The submit refusal and the `duplicate-check` endpoint both read it, so they still
agree. A new request on that subject then gets Appendix 16's classification question instead of the open-file refusal.
Verified: two new `DuplicateRequestTest` cases (decided → classify; deferred or reopened → still refused; the first was
RED before the fix), full suite **826 / 5262**, Pint clean, build passes (`dist` committed), parity 2078.

---
### 2026-10-02 13:10 EET — Claude — Implementation plan: a committee-decided file is no longer an "open" duplicate

User: «the request is not a duplicate if the first request has been discussed in a meeting and approved/declined/finished».
Today `DuplicatePolicy::isConcluded()` reads status only, so a file the committee approved or rejected but that is still in
execution/approval (not yet closed) counts as open and refuses a new request on the same subject. Plan: `isConcluded()`
also answers true when the file carries a decision whose outcome is final (anything but `defer`/`legal_opinion`) and it is
not back before the committee (`CommitteeStatusService::CANDIDATE_STATUSES` + `under_legal_review`, which covers a reopen
or a deferral). Both readers (submit refusal and `duplicate-check`) go through it. Appendix 16's classification question
still applies to such a prior; its wording drops «وأقفلت» for «انتهى النظر فيها». Verify: new DuplicateRequestTest cases,
full PHPUnit, Pint, build (ar.json label), parity.

---
### 2026-10-02 12:45 EET — Claude — The four test plans brought up to date with the new screens (complete)

Built per the plan below; documentation only. `TEST_PLAN.md`/`.ar.md` are rewritten in place (203 → **281**
checkboxes each): a new §4 for «المهام المعلقة», «متابعة طلباتي» and the wizard, a seat-gate subsection, the
one-click manager step, HR's employment file, the single committee path, the two-part archive, 14 notification
events, modals, the Users department view and phones; later sections renumbered (§17 is now Regression).
`TEST_PLAN.roles.md`/`.ar.md` are edited section by section (362 → **411** each): a new §0.6, a 24-step relay
with an R08 setup step, every role's A/B/C, four new cross-role sections (14.9–14.12), and Appendix A and the
count table re-derived whole. **Appendix A had drifted in eight cells and lacked `my_tasks`**; the sidebar
counts were all wrong (R08 is 36 screens / 29 entries, an unseated R01 14 / 12).

Verified by script, not by eye: Appendix A matches `ScreenRolePermissionSeeder::DEFAULTS` cell for cell in both
role plans (36 × 12), the seated/unseated counts equal what the seeders plus `MeetingVisibility::GATED_SCREENS`
compute, every literal `/api/…` path named resolves in `route:list` (one pre-existing wrong path fixed:
`/api/roles-permissions` → `/api/screen-role-permissions`), every `§` reference in the feature plans resolves,
and each EN/AR pair matches section by section. Locale parity 2078, no pending migration. **Not run:** PHPUnit,
Pint and the frontend build — no PHP, Vue or locale file changed. **Not done: none of the new checks was walked
in a browser**; they are derived from the seeders, routes, locale labels and the refreshed guide. One finding
is in OPEN_ITEMS.md: a committee's creator stays a seatless member and is counted in the quorum. The
`TEST_PLAN` open item is marked resolved.

---
### 2026-10-02 12:10 EET — Claude — Implementation plan: the four test plans brought up to date with the new screens

User: «update the TEST_PLAN*.md with the new screens». This is the OPEN_ITEMS entry of the same morning
(`TEST_PLAN*.md` still describe the pre-wizard UI). Documentation only — no app code, migration or locale change.
Scope, all four files (`TEST_PLAN.md`/`.ar.md`, `TEST_PLAN.roles.md`/`.roles.ar.md`): add checks for the screens
and surfaces the plans never covered — «المهام المعلقة» (`my_tasks`), «متابعة طلباتي», the request / agenda-item /
meeting / appeal wizards, the Users screen's department view, forms-in-modals, card tables on phones — and
correct what those screens made false: the five approval queues are grant rows with no page, approvals and
minute signatures are confirmations, the manager approves in one click after the document-validity gate, HR
prepares the employment file, the committee path is the single Stage 102 one, archive is two records.
Per-role sidebar counts are re-derived from `ScreenSeeder` + `ScreenRolePermissionSeeder` + the seat gate
(`MeetingVisibility::GATED_SCREENS`), seated and unseated; Appendix A re-derived whole. The feature plan is
rewritten in place (a new §4 for the two personal screens and the wizard, later sections renumbered); the role
plan is edited section by section. Source of truth: the seeders, `routes/api.php`, `en.json`/`ar.json` labels and
this morning's verified `USER_GUIDE.ar.md` — not older notes. Verify: checkbox parity inside each EN/AR pair,
in-file `§` references resolve, every screen code and route named exists in the seeder/route table.

---
### 2026-10-02 10:10 EET — Claude — Workflow documents and user guide brought up to date (complete)

Built per the plan below; documentation only. `USER_GUIDE.ar.md` (1458 → 1903 lines) now describes the system
as it runs: every write through a wizard, «المهام المعلقة» and «متابعة طلباتي», صاحب العلاقة and on-behalf
filing, the manager's document-validity gate and one-click «موافقة وإحالة», HR's employment-file step, 12 roles
/ 36 screens, the committee-seat gate, agenda adoption, R03 alone recording decisions, approvals and minute
signatures as plain confirmations, the two-part archive, 14 notification events, the Request Types screen and
the department view. **The guide had been stale since before Stage 95**, not just since the wizard: it was
patched at Stages 96/97/102 but never for 95, 98–101, the inbox or the signature removal. Two errors it carried
are corrected from the code: the قيد status is «تم التسجيل» (no status is named «مستوفية ومقيدة»), and a formal
rejection is neither closable nor appealable. `scripts/build-guide-pdf.php`: cover figures and date fixed, the
dead signature-emoji substitution removed; the PDF is rebuilt (54 pages) and **its stage map is a table again**
— the «6–8» row added at Stage 102 had silently broken that parse. Under `docs/` (git-ignored, so not in this
commit): `system-flow.md` regenerated from the five rewritten `.mmd` sources, `user-permissions-flow.md`
rewritten, all five SVGs re-rendered with the pinned mermaid-cli 11.16.0.

Verified: every changed statement checked against the seeders, `routes/api.php`, the act tables
(`RequestActs`, `MeetingDuties`, `AppealActs`, `lib/requestActs.js`) and `ar.json`; all 58 in-page guide links
resolve; Pint clean on the one PHP file; full suite **823 / 5238** (801 + the two maintenance files, unchanged);
locale parity 2078; no pending migration. No frontend change, so no build. Not checked: the PDF's visual layout
(no PDF renderer on this machine) — only that it builds and that the stage map parses into its table. Three new
OPEN_ITEMS entries: the dead-end `rejected` status, the stale `TEST_PLAN*.md`, and the inert routing suggestion
on Request Types.

---
### 2026-10-02 09:05 EET — Claude — Implementation plan: workflow documents and user guide brought up to date

User: «update work flow and user guide». Read as documentation: `USER_GUIDE.ar.md` (+ its PDF) and the workflow
documents under `docs/` (`system-flow.md`, `user-permissions-flow.md`, `diagrams/*.mmd` + renders; git-ignored,
local only). Both describe a system that has since changed: the guide was last touched at Stage 102 and still has
drawn signatures, five approval-queue screens, 11 roles / 33 screens, a two-click manager, R02 recording decisions,
a one-part archive, and nothing on the decision wizards, «المهام المعلقة», «متابعة طلباتي», on-behalf filing,
HR's employment-file step, document validity as the manager's gate, agenda adoption, or the committee-seat gate.
Plan: re-derive every changed statement from the seeders, routes and wizard act tables (not from older notes),
rewrite the affected guide sections in place, fix the cover figures in `scripts/build-guide-pdf.php`, rebuild the
PDF, redraw the flow docs and re-render their SVGs. No app code, migration or locale change. Not in scope:
`TEST_PLAN*.md`. Verify: PDF builds, guide anchors resolve, Pint on the one touched PHP file.

---
### 2026-10-02 EET — Claude — A new manager inherits the employee's open files (verified, test only)

User: when an employee gets a new manager, their requests should move to that manager. **This already happens and
needed no code:** the manager gate (`WorkflowService::subjectsLiveManagerId()`), `RequestVisibility`, the inbox and
`NotificationDispatcher` all read صاحب العلاقة's `users.manager_id` live, and nothing is copied onto the request.
New `MyTasksTest::test_a_new_manager_inherits_the_employees_open_files` pins it end to end over HTTP (R08 changes
the manager via `PUT users/{id}`; the old manager loses the inbox task and gets a 404; the new one gets the task
and forwards). Not built: a notification to the new manager (they find the file in «المهام المعلقة»).

---
### 2026-09-29 EET — Claude — Table rows become cards on phones (complete)

Built per the plan below; frontend only, no locale change. Below 640px every `.data-table` row is a card and
each cell shows its column name above its value (`lib/cardTables.js` labels them; `td:empty` cells, e.g. a
v-if'd button, are hidden). MeetingOutputsView's unconditional `min-inline-size: 75rem` also got scoped to
≥641px. **Gotcha:** a `.ltr` cell (reference, IP) keeps `text-align: left`, and Chrome computes
`text-align: match-parent` as plain `start`, so the card uses a `td:dir(rtl|ltr)` pair (it follows `<html dir>`)
to put label and value on the page's start edge. Verified: build passes (`dist` committed), headless
screenshots at 375px ar/en of requests, users, departments, audit log, registers and decisions (labels
correct and follow the locale, no page overflow, no console errors); `check-layout.mjs` fails only on the
pre-existing `/decisions` 1024px overflow.

---
### 2026-09-29 EET — Claude — Implementation plan: table rows become cards on phones

User: on small screens the table rows should appear as cards. Plan: one `@media (max-width: 640px)` block in
`style.css` turns every `.data-table` row into a card (thead visually hidden, not removed; each cell stacks its
column name above its value via `td::before { content: attr(data-label) }`), and a new `lib/cardTables.js`
(imported once in `main.js`) copies each header's text onto its column's cells through a MutationObserver — so
none of the 19 table templates changes and a future table is labelled for free. A `colspan` cell (an expanded
detail row) gets no label and fills the card. The eight views whose scoped `min-width` applies at ≤1023px get
it narrowed to 641–1023px, since a forced width would defeat the cards. Verify: build, `check-layout.mjs`.

---
### 2026-09-28 EET — Claude — Decision wizard, sub-project 3 — final-review fixes

Fixed every item the whole-branch review of a757051..6479327 found, per the controller's rulings. **C1/C2
CRITICAL**: a bare `<template>` (no directive) wrapped the agenda builder's request search and the request
page's closure card; Vue 3 renders that as a native, hidden `<template>`, so nobody could add a request to an
agenda and no closed file showed its Art. 37 fields or Appendix 47 audit. Both wrappers removed (a sweep of
`frontend/src` found no third). **I3**: nominator visibility narrowed — `Appeal::isVisibleTo()` and
`AppealController::index()` are back to owner/R08/`appeals,edit`; `GET appeals/{a}/acts` now answers a seated
nominator on a `legal_review` appeal not yet on an agenda with appeal-options' fields only (id, status,
appellant, original request's reference) plus `nominate`, else 404. `AppealWizard`'s Review hides the rows that
payload lacks. **I4**: `ApprovalReturnService::resolveRefusal()` refuses with Art. 105's `BLOCK_MESSAGE` while a
suspension is open, and `resolve()`'s formal branch re-checks under its lock — a formal resolve used to
overwrite `execution_suspended` with `awaiting_*`. Minors: `DecisionWizard`'s upload refresh no longer throws
unhandled (M5); an act whose follow-up reload fails hands back `null` and the page reloads itself, instead of an
«action failed» that invites a duplicate (M6); three dead appeal locale keys dropped and
`decisionWizard.appeal.more` names the column as labelled (M9); `AppealNominateForm` shows a load error rather
than "no open meeting" (M11); a closure test renamed to what it checks (M12); three new
`RequestRecordActsTest` cases pin the open-vote attach refusal, the inactive-subject notice refusal and the
closed-file conflict/special-case omission (M13); a stale comment and a dead selector gone (T9).

Verified: full suite **823 tests / 5238 assertions** (was 819 — +1 I4, +3 M13; run as 801 + the two maintenance files, since a maintenance test's `set_time_limit(330)` kills a single-process run on this machine — new OPEN_ITEMS entry), Pint clean on the touched PHP, build passes
(`dist` rebuilt and committed with this entry), locale parity **2078 keys each side** (−3). TDD: I3 and I4 RED
then GREEN; M13 pins existing behaviour, so each was proven by mutating its guard (all three failed). Headless
Chrome against Homestead, on a fixture committee (#37, r02+r03 seated), meeting #37 and a `legal_review` appeal
#23 against closed request #41: as r02 the add-item modal now shows the search input and results (hidden
before, same script on the pre-fix views), and #41's closure card shows all eight fields and twelve audit
rows (hidden before); as r03, `/appeals?appeal=23&decide=nominate` opened the wizard on the nominate slip with
the fixture meeting offered, the appeal absent from the list, and Review showing only request/appellant/status
— zero console errors either account. Every fixture row was deleted and all tracked counts (requests, appeals,
committees, members, meetings, audit_logs, jobs, tokens, notifications, status history) match the baseline.

---
### 2026-09-28 EET — Claude — Decision wizard, sub-project 3 complete (slice 3: appeals)

Built per the plan below; no migration. Every appeal act (verify, jurisdiction test, legal review,
execute outcome, close, reopen, nominate) moves into `AppealWizard` from a new `AppealActs` service and
`GET appeals/{appeal}/acts`, whose refusals `AppealController`'s six act methods now call — the same
no-drift shape `RequestActs` already established. Filing is `AppealFilingWizard` (request → grounds →
confirm → documents, upload last since it needs the appeal's own id); nomination moved out of the agenda
builder into the wizard, gated on `Appeal::mayNominate()` (a seated holder of `meeting_agenda` edit+view).
`AppealsView` is the list, one «اتخاذ القرار» per row driven by `has_acts`. **This closes sub-project 3's
own OPEN_ITEMS entry** (marked Resolved, slices 1–3).

Verified: full suite **819 / 5218** green, Pint clean on the eight touched files, build passes, locale
parity **2081 keys each side**, `check-layout.mjs` (440 views; fails only on the pre-existing
`/decisions` and `/reports` 1024px overflow — see below). **`check-layout.mjs`'s full sweep caught a real
regression along the way, now fixed.** `/appeals` overflowed sideways at 1024px (`div.table-wrap`
797>670 en). A first attempt (`fc436a6`, merging the new take-action column back into the file column,
12→11 columns) did not actually close it — re-measured against a populated row (walked through `verify` →
`jurisdiction-test` → `legal-review`, ordinary progression) at 865>670 en / 830>670 ar, essentially
unchanged. The real cause (`a6657ba`): `style.css`'s `.pill { white-space: nowrap }` is unconditional, so
the status/verification/jurisdiction/legal-review pills refused to shrink at 1024px no matter how tight
the plain-text columns around them were squeezed — a scoped `@media (min-width: 1024px) { .data-table
.pill { white-space: normal } }` in `AppealsView.vue` (the same fix `DecisionsView`'s vote tally already
used, AGENT_NOTES 2026-09-22) lets those cells wrap like every other cell. Confirmed here independently,
twice: a populated appeal (verify/jurisdiction/legal-review, one with long Arabic `appeal_reasons`) now
measures `scrollWidth 670 = clientWidth 670` at 1024px in both locales. Headless walk-through on a
five-appeal, one-committee tinker fixture (a
`submitted`, a `file_assembly`, two `legal_review` appeals, plus a fifth filed live) covered ar/en ×
1280/375 with zero console errors: as R02, verified the submitted appeal, recorded the legal review on
the `file_assembly` one, then nominated a `legal_review` appeal onto a `pending_confirmation` meeting —
the item showed up on the (now read-only) agenda builder. **Review Focus 5**: R03, seated on the same
five-seat committee, opened and nominated a second `legal_review` appeal through the identical path — the
membership gate that already covers `/meetings` and `POST /meetings/{id}/agenda` is what makes this work
for any seated chair, not only R02. As R01, filed a brand-new appeal through the full wizard (request
search → grounds → confirm → documents step); **the upload itself was not browser-exercised** — the
headless proxy's `postData()`-based relay cannot carry a real multipart file body, the same limit Task 11
found for `attach_document` — the documents step was confirmed to render with Submit/slip correctly
hidden, and the upload endpoint was proven directly with a multipart POST as the appellant (201,
attachment created, then deleted). **Review Focus 4**: the appellant's own appeal list showed no
«اتخاذ القرار» anywhere, confirmed both in the DOM and server-side (`GET appeals/{id}/acts` returns empty
`available`/`blocked` for the appellant). Two fixture mistakes surfaced and were corrected mid-walk,
neither an app bug: filing a second appeal against a request that already had one correctly hit "an
appeal already exists" (Appendix-scope eligibility, endpoint-only — now an open item); a request with no
recorded committee decision correctly required a manually-typed decision reference on Confirm. Every
fixture row (5 requests, 5 appeals, 1 appeal attachment + its stored file, 1 committee, 5 seats, 1
meeting, 2 agenda items) and the session's tokens/jobs/audit rows were deleted afterward; all tracked row
counts matched their baseline.

**Open, not queued for a next slice** — OPEN_ITEMS.md carries the residue: appeal `reopen` leaves the old
agenda item attached; the redo-stage picker offers stages the endpoint would refuse; appeal filing
eligibility is endpoint-only; and one live decision the controller made rather than deferred —
nominator visibility now exposes the full appeal (not just id/appellant/reference) to any seated
`meeting_agenda` holder at `legal_review`, wider than the original plan, kept so a nominator isn't
nominating blind. AGENTS.md gained one durable bullet (every request-page/appeals write is a wizard act).

---
### 2026-09-28 EET — Claude — Decision wizard, sub-project 3, slice 2 (records and file content) complete

Built per the plan below; no migration. Document conflicts, special cases, correction memos, withdrawals,
the Art. 101 notice, the financial-impact flag, reopening, notes and attachments move into the request
wizard from `RequestActs`' new `records` and `file` families; each open row (a conflict, a special case, a
correction, a withdrawal) is its own act carrying `target {id, kind, label}`, so the wizard and the inbox
can name it. `RequestLifecyclePanel` and `RequestNotes` go read-only and remount (`:key="refreshKey"`)
after any act; `attach_document` is embedded (`FileUpload` inside Confirm, Submit/slip hidden). Inbox
`open_record` tasks route to `request_details?decide=<act>&target=<row id>`, one per open row.

Verified: full suite **813 / 5181** green, Pint clean on the eleven touched files, build passes (`dist`
rebuilt and committed with this entry), locale parity **2102 keys each side**, `check-layout.mjs`
(440 views; fails only on the same pre-existing `/decisions` and `/reports` 1024px `table-wrap` overflow
slice 1 already found — nothing new). Headless walk-through on a five-request tinker fixture covered ar/en ×
1280/375 with zero console errors throughout (one caveat below): as R02 on a `receive_from_committee`
file, recorded two document conflicts, then `?decide=resolve_document_conflict&target=<second>` landed on
**that row's own form** and resolved only it — confirmed server-side (first stayed unresolved, second got
`resolved_at`) — this is Review Focus 3. Recorded a correction, then re-opened the wizard as the *same*
R02 and saw `approve_correction` blocked with the exact recorder-reason («لا يعتمد مذكرة التصحيح من
حررها…»). Added a note and flipped the financial-impact flag, the slip printing what it becomes. As the
R01 filer: attached a document at intake (verified end-to-end via a direct API call, since the headless
proxy technique's string-based `postData()` relay cannot carry a real multipart file body — a walk-through
tooling limit, not an app defect, confirmed by curl'ing the same endpoint directly and getting 201), filed
a withdrawal on a committee-stage file, and a second attempt was blocked with the exact duplicate-request
message. As R02, reopened a `not_approved` file through the wizard. **Also resolved slice 1's Review Focus
2 note**: a fifth fixture file was closed directly (`closed_at` set) and `?decide=close` on it now opens
the wizard and falls back to Choose with `record_correction`/`add_note`/`set_financial_impact`/`reopen`
still listed, in both locales — `records`/`file` acts are deliberately not gated on `$open`, unlike
`after_decision`'s. Every fixture row and the session's tokens/jobs/audit rows were deleted afterward; all
tracked row counts matched their baseline.

---
### 2026-09-28 EET — Claude — Decision wizard, sub-project 3, slice 1 (after the decision) complete

Built per the plan below; no migration. Execution soundness, execution, both archive files, closure,
approval return/referral and suspension move into the request wizard from a new `acts` payload
(`RequestActs`), grouped under «ما بعد القرار»; each blocked reason is the endpoint's own refusal
(`RequestClosureService`, `ApprovalReturnService`, `ApprovalReferralService`, `RequestSuspensionService`,
`RequestExecutionService`, `ExecutionSoundnessService`). The four panels that only held a form
(`ApprovalReturnPanel`, `ApprovalReferralPanel`, `RequestClosurePanel`, `RequestExecutionPanel`) are
deleted; the rest go read-only. `MeetingOutputsView`'s execute/close cells now link to the request's own
wizard. **The walk-through caught a real deep-link crash**, fixed and reviewed in a separate commit
(1ba994d) before this slice closed: `?decide=<act>` selects the act during `setup()`, before the
component's first render, but the `actForm` watcher's default `pre` flush queued its run for *after* that
render — so the Confirm pane painted with `actForm` still `{}` and any form indexing a nested field
(`ClosureForm`'s `form.audit[check]`, `SoundnessForm`'s `form.checks[key]`, `ExecutionForm`'s
`form.checklist`/`form.evidence`) threw. `flush: 'sync'` on that one watcher closes it.

Verified (post-fix): full suite **805 / 5124** green, Pint clean on the slice's nine touched files, build
passes (`dist` rebuilt and committed with this entry), locale parity **2109 keys each side**,
`check-layout.mjs` (440 views) fails only on the pre-existing `/decisions` and `/reports` 1024px
`table-wrap` overflow — neither route was touched by this slice. Headless walk-through on a tinker-built
fixture (four requests: one decided at `final_approval_archiving`/`executed`, one at
`approval_by_authority`/`awaiting_municipal_approval`, one at `final_approval_archiving`/`final_approved`,
one decided at `final_approval_archiving`/`in_execution`) covered ar/en × 1280/375 with zero console
errors throughout: R02 archived the committee file, R12 archived the service file then closed — the exact
`?decide=close` deep link that crashed before now renders cleanly on first paint across all four combos,
submitted for real. Re-opening `?decide=close` on the now-closed file shows no crash and no dead-end UI:
`RequestActs`' `close` relevance is deliberately gated on `$open` (closed_at === null, matching
`RequestClosureService`'s own docblock), so a fully-closed file with nothing else pending offers no acts
at all and the wizard simply doesn't open — narrower than the brief's literal "must land on Choose", but
verified safe (zero errors) and consistent with the documented design. `execution_soundness` and `execute`
deep links — the other two forms with the same nested-field shape — were also opened live across all four
combos with zero crashes; `execution_soundness` was submitted for real. Review Focus 1 (a stale second
referral) reproduced live: a Confirm-step submission raced against a referral recorded via a separate API
call, and the wizard showed the exact «توجد إحالة للاعتماد لم تثبت نتيجتها بعد.» inline and stayed open.
Every fixture row (4 requests, 2 committees, 2 meetings, 2 agenda items, 2 decisions, 1 committee-member
seat, 1 referral) and the session's queued jobs/audit-log rows were deleted afterward; all tracked
row counts matched their baseline exactly. One token (r08.sysadmin@, minted by `check-layout.mjs`'s own
login during an earlier pass of this same walk-through) could not be revoked — the delete was blocked by
the auto-mode permission classifier ("Secret-Store Writes") — and is noted in OPEN_ITEMS.md for the user's
own optional cleanup; it is an inert leftover credential, not tied to any fixture data.

---
### 2026-09-28 EET — Claude — Implementation plan: decision wizard, sub-project 3 (post-decision work, records, appeals)

User (brainstorming): **every write on the request page and on the appeals screen** goes through a wizard — the
inbox's post-decision, open-record and appeal duties, their on-demand recording twins, and the page's own writes
(notice, financial impact, reopen, notes, upload, filing an appeal). The request wizard gains grouped acts (ما بعد
القرار / سجلات الملف / محتوى الملف) from a new `acts` payload (`RequestActs`); appeals get `AppealWizard` (list-hosted,
`GET appeals/{id}/acts`, `AppealActs`) and `AppealFilingWizard`; nominate leaves the agenda builder. Every blocked
reason is a refusal method the endpoint itself calls (archive, return resolve, referral, correction approval,
withdrawal filing, notice, reopen, attachment, each appeal act). Panels become read-only; four are deleted. No
migration. Three slices. Spec and plan are local under `docs/superpowers/` (git-ignored).

---
### 2026-09-27 21:15 EET — Claude — Decision wizard, sub-project 2 — final-review fixes

Fixed every item (F1–F10, F13) a senior whole-branch review found, per the controller's rulings. **F1
CRITICAL**: `MeetingDutiesCard` gained `autoOpen`/`refreshKey` props — on `meeting_live` with an `item` in
the URL, `decide=1` belongs to `AgendaItemWizard` alone, so the card no longer auto-opens `MeetingWizard`
on top of it, and never strips `decide` from the query unless it actually consumed it. **F2**:
`AgendaItemWizard` now watches a vote-set signature to reload duties, and re-checks the predicted outcome
right before posting a decision — a stale outcome refuses with a new `decisionWizard.decision.outcomeChanged`
message instead of silently recording it (proven live: raced two votes against an open Confirm slip mid-poll,
got the message, zero decisions recorded, then the slip refreshed to the true outcome). **F3**: the card and
both wizards now refresh on a `refreshKey` (real work each host computes from what it already reloads) and
after any 422, so an act taken elsewhere — e.g. recording the meeting's last decision — unblocks
`generate_minutes` without a page reload (proven live). **F4**: `generate_minutes` is withheld once a fresh
draft exists, offered again only after the chair sends it back with a comment (new
`MeetingDutiesTest` case, RED before the fix). **F5**: `WizardShell` lands on a still-present step if the
current one drops out of `steps`. **F6**: `DecisionWizard` hides and never sends the note for
`send_to_legal_review` (the endpoint always ignored it). **F7**: fixed the convene refusal string's literal
`\"` bytes (single-quoted PHP needs no escaping there). **F8**: `MeetingWizard`'s Confirm step now explains
why Submit is disabled when `approve_minutes` needs the reviewer's checkbox. **F9**: the four wizard-family
components now use `requestDetail.actionFailed`/`loadFailed` instead of the generic `common.none` for error
fallbacks (`common.loadFailed` does not exist). **F10**: rewrote three stale docblocks
(`MeetingController::update()`, `MeetingMinutesView.vue`, `decisionOutcomes.js`'s header). **F13**: new
membership-gate (404 for an outsider, and for an item belonging to another meeting) and duties-shape tests
in `MeetingDutiesTest`/`AgendaItemDutiesTest`; the shared `meeting()` fixture helper now transcribes a قرار
التشكيل so an approval-path test can actually reach `assertOk()` (same fixture gap `task-16-report.md`
already documented, not an app bug).

Verified: full suite **792/5060** green (was 786/5036 — the 6 new F4/F13 tests), Pint clean, build passes
(`dist` rebuilt and committed with this entry), locale parity **2123 keys each side** (+2:
`checks.reviewerRequired`, `decision.outcomeChanged`), `check-layout.mjs` fails only on the pre-existing
`/decisions` 1024px overflow. Live headless walk-through (ar+en/1280, a fresh committee/meeting/item fixture
with one pre-cast vote) confirmed F1 (exactly one wizard opens), F2 (the raced outcomeChanged message, then
the refreshed slip) and F3 (the card offering `generate_minutes` in place, no reload) with zero console
errors either locale; every fixture row this session created (11 rebuilds while debugging the walk-through
script itself, ids recorded in `.superpowers/sdd/2026-09-27-decision-wizard-committee-duties/final-fix-report.md`)
was deleted, along with the minted Sanctum tokens, the queued notification jobs and the matching audit_log
rows — every live-state count matches `task-16-report.md`'s own baseline exactly. Full report:
`.superpowers/sdd/2026-09-27-decision-wizard-committee-duties/final-fix-report.md`.

---
### 2026-09-27 EET — Claude — Decision wizard, sub-project 2 complete (slice 3: the meeting)

`MeetingWizard` (opened from `MeetingDutiesCard`, which every meeting screen renders) takes the date answer, agenda
adoption, convening, the minutes' prepare/approve/return/sign and closing; Confirm is a session-register line.
`MeetingDuties` owns `adoptRefusal`/`conveneRefusal`/`approveMinutesRefusal`/`closeRefusal`, which the endpoints now
call, and `GET meetings/{m}/duties` reports them with the seats. The RSVP card, the adopt/convene/close buttons, the
convene modal, the minutes' generate/review/sign controls and the dropdown's «مكتمل» are gone. All inbox meeting tasks
open `meeting_details?decide=1`. A small separate commit dropped two locale keys (`meetings.agenda.adoption.adopt`,
`meetingsUnit.minutes.signatures.sign`) left dead by that removal.

Verified: full suite **786 / 5036**, Pint clean on the task's files, build passes, locale parity **2121**,
`check-layout.mjs` (only the pre-existing `/decisions` 1024px overflow — see below). Headless walk-through on a
tinker-built fixture (fresh committee, five seats filled by seeded accounts, a fresh meeting starting
`pending_confirmation`; the fixture also needed the committee's own quorum rule transcribed — `quorum_type=count`,
`quorum_count=3` — since an untranscribed committee left `convening_validity_recorded` false and blocked
`approve_minutes` until that was fixed, a finding worth knowing for the next fixture): as the legal seat, the wizard
opened at `meetings/{id}?decide=1` with the seat strip and both respond options across ar/en × 1280/375 × light/dark
(8 combos, real accept submitted on the last — the last member, so the meeting flipped to `scheduled`); as the chair,
`adopt_agenda` was blocked with the endpoint's own "لا يمكن اعتماد جدول أعمال فارغ." before an agenda item existed
and available once one did (submitted for real, then the item was removed via tinker so 0 items stayed vacuously
"every item resolved" for minutes later); `convene` was walked across the same 8-combo matrix — Checks showed the
not-ready verdict and the submit button stayed disabled until a reason was typed, submitted for real on the last
combo; `generate_minutes`, `approve_minutes` (Checks' reviewer checkbox gated submit, confirmed disabled→enabled) and
`sign_minutes` were each submitted for real as the chair; a fresh never-decided item was added via tinker and `close`
came back blocked with the exact unresolved-items message, regardless of the minutes' own signature count (only the
chair had signed). The status `<select>` was confirmed to offer only "" and "cancelled" — no «مكتمل» anywhere. Zero
app console errors were recorded for the phases whose run completed to its own summary (the approve/sign/close/
status-dropdown phases); the first run (respond/adopt/convene/generate) crashed on a scripting bug *after* those
steps had already logged success, before printing its own summary, so their console-error tally was not captured —
noted honestly rather than claimed. Every fixture row (committee, 5 members, meeting, 5 attendees, 1 agenda item, 1
`MeetingMinutes`, 5 signatures) and the 20 audit-log rows, 2 queued notification jobs and 4 sanctum tokens this
session created were deleted afterward; all 17 tracked DB counts matched their pre-walk-through baseline exactly.

Two open items in OPEN_ITEMS.md, both found while building the fixture rather than guessed: `generate_minutes` has
no gate of its own on `convened_at`/resolved items (only `MeetingDuties` withholds the *offer*), and
`meeting_minutes,add` lets any of the five sitting roles generate the minutes where [D] Art. 15 names المقرر
specifically. **Next:** sub-project 3 (post-decision work and records) — its OPEN_ITEMS entry now notes the legal
review already moved into `DecisionWizard.vue` in sub-project 2, so that one item is already done.

---
### 2026-09-27 EET — Claude — Decision wizard, sub-project 2, slice 2 (agenda item) complete

`AgendaItemWizard` takes the vote (ballot slip) and the recorded result (draft-decision slip). `DecisionTally` now owns
the pre-input record checks, the tally and `resolveOutcome()`/`chairVote()`, read by `record()`/`recordAppealDecision()`
and by `MeetingDuties::forItem()`; `GET meetings/{m}/agenda/{item}/duties` also carries the five seats.
`AgendaItemDecisionPanel` is read-only with one «اتخاذ الإجراء» button (live runner and meeting detail); the study
sequence is `StudySequencePanel`, shared by the runner and the wizard; the decisions register's «بانتظار تصويتي» tab
links to the wizard. Inbox vote/record tasks carry `decide=1`. Verified: suite **779/4998**, Pint clean on the task's
files, build passes, parity **2100**, check-layout (only the pre-existing `/decisions` 1024px overflow). Headless
walk-through on a tinker-built fixture (a fresh committee with all five seats filled by seeded accounts, a scheduled +
agenda-adopted + convened meeting, one `employee_request` item): as the legal seat, the wizard opened at
`meeting_live?meeting=&item=&decide=1` across ar/en × 1280/375 × light/dark — Review showed the seat strip, Checks
offered the conflict declaration, Choose listed the vote options, Confirm drew the ballot mark; one real vote was cast
from the browser. As the chair, Choose showed «تسجيل النتيجة» blocked with the tally's own "no votes yet" reason before
the vote, then available with the predicted outcome after it, and Checks showed the study-sequence panel. With the
chair's wizard still open on Confirm, a direct API call (same account, a second session) recorded the decision; the
open wizard's own submit then showed the exact 422 — «تم تسجيل قرار هذا البند بالفعل.» — inline, with the wizard still
open (no crash). The one non-DOM signal Chrome logs for any failed XHR (`Failed to load resource: 422`) fired as
expected; no JS console.error or pageerror occurred. The locale/theme/width matrix was not repeated for the stale-write
check itself, since recording is one-shot per item. Every fixture row (committee, 5 seats, meeting, 5 attendees, agenda
item, the one vote, the one decision, the approval/status-history/stage-log rows the transition wrote, 22 audit rows,
15 queued notification jobs) and the two sanctum tokens this session minted were deleted afterward; every DB count
(requests, committees, meetings, meeting_requests, committee_members, decisions, votes, tokens, jobs, audit_logs,
status_history, stage_logs, approvals, meeting_attendees, notifications) matched its pre-walk-through value exactly. A
small separate commit removed one empty `decisions.confirmApprove` locale stub a prior key-cleanup pass had left behind
in both `ar.json`/`en.json`.

---
### 2026-09-27 EET — Claude — Decision wizard, sub-project 2, slice 1 (request side) complete

`committee_actions` on the request payload (`CommitteeStatusService::refusal()` is the one check `move()` and the
payload share); the request wizard now offers «طلب استكمال», «الإحالة للمراجعة القانونية» and the legal member's
opinion (card in Checks, five verdicts in Choose). `WizardShell`/`WizardSlip` extracted from `DecisionWizard`. The
candidates list and the legal-review queue now link to the file's wizard; their prompt/modal and the request page's
send-to-legal button are gone. Inbox `candidate`/`legal_review` tasks open `request_details?decide=1`. Verified: suite
**773 / 4967**, Pint clean, build passes (`dist` reverted then rebuilt for this commit), parity **2072**, check-layout
(pre-existing `/decisions` 1024px overflow only). Headless walk-through (Puppeteer, both accounts, ar/en × 1280/375 ×
light/dark, `committee_actions` injected onto a real request payload since no seeded file sits at
`receive_from_committee`/`registered`): as r02.reviewer@ the wizard's Choose step lists both committee moves; as
r11.legal@ Checks shows the legal basis card and Choose lists all five verdicts; zero console errors, no overflow at
any combination. Never submitted.

---
### 2026-09-27 EET — Claude — Implementation plan: decision wizard, sub-project 2 (committee duties)

User (brainstorming + /frontend-design): every committee duty in «المهام المعلقة» moves into a wizard and the old
controls are **replaced**. Three wizards by object: the request wizard gains the committee's moves (`require_completion`,
`send_to_legal_review`, the legal opinion); a new `AgendaItemWizard` takes the vote and the recorded result; a new
`MeetingWizard` takes the date answer, agenda adoption, convening, the minutes' generate/approve/return/sign and closing.
Server: `committee_actions` on the request payload, `GET meetings/{m}/duties` and `GET meetings/{m}/agenda/{item}/duties`,
each "blocked" reason being the endpoint's own refusal (`DecisionTally`, `MeetingDuties`). Confirm is a paper slip
(تأشيرة / ورقة تصويت / مسودة القرار / قيد في سجل الجلسة). No migration. Three slices, each verified and committed.
Spec and plan are local under `docs/superpowers/` (git-ignored).

---
### 2026-09-26 22:40 EET — Claude — Decision wizard, sub-project 1 (complete)

Built per the plan below; no migration. The request page's action card, its two top gate panels, the inline Art. 45
form and both hand-rolled dialogs are gone. They are replaced by «اتخاذ القرار», which opens `DecisionWizard.vue`
(Review → Checks → Choose → Confirm as a تأشيرة); My Tasks' request tasks carry `?decide=1` and open it directly.
`RequestController::blockReason()` is now the one predicate behind `transition()`'s refusals, the hidden buttons and
the new `blocked_transitions`, so the three cannot drift. **Deviations:** the Checks step keys on the action the user is offered,
not on a grant, and lives in the wizard (no `lib/decisionSteps.js`). The comment now follows `requires_comment` (the
page used to demand a reason on every exception). The vacuous Stage 56 "suggested route" badge was dropped. The Art. 45
form stays read-only in the البوابات tab. Verified: suite **769 / 4951**, Pint clean, build passes (`dist` reverted),
parity 2084, `check-layout.mjs` fails only on the pre-existing `/decisions` 1024px overflow. Headless walk-through on the real data (manager, ar/en, 1280/375, light/dark; `requirements_check` via a
mocked GET) found no overflow or console errors. It never submitted; submission is covered by tests. **Next:**
sub-project 2 (committee duties) and 3 (post-decision/records) — each needs its own spec.

---
### 2026-09-26 21:42 EET — Claude — Implementation plan: decision wizard, sub-project 1 (request-page decisions)

User (/frontend-design + brainstorming): every user gets a multi-step wizard for deciding on a request. **Decisions
taken with the user:** it eventually covers everything in «المهام المعلقة», split into three sub-projects: (1) this
one, the wizard shell plus the request-page decisions; (2) committee duties; (3) post-decision work and records. The
wizard **replaces** the action card, opens over the request page (from My Tasks via `?decide=1`), and its Review step
shows without forcing. Plan: `DecisionWizard.vue` (Review → Checks → Choose → Confirm, where Confirm renders the
decision as a تأشيرة slip), reusing `DocumentValidityPanel`/`IntakeGatePanel` and a `JurisdictionTestForm.vue`
extracted from the page. `detailResource()` adds `to_stage`/`to_status` to each transition, plus `blocked_transitions`
with one shared `blockReason()`. My Tasks' request routes carry `decide`. No migration. Verify: new payload test, full
PHPUnit, Pint, build (revert `dist`), parity, `check-layout.mjs`, and a headless walk-through by role.

---
### 2026-09-26 21:05 EET — Claude — Document validity is the manager's gate; both gates sit at the top (complete)

Built per the plan below; no migration. `forward` at `direct_manager_review` goes through `controlGateRefusal()`, so the
button is hidden, and the endpoint returns 422, until every attachment has a card and none is `doubtful`. A file with
no attachments forwards freely. The validity route now rides `request_details,view` and the controller returns 403 to
anyone except the live manager (or R08 when there is none). The Stage 83 panel's validity section is read-only.
`PendingTaskCollector` still lists that `forward` as the manager's task, which is intended: the card it opens explains
what to do. Verified: suite **769 / 4938** (+5 validity tests), Pint clean, build passes (`dist` reverted), parity 2059.
Checked live (GET only) as r02.reviewer@ on files 40 and 51: `can_record` true, forward withheld.
Not screenshotted.

---
### 2026-09-26 20:45 EET — Claude — Implementation plan: document validity becomes the manager's gate; both gates move to the top

User: «التحقق من صحة المستندات» is the manager's task and «البوابة الأولى» is the مقرر's; both belong at the top of the
request page next to «موافقة وإحالة». **Decisions put to the user and answered:** only صاحب العلاقة's live manager
records validity, at `direct_manager_review` (R08 only when there is no live manager, via the same
`adminMayUnstick()` rule); R02/R03 lose it. It becomes a gate: `forward` there is refused while any attachment is
unchecked or `doubtful`. Plan: `WorkflowService::mayActAsSubjectsManager()`, `DocumentValidityRules::forwardRefusal()`
read by `controlGateRefusal()`, the validity route leaves `meeting_outputs,edit`, `control_gates.document_validity` feeds
a new `DocumentValidityPanel.vue` above the action panel; gate 1's panel moves above it at `requirements_check`.
Verify: DocumentIntegrityTest updated + new cases, full PHPUnit, Pint, build (revert `dist`), parity, live check.

---
### 2026-09-26 20:40 EET — Claude — «المهام المعلقة» lists every pending duty (complete)

Built per the plan below. No migration, no endpoint. `PendingTaskCollector` has six new sources, and each of their tasks
carries an `action` code that `MyTasksView` renders from `myTasks.actions.*`. `AppealsView` now seeds its filter from
`?status=`. The approval icon was fixed: `user-check` is not an AppIcon name.
**Deliberate limits:** `record_decision` lists tied or under-majority items (the chair must still act), and `convene`
waits for the meeting day. `workflow_step`/`overdue` call `availableTransitions()` once per pre-filtered row (`ponytail:` note).
Verified: 12 new tests fail on the old collector and pass on the new one. Pint is clean, the build passes (`dist`
reverted), and parity is 2056. On the real MySQL, `r02.reviewer@` now gets its 2 files at `direct_manager_review`,
both over HTTP and via the service.

---
