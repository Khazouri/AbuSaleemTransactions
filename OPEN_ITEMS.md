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

### Decision wizard for post-decision work and records — decision wizard sub-project 3 — opened 2026-09-26
Also still outside the wizard: Art. 103 soundness, execution, both archives, closure, approval
returns/referrals, suspension lift, corrections/conflicts/special cases/withdrawals, appeals and the
legal review. These live on the request page's tabs and their own screens. **To close:** its own spec
after sub-project 2; many already answer with the detail resource, so they fit the same Checks step.
Sub-project 2 already folded the legal review's request-side reading into `DecisionWizard.vue` (the
request wizard's Review/Checks steps), so this one covers everything else in the list above it, minus
that item.

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
