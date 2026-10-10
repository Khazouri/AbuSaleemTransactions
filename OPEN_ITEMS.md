# Open items

Points left unfinished, unclear, or waiting on a decision. One entry per item, newest
on top. When an item is resolved, mark it **Resolved (date, stage/commit)** in place
rather than deleting it, so the reasoning stays readable.

Format:

```
### <short title> — <source stage> — opened YYYY-MM-DD
What is open, why it was left, and what would close it.
```

---

### The stage modal scrolls sideways on a phone once a step has an attachment item — workflow designer slice 4 — opened 2026-10-10
Found in slice 4's headless walk. At 375px, «تعديل المرحلة» on a step with an attachment item is 1342px wide in Arabic and
1914px in English inside a 357px modal (`.modal` scrollWidth), so every field is cut off. Cause:
`ChecklistEditor.vue`'s `<fieldset class="checklist">` keeps the browser's default `min-inline-size: min-content`, and the
document `<select>` (84 options, the longest 180 characters) is as wide as its widest option. The page itself does not
scroll, so `check-layout.mjs` cannot see it. At 1280px the modal fits.
**To close:** `min-inline-size: 0` on that fieldset (and/or `max-inline-size: 100%` on the select), then re-measure the
modal at 375px in both locales.

### A custom step hides the file from the manager who forwarded into it — workflow designer slice 4 — opened 2026-10-10
Seen in slice 4's walk: once the manager forwards into a custom step, their reload of the request is a 404.
`RequestVisibility` gives صاحب العلاقة's manager the file at `direct_manager_review` (their own rule) and at
`receive_and_register` (Stage 98) only, by stage code. A custom step between the two matches neither. This is not a
slice 4 regression; any slice 3 custom step after the manager does the same. **To close:** decide whether a manager stays
«مطلع» on a custom step (one more clause keyed on the custom stage's version), or accept the gap.

### Checklist items can't be reordered in the editor — workflow designer slice 4 — opened 2026-10-10
`ChecklistEditor` only adds and removes items, so changing the order means removing items and adding them again. Each
re-added item gets a new key. That is harmless today, because only a draft is edited and no request runs on a draft.
**To close:** up/down buttons on each item. Keys stay stable, because the writer keeps a sent key.

### Earlier laps' checklist cards are kept but not shown — workflow designer slice 4 — opened 2026-10-10
A return and re-arrival at a custom step starts a fresh `request_step_reviews` card, and the old one stays in the table.
Only the current lap's card is shown (plan Deviation 8). **To close:** list earlier cards on the request page, if anyone
asks for it.

### Workflow designer slice 3: review leftovers — opened 2026-10-10
These were deferred from the final review:
- **Responsibility at a custom step:** it names its role in Arabic only, and names nobody when its exits name no role.
- **Two tabs:** a stale second tab's PUT silently drops what another tab added, because there is no `updated_at`/409
  check.
- **Orphan workflow:** discarding a new workflow's only draft leaves its `workflows` row behind, and the code stays taken.
- **No confirmation:** deleting a step or an outcome happens without one.
- **First position:** «بعد المرحلة» has no "first" option.
- **Stale selection:** the selected edge goes stale after a built-in rule is redirected.
- **Field name:** report entries use `transition_id` where spec §5 says `transition_ref`.
- **Unchecked status:** the validator ignores `required_status_id`. A custom outcome that sets a status the next
  built-in rule won't accept (`register` needs `routed_to_hr`) strands the file with no error. The default «لا تتغير» is
  safe.

**To close:** each is small. The status check belongs with slice 5's validator work.

### A custom outcome does not refuse interested parties — workflow designer slice 3 — opened 2026-10-10
`WorkflowService::actorMayUse()` refuses the filer and صاحب العلاقة only on `approve`. A custom step routed to a role the
filer holds lets them move their own file past it. Spec §6 ties the wider refusal to `requires_checks` rules.
**To close (slice 4):** apply `actorIsAnInterestedParty` to `requires_checks` rules, as the spec says, and decide whether
every non-creator custom outcome should refuse too.

**Resolved (2026-10-10, workflow designer slice 4)** for `requires_checks` rules: `actorMayUse()` keeps the filer and
صاحب العلاقة off them, and `transition()` refuses with `WorkflowTransitionException::cannotCompleteOwnStep()`. **Still
the user's call:** whether *every* non-creator custom outcome should refuse interested parties too. Slice 4 deliberately
did not widen it (plan Deviation 7); a yes is a one-line change in `actorMayUse()`.

### The designer has no layout persistence or dragging — workflow designer slice 3 — opened 2026-10-10
A custom step's place is its `order_no` (picked with «بعد المرحلة»), and the canvas lays the chain out by that order. Spec
§3's `workflow_stages.position` and dragging were not built. **To close:** add the column and draggable nodes, if the
computed layout proves unreadable once drafts carry many custom steps.

### Built-in steps and rules are locked in the designer — workflow designer slice 3 — opened 2026-10-10
`WorkflowDraftWriter` refuses removing, editing or reordering a built-in stage, and removing or re-homing a built-in
rule; only a built-in rule's destination may change. Validator errors E8–E11 are therefore unreachable today. **To
close (slice 5):** unlock the non-anchor built-ins and add those checks.

### The user guide does not describe «مسارات العمل» — workflow designer slice 2 — opened 2026-10-10
`USER_GUIDE.ar.md` has no section on the Workflows screen or on «عرض مسار العمل» on a request. It was left out
because slice 2 is read-only, and the screen is worth documenting once slice 3 makes it editable. **To close:** a
guide section, plus a PDF rebuild (`scripts/build-guide-pdf.php`), when slice 3 lands.

### Role test plans' Appendix A and screen counts are stale — workflow designer slice 2 — opened 2026-10-10
`TEST_PLAN.roles.md`/`.ar.md` Appendix A has no `committee_roles` row (that screen was added on 2026-10-04). The
plans also say 36 screens (29 sidebar entries for R08), while the seeder now has 38. Slice 2 added only the
`workflow_designer` row and one R08 check; it did not bump counts that were already wrong. **To close:** re-derive
Appendix A and every count from `ScreenSeeder` + `ScreenRolePermissionSeeder` + the seat gate by script, as was done
on 2026-10-02.

### check-layout.mjs cannot check /requests/create — noticed in workflow designer slice 2 — opened 2026-10-10
The check logs in as R08, and R08 has been barred from filing since 2026-10-07. The intake screen therefore
redirects to `/dashboard` and is reported "not checked" at every width, which makes the run exit 1 even with no
overflow anywhere. **To close:** let the script use an R01 account for `/requests/create`, or skip that route on
purpose.

### npm audit: high advisories in vue and axios — noticed in workflow designer slice 2 — opened 2026-10-10
`npm audit` in `frontend/` reports `@vue/server-renderer` < 3.5.42 (pulled in by vue) and axios ≤ 1.19.0. Both
predate slice 2; `@vue-flow/core` added none. The SPA does no server rendering, so the vue advisory does not reach
it, and the axios ones are mostly Node-adapter issues. **To close:** `npm audit fix`, then a build and a browser
check, on its own branch.

### Delete of a workflow version orphans its requests' stages — workflow designer slice 1 — opened 2026-10-10
Deleting a version cascades to its stages (`workflow_stages.workflow_version_id` is `cascadeOnDelete`) and
`requests.current_stage_id` is `nullOnDelete`, so a deleted version's requests lose their stage and
`Request::stageByCode()` silently falls back to the published version (the `reopenAtStage` guard skips null stages).
**To close (slice 3):** a new migration making that FK (and `workflow_versions.workflow_id`) `restrictOnDelete`, the draft
DELETE removing its stages explicitly, and optionally `stageByCode` throwing when `current_stage_id` is set but resolves
no version.

**Resolved (2026-10-10, workflow designer slice 3).** The only delete path is `DELETE workflow-versions/{v}`, which
refuses anything but a draft (422) and removes the draft's rules and stages explicitly. No request runs on a draft, so
nothing is orphaned. The FK was deliberately left `cascadeOnDelete`: changing it needs a SQLite table rebuild and guards
only a hand-run SQL delete.

### Report stage grouping across versions — workflow designer slice 1 — opened 2026-10-10
`ReportMetricsService::byStage()` groups by `workflow_stages.order_no, code, name_ar, name_en`, so an unchanged clone
merges into the same bucket. Buckets split only when a version renames or renumbers a stage, and `orderBy(order_no)` can
then interleave versions. **To close (slice 3):** group by stage code alone and take the label from the published version.

**Resolved (2026-10-10, workflow designer slice 3).** `byStage()` groups by `workflow_stages.code` alone, taking
`MIN(order_no)` for the order and one label (`WorkflowDraftRuntimeTest::test_the_stage_report_groups_one_code_across_versions`).

### Per-version WorkflowStageCount — workflow designer slice 1 — opened 2026-10-10
`RequestResource`'s `stage_progress` divides by `WorkflowStageCount`, which slice 1 pins to the published Standard
version. A request on a version with a different number of stages shows a wrong ratio. **To close (slice 3):** count
the stages of the request's own version.

**Resolved (2026-10-10, workflow designer slice 3).** `WorkflowStageCount::total($stageId)` counts the stage's own
version; `RequestResource` passes the current stage. Reports still divide by the published Standard version.

### Publish must retire and publish in one transaction; clone should lock the workflow row — workflow designer slice 1 — opened 2026-10-10
A moment with no published version makes `Workflow::standard()->publishedVersion` null, and the next read is a PHP
Error. Separately, two concurrent clones race on `max(number)+1` (the unique key turns that into a 500, not
corruption). **To close (slice 3):** publish retires the old and publishes the new inside one transaction, and the
clone endpoint takes `lockForUpdate()` on the workflow row.

**Resolved (2026-10-10, workflow designer slice 3).** `WorkflowController::publishVersion()` retires and publishes in
one transaction under `lockForUpdate()` on the workflow row; `cloneVersion()` takes the same lock and refuses a second
draft.

### redo-stage-options?request_id= has no visibility check — workflow designer slice 1 — opened 2026-10-10
The endpoint uses `request_id` only to pick the version, so it is an existence probe for request ids (no data about
the request is returned). Low risk. **To close:** apply `RequestVisibility` to the id, or ignore ids the caller
cannot open.

### A rejected «مستند آخر» file cannot be replaced — per-file document review — opened 2026-10-09
On both document gates a «غير معتمد» file blocks until a newer upload of the **same document type** replaces it
(`DocumentReview::attachments()`). A file typed «مستند آخر», or one with no type, has no type to be replaced under,
and attachments are never deleted, so once rejected it holds the gate. The reviewer's only way out is to mark it
approved after all. **To close:** decide how such a file leaves the review: a «superseded by» link chosen at upload,
removal by the reviewer, or a third verdict such as «مستبعد».

### HR's register no longer checks the service-file rows are present — per-file document review — opened 2026-10-09
HR's «تجهيز الملف الوظيفي» card used to answer each الملف الوظيفي row of Appendix 57's matrix. Since 2026-10-09 it
judges the files attached instead (user decision). The matrix is still enforced at intake (the submitter's rows) and
before the agenda (`DocumentCompletenessService::refusalForRequest()`), so a missing service-file document is now
first caught when المقرر tries to put the file on an agenda, not at register. **To close:** confirm that is
acceptable, or add the matrix check back beside the per-file review at `register`.

### The manager's return and cancel still require a reason on the real database — optional note — opened 2026-10-07
`WorkflowTransitionSeeder` now seeds `return_to_employee` and both manager `cancel` rows with `requires_comment = false`,
but Homestead's MySQL was unreachable, so the real database still demands a reason for them. The removed
document-validity gate is code and is live already. **To close:** the same
`php artisan db:seed --class=WorkflowTransitionSeeder` as the entry below; one run closes both.

### R08's `submit` row still on the real database — admin may not file — opened 2026-10-07
`WorkflowTransitionSeeder` now deletes R08's `submit` exception at `receive_from_municipality`, but Homestead's MySQL
was unreachable, so the real database still has it: until then an admin can push an employee's returned file
forward. The intake block itself is code (`User::screenPermissions()`) and is already live. **To close:**
`php artisan db:seed --class=WorkflowTransitionSeeder` (safe, it does not touch the permission matrix).

### `request_intake,approve` still granted to R02/R05 on the real database — on-behalf removal — opened 2026-10-07
**Resolved (2026-10-10, workflow designer slice 2).** The new screen needed `ScreenRolePermissionSeeder` on the real
DB. A cell-by-cell comparison against a backup taken just before the reseed shows these two cells were the only ones
changed (1 → 0), so no customised grant was lost.
`ScreenRolePermissionSeeder` no longer grants that tier, but Homestead's MySQL was unreachable when the change was made,
so the real database still has it. It is now inert: nothing reads the tier, and the intake endpoint ignores
`subject_user_id`. Don't reseed the whole matrix to fix it, because that resets grants customised on Roles &
Permissions. **To close:** clear `can_approve` on `request_intake` for R02 and R05 by hand (or untick it on the
Roles & Permissions screen).

### Seeded accounts can still double a sole-holder role; the test plans still describe R10 — العميد/R10 merge — opened 2026-10-04
(1) One holder per post is enforced when someone is named, not on the data already there: `TestUserSeeder` still gives
`multi.role@` R03 beside `r03.head@` (the 2026-10-04 decision that seeded accounts keep their roles), so the real
database has two chairs until the chair is named on «المناصب الفردية»; `activeSeatHolder()` seats the lower id. (2)
`TEST_PLAN.md`/`.ar.md`, `TEST_PLAN.roles.md`/`.ar.md` and `USER_GUIDE.ar.md` still list R10 and `r10.diwan@`, and
don't cover naming العميد. **To close:** decide whether the seeder should hold one holder per post too (drop R03
from `multi.role@`); rewrite the R10 sections and add an R08 "name العميد" check to the role plans.

### «أدوار اللجنة» leaves R05, unassigning and the stale roster open — Stage 103 — opened 2026-10-04
(1) The user named مدير الشؤون الإدارية among the roles the مقرر assigns, then said «don't include it for now»: R05
(an approver, not a seat) is still granted on the Users screen. (2) A seat role can only change hands; there is no
"remove the holder and leave the seat vacant". (3) The seat follows the role at the committee's next scheduling, not
at naming time (so a live sitting keeps its invitees), so the committee roster on the meetings screen shows the former
holder until then. **To close:** decide (1) with the user; (2) is a DELETE on `committee-roles/{seat}` if wanted; (3)
could reseat committees with no live meeting at naming time.

### A stand-in seat holder's temporary grant is invisible and includes the chair's roster edit — stand-in seats — opened 2026-10-02
`SeatDutyGrant` lends a non-role seat holder its role's grants on the seven meeting-duty screens. Three
consequences were left as they are. (1) The Users and Roles screens show only held roles, so an admin
cannot see who is acting on a lent grant or until when. (2) A stand-in chair receives R03's `meetings,edit`,
which also gates `committees/{c}/members`, so while invited they can change the committee's roster the
way a real chair can. (3) The SPA picks up a new or expired grant only when `/auth/me` reloads, which
happens on page load. **To close:** show "acting as <role> until …" on the Users screen if (1) matters,
and decide whether a stand-in chair may edit the roster. If not, split the roster routes onto their own
grant.
**(2) resolved (2026-10-02, the committee is a group the مقرر picks).** Adding or removing a seat holder
now refuses everyone but the مقرر (`CommitteeController::refuseUnlessRapporteur()`), so a stand-in chair
can no longer change the roster. Points (1) and (3) stand.

### A formally rejected request has no way out — found while rewriting the user guide — opened 2026-10-02
`reject_formally` at `requirements_check` leaves the file at that stage with status `rejected`. That status is
in none of `RequestClosureService::CLOSABLE_STATUSES`, `AppealEligibility::QUALIFYING_STATUS_CODES` or
`RequestController::REOPENABLE_STATUS_CODES`, so the file can be neither closed, appealed nor reopened —
unlike its sibling `declare_no_jurisdiction`, which can be closed and appealed. The old guide claimed both
were closable and appealable; the new one says only what the code does. Not checked: whether R02's other
stage-5 actions are still offered on a `rejected` file. **To close:** a process decision on whether [D]
treats الرفض الشكلي as closable/appealable, then add the status to the lists that should hold it.

### A committee's creator stays a seatless member and is counted in the quorum — found while updating the test plans — opened 2026-10-02
`CommitteeController::store()` adds whoever creates a committee as a member with no seat, and
`CommitteeVotingRules`, `MeetingReadinessService` and `DecisionTally` all count `Committee::activeMembers()`
— every member, seated or not. On a fresh install the first committee is created by R08 (an unseated R02
cannot reach the Meetings screen), no seat is bound to R08, and so that row is never promoted to a seat: the
committee has six members, «أكثر من نصف» needs 4 where the five-seat roster would need 3, and the sixth is
never invited to a meeting. The test plans now tell the tester to remove the row and measure both figures.
Not checked: what `DecisionTally` does with the extra member under each majority rule. **To close:** decide
whether the quorum base is the five seats (`whereNotNull('seat')`, as `MeetingController::store()` already
reads them) or the whole roster, and either count seats only or drop the creator's row once five seats are
filled.
**Narrowed (2026-10-02, one مقرر in the system).** A committee now seats the system's مقرر in the rapporteur
seat at creation, so when the مقرر creates it (the usual case) there is no seatless row. A creator who is
not the مقرر (R08) still gets one, so the quorum question above stands for that case.
**Resolved for new committees (2026-10-02, the committee is a group the مقرر picks).** Only the مقرر may
create a committee now, and `store()` no longer adds a separate creator row, so a new committee has no
seatless member. Rows already on an existing database (committee #16 holds three) remain until the مقرر
removes them; the "seats or whole roster" quorum question applies only to those legacy rows.

### `TEST_PLAN*.md` still describe the pre-wizard UI — docs refresh — opened 2026-10-02
**Resolved (2026-10-02, test-plan refresh).** All four plans re-derived from the seeders, `routes/api.php`,
the locale labels and the refreshed guide; see AGENT_NOTES 2026-10-02 «The four test plans brought up to
date». What that pass did not do is in its note: none of the new checks was walked in a browser.

The four test plans were last touched at Stages 96–101 and were out of scope for the guide refresh. They
still walk action-card buttons, the five approval-queue screens, drawn signatures and the two-click manager
step, and their per-role screen counts predate `my_tasks` and the committee-seat gate. **To close:** re-derive
them from the seeders and wizard act tables the way `USER_GUIDE.ar.md` was (see AGENT_NOTES 2026-10-02).

### Request Types still offers a routing suggestion that does nothing — docs refresh — opened 2026-10-02
`requestTypes.routeHint` reads «تبقى المسارات الثلاثة متاحة للاختيار» and the form offers hr / diwan /
committee_secretary, but one route has existed since Stage 96 and `administrative_routing` is off the path
since 2026-09-26, so the field is inert (Stage 96 left it on purpose). The guide now says so. **To close:**
drop the field and `RequestType::ADMINISTRATIVE_ROUTES`, or reword the hint.

### A maintenance test's `set_time_limit` can kill the rest of the PHPUnit run — found in sub-project 3 final-review fixes — opened 2026-09-28
`MaintenanceRunner` calls `set_time_limit(timeout + 30)` (330 s), and when `MaintenanceConsoleTest` drives it
that limit applies to the whole PHPUnit process: every test after it must finish within 330 s, or the run
dies with «Maximum execution time of 330 seconds exceeded» and no summary. On this machine (~2.7 s/test)
the ~256 tests that sort after it do not. Workaround used: run `--exclude-filter
'Maintenance(Bootstrap|Console)Test'` and those two files separately. **To close:** have the
runner skip `set_time_limit` under `app()->runningUnitTests()`, or reset it in those tests' `tearDown()`.

### `GET meetings/appeal-options` has no SPA caller — decision wizard sub-project 3 final-review fixes — opened 2026-09-28
Nomination moved from the agenda builder into the appeal wizard, which reads `GET appeals/{a}/acts`, so
nothing in `frontend/` calls `meetings/appeal-options` any more; it was kept by controller ruling and is
still covered by `AppealCommitteePresentationTest`. **To close:** delete the route, the controller method
and its test, or give it a caller.

### `record_document_conflict` / `record_special_case` are offered only on an unclosed file, but their endpoints have no such check — decision wizard sub-project 3, slice 2 — opened 2026-09-28
`RequestActs::records()` only offers these two acts while `$r->closed_at === null`, but
`RequestLifecycleController::storeDocumentConflict()`/`storeSpecialCase()` carry no matching check — the
same `generate_minutes` pattern sub-project 2 already found: the wizard is narrower than the endpoint, so
a caller that skips it can still record a conflict or special case on a closed file. **To close:** add a
`closed_at === null` guard to both endpoints (or a shared refusal method `RequestActs` can call instead of
restating the condition inline, matching how every other act's blocked reason is sourced from its own
endpoint).

### Eleven request endpoints skip `RequestVisibility` — decision wizard sub-project 3 — opened 2026-09-28
The after-decision lifecycle writes (`close`, both archive endpoints, execution soundness, execute,
approval return record/resolve, approval referral record/result, suspend/lift, reopen) are reachable by
id without going through `RequestVisibility`'s scoping — the wizard only ever *offers* them on a page that
already passed that check, so nothing in the UI can reach an endpoint it shouldn't, but a caller that
skips the wizard is not stopped by the endpoint itself. **To close:** add the same visibility check each
of these already-grant-gated endpoints' sibling reads use, or fold the check into a shared trait/base
method so the eleven cannot drift from one another.

### One leftover r08 Sanctum token from `check-layout.mjs`'s own login — decision wizard sub-project 3 task 6 — opened 2026-09-28
Task 6's walk-through ran `check-layout.mjs` twice (once left running past its budget in an earlier pass,
once to completion in the final pass); its hardcoded `r08.sysadmin@` login minted a token that full
fixture/token cleanup could not revoke — the delete was blocked by the auto-mode permission classifier
("Secret-Store Writes"), and per that denial's own instructions the block was not routed around. It is an
inert leftover credential (R08 is a known-password seeded test account, and nothing about this token is
tied to fixture data), not a functional risk. **To close:** the user (who holds the permission) deletes
`personal_access_tokens` id 288 directly, or simply leaves it — Sanctum tokens don't expire by default in
this app, but the account's password is already the standing security boundary.

### `generate_minutes` does not itself require a convened meeting or resolved items — decision wizard sub-project 2 — opened 2026-09-27
`MeetingMinutesController::generate()` has no gate of its own on `convened_at` or on every agenda item
being resolved — only `MeetingDuties::forMeeting()`'s offer condition (read by the inbox and
`MeetingWizard`) withholds the action until both hold. A caller that skips the wizard (a direct API call,
or a future screen) can still generate a محضر for a sitting that never opened. Tightening the endpoint
touches roughly ten test files that call `generate()` on an unconvened fixture. **To close:** add the same
two checks to `generate()` and convene those fixtures first.

