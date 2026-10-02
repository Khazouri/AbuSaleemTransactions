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

### A stand-in seat holder's temporary grant is invisible and includes the chair's roster edit — stand-in seats — opened 2026-10-02
`SeatDutyGrant` lends a non-role seat holder its role's grants on the seven meeting-duty screens. Three
consequences were left as they are. (1) The Users and Roles screens show only held roles, so an admin
cannot see who is acting on a lent grant or until when. (2) A stand-in chair receives R03's `meetings,edit`,
which also gates `committees/{c}/members`, so while invited they can change the committee's roster the
way a real chair can. (3) The SPA picks up a new or expired grant only when `/auth/me` reloads, which
happens on page load. **To close:** show "acting as <role> until …" on the Users screen if (1) matters,
and decide whether a stand-in chair may edit the roster. If not, split the roster routes onto their own
grant.

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