### `meeting_minutes,add` lets every signing member generate the minutes, not only المقرر — decision wizard sub-project 2 — opened 2026-09-27
`MeetingWizard`'s `generate_minutes` action follows `meeting_minutes,add` (R02/R03/R04/R11/R12 —
Stage 99's whole sitting), so any of the five may (re)compile the draft. [D] Art. 15 gives إعداد المحضر
to المقرر (R02) specifically. **To close:** either split generation onto its own action tier seeded
`['R02']`, or record a decision that any signing member may prepare the draft (Art. 15 read loosely, since
the head still reviews it before it leaves `draft`).

### Decision wizard for committee duties — decision wizard sub-project 2 — opened 2026-09-26 — Resolved (2026-09-27, decision wizard sub-project 2 slices 1–3)
The user wants every task in «المهام المعلقة» decided through a wizard; sub-project 1 covered only the
request-page actions. Still on their own screens: the member's vote and the chair's recorded decision
(`AgendaItemDecisionPanel`), adopting the agenda, convening, minutes generation/review/signing, and the
meeting-date response. **To close:** its own spec → plan → build, reusing `DecisionWizard.vue`'s step
shell with a meeting/agenda-item subject instead of a request.

Built across three slices: `AgendaItemWizard` (vote/record a decision), then `MeetingWizard` +
`MeetingDutiesCard` (respond to the date, adopt, convene, minutes prepare/approve/return/sign, close),
both sharing `WizardShell`/`WizardSlip` with sub-project 1's request wizard. `MeetingDuties::forItem()`/
`forMeeting()` own every refusal the endpoints now call, so the wizard and the endpoint cannot disagree.
The two remaining gaps (minutes generation's own convened/resolved gate, and who may generate) are their
own entries above rather than closing this one incompletely.

### Decision wizard for post-decision work and records — decision wizard sub-project 3 — opened 2026-09-26 — Resolved (2026-09-28, slices 1–3)
Built across three slices: after-decision acts (soundness, execution, archives, closure, returns/
referrals, suspension), records and file content (conflicts, special cases, corrections, withdrawals,
the notice, the financial flag, reopen, notes, attachments), and appeals (verify, jurisdiction test,
legal review, execution, closure, reopen, nomination). Every act now reads its refusal from the
endpoint it posts to; nothing is left on a page-level panel or a page's own inline form.

### Appeal `reopen` leaves the old agenda item — decision wizard sub-project 3, slice 3 — opened 2026-09-28
Reopening a closed appeal does not clear its `committee_agenda_item_id`, so a reopened appeal can never
be nominated again (`AppealActs::forAppeal()`'s nominate condition requires `committeeAgendaItem ===
null`) and `execute_outcome` would still read the pre-reopen decision if the appeal reached execution
again. **To close:** `AppealController::reopen()` clears the link (and, if a decision was recorded
against that agenda item, decides whether the decision itself should also be detached or left as
history).

### The redo-stage picker offers stages the endpoint refuses — decision wizard sub-project 3, slice 3 — opened 2026-09-28
`GET appeals/redo-stage-options` (used by `AppealExecuteForm`'s stage picker) does not filter out stages
`reopenAtStage()` itself would refuse with "the target stage must precede the appeal's own stage" — the
picker and the endpoint can disagree, the same "wizard narrower/wider than the endpoint" shape the
`generate_minutes` and `record_document_conflict` items already found elsewhere. **To close:** filter
the options query the same way `reopenAtStage()` validates, or have the picker call the refusal method
directly per candidate stage.

### Appeal filing eligibility is endpoint-only — decision wizard sub-project 3, slice 3 — opened 2026-09-28
`AppealFilingWizard`'s request step lets the filer pick any of their own requests; whether that specific
request is actually appealable (a final result exists, no appeal is already open on it unless new facts
are declared) is answered only by `POST /appeals`'s own 422 on Confirm, with no up-front check. Verified
live in this task's walk-through: picking a request that already had an open appeal produced exactly
this 422 at Confirm, not at the picker. **To close:** either surface the eligibility in the request
search results (grey out/annotate ineligible ones) or leave it — the 422 is legible and Confirm is a
one-shot step, so this is a UX nicety rather than a correctness gap.

### Nominator visibility widened beyond what the plan claimed — decision wizard sub-project 3, slice 3 — opened 2026-09-28
`Appeal::mayNominate()` — a seated holder of `meeting_agenda` edit+view, e.g. R03 — now sees the **full**
`AppealResource` (grounds, final request, new facts, the verification/jurisdiction/legal-review answers
and who gave them) for any appeal at `legal_review`, where `GET meetings/appeal-options` previously
showed only id, appellant and the request's reference number. This was kept deliberately: nomination
moved out of the agenda builder into the appeal wizard in this slice, and a nominator who can no longer
see the file elsewhere would be nominating blind. **Resolved (2026-09-28, sub-project 3 final-review fixes)** — narrowed:
`Appeal::isVisibleTo()` and `AppealController::index()` reverted to their pre-sub-project-3 rule, and
`GET appeals/{a}/acts` admits a nominator (legal_review, not yet on an agenda) with appeal-options'
fields only — id, status, appellant, original request's reference — plus the one `nominate` act.

### The live committee has only its chair seat filled — Stage 102 — opened 2026-09-26
On the real database committee #16 holds one seated member (chair) and three seatless ones, so it
cannot schedule a meeting until all five Art. 10 (أ) seats are assigned (each to the role
`CommitteeMember::SEAT_ROLES` names). Data, not code. **To close:** fill the five seats on the
Meetings screen (R02/R03), or deactivate the old committee and form a new one.

### Nobody is told when a member declines a meeting date — Stage 102 — opened 2026-09-26
A decline leaves the meeting `pending_confirmation`, and the مقرر sees it only on the meeting page
(and the unanswered members see it in «مهامي»). There is no "date declined" or "meeting approved"
notification. Left out as not asked for. **To close:** add a notification event fired from
`MeetingController::respond()` to the rapporteur on a decline and to all attendees on approval.

### R04's role name still reads «عضو اللجنة» — Stage 102 — opened 2026-09-26
R04 now carries the `ministry_delegate` seat (مندوب الخدمة المدنية بوزارة الحكم المحلي), but its
`RoleSeeder` name and the three `r04.member*` test accounts were left as they were. **To close:**
decide whether to rename R04 or add a dedicated role; renaming touches every test that labels R04.

### Votes do not check that the sitting was convened — Stage 102 — opened 2026-09-26
Convening requires the meeting to be `scheduled` (every member accepted), but votes and decisions
still check only the adopted agenda and the study sequence, not `convened_at`. Pre-existing; not
widened here. **To close:** refuse votes/decisions on a meeting with no `convened_at` and update the
fixtures (`RunsStudySequence`) that vote without convening.

### The decisions register scrolls sideways at 1024px — layout check — opened 2026-09-23
`scripts/check-layout.mjs` fails `/decisions` (register tab) at 1024px in both locales: the table
needs 726px (ar) / 679px (en) in a 670px card. Its 11 columns are already squeezed to ~27px each;
the date column (`nowrap`, 159px in Arabic), the ellipsised subject (165px) and the vote chips
(~100px) set the floor. Every other route × width × locale passes. The 2026-09-22 sweep passed this
page, so it is data-dependent (longer dates or vote tallies on the current database). **To close:**
let the date wrap, cap the subject lower, or drop a column below ~1280px, then rerun the script.

### Every R02 can still see a request returned to intake — filer-only attachments — opened 2026-09-21
`submit` is creator-gated now, so a returned request no longer reaches every R01. But R02 keeps a
`cancel` row at `receive_from_municipality`, and `RequestVisibility`'s assignment clause derives
visibility from outbound rows, so every R02 still sees every returned file there. Pre-existing and
arguably intended (R02 is قسم شؤون الموظفين), left unchanged. **To close:** decide whether cancelling
at intake should be the filer's (and R08's) alone; if so, re-gate that cancel row.

### A manager whose only role is R09, R10 or R11 cannot attach at `receive_and_register` — Stage 101 — opened 2026-09-21
**Resolved (2026-09-21, filer-only attachments) by decision:** only the request's filer attaches documents
now (plus the execution-proof and HR service-file exceptions), so no manager attaches at any stage. The
manager contributes a note and, at `direct_manager_review`, a return with a reason.
Appendix 6 row 3 makes الرئيس المباشر «مشارك» in تجهيز الملف الوظيفي. Stage 98 lets the subject's
manager *see* the file there, and Stage 101 pins that they can add a note — but writing rides
`notes_attachments,add`, held by R01–R07 and R12. الرئيس المباشر is a `users.manager_id` relationship,
not a role, so a manager holding only R09/R10/R11 (all retained logins with no duty) is visible-but-
read-only. **To close:** either accept it (no such manager exists in the seeded data), or let the
note/attachment routes admit the subject's manager without the screen grant — a change to the
permission middleware, larger than one cell justifies.

### The Art. 101 notice copy is in-app only — Stage 101 — opened 2026-09-21
`request_notice_copy` defaults to in-app with email off, like `stage_changed`: it is commentary for
the manager and HR, not a deadline. If either party needs it by email, that is one line in
`NotificationSetting::EVENT_TYPES` (or each user's own preference) — deliberately not decided here.

---

### "Was the employee notified?" is still a hand-ticked answer — Stage 100 — opened 2026-09-21

The `employee_notified` question on the execution card (النموذج 17) and the closure audit
(Appendix 47 #4) is answered by hand. Stage 100 considered deriving it from Art. 101's
notice log and left it: the closure notice fires only *after* closure, and deciding which
earlier notices count as "notified of the result" is a rule someone has to choose, not a
lookup. **To close:** decide the rule (e.g. "a notice with a result moment 5–10 exists"),
then derive the answer the way `execution_document_attached` is derived.

### Approvers cannot open the meeting outputs screen — Stage 100 — opened 2026-09-21

Appendix 6 row 13 gives جهة الاعتماد «إشراف حسب الاختصاص» over execution. Stage 100
implemented it on the request page (approvers keep sight of the files they approved, and
R06/R07 can add notes). `meeting_outputs,view` was **not** widened to R05–R07: that screen
is picked by meeting, and they hold no committee seat, so they would open it onto an empty
picker. **To close:** decide whether approvers need a request-based (not meeting-based)
execution list; if so, add it rather than widening the meeting screen.

### Stage 101 — consultation and information layer — Track N — opened 2026-09-21 — **Resolved (2026-09-21, Stage 101)**

The last Track N stage is unbuilt: the remaining مشارك / مطلع cells of Appendix 6 (HR
consulted on rows 2, 4, 6, 7, 9, 14; الرئيس المباشر informed on rows 1 and 14; اللجنة
informed on row 6). See STAGE_PLAN.md Stage 101.

**Resolved.** Built as planned; seven of the thirteen cells were already true and are now pinned by
`ConsultationLayerTest`. See AGENT_NOTES.md. The two residues are recorded as their own items below.
