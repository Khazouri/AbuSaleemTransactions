# Agent handoff notes

Shared scratchpad between Codex and Claude Code for this repo. See the
"Cross-agent handoff" section in [AGENTS.md](AGENTS.md) for the rules:
newest entry on top, prune once resolved, durable facts go in AGENTS.md
instead.

Format:

```
### YYYY-MM-DD — <Codex|Claude> — <short title>
What happened / what's left / what to watch out for. 2-4 sentences.
```

---

### 2026-09-02 17:55 EET — Claude — Stage 59 complete (appeal intake: real fields + eligibility gates)

Built exactly per the plan below. Two migrations applied to the real MySQL/Homestead database: four
new nullable `appeals` columns (`known_at`, `appeal_reasons`, `final_request`, `new_facts_declaration`)
and a new `appeal_attachments` table (same shape as `attachments`, FK'd to `appeals` instead, cascade-
deleted with the parent). `Appeal` gained the matching fillable/casts, an `attachments()` relation, and
the static `openAgainst()` predicate Track J's scope decision (3) needs.

New `App\Services\AppealEligibility` (mirrors `DecisionEligibility`'s shape exactly) owns all three
intake rules: ownership (`created_by_user_id` must equal the appellant), the decided-status restriction
(reverse-mapped from STAGE_PLAN's 6 named Art. 38 codes through Stage 54b's reconciliation table —
`decided`/`approved_with_conditions`/`outside_jurisdiction`/`final_approved`/`completed_closed`/
`archived` qualify outright; `cancelled` qualifies only when a linked `Decision` with `outcome=reject`
actually exists, distinguishing a real committee rejection from a plain administrative withdrawal using
data that already exists rather than touching the status vocabulary; `rejected` and `in_execution` are
both deliberately excluded, the latter because STAGE_PLAN's own code list skips Art. 38's 18 while
naming 17/19/20 on either side of it), and non-duplication (any prior appeal — any status, not only open
ones — against the same request by the same appellant requires a non-empty `new_facts_declaration`).
`latestDecisionFor()` auto-fills `original_decision_id` from the target's latest committee appearance,
reusing the exact "latest by id" selection `RequestDetailResource::committee_summary`/
`RequestResource::proposed_meeting` already established, so all three places agree on what "the current
decision" means. `original_decision_id` is no longer client-accepted at all (dropped from
`StoreAppealRequest`, same "never client-supplied" treatment `appellant_user_id` already gets) —
confirmed live that a spoofed value is silently ignored. When no Decision is found (the pre-committee
`outside_jurisdiction`-via-`requirements_check` case, or any future decision-less path),
`original_decision_reference` becomes required, enforced in the controller once the lookup comes back
empty since it depends on that query result, not as a static FormRequest rule.

Attachments: the flagged ⚠ design decision was made deliberately — a separate `appeal_attachments`
table plus `AppealAttachmentController` (`store`/`preview`), riding the *existing* `appeals` screen's
`add`/`view` grants (zero seeder changes needed, same as Stage 58's own screen work). Scoped to the
appeal's own appellant or R08 inside the controller (mirroring `AppealController::index()`'s exact
scoping), not `RequestVisibility` — an appeal's documents belong to the appeal, not the original
request's workspace. Same upload validation as the existing `attachments` mechanism (Stage 12), same
"create the parent, then attach as follow-up calls" flow.

Track J intro's scope decision (3), Stage 59's own half: `MeetingOutputService::complete()` — confirmed
by grep to be the *only* writer of `completed_closed` anywhere in the codebase — now refuses (422) to
close a request while `Appeal::openAgainst()` is true, checked inside the same locked transaction right
after the existing stage/status guards. New `MeetingOutputTransitionException::appealOpen()`. Stage 65
will release the hold once an appeal reaches `notified_closed`; nothing to build here beyond the block.

Frontend: `AppealsView.vue`'s create form gained the three required fields (`known_at`/`appeal_reasons`/
`final_request`) plus an always-visible optional `new_facts_declaration` field (the backend states the
reason when it's actually required, matching how exception-reason modals already work elsewhere rather
than duplicating the duplicate-detection logic client-side). After a successful create, the form
switches into an attachments step for the new appeal's id, reusing `FileUpload.vue` — generalized with
a new optional `uploadUrl` prop (falls back to its existing `/requests/${requestId}/attachments`
default, so `RequestDetailView.vue`/`RequestsView.vue` needed zero changes) rather than a second upload
component. New `appeals.create.*` locale keys in both `ar.json`/`en.json`.

Verification: rewrote `tests/Feature/AppealTest.php` in full (6 → 14 tests — Stage 58's fixtures never
set `created_by_user_id` at all since ownership wasn't enforced yet) covering ownership, every qualifying
status code, the `cancelled`-with/without-Decision(reject) split, duplicate-without-declaration vs.
duplicate-with-declaration, decision auto-fill vs. the no-Decision fallback-required case, a spoofed
`original_decision_id` being silently ignored, and attachment upload/preview/cross-appeal-404/role
scoping. Added one new test to `MeetingOutputsTest.php` proving `complete()` 422s while an open appeal
exists and succeeds once it reaches `notified_closed`. Full suite **234 tests / 1353 assertions** green
(was 225/1298), Pint clean on every touched/new file, `npm run build` passes with `AppealsView` picking
up the new markup in its existing chunk and `FileUpload` gaining the generalized prop (then reverted
`frontend/dist`, tracked in git, per every prior stage's note), locale key-parity verified
programmatically (913 keys each side, zero on-one-side-only), and both migrations ran clean against the
real MySQL/Homestead database. Smoke-tested end-to-end over real HTTP against Homestead as
`r01.employee@` (login → create an appeal against a real fixture Request → the duplicate-without-facts
422 confirmed live → upload a real PDF attachment → preview it as the owner (200) → confirmed an R02
stranger gets 404 on the same preview URL, proving the scoping is real, not just unit-tested), using a
`Request` row created directly via tinker (this dev database has zero real `Request` rows, same as every
recent stage's note) and deleted afterward along with the appeal/attachment it produced — no residue
left in the real database.

**Open items for whoever builds Stage 60+**: the formal-verification gate (standing/target/deadline/
duplication re-check by a human) is entirely unbuilt — Stage 59's non-duplication check is deliberately
just the intake-time heuristic STAGE_PLAN itself describes ("re-verified here by a human rather than
only the intake-time heuristic"). `AppealResource` now exposes `known_at`/`appeal_reasons`/
`final_request`/`new_facts_declaration`/`attachments_count`, but the appeals list table itself
(`AppealsView.vue`) doesn't render most of these yet or list uploaded attachments per row — not part of
this stage's own done-when, left as a display polish item.

---

### 2026-09-02 17:15 EET — Claude — Stage 59 implementation plan (appeal intake: real fields + eligibility gates)

Building Stage 59 per STAGE_PLAN.md Track J on top of Stage 58's schema-only `appeals`/`appeal_statuses`.
Re-read the Build bullet, the Track J intro's scope decision (3), and Stage 54b's reconciliation table
(`docs/employee-committee-lifecycle/gap-analysis.md` §4) before designing the eligibility rules, rather
than re-deriving the Art. 38 mapping from memory.

**Qualifying "decided" statuses, resolved from the reconciliation table, not guessed.** STAGE_PLAN names
6 Art. 38 codes (12/13/14/17/19/20). Reverse-mapping each through §4's table: 12→`decided` +
`approved_with_conditions`; 14→`outside_jurisdiction` (exact, reachable either via committee
`declare_no_jurisdiction` *with* a Decision, or `requirements_check`'s own `declare_no_jurisdiction`
*without* one — Stage 54, pre-committee); 17→`final_approved`; 19–20→`completed_closed` (merged) and
`archived` (legacy, code 20 alone). Code 13 (غير موافق عليها) has **no clean current-status match** — the
table itself says `rejected` has "no direct counterpart" (it's a pre-committee administrative rejection,
not a committee non-approval) and only `cancelled` is "loosely" 13, and only for its committee-`reject`
usage, since `cancelled` also covers plain administrative withdrawal (an already-flagged, not-yet-fixed
conflation, gap-analysis.md §4's own "Decisions summary"). Deliberate calls, both documented in code, not
silently resolved: **`rejected` is excluded entirely** (no Art. 38 counterpart per the table itself,
consistent with not re-opening Stage 54b's decision); **`cancelled` qualifies only when a `Decision` row
with `outcome = 'reject'` is actually linked to the request** (`Decision::whereHas('meetingRequest', ...)`)
— distinguishing a real committee rejection from a plain withdrawal using data that already exists,
without touching the status vocabulary itself. `in_execution` (Art. 38 code 18) is deliberately **not**
included — STAGE_PLAN's own code list skips 18 while including 17/19/20 on either side of it; honoring
that literally rather than second-guessing it.

**Ownership**: `original_request_id`'s `created_by_user_id` must equal the acting user — not enforced at
all in Stage 58. **Non-duplication**: ANY prior `Appeal` row (any status, not just open ones — refiling
the identical appeal after a first one already concluded is exactly what "unless new facts" guards
against) for the same appellant + `original_request_id` requires a non-empty `new_facts_declaration` on
the new one; Stage 60 re-verifies this by a human rather than trusting the heuristic alone, per its own
Build bullet text — so this stays a simple non-empty-string check, no semantic verification.

All three rules land in a new `App\Services\AppealEligibility` (mirrors `DecisionEligibility`'s shape:
`reasonBlockingAppeal(): ?string`, ordered checks, each with its own Arabic message), not in the
FormRequest — this codebase's established split (grep confirms no `withValidator` usage anywhere) keeps
DB-dependent business rules in a service/controller, converted to `ValidationException::withMessages` in
the controller, exactly like `MeetingOutputsController`/`CommitteeCandidateController` already do.

**"القرار المتظلم منه (auto-filled from the pick)"**: `original_decision_id` stops being client-supplied
(dropped from `StoreAppealRequest::rules()` entirely, same treatment `appellant_user_id` already gets) —
the controller derives it from the target request's latest `MeetingRequest`→`Decision` (reusing the exact
"latest agenda appearance" pattern `RequestDetailResource::committee_summary`/`RequestResource::
proposed_meeting` already established: `sortByDesc('id')->first()`). When no Decision is found (the
pre-committee `outside_jurisdiction` case, or any future decision-less path), `original_decision_reference`
becomes required instead — enforced in the controller once the lookup comes back empty, not as a static
FormRequest rule, since it depends on the query result.

**New intake fields on `appeals`** (nullable at the DB layer, required at the FormRequest layer for the
three substantive ones): `known_at` (تاريخ العلم به, date, not in the future), `appeal_reasons` (أسباب
الاعتراض, text, required), `final_request` (الطلب النهائي, text, required), `new_facts_declaration`
(nullable text, conditionally required per the non-duplication rule above).

**Attachments — the flagged ⚠ design decision, picked deliberately: a separate `appeal_attachments`
table**, not polymorphic `attachments` (would touch every existing consumer: `AttachmentController`,
`AttachmentResource`, every eager-load of `request.attachments` across `RequestDetailResource`,
`PresentationMemoCompiler`, `MeetingMinutesCompiler`, etc., for a feature that only needs one new,
independent parent) and not hung off the original request with a label (an appeal's supporting document
is not the original request's document — an adversarial record should keep the two files visibly
separate). Same shape as `attachments` (disk/path/original_name/mime_type/size_bytes/label/
uploaded_by_user_id), same validation (`StoreAppealAttachmentRequest` mirrors `StoreAttachmentRequest`
verbatim), same "create parent first, upload attachments as follow-up calls" flow Stage 12/13 already
established — not required at appeal-creation time. New `AppealAttachmentController` (`store`/`preview`),
gated on the *existing* `appeals` screen's `add`/`view` grants (no seeder change needed — the grants
already fit, same as Stage 58's own screen work). Ownership-scoped the same way `AppealController::index()`
already scopes the list (appellant or R08), not `RequestVisibility` (that gate is about a *request's*
workspace, and an appeal's documents belong to the appeal, not the request).

**Track J intro's scope decision (3) — Stage 59's half**: `MeetingOutputService::complete()` is the
**only** writer of `completed_closed` anywhere in the codebase (confirmed by grep — every other
`completed_closed` mention is a read-side filter/count), so it is the single point to gate. A new
`Appeal::openAgainst(int $requestId): bool` (any appeal against the request whose status isn't
`notified_closed` — including a null `appeal_status_id`, which shouldn't happen since `store()` always
sets `submitted`, but is treated as open defensively) is checked right after the existing
`wrongStage`/`transitionNotAllowed` guards, inside the same locked transaction, throwing a new
`MeetingOutputTransitionException::appealOpen()`. Stage 65 will be the one to actually *release* this
hold once an appeal concludes (nothing to build here beyond the block itself) and Stage 66 owns the
non-reopening rule — this stage only carries its own half, per the intro's own "Stages 59/65/66 each
carry their half" line.

**Frontend**: `AppealsView.vue`'s create form gains the three new required fields plus the optional
new-facts declaration (always visible, not conditionally shown — the backend states the reason when it's
actually required, matching how exception-reason modals already work elsewhere in this app rather than
duplicating the duplicate-detection logic client-side). After a successful create, the form switches into
an attachments step for the newly-created appeal's id, reusing `FileUpload.vue` — generalized with a new
optional `uploadUrl` prop (falls back to its existing `/requests/${requestId}/attachments` default, so
`RequestDetailView.vue`/`RequestsView.vue` need zero changes) rather than a second upload component.

**Verification plan**: rewrite `tests/Feature/AppealTest.php`'s fixtures (now needs a creator +
qualifying status per case — Stage 58's helper didn't set `created_by_user_id` at all, since ownership
wasn't enforced yet) and add real Stage 59 coverage — ownership block, non-qualifying-status block, the
`cancelled`-without-Decision vs. `cancelled`-with-Decision(reject) split, duplicate-without-declaration
block vs. duplicate-with-declaration success, decision auto-fill vs. the no-Decision fallback-required
case, attachment upload + preview + cross-appeal 404s + role scoping. Add one new test to
`MeetingOutputsTest.php` (already has a `decidedMeetingOutput()` fixture reaching `in_execution`) proving
`complete()` 422s while an open appeal exists and succeeds once the appeal reaches `notified_closed`.
Plus the full PHPUnit suite, Pint, `npm run build`, locale key-parity, and `php artisan migrate` against
the real MySQL/Homestead database.

---

### 2026-09-02 16:45 EET — Claude — Stage 58 complete (appeal entity, schema & status machine)

Built exactly per the plan below. Two migrations (`appeal_statuses`, `appeals`) applied to the real
MySQL/Homestead database; `AppealStatusSeeder` (6 rows, [A] §9's own sequence) added to
`DatabaseSeeder`'s call list right after `WorkflowTransitionSeeder`. `ScreenSeeder` gains the new
top-level `appeals` screen (ungrouped, positioned right after `notes_attachments`) and
`ScreenRolePermissionSeeder` gains its grants (`view => '*'`, `add => ['R01']`) — reseeded against the
real database and confirmed via tinker: 6 `appeal_statuses` rows in order, 30 `screens` rows total (the
`ScreenSeeder` docblock's stale "30 total" count was actually already wrong going into this stage —
Stage 57's removal of `authority_approval` had dropped the real total to 29 without the comment being
updated; fixed both the drift and added `appeals` in the same edit, landing back at 30 — verified by
counting the live table, not assumed from the array).

`Appeal`/`AppealStatus` models, `AppealController` (`index`/`store` only), `StoreAppealRequest`
(FK-existence validation only — no ownership/decided-status/duplication check, per the stage's own "no
workflow logic yet" scope), `AppealResource`. `appellant_user_id` is always `$request->user()->id`,
confirmed by test that a client-supplied `appellant_user_id` field is silently ignored (it isn't even a
validated key). `Appeal::class` added to `AuditLog::AUDITED_MODELS` plus a matching
`auditLog.models.appeal` locale key in both files.

**One index-scoping decision worth restating outside the plan**: `AppealController::index()` scopes to
`appellant_user_id = actor.id` for everyone except R08 — there is no staff review queue yet, since
Stage 58's Build bullet only asks for the row to be "visible on its own screen" (i.e., visible to its
filer), and none of Track J's later stages (verification/legal review/committee presentation) have
landed yet to justify broader visibility. This is flagged in the `add` grant's own seeder comment as
something to widen once a later stage needs it, not something silently left too narrow.

Frontend: real `AppealsView.vue` (not a placeholder) — a debounced `/requests?search=` picker mirroring
`MeetingAgendaBuilderView.vue`'s existing pattern, two optional fallback fields
(`original_decision_reference`/`original_decision_date`), and a list table scoped by the same rule the
backend enforces. New `/appeals` route, a new `flag` glyph in `AppIcon.vue`, `appeals: 'flag'` in
`AppSidebar.vue`'s `ICON_BY_CODE` (top-level, so no `nav.groups.*` key needed), and a full `appeals.*`
locale block in both `ar.json`/`en.json`.

Verification: new `tests/Feature/AppealTest.php` (6 tests — create+list round-trip; the appellant is
always the acting user regardless of client input; a non-admin's list is scoped to their own appeals
while R08 sees all; a role without `appeals,add` gets 403 but can still view; both the
recorded-Decision path and the free-text-fallback path succeed; a nonexistent
`original_request_id`/`original_decision_id` 422s), full suite **225 tests / 1298 assertions** green
(was 219/1267), Pint clean on every touched/new file, `npm run build` passes with `AppealsView` as its
own lazy chunk (then reverted `frontend/dist`, tracked in git, per every prior stage's note), locale
key-parity verified programmatically (906 keys each side, zero on-one-side-only), and both migrations
plus the three reseeds ran clean against the real MySQL/Homestead database. Smoke-tested end-to-end
over real HTTP against Homestead as `r01.employee@` (login → `permissions.appeals` present with
`can_add:true` → `POST /api/appeals` → `GET /api/appeals` lists it), using a request row created
directly via tinker (this dev database has zero real `Request` rows, same as every recent stage's
note) and deleted afterward — no residue left in the real database. One smoke-test false alarm worth
recording: Arabic text sent through this Windows Git-Bash session's `curl -d '...'` arrived on the
server as literal `?` bytes — confirmed via tinker (`bin2hex` showed `3f` repeated) that this is a
shell/console encoding artifact of this terminal, not an app or database bug; writing the same Arabic
string from PHP directly round-tripped correctly.

**Open items for whoever builds Stage 59+**: the richer intake fields ([A] §9's تاريخ العلم به / أسباب
الاعتراض / الطلب النهائي, supporting documents) and every validation STAGE_PLAN names for that stage
(ownership, decided-status restriction via Stage 54b's reconciliation table, the same-facts
non-duplication check, Track J intro's scope decision (3) — an open appeal blocking the original
request's closure) are all still unbuilt, exactly as scoped. The attachments question STAGE_PLAN
flags as needing its own deliberate pick (`attachments.request_id` is a hard, non-polymorphic FK) is
untouched here — this stage's `StoreAppealRequest` has no attachment field at all.

---

### 2026-09-02 16:00 EET — Claude — Stage 58 implementation plan + build (appeal entity, schema & status machine)

Building Stage 58 per STAGE_PLAN.md Track J: the foundational `appeals` table and its own 6-step
status machine, independent of `requests`/`workflow_stages`, per the two scope decisions already
recorded in Track J's intro (an appeal is a new entity, never a `RequestType`; it gets its own small
status machine, never a detour through the 14-stage `workflow_stages` table). Re-read [D] Art. 47 and
Arts. 75–79 (`official-procedures-manual-index.md` §7's التظلمات الوظيفية entry, already transcribed)
and [A] §9's 6-step summary before building, per the track's own citation caveat.

**Schema**: `appeal_statuses` (id, `order_no` unique, `code` unique, `name_ar`, `name_en`, `color`) —
same shape as `request_statuses` plus an `order_no` mirroring `workflow_stages`', since this is a
sequential 6-step machine, not a flat status bag. Seeded verbatim from [A] §9's own numbered sequence
(`submitted`/`formal_verification`/`file_assembly`/`legal_review`/`committee_presentation`/
`notified_closed`), each lining up 1:1 with Stages 59/60/61/62/63/65. `appeals` (`appellant_user_id`
nullable FK→users nullOnDelete, mirroring `requests.created_by_user_id`'s shape; `original_request_id`
FK→requests, NOT nullable — requests are never deleted in this codebase, confirmed by grep for a
destroy route, so the default RESTRICT-on-delete needs no explicit nullOnDelete; `original_decision_id`
nullable FK→decisions nullOnDelete, since not every decided matter rode a committee vote — e.g. Stage
54's `reject_formally` at `requirements_check` writes no `Decision` row — plus `original_decision_
reference`/`original_decision_date` as the free-text fallback for exactly that case; `appeal_status_id`
nullable FK→appeal_statuses nullOnDelete). Deliberately minimal: the richer intake fields ([A] §9's
تاريخ العلم به / أسباب الاعتراض / الطلب النهائي / attachments, and the non-duplication check) are
explicitly Stage 59's own Build bullet, not re-derived here — building them now would risk conflicting
with that stage's own design pass, particularly the attachments question STAGE_PLAN already flags as
needing its own deliberate pick (`attachments.request_id` is a hard, non-polymorphic FK).

**New top-level `appeals` screen, NOT nested under `meetings_management`** — filing an appeal is an
employee-facing action, not committee administration, matching STAGE_PLAN's own Build bullet wording
verbatim. Seeded `view => '*'` (controller scopes the query to the caller's own appeals unless R08,
same admin-bypass shape used elsewhere) and `add => ['R01']` only — a deliberate, documented narrowing
per the same "employee-facing" framing; widen once a later Track J stage gives staff roles (R02/R09
verification, R05/legal review, R03 committee presentation) their own review queue over this screen.

**Backend**: `App\Models\AppealStatus` (plain lookup) and `App\Models\Appeal` (belongsTo appellant/
originalRequest/originalDecision/status). `AppealController::index()` — scoped list, eager-loading only
the three thin relations the list needs. `AppealController::store()` — `appellant_user_id` is always
`$request->user()->id`, never client-supplied, matching `RequestController::store()`'s
`created_by_user_id` precedent; `appeal_status_id` defaults to `submitted`. New `StoreAppealRequest`
validates only FK existence (`exists:requests,id` / `exists:decisions,id`) — no ownership, no
decided-status check, no duplication check, matching the stage's own "no workflow logic yet" scope.
Routes: `GET/POST /appeals` behind `screen.permission:appeals,view|add`. `Appeal::class` added to
`AuditLog::AUDITED_MODELS` (low-volume, high-consequence, same category as the `requests` block) and a
matching `auditLog.models.appeal` locale key in both files.

**Frontend**: real `AppealsView.vue` (not a placeholder — the stage's own done-when requires an
actually-creatable, actually-visible row) — a debounced request-search picker mirroring
`MeetingAgendaBuilderView.vue`'s existing `/requests?search=` pattern, two optional fallback fields
(decision reference/date), and a scoped list table, styled after `BackupView.vue`'s list+create shape.
New route `/appeals` (screenCode `appeals`), a new `flag` glyph in `AppIcon.vue`, and an `appeals: 'flag'`
entry in `AppSidebar.vue`'s `ICON_BY_CODE` (top-level, ungrouped, so it needs no `nav.groups.*` key).
New `appeals.*` locale block in both `ar.json`/`en.json`.

**Verification plan**: new `tests/Feature/AppealTest.php` — an appeal round-trips through create+list;
`appellant_user_id` is always the acting user regardless of what the client sends; a non-R08 actor's
list is scoped to their own appeals while R08 sees all; a role without `appeals,add` gets 403; the
`original_decision_id` fallback (decision reference/date, no Decision row) and the with-Decision path
both create successfully; a nonexistent `original_request_id`/`original_decision_id` 422s — plus the
full PHPUnit suite, Pint on touched/new files, `npm run build`, locale key-parity, and
`php artisan migrate` against the real MySQL/Homestead database.

---

### 2026-09-02 15:15 EET — Claude — Stage 58 sub-staged into TRACK J (planning only, no code)

Per the user's request, broke the former single "Stage 58 — Appeal (تظلم) lifecycle" placeholder in
STAGE_PLAN.md into its own **Track J** (Stages 58–66, 9 stages) — the same treatment Track I got out
of the original single "Stage 38+" note. Re-read [D] Arts. 34–37/47/75–79 (transcribed in
`docs/employee-committee-lifecycle/official-procedures-manual-index.md`) and [A] §8–9 (`official-
process-summary.md`) before drafting, rather than sub-staging from the one-paragraph summary already
in STAGE_PLAN.md.

**Two scope decisions recorded once in Track J's intro, not re-litigated per stage**: (1) an appeal is
a new `appeals` entity referencing an already-decided `Request`, never a Grievance-type `Request`
routed through the ordinary pipeline — [D] Art. 47 already treats التظلمات as its own classification,
and [A] §8's exceptional request-states ("محل تظلم" / "أعيد فتحها بموجب تظلم أو حكم") only make sense
if the تظلم lives outside that request's own workflow. (2) Appeals get their own small 6-step status
machine ([A] §9), not a detour through the 14-stage `workflow_stages` table — kept separate the same
way Stage 29's `CommitteeStatusService` was deliberately kept separate from `WorkflowService`.

**The 9 stages**: 58 (entity/schema/status machine), 59 (intake against a decided matter, with Art.
75 point 2's non-duplication check), 60 (formal verification gate — appellant standing/valid target/
deadline/duplication), 61 (original-file assembly, a read-only compiler reusing Stage 46's
presentation-memo compiler and Stage 36/50's minutes compiler rather than re-deriving), 62
(jurisdiction test + legal review, with Art. 77's explicit disciplinary-board exclusion terminating
the appeal rather than letting it proceed), 63 (committee presentation with Art. 75 point 5's own
5-outcome vocabulary, riding the existing Stage 31 agenda/vote/signature machinery via a new
`item_type=appeal`), **64 — flagged ⚠, the one stage needing its own design pass before coding**
(outcome execution: 4 of the 5 outcomes are straightforward per-outcome effects, but إعادة اإلجراءات
من المرحلة التي وقع فيها العيب means genuinely re-entering the *original* request's own
`WorkflowService` state machine at a specific stage — real new coupling between two systems kept
apart everywhere else in this track, same caution level as Stage 57), 65 (notification + closure,
reusing Stage 23's `NotificationDispatcher` and the closure-field precedent from Stage 37/44), 66
(the Art. 78–79 non-reopening rule — an enumerated-reason-only `reopen` action, with an explicit note
that [A] §8's "أو حكم" (court-judgment reopening) mention has no fuller spec anywhere in this folder
and is deliberately given the same generic mechanism rather than a dedicated workflow).

Updated the Suggested-order block and the "stages not to rush" line (added 64 alongside 57) to match.
**Nothing implemented** — STAGE_PLAN.md is the only file touched; whoever picks up Track J should
start at Stage 58 per its own dependency note (58 & 59 first; 63 depends on Track H's Stage 20/21/31
machinery; 64 needs its own design pass, don't rush it alongside another stage).

**Revised the same session after a verification pass — 7 corrections, listed because several were
real errors in the first draft, not polish.** Re-read [D] Arts. 34–37/47/75–79 and [A] §8/§9 against
the actual codebase rather than trusting the draft: (1) **fabricated citation precision** — the first
draft cited "Art. 75 point 1/2/3…", but the index records Arts. 75–79 as one block and gives
article-level attribution to only Art. 77 and Arts. 78–79; the numbered 1–6 sequence is [A] §9's, so
every stage's Source line was rewritten to "[D] Arts. 75–79 (<topic>) + [A] §9 step N". (2)
**A missing [D] requirement the draft never mentioned**: Arts. 34–37's اإلقفال rule makes "any تظلم
path concluded, with the file kept open until it does" one of only 4 ways a matter may be closed — so
an open appeal must block the original request's closure, a cross-system rule touching
`WorkflowService::hasTerminalStatus()` and Stage 37's `MeetingOutputService`; now scope decision (3)
in the track intro, with its halves in Stages 59/65. (3) **Stage 65's claimed precedent doesn't
exist** — grepped `closed_at`/`closure`/`closed_by` on `Request` and its migrations: nothing. Stage 37
gave requests a `completed_closed` *status* and no closure record, and "preserve, don't erase" is the
master-data deletion pattern, unrelated; the 8 closure fields are new work sourced from [D] Arts.
34–37 directly, and the same gap exists for ordinary requests (deliberately not closed here). (4)
**`attachments` is not polymorphic** (`request_id` FK, cascadeOnDelete), so Stage 59's "reuse the
existing attachment mechanism" was wrong — an appeal's own documents have nowhere to live; three
options now stated explicitly for a deliberate pick. (5) **Notification proof is only partial** —
`notifications` is Laravel's database-channel table, so إثبات التبليغ exists for in-app only, with no
email/SMS delivery log; Stage 61 must show absence-of-evidence rather than a fabricated "notified".
(6) **Stage 66 was standing on the losing document** — it leaned on [A] §8's "محل تظلم" request state,
but that's the looser 27-state list [A] itself says to reconcile against [D] Art. 38's 20 codes, which
have no under-appeal code, and Stage 54b already made that call; reframed as a derived flag from
Arts. 34–37's closure rule so Stage 54b's reconciliation stays intact. (7) **Stage 63 badly understated
its own size** — a grep found ~14 hardcoded `item_type === 'employee_request'` guards across 6
controllers plus `DecisionEligibility::pendingVotesQuery()`'s SQL filter, each needing an individual
yes/no for appeals; the stage now carries that inventory instead of implying a one-line enum widening.
Also confirmed correct and left alone: `MeetingRequest.request_id` is already nullable (Stage 31), both
compilers exist, `DecisionController::ACTIONS` has 7 entries, and gap-analysis.md §12 independently
settles the GRIV-vs-appeal distinction the intro now cites.

---

### 2026-09-02 14:35 EET — Claude — Stage 57 complete (⚠ pre-committee ministry stage + approval-tail restructure)

Built exactly per the plan below. No new migration — this is purely a seed-data + service-logic
restructure (`workflow_stages`/`workflow_transitions`/`screens`/`screen_role_permissions` are all
seeded, not migrated, tables). `WorkflowStageSeeder` now deletes `ministry_endorsement` and
`competent_authority` first (their `workflow_transitions` rows cascade-delete automatically —
`from_stage_id`/`to_stage_id` are `cascadeOnDelete` on `workflow_stages`, confirmed by reading the
migration before relying on it) then renumbers the surviving 12 with the same bump-then-set idiom
Stage 51/52 already established. `WorkflowTransitionSeeder`: `observations` now forwards straight
into `forward_to_committee` (same role R02, same status `ready`); `local_governance_ministry`'s
approve now targets `final_approval_archiving` directly with `set_status_id` = `final_approved` (was
`competent_authority` / `approved`); the old `competent_authority → final_approval_archiving` row is
gone; both doomed stages dropped out of `cancellationRoles` and the deadline-escalation list (leaving
them in would have thrown on a missing `$stages[...]` key the moment the stage rows were actually
gone — a loud failure I confirmed doesn't happen, not a silent one I had to guess about).

`WorkflowService::APPROVAL_LEVELS` drops `competent_authority`, `final_approval_archiving` moves from
level 6 to 5. The one real mechanical fix, flagged in the plan below as the wrinkle to watch:
`destinationStageId()`'s existing runtime bypass override only ever touched `to_stage_id`, leaving
`set_status_id` applied as-authored regardless of which branch fired — harmless while the bypass
target (`competent_authority`) still meant "one approval pending" either way, wrong once the bypass
target became `final_approval_archiving` itself (arrival there means every approval is done). Added a
sibling `destinationStatusId()` mirroring the same new `bypassesMinistryApproval()` predicate, wired
into `applyRule()` everywhere `$rule->set_status_id` was previously read directly (both the model
write and the `RequestStatusHistory` row) — confirmed by the tinker smoke test below that the bypass
path now stamps `final_approved` on arrival, matching Stage 54b's own reconciliation of that status
against Art. 38 code 17.

Screens: `ScreenSeeder` now has its **first-ever explicit deletion** (`authority_approval` — its
`screen_role_permissions` rows cascade-delete with it, confirmed by reading that migration too), with
the removal mirrored in `ApprovalController::LEVELS`, `RequestController::
actorCanApproveCurrentLevel()`'s `$screenByStage`, `routes/api.php`'s `$approvalScreens` (which also
removes the `/approvals/authority` routes for free, since that array drives a route-registration
loop), `ScreenRolePermissionSeeder::DEFAULTS`, and the two frontend arrays that mirror the same list
generically (`router/index.js`, `AppSidebar.vue`'s `ICON_BY_CODE`). R07 now holds exactly one approval
screen (`final_approval`) instead of two.

**Test files needing real edits, found by grepping the affected codes and reading every hit in
context rather than assumed from the filename** (per the plan's own inventory): `tests/Unit/
WorkflowServiceTest.php` — the full happy-path walk (14→12 stage-log entries, approval level sequence
range(1,6)→range(1,5), role-pluck list loses a duplicate `R07`), the seeded-rule-shape counts (15→13
non-exception rows, 16→14 cancel rows, both per-stage-code maps, plus new assertions that both doomed
codes are genuinely absent from the rule map, not merely unreferenced), and the low-grade ministry-skip
test (one fewer `forward`/R05 step in the setup since the two-hop `ministry_endorsement` detour is
gone, bypass destination `competent_authority`→`final_approval_archiving` with the new
`final_approved` status, one R07 click instead of two to reach `in_execution`).
`tests/Feature/CommitteeSeatRosterTest.php` — the ministry-delegate-seat guarantee test's bypass-branch
assertion. `tests/Feature/MeetingOutputsTest.php` — one hardcoded `level => 6` assertion, caught by the
first full-suite run, not anticipated in the plan (the plan's file inventory missed this one hit since
the initial grep for the four stage codes doesn't catch a bare approval-level literal with no stage
code nearby — worth remembering: a renumbered level sequence needs its own separate grep, `level.*[0-9]`
near `approvals`, not just the stage-code grep). Every other file the initial grep matched
(`ApprovalChainTest`, `DecisionOutcomeTemplateTest`, `CommitteeCandidatesDashboardTest`,
`DecisionVotingTest`, `RequestSlaTest`, `RequirementsCheckJurisdictionTest`, my own
`EmployeeRequestVisibilityTest`) needed no change — confirmed by reading each hit, not assumed.

Verification: full suite **219 tests / 1267 assertions** green (was 219/1264 before this stage's test
rewrites — same test count, net +3 assertions from the two new "genuinely absent, not just
unreferenced" assertions plus the new status assertion in the seat-roster test), Pint clean on every
touched file, `npm run build` passes with `RequestDetailView`/`ApprovalQueueView` picking up nothing
new (no frontend markup changed, only two small array literals) in their existing chunks — then
reverted `frontend/dist`, tracked in git, per every prior stage's note. Reseeded
`WorkflowStageSeeder`/`WorkflowTransitionSeeder`/`ScreenSeeder`/`ScreenRolePermissionSeeder` against the
real MySQL/Homestead database and confirmed via tinker: 12 stages (both doomed codes genuinely absent,
not just unreferenced), 29 screens (`authority_approval` gone), 54 transitions with zero rows
referencing a now-missing stage. Ran a full end-to-end smoke test directly against the real database
inside a rolled-back transaction (no residue — `Request::count()` still 0 afterward): the
ministry-required branch walks `approval_by_authority`[approved]→`local_governance_ministry`[approved]
→`final_approval_archiving`[final_approved]→(self-loop)[in_execution]; the bypass branch walks
`approval_by_authority`→`final_approval_archiving`[final_approved] directly→(self-loop)[in_execution]
— both exactly as designed. `gap-analysis.md` §14 updated with the resolution (git-ignored, local-only,
not a tracked-file change), same housekeeping Stage 54b did for its own table.

**Open items for whoever builds further Track I stages**, both already flagged as deliberate scoping
decisions, not oversights: (1) `jurisdiction_test.requires_central_approval` (Stage 54's Art. 45 Q6
answer) still drives nothing downstream — a future stage could wire it into the ministry-routing branch
instead of/alongside `requiresMinistryApproval()`, but that's an independent design decision from this
stage's stage-count restructuring, deliberately not conflated with it. (2) [A] §5's Path 3
(send-the-minutes-back-for-reconsideration) has no equivalent — a genuinely new capability, not named
in this stage's Build bullet. Stage 58 (appeal/تظلم lifecycle) is next per STAGE_PLAN's own text,
flagged there as comparable in size to all of Track H and needing its own sub-staging when scheduled.

---

### 2026-09-02 13:40 EET — Claude — Stage 57 implementation plan (⚠ pre-committee ministry stage + approval-tail restructure)

Building Stage 57 per STAGE_PLAN.md Track I — the one stage in the whole track explicitly flagged
"needs its own design pass before touching code, not a quick fix alongside another stage." Took that
literally: read [D] Art. 31/92–97, [E] stages 14–21, [A] §5's Path 1/2/3, and gap-analysis.md §14 in
full before writing any code, then checked the real database for actual blast radius.

**Blast radius check first.** `Request::count()` against the real MySQL/Homestead database is **0** —
this is a dev/test system with no live requests sitting at any of the affected stages. The "in-flight
requests need a defined migration path" risk STAGE_PLAN itself flags is therefore moot for *this*
database today; the remaining risk is purely the code/test surface (stage graph, approval levels,
screens, ~15 files). Grepped the whole repo for the four affected stage codes and the two doomed
approval-screen codes to build a complete file inventory before touching anything, rather than fixing
forward from memory.

**What the standard actually says, reconciled against what's currently built:**
[E]'s 21-stage table (stages 14–17) and [A] §5 agree on one model: after the committee decides, the
minutes go to **عميد البلدية أو المفوض قانونًا** (the mayor or their legal delegate) for approval;
*from there* a single binary gate ("هل تتطلب الحالة إجراء أو موافقة مركزية؟", [E] stage 15) decides
whether the file also needs **وزارة الحكم المحلي** (Path 2) or is done (Path 1). There is no
third approving *party* after the ministry — [E] stage 17's "الاعتماد النهائي" is the *completion
checkpoint* ("once every required approval is complete"), not a separate authority's approval, and
[A] §5 Path 2 itself treats "وزارة الحكم المحلي أو وزارة الخدمة المدنية أو الجهة المختصة" as
interchangeable names for the *same* central-approval party, not three sequential approvers. Current
system has **two** structural mismatches against this: (1) `ministry_endorsement` sits at workflow
stage 8, *before* the committee ever sees the request (stage 9's `forward_to_committee`) — no
document puts ministry involvement before the committee's own decision; (2) the post-committee tail
is 4 levels (`approval_by_authority`→`local_governance_ministry`→`competent_authority`→
`final_approval_archiving`) gated by a numeric `decision_grade` threshold, not the standard's binary
two-path branch. **Fix: delete `ministry_endorsement` entirely (14→13 stages) and delete
`competent_authority` as a separate stage/screen/approval-level (13→12 stages)**, collapsing the tail
to `approval_by_authority` → [conditional] `local_governance_ministry` → `final_approval_archiving`.

**Explicitly NOT touched, and why — a scoping decision worth restating before someone assumes it was
missed:** Stage 54 (already built) added `requests.jurisdiction_test`, whose Q6
(`requires_central_approval`) is [D] Art. 45's *own* early, human-recorded answer to almost this exact
question ("هل توجد موافقة مسبقة أو لاحقة من وزارة الحكم المحلي؟"), captured at `requirements_check`
— and today it drives *nothing* downstream; it only gates the three `requirements_check` actions.
Swapping the post-committee branch's signal from `Request::requiresMinistryApproval()`
(`decision_grade` vs `RequestType.decision_grade_threshold`, purpose-built for exactly this gate,
already tested end-to-end including the Stage 45 ministry-delegate-seat guarantee) to
`jurisdiction_test.requires_central_approval` would be a second, *independent* design decision — which
signal drives ministry-routing — conflated with this stage's actual ask (how many *stages* the tail
has). STAGE_PLAN's own Build bullet is about stage/screen structure, not about re-plumbing which field
drives the existing bypass; changing both at once would make it much harder to isolate a regression
in either. Flagging this as a concrete, well-defined open item for a future stage (wire
`jurisdiction_test.requires_central_approval` into the branch, and decide what happens if it disagrees
with `requiresMinistryApproval()`) rather than silently deciding it here.

Also not touched: [A] §5's **Path 3** ("جهة الاعتماد ترفض التوصية أو تطلب تعديلها → إعادة مسببة
للجنة") — a send-the-minutes-back-for-reconsideration outcome with no current equivalent. STAGE_PLAN's
Stage 57 Build bullet doesn't name it, and it's a genuinely new capability (a new exception transition
+ status), not a restructuring of what exists — left as an open item, not built.

**The one real mechanical wrinkle, worth recording before writing the fix**: `WorkflowService::
destinationStageId()` already overrides `to_stage_id` at runtime (bypassing to whatever the low-grade
destination is) but the transition rule's `set_status_id` is always applied as-authored, unconditionally
— today that's harmless because the bypass target (`competent_authority`) still means "one approval
still pending" under either branch, so status `approved` is correct regardless of which branch fired.
Once the bypass target becomes `final_approval_archiving` directly, reusing the row's fixed `approved`
status would misrepresent "every approval done" as "still pending" — Stage 54b's own status
reconciliation confirmed `approved` deliberately spans "pending mayor OR pending ministry" (Art. 38
codes 15–16) while `final_approved` is the exact-match code 17 ("every approval complete"). Fix: a new
sibling `destinationStatusId()` mirroring the same `bypassesMinistryApproval()` predicate, used in
`applyRule()` everywhere `$rule->set_status_id` was read directly (both the model write and the
`RequestStatusHistory` row) — so the bypass path now stamps `final_approved` on arrival, matching the
non-bypass path's eventual arrival status once `local_governance_ministry` approves (that row's own
`set_status_id` changes from `approved` to `final_approved` too, since ministry approving is now the
literal last gate before `final_approval_archiving`).

**New stage numbering (14 → 12, `WorkflowStageSeeder`)**: delete the two stage rows first (their
`workflow_transitions` rows cascade-delete automatically — `from_stage_id`/`to_stage_id` are
`cascadeOnDelete` on `workflow_stages`, confirmed by reading the migration), then the existing
bump-by-1000-then-set-final-values idiom (same one Stage 51/52 already established) renumbers the
remaining 12: …6 reviewer_review, 7 observations, **8 forward_to_committee** (was 9), **9
receive_from_committee** (was 10), **10 approval_by_authority** (was 11), **11
local_governance_ministry** (was 12), **12 final_approval_archiving** (was 14). Stages 1–7 keep their
current order_no unchanged (both removed stages sit after them). No `target_days_min`/`max` value is
lost — both removed stages were seeded `null,null` (no sourced Stage 52 target) already.

**`WorkflowTransitionSeeder` changes**: `observations`'s `forward` row now targets
`forward_to_committee` directly (same role R02, same status `ready` — collapses two R02/R05 hops into
one, since `ministry_endorsement`'s only real content was "sit at another R05-owned stage before the
R05-owned agenda-insertion stage"). `local_governance_ministry`'s `approve` row now targets
`final_approval_archiving` directly with `set_status_id` = `final_approved` (was `competent_authority`
/ `approved`). The old `competent_authority → final_approval_archiving` row is deleted outright — no
replacement, since that stage no longer exists. Both stages also drop out of `cancellationRoles` and
`$openStageCodesForDeadlineEscalation` (their rows would cascade-delete anyway once the stage is gone,
but the seeder's own arrays would otherwise throw on a missing `$stages[...]` key on next reseed — a
loud failure, not a silent one, but removing them up front is the honest fix).

**`WorkflowService::APPROVAL_LEVELS`**: drop `competent_authority => 5`; `final_approval_archiving`
moves from level 6 to level 5. A full approval chain is now 5 ledger rows, not 6 (reviewer, committee,
admin-manager, [ministry], final-self-loop) — one fewer even on the path that still visits ministry,
since the old design needed two separate R07 clicks (`authority` then `final`) to close out a request
that had already cleared ministry; now one R07 self-loop click does it.

**Screens/routes**: delete the `authority_approval` screen (its `screen_role_permissions` rows
cascade-delete — confirmed via migration) — the **first** screen this seeder has ever had to actually
remove rather than just add/modify, so `ScreenSeeder` needs an explicit `Screen::whereIn(...)->delete()`
call, not just dropping the row from its upsert array (which would leave a stale, orphaned row behind).
Mirrored removals: `ApprovalController::LEVELS['authority']`, `RequestController::
actorCanApproveCurrentLevel()`'s `$screenByStage['competent_authority']`, `routes/api.php`'s
`$approvalScreens['authority']` (which also deletes the `/approvals/authority` routes for free, since
that array drives a loop), `ScreenRolePermissionSeeder::DEFAULTS['authority_approval']`, and the two
frontend arrays that mirror the same list generically (`router/index.js`'s approvals-screen map,
`AppSidebar.vue`'s `ICON_BY_CODE`). R07 keeps exactly one approval screen (`final_approval`) instead of
two.

**Test files needing real edits** (found by grepping the 4 stage codes + inspecting each hit, not
assumed): `tests/Unit/WorkflowServiceTest.php` — the full happy-path walk (14→12 steps, level sequence
range(1,6)→range(1,5), role-pluck list loses a duplicate `R07`), the seeded-rule-shape counts (15→13
non-exception rows, 16→14 cancel rows, both per-stage-code maps), and the low-grade ministry-skip test
(bypass destination `competent_authority`→`final_approval_archiving`, one fewer approval click).
`tests/Feature/CommitteeSeatRosterTest.php` — the ministry-delegate-seat guarantee test's bypass-branch
assertion (`competent_authority`→`final_approval_archiving`, plus asserting the new `final_approved`
status). Every other file the initial grep matched (`ApprovalChainTest`, `DecisionOutcomeTemplateTest`,
`CommitteeCandidatesDashboardTest`, `DecisionVotingTest`, `MeetingOutputsTest`, `RequestSlaTest`,
`RequirementsCheckJurisdictionTest`, my own `EmployeeRequestVisibilityTest`) only reference stage codes
that are unaffected by this change (`approval_by_authority`, `local_governance_ministry`,
`requirements_check`, etc., all kept as-is) or matched on an unrelated substring (`jurisdiction_test.
final_approval_authority`, a free-text field name, not the `final_approval` screen) — confirmed by
reading each hit in context, not assumed safe from the filename alone.

**Explicitly not touched**: `TEST_PLAN.md`/`TEST_PLAN.ar.md`'s approval-chain sections (already stale
against the diagram-alignment redesign per multiple earlier notes; a full docs sweep remains its own
deferred future session, not bundled into this stage). `STAGE_PLAN.md` itself (no prior stage edits it
to mark completion — AGENT_NOTES + git log are the completion record). `gap-analysis.md` §14 will get
a resolution note added after the build, same housekeeping Stage 54b did for its own table — git-ignored,
local-only, not a tracked-file change.

**Verification plan**: rewrite the two test files named above to match the new 12-stage/5-level chain;
add a tinker/manual smoke proving both branches end-to-end against the real database (grade at
threshold → visits `local_governance_ministry` → `final_approved` → `in_execution`; grade below
threshold → skips straight to `final_approval_archiving` → `final_approved` → `in_execution`); full
PHPUnit suite; Pint on every touched file; `npm run build`; reseed `WorkflowStageSeeder` +
`WorkflowTransitionSeeder` + `ScreenSeeder` + `ScreenRolePermissionSeeder` against the real
MySQL/Homestead database and confirm via tinker that both doomed stages/screen are actually gone (not
just unreferenced) and no transition row survives pointing at them.

---

### 2026-09-02 13:05 EET — Claude — Stage 56 complete (verify 3-way routing selection rule)

Built exactly per the plan below. Confirmed the free/manual-choice verdict by re-reading
`WorkflowTransitionSeeder`'s three `route_to_*` rows before writing anything — all three are gated
only on `requires_submitter_manager=true`, nothing about the request itself constrains the choice,
and neither [D] Art. 11/16 nor [E] stage 03 give a literal subject→body table to enforce instead.

One migration (`request_types.default_administrative_route`, nullable string, applied to the real
MySQL/Homestead database), `RequestType::$fillable`/docblock, and a documented 3-way split seeded in
`RequestTypeSeeder` (8 types → `committee_secretary`, `APPT`/`CTRC` → `hr`, `SECD`/`TRNS` → `diwan`) —
reseeded and verified via tinker against the real database. `RequestResource`'s `request_type` block
gained the new field (inherited by `RequestDetailResource` for free), and `RequestController::detailResource()`'s
eager-load widened to include it. **No change to `WorkflowService`/`WorkflowTransitionSeeder`'s
actual rules** — this is advisory only, per the stage's own "additive to the existing 3 paths, not a
replacement" instruction.

Frontend: `RequestDetailView.vue` gained a `suggestedRoutingAction` computed (non-null only at
`current_stage.code === 'administrative_routing'`) and a small badge rendered next to the matching
exception button among the existing three — the other two stay exactly as clickable as before. New
`requestDetail.suggestedRoute` locale key in both `ar.json`/`en.json`.

Verification: extended `DirectManagerRoutingTest.php` with a new test proving the suggestion round-trips
through the detail endpoint (`request_type.default_administrative_route`) and that all three routing
actions remain available and independently executable regardless of the suggestion — proving this is
advisory, not a gate. Full suite **219 tests / 1271 assertions** green (was 218/1264), Pint clean on
every touched/new file, `npm run build` passes with `RequestDetailView` picking up the new markup in
its existing chunk (then reverted `frontend/dist`, tracked in git, per every prior stage's note), and
the migration plus the `RequestTypeSeeder` reseed both ran clean against the real MySQL/Homestead
database — confirmed via tinker that all 12 types now carry a `default_administrative_route` value.
`docs/employee-committee-lifecycle/gap-analysis.md` gained a new §17 recording this verification in
full (git-ignored, local-only), and its §13 bullet plus the summary table's Stage 56 row were updated
to point at it.

Next per STAGE_PLAN's suggested order: **Stage 57** (pre-committee ministry stage + approval-tail
restructure) — flagged in STAGE_PLAN as the one item in this track with real blast radius, needing its
own design pass before touching code, not a quick fix alongside another stage — or **Stage 58**
(appeal/تظلم lifecycle), the largest remaining gap.

---

### 2026-09-02 12:35 EET — Claude — Stage 56 implementation plan (verify 3-way routing selection rule)

Building Stage 56 per STAGE_PLAN.md Track I: confirm whether `administrative_routing`'s 3-way
selection (`route_to_hr`/`route_to_diwan`/`route_to_committee_secretary`) is a subject-matter-based
rule per [D] Art. 11/16 and [E] stage 03 ("بحسب الموضوع"), or effectively a free/manual choice today.

**Verdict, checked against `WorkflowTransitionSeeder` before writing anything: it is a free manual
choice today.** All three routing rows are `seedException()` calls gated only on
`requires_submitter_manager=true` (the same actor as `direct_manager_review`) with
`required_role_id=null` — nothing about the request (type, department, content) constrains which of
the three the manager may pick. The docs don't give a literal mapping table either — Arts. 11/16 both
say "بحسب الموضوع" without enumerating which subjects go where — so there is no sourced rule to copy
verbatim, only a shape ("selection should track subject matter") to honor with a documented judgment
call, same category as Stage 47's/53's own flagged judgment calls.

**Per the stage's own instruction, this is additive, not a replacement of the 3 manual paths**: a
manager can still pick any of the three routes freely; a new per-`request_type`
`default_administrative_route` column (`hr|diwan|committee_secretary`, nullable) supplies a
*suggested* route surfaced in the UI as a badge on the matching action button — informational only,
no server-side enforcement, no new gate, mirroring Stage 52's "soft, non-blocking" precedent for
per-stage timeframes.

**The 3-way split** (documented reasoning, not sourced): `PROM/CONF/ALLW/LEAV/EOSV/SETL/GRIV/PEVG` →
`committee_secretary` (already committee-track staff matters — رئيس قسم شؤون الموظفين preps these
directly for the committee, no cross-body coordination needed first); `APPT/CTRC` → `hr` (hiring/
contracting are core HR administrative functions — مدير إدارة الموارد البشرية); `SECD/TRNS` →
`diwan` (moving an employee across organizational units or to/from another body has municipality-wide
governance implications — وكيل الديوان). Flagging this explicitly as a starting point to replace
outright, not layer on top of, if a future session gets the actual per-subject mapping from [D]'s
appendix.

**Backend**: one migration (`request_types.default_administrative_route`, nullable string, after
`required_documents`), added to `RequestType::$fillable`+docblock, seeded per the split above in
`RequestTypeSeeder`, exposed in `RequestResource`'s `request_type` array (inherited by
`RequestDetailResource` for free — no separate change needed there). **Frontend**: `RequestDetailView.vue`
gains a `suggestedRoutingAction` computed (only non-null when `current_stage.code ===
'administrative_routing'`, mapping the type's `default_administrative_route` to its matching action
name) and a small suggested-route badge rendered next to that one exception button among the existing
three — the other two stay exactly as clickable as before. New `requestDetail.suggestedRoute` locale
key in both `ar.json`/`en.json`.

**Verification plan**: extend `DirectManagerRoutingTest.php` (or a new test) asserting the seeded
`default_administrative_route` values round-trip through `/requests/{id}` for a couple of
representative types, and that all three routing actions remain available/executable regardless of
the suggestion (proving this is advisory, not a gate) — plus the full PHPUnit suite, Pint, `npm run
build`, and `php artisan migrate` + the `RequestTypeSeeder` reseed against the real MySQL/Homestead
database.

---

### 2026-09-02 12:15 EET — Claude — Stage 55 complete (verify `direct_manager_review` gate — confirmed compliant, no code change)

Per STAGE_PLAN.md Track I, Stage 55 is a verification-only stage ("adjust copy/validation messages
only if an actual gap is found, not the gate mechanism itself") — same category as Stage 38. Re-read
every source touching [D] Art. 10's "no withholding without a written reason" (the manual index's own
"Art. 10" text is the 5-seat-roster article; the actual citation traces through
`official-detailed-flow.md`'s comparison note and gap-analysis.md §13's paraphrase) against the real
`WorkflowTransitionSeeder` rows and the generic exception-action UI.

**Verdict: already compliant, nothing to change.** `forward` (the sole deterministic continuation) is
`is_exception=false`/`requires_comment=false` and renders as the primary button — matching [E] stage
02's "review-then-forward" default framing. `return_to_employee` and `cancel` are both manager-gated
exceptions with `requires_comment=true` (the `seedException()` default), enforced both client-side
(the exception-reason modal won't submit empty) and server-side
(`WorkflowTransitionException::commentRequired()`), and both render under the UI's visually-secondary
"إجراءات الاستثناء" block, not as equal-weight buttons next to `forward` — matching [E]'s "not a
routine gate" framing for blocking. The reason is permanently written to the stage-log/status-history
row, satisfying "documented reason" rather than a UI-only prompt. Art. 10's other half, "دون تأخير غير
مبرر" (no unjustified delay), is already covered generically: `direct_manager_review` is a normal open
stage under Stage 17's SLA system and is already in `WorkflowTransitionSeeder`'s
`$openStageCodesForDeadlineEscalation` list, so an overdue manager review already escalates via the
seeded `deadline_expired` exception like every other open stage.

No wording change was made deliberately: the generic `commentRequired()` message and exception-modal
copy are shared verbatim by every comment-required action app-wide (return_missing_docs, reject_review,
reject_formally, request_edit, defer, cancel, declare_no_jurisdiction, etc.) — carving out
stage-specific wording for just this one action would break that established one-mechanism convention
for a distinction Art. 10 itself doesn't ask for.

**Deliverable**: `docs/employee-committee-lifecycle/gap-analysis.md` gained a new §16 recording this
verification in full (git-ignored, local-only — doesn't show in `git status`), and its §13 bullet plus
the summary table's Stage 55 row were updated to point at it. No migration, no seeder change, no
PHP/Vue file touched — matching this stage's own scope. No PHPUnit run needed (nothing executable
changed).

Next per STAGE_PLAN's suggested order: **Stage 56** (verify 3-way `administrative_routing` selection
rule — same verification-only shape as this stage).

---

### 2026-09-02 11:40 EET — Claude — Stage 54b complete (status-vocabulary reconciliation)

Built exactly per the plan below. `docs/employee-committee-lifecycle/gap-analysis.md` §4 (git-ignored,
local-only, so this doesn't show up in `git status`) now carries the full 28-row reconciliation table
plus a decisions summary, replacing the old one-paragraph finding; the stale "25 statuses" count was
corrected to 28 in both the baseline-facts section and §4's own heading. Every current status was kept
as-is (code and label) — the divergences found are all either exact matches, deliberate coarser merges
the status/stage split already explains, deliberate finer splits with a traced functional reason (the
3-way routing statuses; `completion_required` vs. `deferred` per Art. 27), or this-system-only
procedural pauses Art. 38 doesn't itemize. One substantive finding was flagged rather than silently
fixed: `rejected`/`cancelled` each currently span meanings Art. 38 keeps separate (pre-committee
administrative rejection vs. a committee's own non-approval decision) — left open for a future stage,
since building a distinct status is new-feature scope, not a reconciliation decision.

Only one tracked file changed: `RequestStatusSeeder`'s class docblock gained a pointer to the
reconciliation document, so a future reader doesn't mistake a merged/split/reused status for an
oversight. No migration, no seeded value change, no route/controller/frontend change — confirmed the
full PHPUnit suite is still **218 tests / 1264 assertions** green (unchanged from Stage 54, as
expected for a docblock-only tracked change) and Pint is clean on the one touched file.

Next per STAGE_PLAN's suggested order: **Stage 55** (verify `direct_manager_review` gate wording) —
flagged in STAGE_PLAN as likely already close to compliant, confirm before assuming a rebuild is
needed.

---

### 2026-09-02 11:20 EET — Claude — Stage 54b implementation plan (status-vocabulary reconciliation)

Building Stage 54b per STAGE_PLAN.md Track I: a documentation/housekeeping stage, same category as
Stage 38 ("no code change — this stage is the housekeeping the other 20 depend on") — the Build bullet
itself asks to "document the mapping... and decide, per divergent status, whether to rename/merge/
keep," not to redesign the status machine.

**First correction, before building the table**: `gap-analysis.md`'s own baseline count ("25
statuses") is stale — `RequestStatus::count()` against the real database is **28** today (confirmed
via tinker), since `outside_jurisdiction` (Stage 49) postdates when that count was written and the
4 routing/registration statuses from the diagram-alignment redesign were undercounted in the doc's own
arithmetic (9+4+3+5+2+4=27, not 25, even before adding `outside_jurisdiction`). Both the baseline
section and §4's own heading get corrected to 28 as part of this pass.

**Method**: traced every `set_status_id` in `WorkflowTransitionSeeder` and every `to` in
`CommitteeStatusService::ACTIONS` to know EXACTLY which real-world moment each of the 28 current
statuses represents (not guessed from the name), then compared each against Art. 38's 20-code
dictionary (`official-procedures-manual-index.md` §5, already transcribed in full). Building a
per-current-status table (28 rows), not a per-Art-38-code table, since Stage 54b's own Build bullet
frames the decision as "per divergent status."

**The substantive finding worth flagging up front, not buried in the table**: `rejected` and
`cancelled` are each reused across meanings Art. 38 keeps distinct. `rejected` fires both from
`reviewer_review` (an R02 case officer's own kickback) and now also from `requirements_check`'s new
Stage 54 `reject_formally` — both are *pre-committee administrative* rejections, which Art. 38 has no
code for at all (its dictionary only reaches a rejection-shaped code at 13 "غير موافق عليها," a
*committee* non-approval, post-meeting). `cancelled` is reused for plain administrative withdrawal at
any open stage AND for the committee's own `reject` decision outcome (`DecisionController`'s
outcome→action map sends `reject` through the generic `cancel` self-loop) — the latter usage is the
one that's actually closest to Art. 38's 13, but it's indistinguishable in the data from an ordinary
cancellation. This is recorded as an open finding for a future stage to weigh (e.g., a possible future
`rejected_by_committee` status), not fixed here — adding a new status/workflow branch is new-feature
scope, not a reconciliation-table decision.

**Everything else is either an exact match (`outside_jurisdiction`↔14, `on_agenda`↔09,
`under_discussion`↔10, `final_approved`↔17, `in_execution`↔18), a deliberate coarser merge this
system's status+stage split already explains (`in_review` spans Art. 38's 01–03, `ready` spans 06–08,
`approved` spans 15–16, `completed_closed` merges 19+20), a deliberate finer split with its own
documented reason (the 3 `routed_to_*` statuses split Art. 38's single 02; `completion_required` vs.
`deferred` were already correctly kept apart per Art. 27's "غير مستوفٍ ≠ مؤجل" rule, verified in
Stage 49's own planning note), or a this-system-only procedural pause Art. 38 doesn't itemize
(`legal_opinion_requested`, `referred_to_other_body`, `nominated_for_committee`,
`awaiting_recommendation_approval`, `returned`)** — verdict for every one of these: **keep**. No
current status name is actually *wrong* against Art. 38, only coarser, finer, or differently
sequenced (this system's committee-secretary/case-officer role split, already a known and accepted
Track G decision, means several of Art. 38's early codes — 03 through 08 — don't map onto this
system's pipeline in the same order [D] describes; that's a pipeline-shape difference already made
and recorded, not a status-naming problem to fix here). A rename would misrepresent already-correct
labels as if they needed fixing.

**Deliverables**: the full reconciliation table + decisions replaces the current one-paragraph §4 in
`gap-analysis.md` (git-ignored, local-only — no code, no migration, no seeder value change); a
one-line pointer comment added to `RequestStatusSeeder`'s class docblock referencing the reconciliation
for traceability, the only tracked-file change this stage makes. No PHPUnit run needed (nothing
executable changed) beyond confirming the status count via tinker, already done above.

---

### 2026-09-02 10:55 EET — Claude — Stage 54 complete (committee jurisdiction validation at initial review)

Built exactly per the plan below. One migration (`requests.jurisdiction_test`, nullable json, applied
to the real MySQL/Homestead database) and two new self-loop `WorkflowTransitionSeeder` rows at
`requirements_check` (`declare_no_jurisdiction` reusing Stage 49's status/action name; `reject_formally`
reusing `reject_review`'s `rejected` status) — reseeded and verified via tinker (60 transitions total,
both new rows self-looping, `is_exception=true`, `requires_comment=true`).

New `RecordJurisdictionTestRequest` (all 6 of Art. 45's questions required together — a partial answer
set would misrepresent Art. 45's "not finalized until all 6 are answered" as done) and
`RequestController::recordJurisdictionTest()`, riding the same `notes_attachments,edit` grant
`updateFinancialImpact()` already uses, at new `PATCH requests/{requestRecord}/jurisdiction-test`. The
gate itself lives in two places, not one — a real design point worth restating here since the plan
below only anticipated the first: `RequestController::transition()` (the generic detail-screen
endpoint) **and** `ApprovalController::store()` (the dedicated reviewer approval queue, R02's primary
approve path per Stage 18's own comment calling `/transition` "the older generic workspace endpoint").
Both call `WorkflowService::transition()` for the same `requirements_check` `approve` action, so gating
only one would have left the jurisdiction test trivially bypassable through the other — caught before
writing tests, not after, by tracing every caller of `WorkflowService::transition()` for this stage/
action pair. `detailResource()`'s `available_transitions`/`available_actions` preview gained a matching
filter so the SPA never offers a button either endpoint would refuse, mirroring the existing Stage 18
approve-permission filter's exact shape (controller-layer, not inside `WorkflowService`, which stays a
pure role/manager/status engine — consistent with why `actorCanApproveCurrentLevel()` already lives at
this same layer).

**One pre-existing test file needed a legitimate fixture update, not a regression fix**:
`ApprovalChainTest::requestAt()` now seeds a satisfied `jurisdiction_test` by default, so its six
scenarios (queue approval, missing-signature 422, cross-level refusal, revoked-permission refusal,
detail-transition approval, self-approval refusal) stay about the mechanics they were written to test
rather than becoming confounded by the new gate — documented in-place with a comment explaining why.

Frontend: `RequestDetailView.vue` gained a jurisdiction-test card (visible only at
`current_stage.code === 'requirements_check'`) with the 6 fields as a draft form (synced from
`request.jurisdiction_test` on every load, PATCHed on save), and `reject_formally` was added to the
existing `destructive`-styling class list alongside `reject_review`/`cancel` — `declare_no_jurisdiction`
needed zero new frontend code since it reuses Stage 49's existing locale key and generic exception-
button rendering. New `requestDetail.jurisdictionTest.*` + `workflow.actions.reject_formally` locale
keys in both `ar.json`/`en.json` (884 keys each side, zero on-one-side-only, verified
programmatically).

Verification: new `tests/Feature/RequirementsCheckJurisdictionTest.php` (7 tests — recording round-
trips through the detail resource; a partial answer set 422s; all three gated actions refused before
recording, both via `/transition` and via the reviewer approval queue; `return_missing_docs` unaffected;
all three gated actions succeed once recorded, landing at the right stage/status;
`available_transitions` excludes the gated actions before recording and includes them after), full
suite **218 tests / 1264 assertions** green (was 211/1215), Pint clean on every touched/new file,
`npm run build` passes with `RequestDetailView` picking up the new markup in its existing chunk (then
reverted `frontend/dist`, tracked in git, per every prior stage's note), and both the migration and the
`WorkflowTransitionSeeder` reseed ran clean against the real MySQL/Homestead database.

Next per STAGE_PLAN's suggested order: **Stage 54b** (status-vocabulary reconciliation) or **Stage 55**
(verify `direct_manager_review` gate wording) — both still open.

---

### 2026-09-02 10:20 EET — Claude — Stage 54 implementation plan (committee jurisdiction validation at initial review)

Building Stage 54 per STAGE_PLAN.md Track I: `requirements_check` (R02's initial-review checkpoint,
stage 5) currently has only 3 outcomes — `approve`/`return_missing_docs`/`cancel` — with no
jurisdiction check at all. [D] Art. 45's 6-question test ("classification is not finalized until all
6 are answered") and [A] §4 stage 3's 4 named results (مقبول شكليًا وينتقل للدراسة / متوقف الستكمال
نواقص / محال لجهة أخرى لعدم الاختصاص / مرفوض شكلاً بقرار مسبب) are the two sources named by the
stage.

**Mapping the 4 results onto the 3 existing + 2 new outcomes**: #1 (accepted, moves to study) is the
existing `approve`; #2 (suspended for missing docs) is the existing `return_missing_docs` — both
unchanged. #3 (referred elsewhere for lack of jurisdiction) is genuinely missing — gap-analysis.md §5
says so explicitly ("no 'عدم اختصاص' outcome anywhere in the main workflow" outside the committee
stage). #4 (formally rejected, with a reasoned decision) is also genuinely missing — nothing at
`requirements_check` today lets R02 reject a request outright with a reason, only kick it back
(`return_missing_docs`) or park it (`cancel`).

**#3 reuses the exact action name `declare_no_jurisdiction`, seeded a second time at
`requirements_check`** (self-loop, R02, `requires_comment=true`, status `outside_jurisdiction` — the
same status Stage 49's committee-level declaration already uses). Same real-world fact (the matter is
outside the committee's jurisdiction), just determined at an earlier checkpoint — reusing the action
name means the frontend's generic `actionLabel()`/`workflow.actions.declare_no_jurisdiction` lookup
and its exception-button rendering need zero new code, and it's the kind of vocabulary consolidation
Stage 54b's own charter favors over inventing a near-duplicate. **#4 is a genuinely new action**,
`reject_formally` (self-loop, R02, `requires_comment=true`, status `rejected` — reusing the existing
`rejected` status `reject_review` already produces at a different stage/action, rather than adding a
new status: `ReportMetricsService::ABANDONED_STATUSES` already treats `rejected` as an abandoned
outcome, which is the correct classification for this too). Neither outcome is modeled as terminal in
`WorkflowService::hasTerminalStatus()` — matches the existing precedent that `rejected`/
`outside_jurisdiction` already aren't in that list, so a request can still be re-acted-on afterward if
a reviewer reconsiders (same as `reject_review`'s kickback today).

**"Gated on the 6-question test"**: a new nullable `requests.jurisdiction_test` JSON column holds
structured answers to Art. 45's 6 questions (`has_legal_basis`, `employee_covered`,
`within_municipal_jurisdiction`, `committee_decides` [binding vs. advisory-only — Q4],
`final_approval_authority` [free text — Q5], `requires_central_approval` [Q6]) — booleans plus one
free-text field, not a free-text blob, so the record is queryable/auditable rather than just another
comment. New `PATCH requests/{requestRecord}/jurisdiction-test` (new `RecordJurisdictionTestRequest`,
all 6 required) rides the same `notes_attachments,edit` grant (R01/R02) `updateFinancialImpact`
already uses for this exact kind of narrow ancillary correction — no stage restriction on the PATCH
itself (a working draft, editable any time, mirroring that precedent). The actual gate: `approve`,
`declare_no_jurisdiction`, and `reject_formally` at `requirements_check` are refused (422) until
`jurisdiction_test` is non-null — enforced in `RequestController::transition()` as a controller-level
business check, the same layer the existing Stage 18 approve-permission check already lives at (not
inside `WorkflowService`, which stays a pure role/manager/status engine). `return_missing_docs` is
exempt — a file with missing documents can't be honestly classified yet, matching [A]'s own stage
ordering (فحص أولي → استكمال النواقص, completion happens after classification, not before it).

**Preview/execution agreement, the load-bearing property `actorMayUse()`'s docblock already
insists on**: `RequestController::detailResource()`'s `available_transitions`/`available_actions`
filter (which already excludes `approve` when `actorCanApproveCurrentLevel()` fails) gains a second
filter excluding the same 3 gated actions at `requirements_check` when `jurisdiction_test` is still
null — so the UI never offers a button the transition endpoint will refuse. This mirrors the existing
approve-permission filter's shape exactly (a controller-layer filter, not an `actorMayUse()` change),
consistent with that method's own precedent of layering business rules outside the raw workflow-rule
engine.

**Frontend**: `RequestDetailView.vue` gains a jurisdiction-test card (visible only when
`current_stage.code === 'requirements_check'`) with 6 fields (4 yes/no selects, 1 binding/advisory
select, 1 free-text authority field) and a save button riding `v-can="'notes_attachments.edit'"`,
mirroring the financial-impact toggle's PATCH-and-replace pattern. No new exception-button code is
needed for either new action — `declare_no_jurisdiction` already renders generically (same locale key
as its Stage 49 use), and `reject_formally` needs only a new `workflow.actions.reject_formally` locale
key plus adding it to the existing `destructive`-styling class list alongside `reject_review`/`cancel`.

**Verification plan**: new `tests/Feature/RequirementsCheckJurisdictionTest.php` — recording the test
round-trips through the detail resource; `approve`/`declare_no_jurisdiction`/`reject_formally` are all
refused with 422 before it's recorded and all three succeed after; `return_missing_docs` and `cancel`
are unaffected either way; `declare_no_jurisdiction` sets `outside_jurisdiction` and `reject_formally`
sets `rejected`, both as self-loops (current_stage unchanged); `available_transitions` excludes the 3
gated actions before recording and includes them after — plus the full PHPUnit suite, Pint on
touched/new files, `npm run build`, locale key-parity, and `php artisan migrate` +
`db:seed --class=WorkflowTransitionSeeder` against the real MySQL/Homestead database.

---

### 2026-09-02 09:55 EET — Claude — Stage 53 complete (RequestType catalogue + per-type document checklists)

Built exactly per the plan below. One migration (`request_types.required_documents`, nullable json,
applied to the real MySQL/Homestead database), `RequestType` cast to `array`, and
`RequestTypeSeeder` grown from 7 to 12 rows (`CONF`/`APPT`/`CTRC`/`SETL`/`PEVG` added; every existing
type also gained a seeded checklist, not just the new ones — an empty `required_documents` array on
`LEAV`/`ALLW`/etc. would have rendered no checklist card at all, a worse gap than a reasonably-derived
starting list). `SETL` picked up `default_has_financial_impact = true`, closing the exact gap Stage
47's own note flagged ("settlement" was in that stage's example list with no matching type row yet).

`RequestController::intakeOptions()`'s `types` payload gained `required_documents` — the one endpoint
the intake screen actually calls — and `RequestIntakeView.vue` renders it as a read-only checklist
card (bilingual `{ar, en}` pairs) once a type is picked, positioned between the basic-data fieldset
and the attachments fieldset. No new validation, no enforcement — purely informational, matching the
stage's own "soft checklist" framing. New `intake.requiredDocuments.*` locale keys in both
`ar.json`/`en.json` (865 keys each side, zero on-one-side-only, verified programmatically).

No admin CRUD screen was built for `RequestType` — confirmed by grep that none exists today (no
`request_types` screen in `ScreenSeeder`, every read of the model elsewhere is lookup-only), and the
stage's Build bullet only asked for the catalogue + checklists to exist, not an edit UI; the data
stays seeded-only, matching `default_sla_days`/`decision_grade_threshold`'s existing precedent.

Verification: extended `RequestIntakeTest.php` with a new test asserting all 12 codes are present in
`/requests/intake-options` and that `PROM`'s checklist carries bilingual entries, full suite **211
tests / 1215 assertions** green (was 210/1209), Pint clean on every touched/new file, `npm run build`
passes with `RequestIntakeView` picking up the new markup in its existing chunk (then reverted
`frontend/dist`, tracked in git, per every prior stage's note), and both the migration and the
`RequestTypeSeeder` reseed ran clean against the real MySQL/Homestead database — confirmed via tinker
that all 12 types exist with a non-empty `required_documents` array each.

**Sourcing caveat, worth repeating for whoever reads this later**: the seeded checklists are derived
from what `official-procedures-manual-index.md` §7 (Arts. 48–79) says each type-specific chapter
requires, not copied from [D]'s literal per-type document appendices (the index is explicitly a
structured summary, not a verbatim transcription, per its own README caveat) — treat these as a
reasonable starting point, replace outright rather than layer on top once a future session has the
actual appendix pages in hand.

Next per STAGE_PLAN's suggested order: **Stage 54** (committee jurisdiction validation at initial
review) or **Stage 54b** (status-vocabulary reconciliation) — both still open.

---

### 2026-09-02 09:30 EET — Claude — Stage 53 implementation plan (RequestType catalogue + per-type document checklists)

Building Stage 53 per STAGE_PLAN.md Track I: `RequestType` currently has 7 rows (PROM/LEAV/ALLW/
SECD/GRIV/TRNS/EOSV) against [D] Art. 46's six رئيسية functional groups (التعيين، التعاقد، الترقية،
الندب، الإعارة، النقل) plus Art. 47's secondary categories — several of Art. 46/47's named categories
have no matching row today, and none carry a per-type required-document checklist at all.

**New types, exactly the ones the stage's own Build bullet names, no more:** `CONF` (تثبيت بعد
الاختبار / Confirmation after probation — [A]'s jurisdiction item #1, [D] Arts. 48–51's own first
type-specific chapter), `APPT` (التعيين / Appointment), `CTRC` (التعاقد / Contracting), `SETL`
(تسوية وضع وظيفي / Employment status settlement — Arts. 72–74's "the specific thing being settled
must be named" caution applies to how this type's own request title/description should be filled in,
not to anything this stage enforces), `PEVG` (تظلم من تقييم أداء / Performance-evaluation grievance —
Arts. 70–71 + 75–79: a grievance against a performance report is legally distinct from the generic
`GRIV` grievance, since Art. 70 says the committee never substitutes for the direct manager's
evaluation itself, only for a grievance *against* one with legal effect). Not adding a separate
`الإعارة` (loan-to-another-body, Arts. 65–66) row this stage — Art. 46/47's own text treats الندب
and الإعارة as two distinct legal mechanisms, but the stage's Build bullet doesn't name it explicitly
and `SECD` ("Secondment"/انتداب) already occupies that conceptual space closely enough that adding a
second, near-synonymous row without being asked would be scope creep, not a named gap.

**`default_has_financial_impact` for the new types**: `SETL` gets `true` — Stage 47's own note
explicitly named "settlement" as one of its five financial-impact examples
("promotion, settlement, allowance, back-pay, grade change") that had no matching `RequestType` row
yet and flagged Stage 53 as the place to close that gap; this stage closes it. The other four new
types (`CONF`, `APPT`, `CTRC`, `PEVG`) default `false`, matching every other type outside Stage 47's
named list.

**`required_documents` — new nullable JSON column, seeded as a soft, informational checklist only,
not enforced anywhere.** Migration `add_required_documents_to_request_types_table` adds
`required_documents` (json, nullable, `after('default_has_financial_impact')`), cast to `array` on
the model. Each entry is a bilingual `{ar, en}` pair, matching the app's house convention of
name_ar/name_en pairs rather than a single-locale string list.

**Honest sourcing caveat, stated up front rather than glossed over**: `official-procedures-manual-
index.md` §7 (Arts. 48–79) is, per its own README, a **structured index of [D]'s 142 pages, not a
verbatim transcription** — it names each type-specific chapter's legal principles and what the
committee verifies, but does not reproduce the literal per-type document-list appendices article by
article. So the checklist seeded here is built from what the index *does* say each chapter requires
(e.g. Art. 75–79's grievance intake fields are described almost verbatim: "the grievance vs. the
original decision, date of knowledge, reasons, the specific ask, supporting documents" — used
directly for `GRIV`/`PEVG`; Arts. 52–57's "HR must first build a ملف استحقاق الترقية confirming legal/
employment/financial conditions" — used for `PROM`'s checklist entries) rather than copied from an
appendix this session cannot read verbatim. This is a judgment-call starting checklist, not a sourced
appendix transcription — flagged here the same way Stage 52's ratio thresholds were flagged, so a
future session with the actual PDF's appendix pages in hand replaces these seeded arrays outright
rather than layering a second interpretation on top. Every existing type (`LEAV`, `ALLW`, `SECD`,
`TRNS`, `EOSV`) also gets a first checklist for the same reason — a type with an empty
`required_documents` array renders no checklist card on the intake screen, which is a worse UX gap
than a reasonably-derived starting list.

**No new admin CRUD screen for `RequestType`.** Confirmed by grep: no `request_types` screen exists
in `ScreenSeeder`, and `RequestType` is read-only lookup data everywhere it's used
(`RequestController::filters()/intakeOptions()`, `ReportController`, `StoreRequest`). Building a
management UI is out of this stage's scope — the Build bullet only asks for the catalogue and its
checklists to exist, not an edit screen; `required_documents` stays seeded, matching how
`default_sla_days`/`decision_grade_threshold` are already seeded-only today with no edit UI.

**Wiring**: `RequestController::intakeOptions()`'s `types` payload gains `required_documents`
alongside its existing fields — that's the one endpoint the intake screen actually calls.
`RequestIntakeView.vue` gets a new read-only checklist card shown once a type is selected
(`selectedType.required_documents`, bilingual via the existing `name()`-style locale helper),
positioned between the basic-data fieldset and the attachments fieldset — informational only, no
new validation, matching the stage's own "soft checklist" framing. New `intake.requiredDocuments.*`
locale keys in both `ar.json`/`en.json`.

**Verification plan**: extend `RequestIntakeTest.php` (or add a new test) asserting
`GET /requests/intake-options` returns `required_documents` for a seeded type and `[]`/`null`-safe for
one with none; a quick seeder assertion that all 12 types now exist with the expected codes; the full
PHPUnit suite, Pint on touched/new files, `npm run build`, and `php artisan migrate` +
`db:seed --class=RequestTypeSeeder` against the real MySQL/Homestead database.

---

### 2026-09-01 23:40 EET — Claude — Stage 52 complete (per-stage operational timeframes / soft SLA)

Built exactly per the plan below. One migration (`workflow_stages.target_days_min`/`target_days_max`, both nullable),
applied to the real MySQL/Homestead database, plus a `WorkflowStageSeeder` reseed carrying the [A] §12 → stage
mapping recorded in the plan — verified via tinker: 7 of the 14 stages got a target (`receive_and_register`=1,
`requirements_check`=3, `reviewer_review`=5, `observations`=5–10, `receive_from_committee`=3,
`approval_by_authority`=5, `final_approval_archiving`=5), the other 7 stayed `null, null`.

`Request::latestStageLog()` (`hasOne(...)->latestOfMany('acted_at')`) and `Request::stageTimeliness(): ?array`
(the ratio-based green/yellow/red/critical bucketing) landed exactly as planned. **One real gotcha, not scope
creep**: eager-loading `latestStageLog` with a restricted column list (`'latestStageLog:id,request_id,acted_at'`)
breaks — Laravel's generated `latestOfMany` subquery join produces an "ambiguous column name: request_id" SQL
error, because the column restriction interferes with the join's own internal aliasing. Fixed by eager-loading the
relation with no column restriction (`'latestStageLog'`) in both `RequestController::index()` and
`detailResource()`; `currentStage`'s column list still restricts to the fields actually needed
(`target_days_min`/`target_days_max` added there). `RequestResource::stageTimeliness()` is unconditional, so every
other endpoint reusing that resource (`ApprovalController`, `CommitteeCandidateController`, `ReportController`) now
also calls it — verified this is silently safe, not an N+1 risk: those controllers eager-load `currentStage` with a
column list that omits the two target columns, so `stage_timeliness` resolves to `null` there without touching
`latestStageLog` at all (the method checks `target_days_max === null` before ever touching the log relation) —
deliberately not extending soft-SLA visibility to those other lists, since Stage 52's own scope is the general
request workspace, not every list screen.

Frontend: `RequestResource`'s new field surfaces on both `RequestsView.vue` (a small colored dot next to the stage
name, tooltip-labelled) and `RequestDetailView.vue`'s summary card (level badge + elapsed/target days line,
styled after the existing `documentsComplete`/`financialImpact` rows) — both `v-if`-gated on the field being
non-null, so a stage with no sourced target shows nothing rather than a fabricated "on target". New
`requestDetail.stageTimeliness.*` locale keys in both `ar.json`/`en.json`. Never blocking — no gate, no workflow
change, purely informational, per the stage's own Goal line.

Verification: new `tests/Feature/StageTimelinessTest.php` (6 tests — green/yellow/red/critical bucketing against a
hand-set `RequestStageLog.acted_at`, a stage with no sourced target reporting `null`, and the field round-tripping
through both the list and detail endpoints), full suite **210 tests / 1209 assertions** green (was 204/1194), Pint
clean on every touched/new file, `npm run build` passes with `RequestsView`/`RequestDetailView` picking up the new
markup in their existing chunks (then reverted `frontend/dist`, tracked in git, per every prior stage's note),
locale key-parity verified programmatically (863 keys each side, zero on-one-side-only), and the migration plus the
`WorkflowStageSeeder` reseed both ran clean against the real MySQL/Homestead database. **Not verified: no browser
this session** — the badge/dot's layout is calculated from the existing `.status`/`.sla-alert` patterns, not
observed, consistent with every prior UI-touching note.

**Open item for whoever builds Stage 53+**: the [A] §12 → 14-stage mapping and the 1.0/1.5/2.0 ratio thresholds are
both documented judgment calls, not sourced figures — if a future session gets hold of [D]'s actual appendix
(الملحق 7/8/37/38/71) with its real per-stage day counts and bucket boundaries, replace the seeded values and the
threshold constants in `Request::stageTimeliness()` outright rather than layering a second interpretation on top.

---

### 2026-09-01 23:10 EET — Claude — Stage 52 implementation plan (per-stage operational timeframes / soft SLA)

Building Stage 52 per STAGE_PLAN.md Track I: a non-blocking, per-`workflow_stages` target duration, surfaced as a
green/yellow/red/critical escalation indicator — explicitly *not* a replacement for Stage 17's hard per-type
`due_date`/`overdue_at` SLA, which stays untouched.

**Source figures, not fabricated.** [D]'s own appendix ("الملحق 7, 8, 37, 38, 71" — "المدد التشغيلية المستهدفة") is
the named source but its exact day-counts aren't reproduced anywhere in the locally-indexed docs; what *is* already
quoted verbatim, and cross-referenced in `official-process-summary.md` as "Cf. [D]'s appendix... the primary
reference for Track I Stage 52", is [A] §12's 10-step non-binding timeframe list (تسجيل الطلب وإشعار الموظف = يوم
عمل; الفحص الأولي = 3 أيام; طلب النواقص = خلال 3 أيام; الدراسة الإدارية والفنية = 5–10 أيام; المراجعة القانونية = 5
أيام; الإدراج في جدول الأعمال = أول اجتماع متاح; إعداد المحضر بعد الاجتماع = 3 أيام; الاعتماد المحلي = 5 أيام؛ تنفيذ
القرار = 5 أيام; تبليغ الموظف = يوم-يومان). Using those.

**Mapping [A] §12's 10 process steps onto the current 14 `workflow_stages` rows is a judgment call, recorded here
rather than left implicit** — the two lists don't share granularity (some [A] steps are exception sub-flows or
cross-cutting notification steps with no dedicated stage; some current stages, notably the diagram-alignment
redesign's stages 2–3, postdate [A]'s process description entirely and have no timeframe named for them at all):
- `receive_and_register` (4) ← "تسجيل الطلب وإشعار الموظف" = 1 day (exact name match: "الاستلام والتسجيل").
- `requirements_check` (5) ← "الفحص الأولي" = 3 days.
- `reviewer_review` (6) ← "المراجعة القانونية" = 5 days (its own name, "مراجعة المقرر وفق اللوائح", is a
  regulatory/legal-conformance check — the closest fit).
- `observations` (7) ← "الدراسة الإدارية والفنية" = 5–10 days (this is already this codebase's established "الدراسة"
  checkpoint, per Stage 47/51's notes).
- `receive_from_committee` (10) ← "إعداد المحضر بعد الاجتماع" = 3 days (post-vote/decision minutes prep happens at
  this stage before the record moves to `approval_by_authority`).
- `approval_by_authority` (11) ← "الاعتماد المحلي" = 5 days (exact conceptual match — local approval, per
  permissions).
- `final_approval_archiving` (14) ← "تنفيذ القرار بعد اعتماده" = 5 days (closest available stage for
  post-decision execution/closing; "تبليغ الموظف" (1–2 days) is a notification sub-step already handled by
  `NotificationDispatcher`, not modeled as its own stage, so it isn't separately targeted).
- Left **without a target** (`target_days_min`/`max` both null — no indicator renders, not a fabricated "on
  target"): `receive_from_municipality` (1, pre-registration intake, no [A] figure), `direct_manager_review` (2)
  and `administrative_routing` (3) (both postdate [A]'s source text — added by the diagram-alignment redesign),
  `ministry_endorsement` (8), `forward_to_committee` (9) ("الإدراج في جدول الأعمال" is "first available meeting",
  not a day count), `local_governance_ministry` (12), `competent_authority` (13) (central-ministry steps, [A] only
  says "عند الحاجة", no duration given).

**Mechanism**: two new nullable `workflow_stages` columns, `target_days_min`/`target_days_max`
(`unsignedSmallInteger`), seeded per the mapping above (both set together or both left null — no case needs a
lone min or max). `Request` gains a `latestStageLog(): HasOne` (`hasOne(RequestStageLog::class)->latestOfMany
('acted_at')`) — the latest stage-log row's `acted_at` is definitionally when the request arrived at its current
stage, since every `WorkflowService`/`CommitteeStatusService` transition that changes `current_stage_id` writes a
matching log row in the same transaction. New `Request::stageTimeliness(): ?array` (mirroring `isOverdue()`/
`documentsComplete()`'s "small derived-fact method" pattern) returns `null` when the current stage has no target,
else `{level, elapsed_days, target_days_min, target_days_max}`. **Threshold scheme is a second judgment call, since
the source only names that 4 buckets exist (أخضر/أصفر/أحمر/حرج), not their boundaries**: ratio =
elapsed_days / target_days_max; ≤1.0 green, ≤1.5 yellow, ≤2.0 red, else critical — a plain, symmetric doubling
scheme, documented so a future stage with real boundary data can replace it outright rather than guess what this
one meant. Calendar days, not business days — matching `RequestDeadlineService::dueDateFor()`'s existing precedent
of plain calendar-day arithmetic (the source text says "أيام عمل" but Stage 17 already established this
simplification for the hard SLA; keeping both mechanisms consistent with each other beats introducing business-day
math only for the soft one).

**Frontend**: `RequestResource`/`RequestDetailResource` (shared, so it's automatically on both the list and detail
payload) gain `stage_timeliness`. `RequestsView.vue`'s stage column gets a small color dot (green/yellow/red/
critical, using `--color-success/warning/danger-fg` tokens — "critical" reuses the danger family with a filled
treatment rather than inventing a token) next to the stage name, only rendered when non-null; `RequestDetailView
.vue`'s summary card gets one more row, styled after the existing `documentsComplete`/`financialImpact` rows,
showing the level plus elapsed/target days. New `requestDetail.stageTimeliness.*` locale keys in both `ar.json`/
`en.json`. **Never blocking**: no gate, no required action, no workflow change — purely informational, per the
stage's own Goal line.

**Verification plan**: new `tests/Feature/StageTimelinessTest.php` — a request just arrived at a targeted stage
reads `green`; one past `target_days_max` reads `yellow`, past 1.5x reads `red`, past 2x reads `critical`; a
request at a stage with no target returns `null`; the field round-trips through both `RequestResource` and
`RequestDetailResource` — plus the full PHPUnit suite, Pint on touched/new files, `npm run build`, and `php
artisan migrate` against the real MySQL/Homestead database.

---

### 2026-09-01 17:20 EET — Claude — Stage 51 complete (employee-facing visibility)

Built exactly per the plan below. No migration — every field is derived from existing columns/
relations. `Request::documentsComplete()` (a plain status-code check, mirroring `isOverdue()`/
`requiresMinistryApproval()`), `RequestDetailResource` gained `documents_complete` and
`committee_summary` (meeting_number/meeting_date/agenda_item_number/committee_result/decision_date,
sourced from the request's latest `MeetingRequest` — same "latest by id" selection
`RequestResource::proposed_meeting` already uses — null when the request has never ridden an agenda),
and `RequestController::detailResource()`'s eager-loads grew `meetingRequests.meeting`/
`meetingRequests.decision` to back it.

The Art. 32 audit (read every `App\Notifications\*` class, grepped both locale files for "نهائي"/
"final" near an approval/decision context) came back clean, as recorded in the plan below — nothing
in this codebase currently describes a partial result as final. That is a verified finding, not a
skipped step.

Frontend: `RequestDetailView.vue`'s `.summary` card gained a documents-complete row next to the
financial-impact one, and a new `.committee-summary` card (shown only when `committee_summary` is
non-null) renders the five fields, reusing the existing `decisions.outcome.*` locale keys for the
result label rather than a second outcome-label map. New `requestDetail.documentsComplete.*` +
`requestDetail.committeeSummary.*` keys in both `ar.json`/`en.json`.

Verification: new `tests/Feature/EmployeeRequestVisibilityTest.php` (5 tests — no committee summary
before any agenda appearance; `incomplete` and `completion_required` statuses both report
`documents_complete: false`; an undecided agenda item reports the meeting/item number with a null
result; a decided agenda item reports the full block), full suite **204 tests / 1194 assertions**
green (was 199/1176), Pint clean on every touched/new file, `npm run build` passes with
`RequestDetailView` picking up the new markup in its existing chunk (then reverted `frontend/dist`,
tracked in git, per every prior stage's note), and locale key-parity verified programmatically (856
keys each side, zero on-one-side-only). **Not verified: no browser this session** — the new summary
rows' layout is calculated from the existing `.summary`/`.card` pattern, not observed, consistent with
every prior UI-touching note.

Next per STAGE_PLAN's suggested order: **Stage 52** (per-stage operational timeframes / soft SLA).

---

### 2026-09-01 17:00 EET — Claude — Stage 51 implementation plan (employee-facing visibility)

Building Stage 51 per STAGE_PLAN.md Track I: "[A] §7 requires an employee viewing their own request
to see" a specific field list, plus [D] Art. 32's rule that a result is never described as final
before every required approval tier is complete. `RequestDetailResource` is the one detail workspace
every role (including the requesting employee themselves, via `RequestVisibility::apply()`'s
unconditional `created_by_user_id = actor.id` clause — confirmed by re-reading that service) already
uses, so this stage extends that shared resource rather than building a separate employee-only screen.

**Cross-checked [A] §7's full field list against what's already exposed, rather than assuming the
Build bullet's two named additions are the only gaps.** Most of §7's 13 fields already exist on the
shared resource today: رقم الطلب (`reference_number`), تاريخ التقديم (`submitted_at`), نوع الطلب
(`request_type`), الحالة الحالية (`status`), حالة الاعتماد (implicit in `status.code`, and the
`approved`/`final_approved` split already keeps "committee approved" and "every tier approved"
distinct — see the Art. 32 audit below). "نسخة من الرد أو القرار الذي يجوز تسليمه قانونًا" has no
dedicated decision-letter artifact type anywhere in this schema — attachments are the only document
channel that exists, so this stays an honest gap, not fabricated. The Build bullet's own two named
additions are the two genuinely missing pieces: **`documents_complete`** and **`committee_summary`**.

**`documents_complete`**: a derived boolean, not a new column — `RequestStatusSeeder` already has two
status codes that literally mean "the file is incomplete right now": `incomplete` (Stage 16's
`return_missing_docs` exception) and `completion_required` (Stage 29's `CommitteeStatusService`
sub-state). New `Request::documentsComplete(): bool` = the current status code is neither of those —
mirrors the existing `isOverdue()`/`requiresMinistryApproval()` pattern of small derived-fact methods
living on the model.

**`committee_summary`**: [A] §7's رقم اجتماع اللجنة / تاريخ الاجتماع / رقم بند جدول الأعمال / نتيجة
اللجنة / رقم القرار وتاريخه, sourced from the request's latest `MeetingRequest` (Stage 44's
`Request::meetingRequests()`, same "latest by id" selection `RequestResource::proposed_meeting`
already uses) — `meeting.meeting_number`, `meeting.scheduled_at`, the agenda item's own 1-indexed
`agenda_order` (exactly "رقم بند جدول الأعمال" — confirmed by reading `MeetingController`'s
add/reorder code), and, once a `Decision` exists, its `outcome` and `decided_at`. Null when the
request has never ridden an agenda (the common case before the committee stage). **"رقم القرار"
(decision number) is a genuine, currently-nonexistent field** — `decisions` has no dedicated
sequential numbering column, only an autoincrement PK never meant to be shown as a legal document
number, and [D] Art. 99–100's own numbering philosophy (one reference number per transaction for its
whole lifecycle, no separate per-artifact numbering scheme) argues against inventing one here. This
stage exposes `decided_at` (the decision date) only and does not fabricate a `decision_number` field
— the same honest-gap treatment Stage 44 gave "نتيجة الدراسة" and Stage 50 gave "referral_authority"
before it existed.

**Art. 32 audit — done, no code change needed.** Read every `App\Notifications\*` class and grepped
`frontend/src/locales/ar.json`/`en.json` for "نهائي"/"final" combined with an approval/decision
context. Found nothing describing a partial result as final: `DecisionRecordedNotification` says
"قررت اللجنة الموافقة" (the *committee* approved), never "اعتماد نهائي"; the `approved` vs.
`final_approved` status pair already keeps "committee-level approval" and "every required tier
complete" as two distinct, correctly-labeled states (Stage 18's approval-chain design already got
this right); every other "نهائي" hit in the codebase is "الموعد النهائي" (a deadline) or the literal
`final_approval_archiving` stage name, unrelated to Art. 32's concern. Recording this as a verified
finding, not a silent skip.

**Frontend**: `RequestDetailView.vue`'s existing `.summary` card gains a documents-complete
indicator next to the other derived badges (financial impact's neighbor), and a new card — shown only
`v-if="request.committee_summary"` — rendering the five committee_summary fields, styled after the
existing `.summary`/`.card` pattern already used throughout that view. New
`requestDetail.committeeSummary.*` + `requestDetail.documentsComplete.*` locale keys in both
`ar.json`/`en.json`.

**Verification plan**: extend `RequestController::detailResource()`'s eager-loads with
`meetingRequests.meeting:id,meeting_number,scheduled_at` and
`meetingRequests.decision:id,meeting_request_id,outcome,decided_at`; new
`tests/Feature/EmployeeRequestVisibilityTest.php` — a request never presented to any committee gets
`committee_summary: null` and `documents_complete: true`; a request with an `incomplete`/
`completion_required` status gets `documents_complete: false`; a request that has ridden an agenda but
has no decision yet gets a `committee_summary` with `committee_result: null`; one with a recorded
decision gets the full block matching the meeting/agenda-item/decision fixture — plus the full
PHPUnit suite, Pint on touched files, `npm run build`, and a locale key-parity check. No migration —
every field is derived from existing columns/relations.

---

### 2026-09-01 16:40 EET — Claude — Stage 50 complete (minutes content completeness)

Built exactly per the plan below. One migration (`decisions.referral_authority`, nullable string,
applied to the real MySQL/Homestead database), threaded through `StoreDecisionRequest` (optional on
every outcome), `DecisionController::record()`'s `Decision::create()` call, and `DecisionResource`.
`AgendaItemDecisionPanel.vue`'s record-decision form gained a matching optional text input next to the
existing comment textarea.

`MeetingMinutesCompiler` gained everything the plan called for: `attendeeInfo()` (shared by
`attendance.present`/`.absent` and the new top-level `required_signatories`) reads
`committee.members` keyed by `user_id` to surface each attendee's Stage 45 seat and `is_head` flag;
`agendaItem()` reads `facts_summary`/`legal_opinion` straight from the item's own
`presentationMemo.content.authored` when one exists (null otherwise — never fabricated),
`documents_reviewed` from `request.attachments` (same shape `PresentationMemoCompiler` already uses
for `key_documents`), and a new `dissentingOpinions()` helper surfaces any vote whose value differs
from the decided outcome and carries a comment. `required_signatories` is the same present-attendee
roster `MeetingMinutesController::review()` itself computes when it actually creates signature rows —
documented in the compiler's docblock as a roster, not a live signed-status embed, since `content`
freezes at generate() time, which is only ever reachable while `status=draft`, and
`MeetingMinuteSignature` rows are only created later inside `review()`'s approve branch (which leaves
draft in the same transaction) — the two can never coexist, so embedding live signed_at/image data
here would always read "nobody has signed yet." Live signed proof stays exactly where it already
lives: `MeetingMinutes::signatures` via `MeetingMinutesResource`, unchanged.

Frontend: `MeetingMinutesView.vue`'s attendance lists show a seat/head badge per attendee (reusing the
existing `committees.seats.*`/`committees.head` locale keys, no new ones needed there), a new
"required signatories" list under the quorum line, and the agenda-item block gained
facts-summary/documents-reviewed/legal-basis/referral-authority/dissenting-opinions rows, each only
rendered when the underlying data exists — most items won't have a presentation memo or a referral,
and an item with no recorded decision yet has nothing to dissent from.

Verification: extended `MeetingMinutesTest.php` with `test_generated_minutes_include_the_stage_50_
content_gaps` (a full committee/meeting/request/agenda-item/attachment/presentation-memo/three-vote/
decision fixture — one seated chair, two unseated members, a 2-1 approve/reject split with the
dissenting voter's reason, a `referral_authority` on the recorded decision — asserting all six gaps
round-trip through the compiled minutes: seat/head on the chair vs. plain attendee, the full
required-signatories roster, facts_summary/legal_basis from the presentation memo, the one attachment
in documents_reviewed, the referral_authority on the decision block, and exactly the dissenting
member's vote/comment in dissenting_opinions — not the approving pair's). Full suite **199 tests /
1176 assertions** green (was 198/1154), Pint clean on every touched/new file, `npm run build` passes
with `AgendaItemDecisionPanel`/`MeetingMinutesView` picking up the new markup in their existing
chunks (then reverted `frontend/dist`, tracked in git, per every prior stage's note), locale
key-parity verified programmatically (846 keys each side, zero on-one-side-only), and the migration
ran clean against the real MySQL/Homestead database — confirmed via tinker that
`decisions.referral_authority` exists. **Not verified: no browser this session** — the new
attendance-badge/signatories/agenda-item rows are calculated from the existing sections' patterns, not
observed, consistent with every prior UI-touching note.

Next per STAGE_PLAN's suggested order: **Stage 51** (employee-facing visibility).

---

### 2026-09-01 16:00 EET — Claude — Stage 50 implementation plan (minutes content completeness)

Building Stage 50 per STAGE_PLAN.md Track I: closing every gap [D] Art. 28's minutes-content list
exposes against the current `MeetingMinutesCompiler`. Re-read Art. 28's list (already indexed in
`docs/employee-committee-lifecycle/official-procedures-manual-index.md`) against the compiler's
current output field-by-field before deciding sourcing for each of the six named gaps.

**Attendee roles/titles**: `committee_members.seat` (Stage 45's 5-seat roster:
chair/legal/hr_director/ministry_delegate/rapporteur) and `is_head`, looked up per attendee against
`meeting.committee.members` keyed by `user_id`. Applies to both `attendance.present` and `.absent`
entries — a member's seat is a standing fact regardless of whether they showed up.

**Per-item facts summary + legal-basis**: both already exist as *authored* fields on
`PresentationMemo.content.authored` (Stage 46 — `facts_summary`/`legal_opinion`), the pre-meeting memo
a agenda item may have. Reading them into the minutes snapshot is honest reuse of already-captured
data, not fabrication — exactly the compiler's own house style (reuse `PresentationMemoCompiler`'s
`key_documents` shape for the next bullet, below). Null when no memo was ever generated for the item.

**Documents-reviewed list**: `request.attachments`, same `{id, original_name, mime_type, size_bytes,
label}` shape `PresentationMemoCompiler::compile()` already uses for `key_documents` — reusing the
shape, not the method (different eager-load context), since duplicating the mapping inline is cheaper
than a cross-service dependency for four scalar fields.

**Per-member dissenting opinion + reason**: `votes.comment` is already captured (Stage 21's
`StoreVoteRequest`) but never read anywhere past the live vote-casting UI. New `dissentingOpinions()`
helper: every vote whose `vote` differs from the decided `outcome` AND carries a non-null `comment` —
that is what "أي تحفظات يوجب النظام إثباتها" (reservations the system requires recording) means: a
member who voted against the outcome and left a reason. A vote matching the outcome isn't a dissent
regardless of whether it has a comment.

**Referral-authority — a genuine, currently-nonexistent field, not derivable from anything today.**
No column, form field, or free-text convention anywhere captures *which* other body a `refer_other_body`
(or `no_jurisdiction`) outcome was referred to — only `decisions.comment`, unstructured. New nullable
`decisions.referral_authority` string column (migration), optional on `StoreDecisionRequest`, threaded
through `DecisionController::record()`'s `Decision::create()` call and `DecisionResource`. Not
restricted to any one outcome server-side (the committee head decides when it's relevant, same as
`comment` itself is optional) — but the record-decision panel will only bother showing the field, since
that's the honest low-effort version of "distinct from the free-text comment" the stage asks for; no
new outcome, no seeded status, no workflow change.

**Embedded signature references — a documented judgment call, not a live-sync mechanism.**
`MeetingMinutes::content` is frozen at `generate()` time, which is only ever reachable while
`status=draft` — and `MeetingMinuteSignature` rows are only created later, inside `review()`'s
`approve` branch, which moves status *out of* draft in the same transaction. So a compiled snapshot can
never coexist with real signature rows: attempting to embed live signed_at/image data would always
render as "nobody has signed yet," which is worse than not embedding it at all. Building instead: a
`required_signatories` list at the top level of `compile()`'s return, the same roster `review()` itself
computes (present attendees) — this satisfies Art. 28's literal requirement to record *who* is required
to sign as part of the permanent minutes record. Actual signed proof (image, timestamp) stays exactly
where it already lives and is already exposed — `MeetingMinutes::signatures` via
`MeetingMinutesResource`, unchanged. Documenting this split explicitly in the compiler's docblock so a
future stage doesn't "fix" it into a broken live-embed.

**Frontend**: `MeetingMinutesView.vue`'s attendance lists gain a seat/head badge; the agenda-item block
gains facts-summary/legal-basis/documents-reviewed/dissenting-opinions/referral-authority rows (each
only rendered when present — most items won't have a presentation memo or a referral); a new
"required signatories" list under the attendance section. `AgendaItemDecisionPanel.vue`'s
record-decision form gains one more optional text input (`referral_authority`) next to the existing
comment textarea. New `meetingsUnit.minutes.*` + `decisions.referralAuthorityPlaceholder` locale keys
in both `ar.json`/`en.json`.

**Verification plan**: extend `MeetingMinutesTest.php` (or add assertions to `generate()`'s test) —
a compiled minutes doc includes seat/head info for a seated attendee, facts_summary/legal_basis pulled
from an existing presentation memo and null without one, documents_reviewed matching the request's
attachments, a dissenting vote surfaced with its comment while an outcome-matching vote is excluded
even with a comment, `referral_authority` round-trips through `record()`/`DecisionResource`, and
`required_signatories` matches the present-attendee roster — plus the full PHPUnit suite, Pint, `npm
run build`, and `php artisan migrate` against the real MySQL/Homestead database.

---

### 2026-09-01 15:30 EET — Claude — Stage 49 complete (richer committee decision outcomes)

Built exactly per the plan below. Bullet 2 (whether "إعادة الملف لاستكمال بيانات" should be an
in-meeting decision outcome) needed no code — [D] Art. 27's explicit "غير مستوفٍ and مؤجل must never
be used interchangeably" already matches the current split between `CommitteeStatusService::
require_completion` (pre-meeting) and the existing `defer` decision outcome (in-meeting), and Art. 26
already folds "needs more documents/data" into التأجيل's 6 reasons rather than a separate outcome. That
finding is recorded here, not implemented as new code.

Bullet 1 (an explicit "عدم اختصاص" outcome) is a new seventh entry alongside Stage 35/41's existing six,
built as a mechanical extension of that established pattern: new self-loop `WorkflowTransitionSeeder`
exception at `receive_from_committee` (`declare_no_jurisdiction`, R03, `is_exception=true`,
`requires_comment=true`, order 54, no signature — same shape as `refer_to_another_body`/
`request_legal_opinion`), a new `outside_jurisdiction` `RequestStatus` row, a new
`votes_no_jurisdiction_count` column on `decisions` (migration), and the outcome key `no_jurisdiction`
threaded through every place the existing six were: `DecisionController::ACTIONS`/`LABELS`
(ar+en outcomes/columns)/`exportRow()`/`record()`'s `Decision::create()`, `Decision::$fillable`,
`DecisionResource`, `IndexDecisionRequest::OUTCOMES`, `StoreVoteRequest`'s `Rule::in` list,
`MeetingMinutesCompiler::voteTally()` (both branches), frontend `decisionOutcomes.js`'s
`DECISION_OUTCOMES` array (which drives `AgendaItemDecisionPanel.vue`'s vote buttons/tally and
`DecisionsView.vue`'s pending-tab buttons/outcome filter generically — no further change needed in
either component), `DecisionsView.vue`'s one hardcoded register-table tally cell + a new
`.outcome.no_jurisdiction` CSS class (muted/warning tokens, deliberately not reusing `reject`'s danger
tokens — a jurisdiction declaration isn't a merits rejection and shouldn't read as one), and new
`decisions.tally/vote/outcome.no_jurisdiction` + `workflow.actions.declare_no_jurisdiction` locale keys
plus an appended "عدم اختصاص"/"No jurisdiction" segment on `decisions.columns.tally`'s header string, in
both `ar.json`/`en.json`.

One pre-existing test needed a legitimate update, not a regression fix: `DecisionRegisterTest::
test_filters_endpoint_offers_the_committees_and_outcomes` hardcoded the old six-outcome array from
`/decisions/filters`, the same category of update Stage 41's note already flagged for this exact test.

Verification: new `DecisionOutcomeTemplateTest::test_no_jurisdiction_self_loops_at_the_committee_stage_
and_requires_a_comment` (mirrors the existing `refer_to_another_body` test's shape — a plurality vote
stays at `receive_from_committee`, sets status `outside_jurisdiction`, 422s without a comment, writes no
`Approval` ledger row), full suite **198 tests / 1154 assertions** green (was 197/1144 — one new test,
plus the updated pre-existing assertion), Pint clean on every touched/new file, `npm run build` passes
with `DecisionsView`/`decisionOutcomes`/`AgendaItemDecisionPanel` picking up the new outcome in their
existing chunks (then reverted `frontend/dist`, tracked in git, per every prior stage's note), locale
key-parity verified programmatically (838 keys each side, zero on-one-side-only), and both the migration
and the two reseeds ran clean against the real MySQL/Homestead database — confirmed via tinker that
`outside_jurisdiction` exists (28 statuses total, was 27) and the new `declare_no_jurisdiction` row is a
7→7 self-loop, R03, exception, comment-required (58 transitions total, was 57). **Not verified: no
browser this session** — the register table's new tally column and the outcome badge's styling are
calculated from the existing six-outcome pattern, not observed, consistent with every prior UI-touching
note.

Next per STAGE_PLAN's suggested order: **Stage 50** (minutes content completeness).

---

### 2026-09-01 15:00 EET — Claude — Stage 49 implementation plan (richer committee decision outcomes)

Building Stage 49 per STAGE_PLAN.md Track I. Two Build bullets, one needing code, one needing a
documented judgment call after re-reading the standard.

**Bullet 2 resolved with no code change, backed directly by [D]'s own text, not just inferred.**
`docs/employee-committee-lifecycle/official-procedures-manual-index.md` Art. 27 (read for this plan)
draws the exact line STAGE_PLAN's own text gestures at: **غير مستوفٍ** (a completeness gap caught
*before* the item is even added to the agenda) and **مؤجل** (a deferral decided *after* the committee
has already added, studied, and started deliberating the item) are two different moments and "must
never be used interchangeably." That is a direct, unambiguous verdict on the "reconsider" question —
the current architecture already gets this right: `CommitteeStatusService::require_completion`
(pre-meeting, status-only, → `completion_required`) is غير مستوفٍ; the existing in-meeting `defer`
decision outcome (Stage 21, → status `deferred`) is مؤجل. Art. 26 additionally lists "needs more
documents/data" as one of التأجيل's 6 named reasons, not a separate outcome — so when a completion
need surfaces *during* deliberation, the existing `defer` outcome is already the correct in-meeting
path for it; there is no missing "استكمال" outcome to build. Nothing to change here beyond recording
this finding — building a redundant second in-meeting "request completion" outcome would blur exactly
the distinction Art. 27 insists on keeping sharp.

**Bullet 1: a new "عدم اختصاص" outcome, distinct from the existing `refer_to_another_body`.**
[D]'s Art. 38 status dictionary (already indexed in this file) has status 14 "عدم اختصاص — Matter
outside committee jurisdiction" as its own entry, separate from status 15/16 (referred to local/
central *approval* authority) and separate from Stage 35's `refer_to_another_body` (an "ask another
body's input" self-loop — [D] Art. 26 lists "another body's input" as one of التأجيل's 6 reasons, a
sub-case of deferral, not "we have no jurisdiction over this at all"). Mechanism mirrors
`request_legal_opinion`/`refer_to_another_body`'s exact existing shape: a new self-loop exception at
`receive_from_committee` (`is_exception=true`, R03, `requires_comment=true`, no signature — matches
`WorkflowService::approvalLevel()`'s `action==='approve'`-only gate) rather than an automated
destination stage, since [D]'s own detailed-flow path 13D ("عدم اختصاص → تحديد الجهة المختصة →
إحالة/إعادة") describes identifying-then-manually-routing as a human follow-up step this system has
no automated "correct body" registry to drive. New outcome key `no_jurisdiction` (`DecisionController
::ACTIONS`), new workflow action `declare_no_jurisdiction`, new status `outside_jurisdiction`.

**Files to touch, all mechanical extensions of Stage 35's already-established 6-key pattern (soon to
be 7) — the same set of places Stage 35/41 each had to touch when adding a key to this list, per
their own AGENT_NOTES entries:**
- Backend: one migration (`decisions.votes_no_jurisdiction_count`, matching the shape of the existing
  `votes_refer_other_body_count` column — `votes.vote`/`decisions.outcome` already widened to
  `string(30)` in Stage 35, `no_jurisdiction` fits without a further width change); `RequestStatusSeeder`
  (new `outside_jurisdiction` status row); `WorkflowTransitionSeeder` (new `seedException()` call,
  order 54, right after `refer_to_another_body`'s 53); `DecisionController::ACTIONS` +
  `LABELS['ar'/'en']['outcomes'/'columns']` + `exportRow()` + `record()`'s `Decision::create()` call;
  `Decision` model `$fillable`; `DecisionResource`; `IndexDecisionRequest::OUTCOMES`; `StoreVoteRequest`'s
  `Rule::in` list; `MeetingMinutesCompiler::voteTally()` (both the decided-snapshot and live-count
  branches — a third place this same list is duplicated, per Stage 41's own note).
- Frontend: `decisionOutcomes.js`'s `DECISION_OUTCOMES` array (drives `AgendaItemDecisionPanel.vue`'s
  vote buttons/tally and `DecisionsView.vue`'s pending-tab vote buttons and outcome filter generically
  — no further change needed in either component beyond that one array); `DecisionsView.vue`'s
  register-table tally cell (hardcoded field list, like Stage 41's) and its `.outcome.no_jurisdiction`
  CSS class; new `decisions.tally/vote/outcome.no_jurisdiction` + `workflow.actions.declare_no_jurisdiction`
  locale keys and an appended "عدم اختصاص"/"No jurisdiction" segment on `decisions.columns.tally`'s
  header string, in both `ar.json`/`en.json`.

**Verification plan**: extend `tests/Feature/DecisionOutcomeTemplateTest.php` (or a new test) with a
`no_jurisdiction`-plurality vote asserting it stays at `receive_from_committee` (self-loop, matching
`refer_to_another_body`'s existing test shape), sets status `outside_jurisdiction`, requires a comment,
and writes no `Approval` ledger row — plus the full PHPUnit suite, Pint on touched/new files, `npm run
build`, `php artisan migrate` and the two reseeds against the real MySQL/Homestead database, and a
locale key-parity check.

---

### 2026-09-01 14:45 EET — Claude — Stage 48 complete (rapporteur/voting-member separation + conflict of interest)

Built exactly per the plan below. Two migrations applied to the real MySQL/Homestead database:
`committees.rapporteur_votes` (boolean, default false — the tashkil-decision flag, since no dedicated
tashkil table exists in this schema) and a new `conflict_of_interest_declarations` table (shaped like
`votes`: `meeting_request_id`/`user_id` FKs, both cascadeOnDelete, unique per item+user). **One real
gotcha hit applying the second migration to MySQL, not caught by the sqlite test suite**: the default
derived unique-index name (`conflict_of_interest_declarations_meeting_request_id_user_id_unique`)
exceeds MySQL's 64-character identifier limit — sqlite has no such limit, so PHPUnit's suite passed
clean while `php artisan migrate --force` failed outright against the real database with a 1059 syntax
error. Fixed by naming the index explicitly (`coi_declarations_item_user_unique`) after dropping the
partially-created table (MySQL's `CREATE TABLE` and the failed `ALTER TABLE ... ADD UNIQUE` are two
separate statements, so the bare table existed without its constraint) and re-migrating clean. Worth
remembering for any future long-named join/pivot table: MySQL's identifier limit is a real constraint
sqlite-backed tests cannot catch.

`DecisionEligibility::reasonBlockingVote()` gained two more checks (after the existing membership/
attendance pair): `isRecused()` (a public method, also called directly by
`MeetingDiscussionNoteController::store()` to block the *discussion feed* for the same user — recusal
blocks both, per [D] Art. 11/15/18's "blocked from deliberation/voting once recused") and a private
`isNonVotingRapporteur()` reading `$agendaItem->meeting->rapporteur_user_id` against
`$meeting->committee->rapporteur_votes` — literally the two fields STAGE_PLAN's own Build bullet named,
not the standing `CommitteeMember.seat === 'rapporteur'` roster seat Stage 45 built. Those two rapporteur
concepts are deliberately different and not reconciled here: `seat='rapporteur'` is the institutional
5-seat roster ([D] Art. 10); `Meeting.rapporteur_user_id` is the per-meeting assignment Stage 30's
scheduling wizard already collects. Nothing in this stage links them — a scheduler could assign someone
as a meeting's rapporteur who doesn't hold the committee's standing rapporteur seat, and the vote-block
follows the meeting assignment either way, matching the plan's literal text. `pendingVotesQuery()` grew
the same two exclusions (a `whereDoesntHave('conflictDeclarations', ...)` and a `rapporteur_user_id`/
`rapporteur_votes` guard on the meeting join) so the "awaiting my vote" worklist can never offer an item
the vote endpoint would refuse — the same invariant Stage 25's own docblock already promises.

New `ConflictOfInterestController` (`index`/`store`, riding `decisions,add`/`decisions,view` — the same
grants vote-casting already uses, not a new screen) — `store()` is deliberately the whole recusal
mechanism: there is no separate "recuse" step and no withdrawal endpoint, since a disclosed conflict is
a standing fact about an item, not a toggle. Guards mirror `DecisionController::vote()`'s shape
(`employee_request` only, blocked once a decision exists, actor must hold a committee seat).
`MeetingMinutesCompiler::agendaItem()` folds declarations into the compiled snapshot
(`conflict_declarations: [{user, reason, declared_at}]`) — the "recorded in the compiled minutes" half
of the stage's goal.

Frontend: `AgendaItemDecisionPanel.vue` (the shared vote/tally/record-decision block) gained a new
`meeting` prop and a conflict-of-interest section ahead of the vote buttons — a declared-conflicts list,
a declare form (gated `v-can="'decisions.add'"`, hidden once the signed-in user has already declared),
and the vote-actions block itself is now additionally `v-if`-gated on neither `myConflictDeclaration` nor
`isNonVotingRapporteur` being true, with an explanatory `<p class="alert">` in either case — proactive UX
only, the server (`DecisionEligibility`) is the real enforcement. Both `MeetingDetailView.vue` and
`MeetingLiveView.vue` now pass `:meeting="meeting"` into the panel (previously only `meeting-id`).
`MeetingsView.vue`'s committee form gained a `rapporteur_votes` checkbox next to the existing `is_active`
one — this is the only place the tashkil flag is actually set today, since no dedicated tashkil workflow
exists.

Verification: new `tests/Feature/RapporteurVoteConflictOfInterestTest.php` (5 tests — the rapporteur is
blocked from voting and from the pending-votes worklist until `rapporteur_votes=true`, then both work;
declaring a conflict blocks both the vote endpoint and the discussion-notes endpoint with their own
Arabic messages, and removes the item from the worklist, while an unrelated head member is unaffected;
only a committee member may declare; a role without `decisions,add` gets 403; a declared conflict shows
up in the compiled minutes with its reason), full suite **197 tests / 1144 assertions** green (was
192/1121), Pint clean on every touched/new file, `npm run build` passes with `AgendaItemDecisionPanel`
picking up the new markup in its existing chunk (then reverted `frontend/dist`, tracked in git, per
every prior stage's note), locale key-parity verified programmatically (834 keys each side, zero
on-one-side-only), and both migrations ran clean against the real MySQL/Homestead database after the
identifier-length fix above. **Not verified: no browser this session** — the panel's new section layout
is calculated from its existing pattern, not observed, consistent with every prior UI-touching note.

**Open items for whoever builds Stage 49+**: the two "rapporteur" concepts (the standing
`CommitteeMember.seat` and the per-meeting `Meeting.rapporteur_user_id`) remain unreconciled — if a
future stage wants the scheduling wizard to default a meeting's rapporteur from the committee's seated
one, that's new work, not assumed here. There is still no dedicated tashkil (committee formation
decision) record anywhere in the schema — `rapporteur_votes` is a plain boolean directly on `Committee`,
editable through the same committee CRUD as `is_active`, not a versioned or documented formation
decision; if a later stage needs to model the tashkil decision itself (date, signatories, scope) rather
than just its resulting flag, this column is the thing to migrate off of, not build alongside.

---

### 2026-09-01 14:15 EET — Claude — Stage 48 implementation plan (rapporteur/voting-member separation + conflict of interest)

Building Stage 48 per STAGE_PLAN.md Track I. Two independent halves per the stage's own Build bullets.

**Rapporteur/voting split.** No tashkil (committee formation decision) table exists anywhere in this
schema (confirmed by grep — the word appears only in STAGE_PLAN.md and AGENT_NOTES.md), so the flag the
Build bullet asks for ("a flag on the tashkil/committee record") lands on `Committee` itself: new
`rapporteur_votes` boolean, default false. The Build bullet's own wording names `Meeting.rapporteur_
user_id` explicitly (Stage 30's per-meeting scheduling field) as what to block — not
`CommitteeMember.seat === 'rapporteur'` (Stage 45's separate, standing 5-seat roster concept), even
though Stage 45's own note speculated the seat would be "the natural consumer" here. Taking STAGE_PLAN's
literal text over that speculation: this stage reads `meeting.rapporteur_user_id` against
`meeting.committee.rapporteur_votes`, and does not touch or reference `CommitteeMember.seat` at all. The
two rapporteur concepts stay independent — a documented, not silently glossed-over, decision.

**Mechanism**: `DecisionEligibility::reasonBlockingVote()` (Stage 25's single gate, already shared by the
vote endpoint and the pending-votes worklist) gains a final check after the existing membership/
attendance pair. `pendingVotesQuery()` gains the matching SQL exclusion so the worklist and the guard can
never disagree, per that method's own existing docblock promise.

**Conflict of interest.** New `conflict_of_interest_declarations` table, shaped like `votes` (cascade FKs,
unique per item+user) — its existence for a given (item, user) pair IS the recusal, no separate
recuse/withdraw step. New `ConflictOfInterestController@store` (declare, riding `decisions,add` — the
same grant vote-casting uses) and `@index` (list, `decisions,view`). `DecisionEligibility::isRecused()`
(public) is read by both `reasonBlockingVote()` and, directly, by
`MeetingDiscussionNoteController::store()` — recusal blocks the discussion feed too, per the stage's
"blocked from deliberation/voting" wording, whereas the rapporteur restriction blocks only the vote (a
rapporteur's whole role is recording discussion, so it would be wrong to also silence them there).
`MeetingMinutesCompiler::agendaItem()` gains a `conflict_declarations` array in its compiled snapshot —
the "recorded in the compiled minutes" requirement.

**Frontend**: `AgendaItemDecisionPanel.vue` (Stage 35's shared vote/tally/record-decision block) is the
one place both halves need UI — a new `meeting` prop (for the rapporteur check) and a conflict-of-
interest section (declared-conflicts list + a declare form) ahead of the existing vote buttons, which
become conditionally hidden with an explanatory message for a recused or non-voting-rapporteur viewer.
Both parent views (`MeetingDetailView.vue`, `MeetingLiveView.vue`) need to start passing `:meeting`
into the panel, not just `:meeting-id`. `MeetingsView.vue`'s committee form gains the `rapporteur_votes`
checkbox — the only place this flag can be set, since no tashkil workflow exists to set it otherwise.

**Verification plan**: new `tests/Feature/RapporteurVoteConflictOfInterestTest.php` — rapporteur blocked
from voting and the worklist until the committee grants it, then both work; a declared conflict blocks
both the vote and discussion-note endpoints and clears the worklist entry, while other members are
unaffected; only a committee member may declare; a role without `decisions,add` is refused; declarations
appear in generated minutes — plus the full PHPUnit suite, Pint, `npm run build`, locale key-parity, and
`php artisan migrate` against the real MySQL/Homestead database.

---

### 2026-09-01 13:55 EET — Claude — Stage 47 complete (Salaries & Benefits participation trigger)

Built exactly per the plan below, including the confirmation it asked for: re-reading [D] and [A] side
by side confirmed [D] does treat this as deliberate scope narrowing (no named committee-adjacent party,
only Art. 33's post-decision financial-effect execution), so this stage built the lighter advisory
version, not a blocking parallel-review gate or a new committee seat.

Two migrations (`request_types.default_has_financial_impact`, `requests.has_financial_impact`, both
boolean/not-null/default-false), both applied to the real MySQL/Homestead database. `DepartmentSeeder`
gained `SAL` (قسم المرتبات والمزايا, a genuine sibling of `CMT`, not a rename of the existing `FIN`
إدارة الشؤون المالية), reseeded — the real database now has 7 departments. `RequestTypeSeeder` set
`default_has_financial_impact = true` only for `PROM`/`ALLW` (the two of the current 7 types that
unambiguously match the stage's own example list); `settlement`/`back-pay`/`grade change` have no
dedicated `RequestType` row yet, a gap for Stage 53, not guessed at here — reseeded and verified via
tinker (`ALLW: true, LEAV: false, PROM: true`).

`RequestController::store()` sets `has_financial_impact` from the type's default at intake.
New `PATCH requests/{requestRecord}/financial-impact` (`UpdateFinancialImpactRequest`,
`updateFinancialImpact()`) lets R01/R02 correct it afterward — deliberately riding the *existing*
`notes_attachments,edit` grant rather than adding an `edit` tier to `request_details` (which has none
today and would imply much broader request-field-editing than this narrow correction needs), gated by
`RequestVisibility::canView` like `NoteController`. `RequestResource` exposes the flag unconditionally.

**Notification hooked at the one place every stage move already announces itself, no `WorkflowService`
or `workflow_transitions` change at all**: `NotificationDispatcher::stageChanged()` (already called by
both `transition()` and `applySystemTransition()`) gained one more check — landing at `observations`
(Stage 44's established "الدراسة" checkpoint) with `has_financial_impact = true` notifies active `SAL`
department users via new `FinancialImpactReviewNotification` (new `financial_impact_review` event type,
in_app+email like `action_required`). This also fires correctly on Stage 32's `receive_from_committee →
observations` `return_to_study` self-loop, confirmed by a dedicated test.

**The one real correctness gap the plan flagged and this stage had to actually close, not defer**: every
other notification recipient is, by construction, someone `RequestVisibility::canView()` already admits
(role-based recipients are pulled from the same `workflow_transitions` roles that gate visibility; the
creator obviously sees their own request) — a SAL employee has no such role, so without a fix they'd be
notified into a 404. Fixed with one bounded `orWhere('requests.has_financial_impact', true)` clause in
`RequestVisibility::apply()`, gated on the actor's own `department_id` matching `SAL`'s — narrower than
Stage 44's separate RequestVisibility-bypass endpoints (this extends the one existing generic rule
rather than adding a second read path for the same data) and, like creator visibility, not stage- or
terminal-status-gated. No write capability changed — `WorkflowService::actorMayUse()` untouched, so a
SAL employee still cannot transition the workflow themselves, only read the file and its attachments.

Frontend: `RequestDetailView.vue`'s `.card.summary` grid gained a financial-impact badge plus a
`v-can="'notes_attachments.edit'"`-gated "Correct" toggle button that PATCHes the new endpoint and
replaces `request.value` with the response, matching the existing `transition()` pattern. New
`requestDetail.financialImpact.*` locale keys in both `ar.json`/`en.json`.

Verification: new `tests/Feature/FinancialImpactReviewTest.php` (6 tests — the happy-path landing at
`observations` notifies only active `SAL` users when flagged, notifies nobody when unflagged, the
`return_to_study` self-loop re-notifies, a `SAL` user can view a flagged request they didn't create but
gets 404 on an unflagged one, the manual override changes what the *next* `observations` entry
notifies, and the PATCH endpoint 403s for a role without `notes_attachments,edit`), full suite **192
tests / 1121 assertions** green (was 186/1109), Pint clean repo-wide (`--test` passes with zero diffs —
no pre-existing drift left to flag this time), `npm run build` passes with `RequestDetailView` picking
up the new markup in its existing chunk (then reverted `frontend/dist`, tracked in git, per every prior
stage's note), locale key-parity verified programmatically (827 keys each side, zero on-one-side-only),
and both migrations plus the `DepartmentSeeder`/`RequestTypeSeeder` reseed ran clean against the real
MySQL/Homestead database. **Not verified: no browser this session** — the badge/toggle's layout is
calculated from the summary grid's existing pattern, not observed, consistent with every prior
UI-touching note.

**Open item for whoever builds Stage 48+**: `has_financial_impact` is a plain boolean with no audit
trail of who corrected it or why — if that ever matters, `AuditObserver`'s existing generic `requests`
coverage (Stage 22) already logs the old/new value on any `update()`, so nothing new is needed there,
just worth knowing it's not a separate dedicated log. Stage 53's request-type catalogue is the natural
place to revisit whether `settlement`/`back-pay`/`grade-change` deserve their own `RequestType` rows
with `default_has_financial_impact = true`, rather than leaving those cases to the manual override.

---

### 2026-09-01 13:15 EET — Claude — Stage 47 implementation plan (Salaries & Benefits participation trigger)

Building Stage 47 per STAGE_PLAN.md Track I. The stage's own text flags an open question first: "[D]
doesn't name this seat on the committee itself (only [A] does, as a participating party outside the
fixed 5-seat roster) — confirm during Stage 45's roster work whether [D] treats this as deliberate
scope narrowing before building it as a full parallel-review step." That confirmation was never done
during Stage 45 (its note only says the 5-seat roster itself was verified) and is done now, by
re-reading both sources before writing any code.

**Verdict: [D] does treat this as scope narrowing, confirmed by reading both documents side by
side.** [D]'s own canonical 5-phase process shape (`official-procedures-manual-index.md`, the "5
phases" bullet) is الإعداد اإلداري → التنظيم اإلجرائي → المراجعة القانونية → الدراسة والمداولة →
االعتماد والتنفيذ — no phase or named party for قسم المرتبات والمزايا. The only place [D] mentions a
financial dimension at all is Art. 33 (execution, *after* a decision, "تنفيذ أي أثر مالي متى استحق")
— financial *effects are executed* post-decision, not *reviewed pre-decision* by a named party. [A]
§3 party 5 is the only source that elevates قسم المرتبات والمزايا to a named participating party
during the study phase ("يشارك فقط عندما يكون للمسألة أثر مالي"), and [A]'s own §5 (الدراسة اإلدارية
والفنية, owned by قسم شؤون الموظفين) already folds this in as one of several departments قسم شؤون
الموظفين *may* request a statement from ("الرئيس المباشر / إدارة الموارد البشرية / قسم المرتبات /
اإلدارة المالية / مكتب الشؤون القانونية / أي جهة أخرى ذات صلة") — i.e. even [A] treats it as one
optional consultation among several, not a formal gate. So this stage builds the flag + an advisory
notification, **not** a blocking parallel-review status/gate, a new committee seat, or a new
workflow_transitions row — building the heavier version would misrepresent [D]'s standard the same
way Stage 46 flagged for `legal_opinion`.

**Schema**: `request_types.default_has_financial_impact` (boolean, default false) — seeded `true` for
`PROM` and `ALLW` only. Of the stage's own example list ("promotion, settlement, allowance, back-pay,
grade change"), only promotion and allowance have an existing matching `RequestType` row today;
`settlement`/`back-pay`/`grade change` have no dedicated type yet (a gap for Stage 53's request-type
catalogue work, not fabricated here). `requests.has_financial_impact` (boolean, NOT NULL, default
false) — set once at intake from the type's default (`RequestController::store()`), then editable
afterward — the stage's own Build bullet says "derived from request type **or set manually**", so a
manual override path is real scope, not gold-plating.

**Manual override, gated without a new permission tier**: new `PATCH
requests/{requestRecord}/financial-impact` (`UpdateFinancialImpactRequest`, `{has_financial_impact:
bool}`), riding the *existing* `notes_attachments,edit` grant (`[R01, R02]`) rather than adding an
`edit` tier to `request_details` (which currently has none, and broadening it would imply a much
wider "can edit core request fields" capability this stage doesn't need). R01/R02 are exactly the
roles that would need to correct an under/over-derived flag — R01 at intake, R02 during
requirements_check/reviewer_review/observations. Gated by `RequestVisibility::canView` like
`NoteController`, not a new bypass.

**New department**: `DepartmentSeeder` gains `SAL` — قسم المرتبات والمزايا / Salaries & Benefits — a
sibling of `CMT` under the `ABS` root, matching the org chart in `official-process-summary.md` §1
(المرتبات والمزايا is listed as its own admin unit alongside شؤون الموظفين, distinct from the
existing `FIN` إدارة الشؤون المالية department, which is a different, broader unit).

**Notification, hooked at the one place every stage move already announces itself**: no new
`workflow_transitions` row and no change to `WorkflowService` — `NotificationDispatcher::
stageChanged()` (already called by both `WorkflowService::transition()` and `applySystemTransition()`
on every move) gets one more check: if `$toStage?->code === 'observations'` (the stage Stage 44 already
established as this system's "الدراسة" checkpoint — R02's `request_stage_logs` rows there) and
`$requestRecord->has_financial_impact`, notify active users in the `SAL` department. This also fires
correctly on the `receive_from_committee → observations` `return_to_study` exception (Stage 32),
which is the right behaviour — a request bounced back for re-study should re-notify Salaries &
Benefits too if it's flagged. New `financial_impact_review` event type in
`NotificationSetting::EVENT_TYPES` (in_app+email, mirroring `action_required` — it asks someone to
form an opinion, not just FYI) and a new `FinancialImpactReviewNotification`, shaped like
`ActionRequiredNotification` but without a stage param (always "study").

**The one real correctness gap this stage has to close, not defer**: every existing notification
recipient is, by construction, someone `RequestVisibility::canView()` already lets in — `actorsForStage()`'s
role-based recipients are pulled from the same `workflow_transitions` roles `RequestVisibility`
checks, and the creator obviously can see their own request. A SAL department employee has no role
tied to any `workflow_transitions` row, so without a change they'd be notified and then hit a 404
opening the file — a dead-end notification, not simply an unbuilt nice-to-have. Fix: one bounded
addition to `RequestVisibility::apply()` — `has_financial_impact = true` requests are also visible to
an actor whose own `department_id` is `SAL`'s. This is deliberately narrower than Stage 44's
RequestVisibility-bypass endpoints (that gave R04 members a *separate* read endpoint for meeting
context) — here the existing generic workspace visibility rule is what's extended, since "read the
file and its attachments to form an opinion" is exactly what the generic detail workspace already
offers everyone else it lets in, and inventing a second endpoint for identical data would be the kind
of duplication AGENTS.md's conventions warn against. No write capability changes — `WorkflowService::
actorMayUse()` is untouched, so a SAL employee still cannot transition the workflow themselves.

**Frontend**: `RequestDetailView.vue`'s `.card.summary` grid gains a "financial impact" badge
(yes/no), plus a small toggle button next to it gated `v-can="'notes_attachments.edit'"` that PATCHes
the new endpoint and replaces `request.value` with the response (same pattern `transition()` already
uses). `RequestResource` exposes `has_financial_impact` unconditionally (a plain column, like
`decision_grade`). New `requestDetail.financialImpact.*` locale keys in both `ar.json`/`en.json`.

**Verification plan**: new `tests/Feature/FinancialImpactReviewTest.php` — a PROM/ALLW request is
created with the flag already true and a LEAV request with it false; landing at `observations`
(happy path AND via `return_to_study`) notifies active SAL-department users only when the flag is
true; a SAL employee can `GET` a flagged request they didn't create and aren't a workflow actor for,
and still 404s on an unflagged one; the PATCH endpoint updates the flag for R01/R02 and 403s for a
role without `notes_attachments,edit`; the manual override changes what the next `observations` entry
notifies (flip false→true before the request reaches that stage, confirm the notification fires) —
plus the full PHPUnit suite, Pint on touched/new files, `npm run build`, and `php artisan migrate`
against the real MySQL/Homestead database.

---

### 2026-09-01 10:35 EET — Claude — Stage 46 complete (presentation memo compiler)

Built exactly per the plan below. New `presentation_memos` table (unique `meeting_request_id`,
cascadeOnDelete — a memo belongs to one agenda item, not one meeting), `PresentationMemo` model
(`content:array`), a `presentationMemo(): HasOne` added to `MeetingRequest`, `PresentationMemoCompiler
::compile()` (the 7 honestly-derivable Art. 22 fields only), and `PresentationMemoController`
(`show`/`generate`/`update`). Rides the `meeting_agenda` screen's `add` grant (`R03, R04, R09`) —
confirmed by reading the seeder and every existing agenda-building route that this is the **first**
real use of that tier; `addAgendaItem`/`reorderAgenda`/`updateAgendaItem`/`removeAgendaItem` all ride
`edit` (`R03, R09` — no R04) instead, which would have shut the رئيس قسم شؤون الموظفين-as-مقرر (R04)
out of drafting their own memo. `content` is `{derived, authored}`: `generate()` always refreshes
`derived` and preserves `authored` across regenerates, except seeding `facts_summary` from the
request's own `description` on the very first generate only (mirrors Stage 42's
`DecisionDraftComposer` — a computed starting draft, not a fabricated final value).

**One judgment call worth restating outside the plan**: `legal_opinion` is a plain authored field, not
read from Stage 35's committee-voted `legal_opinion` decision outcome — that outcome is a mid-meeting
vote asking for a legal opinion later, not [D] Art. 21's pre-meeting legal-member file review, and no
model for Art. 21's review exists anywhere in this codebase yet (confirmed by grep). Conflating the
two would have silently misrepresented one process step as another. Building Art. 21's actual review
workflow is left as an open follow-up, not silently assumed away.

**One incidental fix, needed to make the new deep-link work, not scope creep**: `MeetingLiveView.vue`'s
`watch(meetingId, ...)` had no `{ immediate: true }`, so a `?meeting=` deep link (already used by
`MeetingDetailView.vue`'s pre-existing "open live runner" link) never actually loaded the meeting on
mount — the watcher only fires on a *change* to `meetingId`, and neither `onMounted` hook called
`load()` either. This was a real, pre-existing latent bug, not something this stage introduced, but
the new `MeetingAgendaBuilderView.vue` → `meeting_live?meeting=&item=` deep link (per agenda-item "فتح
مذكرة العرض" link) depends on it working, so fixing it was in scope. Added `{ immediate: true }` to
that watcher — verified safe for the empty-`meetingId` case too (every branch inside already no-ops on
a falsy `meetingId`).

Frontend: `MeetingLiveView.vue` gains a 7th quick-info tab (`memo`, between `previous` and `notes`) —
its own fetch (`memo`/`memoLoading`/`memoError`, independent of `context`'s lifecycle since it can be
entirely absent until generated), a generate/regenerate button and 4 authored-field textareas both
gated `v-can="'meeting_agenda.add'"`, reusing the existing `.info-grid`/`.attachment-list`/`.notes`
styles rather than inventing new ones. `MeetingAgendaBuilderView.vue` gets one deep-link per
`employee_request` row. New `meetingsUnit.live.tabs.memo` + `meetingsUnit.live.memo.*` +
`meetingsUnit.agenda.openMemo` locale keys in both files (822 keys each side, zero on-one-side-only,
verified programmatically).

Verification: 6 new tests in `PresentationMemoTest.php` (derived-field compilation incl. work_unit vs.
referring_body genuinely differing; regenerate refreshes a changed attachment count while preserving
an already-edited `legal_opinion`; `prior_decisions` scoped to the same request's own earlier
committee appearance and excludes a decision on a different request by the same employee; `update()`
404s before any memo exists then patches only submitted authored keys; 422 on a non-`employee_request`
item for both `show`/`generate`; R04 can generate, a role without the grant gets 403), full suite
**186 tests / 1109 assertions** green (was 180/1078), Pint clean on every touched/new file, `npm run
build` passes with `MeetingLiveView`/`MeetingAgendaBuilderView` picking up the new code in their
existing chunks (then reverted `frontend/dist`, tracked in git, per every prior stage's note), and the
migration ran clean against the real MySQL/Homestead database. **Not verified: no browser this
session** — the memo tab's layout is calculated from the existing tab-panel patterns, not observed,
consistent with every prior UI-touching note.

**Open item for whoever builds Stage 47+**: [D] Art. 21's pre-meeting legal-member review has no model
anywhere — if a future stage wants to build it, `legal_opinion` here is the natural field to wire it
into (populate from the review's outcome instead of staying purely authored), but that's a deliberate,
separate piece of work, not something this stage silently assumed.

---

### 2026-09-01 09:40 EET — Claude — Stage 46 implementation plan (presentation memo compiler)

Building Stage 46 per STAGE_PLAN.md Track I: a compiled "مذكرة العرض" (presentation memo) per
committee agenda item, mirroring `MeetingMinutesCompiler`'s shape ([D] Art. 22's 11-field list),
symmetric with Stage 36's post-meeting minutes compiler but pre-meeting and item-scoped instead of
meeting-scoped.

**Schema: one new table, `presentation_memos`, keyed on `meeting_request_id` (unique, cascadeOnDelete)**
— a memo belongs to one agenda item, not one meeting, since [D] Art. 22 has the مقرر draft one per
matter before it's even inserted into an agenda (Art. 23 is the *next* article). A single `content`
json column split into two halves: `content.derived` (recomputed fresh on every `generate()`) and
`content.authored` (free text a human writes, preserved across regenerates once set). No status/
approval lifecycle — unlike `meeting_minutes`, Art. 22 describes no review/sign step for the memo
itself, only a caution that it must stay neutral, so this stage doesn't invent one.

**Field-by-field mapping, since roughly half of Art. 22's 11 fields have no existing column and
would otherwise be fabricated — the honest-gap pattern this codebase already uses (Stage 44's
`نتيجة الدراسة` skip) — recorded here so the split isn't re-litigated mid-build:**
- `derived` (always recomputed, reusing `MeetingController::agendaItemContext()`'s exact query
  shapes — the Stage 44 endpoint already answers most of these): reference_number, employee
  (creator id+name), **work_unit** = creator's own `department_id` (جهة عمله), subject (request
  title), submission_date (`submitted_at`), **referring_body** = the *request's own* `department_id`
  (الجهة المحيلة — deliberately a second, distinct field from work_unit, even though intake usually
  sets both to the same department today; they're allowed to diverge), key_documents (all of the
  request's attachments — no "essential vs. not" flag exists anywhere in the schema, so, same as
  Stage 44's attachments tab, this is the full list, not a fabricated subset), prior_decisions.
- **`prior_decisions` scoping call**: decisions recorded against *this same request's own* earlier
  committee appearances only (`Decision::whereHas('meetingRequest', fn ($q) => $q->where('request_id',
  ...))`) — a request can revisit `receive_from_committee` via the `defer`/`legal_opinion`/
  `refer_other_body` self-loops (Stage 21/35), so "same topic" (الموضوع) genuinely can have prior
  committee history. Deliberately **not** widened to the employee's other, unrelated requests —
  Stage 44's `previous_requests` tab already covers that broader case in the same live-runner screen,
  and duplicating it here under a different field name would be redundant, not more complete.
- `authored` (never touched by `generate()` once set — only the PATCH endpoint below writes them):
  facts_summary, employment_status_notes, legal_opinion, committee_question. None of these four have
  any backing column: `facts_summary` is seeded once, on the row's *first* `generate()` only, from
  `Request.description` as an editable starting draft (mirroring Stage 42's `DecisionDraftComposer`
  pattern — a computed draft a human refines, not a final value); the other three start blank and are
  written entirely by whoever drafts the memo.
- **`legal_opinion` is deliberately NOT sourced from `Decision.outcome === 'legal_opinion'`/
  `Decision.comment`** (Stage 35's committee-voted "ask for a legal opinion" self-loop) even though
  that's the only "legal opinion"-shaped data that exists in the schema today — it's the wrong point
  in the process. [D] Art. 21 (المراجعة القانونية السابقة للاجتماع) describes a *pre-meeting* legal-
  member file review with its own outcome vocabulary (سليم قانونيًا وجاهز للعرض / يحتاج إلى استكمال
  مستند... / etc.), and **no model for that review exists anywhere in this codebase** (confirmed by
  grep — this is a genuine, unbuilt gap, not something this stage is scoped to close). Wiring
  `legal_opinion` to the Stage 35 outcome instead would conflate a mid-meeting committee vote with a
  pre-meeting file check and silently misrepresent one as the other. So for this stage `legal_opinion`
  is a plain authored field — the legal-seat committee member (or whoever drafts the memo) writes it
  in directly when one exists, same as Art. 22's own "عند وجودها" qualifier already allows for blank.
  Building Art. 21's actual review workflow is left as an explicit follow-up, not silently assumed.

**Backend**: `App\Models\PresentationMemo` (`content:array`, `generated_at:datetime`), a
`presentationMemo(): HasOne` added to `MeetingRequest`, `App\Services\PresentationMemoCompiler::
compile(MeetingRequest $agendaItem): array` (derived fields only), `App\Http\Controllers\Api\
PresentationMemoController` (`show`/`generate`/`update`), `App\Http\Requests\PresentationMemo\
UpdatePresentationMemoRequest` (the 4 authored fields, `sometimes|nullable|string`, Arabic messages),
`PresentationMemoResource`. All three actions 422 on a non-`employee_request` agenda item, matching
`agendaItemContext()`'s own guard. Riding the **`meeting_agenda` screen's existing `add` grant** (`view => '*', add => [R03, R04, R09],
edit => [R03, R09]`) rather than a new screen or `meeting_live` — drafting a memo sits squarely in
agenda-prep territory (between Art. 21's legal review and Art. 23's agenda insertion), and `add`
already covers R04 (a member acting as مقرر), which the agenda-structure routes' `edit` tier (R03
chair + R09 secretary only, no R04 — confirmed by reading the seeder and the existing
`addAgendaItem`/`reorderAgenda`/etc. routes, all gated `meeting_agenda,edit`) would wrongly exclude
the rapporteur from. This also happens to be the first real use of `meeting_agenda`'s `add` grant —
every existing agenda-building route rides `edit` instead, so `add` was seeded but unused until now.
No seeder changes needed. `show()` returns `{data: null}`
when nothing's been generated yet (matching `MeetingMinutesController::show()`'s shape, not a 404);
`generate()` is an idempotent `updateOrCreate` that always refreshes `content.derived` and preserves
(or, first time only, seeds) `content.authored`; `update()` 404s if no memo exists yet (must generate
before editing narrative text) and merges only the submitted authored keys.

**Frontend**: `MeetingLiveView.vue` gains a 7th quick-info tab (`INFO_TABS` grows to include `'memo'`,
placed after `previous` and before `notes`) — fetched alongside `context` in the existing per-item
watcher, since the runner screen is already reachable before a meeting is convened (just shows a
warning banner, per its existing `!meeting.convened_at` check), which is what makes it usable for
"viewable before/during the meeting" without a second screen. The tab shows the derived fields
read-only (same `<dl class="info-grid">` shape as the summary tab), the key-documents list (same
`<ul class="attachment-list">` shape as the attachments tab), prior_decisions, and 4 textareas for
the authored fields with a save button gated `v-can="'meeting_agenda.add'"` — plus a
generate/regenerate button, same gate. `MeetingAgendaBuilderView.vue` gets one added deep-link per
`employee_request` agenda-item row ("فتح مذكرة العرض") to `{ name: 'meeting_live', query: { meeting,
item } }`, since that screen currently only accepts `?meeting=` — `MeetingLiveView.vue` gains a small
`route.query.item` read into `selectedItemId` on mount, so the deep link actually lands on the right
item instead of just the meeting's default. New `meetingsUnit.live.tabs.memo` +
`meetingsUnit.live.memo.*` locale keys in both `ar.json`/`en.json`.

**Verification plan**: new `tests/Feature/PresentationMemoTest.php` — `generate()` compiles the
expected derived shape (reference/employee/work_unit/subject/submission_date/referring_body/
key_documents from the request's attachments); a second `generate()` call refreshes a changed derived
field (e.g. a newly uploaded attachment) while leaving a previously-edited `authored.facts_summary`
untouched; `facts_summary` seeds from `Request.description` only on the very first generate;
`prior_decisions` includes a decision from the same request's earlier committee appearance (via a
`defer` self-loop) and excludes a decision on a *different* request by the same employee; `update()`
404s before any memo exists, then patches only the submitted authored keys after one does; 422 on a
non-`employee_request` item for all three actions; an R04 can generate (the `add` tier) but a role
without `meeting_agenda,add` gets 403 — plus the full PHPUnit suite, Pint on touched/new files,
`npm run build`, and `php artisan migrate` against the real MySQL/Homestead database.

---

### 2026-08-31 16:05 EET — Claude — Stage 45 complete (fixed 5-seat committee roster)

Built additively per STAGE_PLAN.md Track I: a nullable `committee_members.seat` string column
(`chair|legal|hr_director|ministry_delegate|rapporteur`, [D] Art. 10's roster) sitting **alongside**
the pre-existing `is_head` boolean rather than replacing it — chosen because most committees in this
system are still generic open R03/R04 rosters (Stage 32's 3-R04-member tie-breaking test committee
included), and forcing every committee down to exactly 5 members would have broken that established
model for no requirement STAGE_PLAN actually stated. `seat` is purely additive: a committee can name
its 5 institutional seats without losing the ability to also carry extra unseated members.

Uniqueness is enforced two ways — a DB-level `unique(['committee_id','seat'])` index (MySQL/SQLite
both treat every NULL as distinct, so any number of seatless members coexist safely) as the backstop,
and `StoreCommitteeMemberRequest`'s own `Rule::unique(...)` for the friendly Arabic 422 message,
**added conditionally only when `seat` is actually filled** — a naive unconditional `nullable|unique`
combo would have been a real bug: Laravel's unique-rule verifier runs `WHERE seat = NULL`, which
`Illuminate\Database\Query\Builder::where()` silently rewrites to `WHERE seat IS NULL`, so it would
have rejected every *second* seatless member against the first one already on the committee. Caught
by reading Laravel's own `Builder::where()`/`DatabasePresenceVerifier::getCount()` source before
writing the rule, not by a failing test. `CommitteeController::addMember()` also forces `is_head=true`
whenever `seat==='chair'` (Art. 10: رئيس اللجنة *is* the head) — the two concepts stay independent for
every other seat, so a legal/HR/ministry-delegate/rapporteur seat carries no head implication.

`CommitteeResource` gained a `seats` map (`{chair: {member_id, user:{id,name}}|null, ...}` for all 5
codes) so a committee's roster is individually addressable regardless of how many other unseated
members it also carries — this is what "5 seats individually identifiable" (STAGE_PLAN's done-when)
actually means in the API. Frontend: `MeetingsView.vue`'s membership panel gained a 5-seat summary
strip (filled/vacant per seat) above the existing member list, and the add-member form gained a seat
`<select>` (5 seats + "no fixed seat") alongside the existing user picker and head checkbox; member
rows now show their seat label instead of the generic "Head" pill when one is set. New
`committees.seats.*` locale keys in both `ar.json`/`en.json`.

**The one load-bearing guarantee this stage exists to prove, per STAGE_PLAN's own done-when**:
filling and voting the مندوب الخدمة المدنية (`ministry_delegate`) seat must never itself decide
whether a request also needs the *separate* central ministry approval `Request::requiresMinistryApproval()`
governs (Art. 14 — the delegate's committee membership never substitutes for that referral). Verified
by a new `tests/Feature/CommitteeSeatRosterTest.php::test_the_ministry_delegate_seats_vote_never_
substitutes_for_the_separate_ministry_approval` — same committee, same ministry-delegate seat holder,
same approve vote, run twice with only the request's own `decision_grade` differing (10 = at
threshold, 5 = below): the grade-10 run lands at `local_governance_ministry` after the admin-manager
approval step and the grade-5 run lands at `competent_authority`, proving the branch (`WorkflowService
::destinationStageId()`) is driven only by `decision_grade`/`decision_grade_threshold`, completely
unaffected by whether a ministry_delegate seat exists or how it voted. `WorkflowService` itself was
**not touched** — this stage adds no new coupling between committee seats and that logic, which is
exactly the guarantee being proven.

Verification: 5 new tests in `CommitteeSeatRosterTest.php` (seat identifiability, seat-collision 422,
unknown-seat-value 422, multiple unseated members coexisting safely, the ministry-delegate guard
above), full suite **180 tests / 1078 assertions** green (was 175/1042), Pint clean on every touched
file, `npm run build` passes (then reverted `frontend/dist`, tracked in git, per every prior stage's
note), and the migration ran clean against the real MySQL/Homestead database — confirmed via
`Schema::getColumns` that `committee_members.seat` exists, nullable, `varchar(255)`. Locale
key-parity verified programmatically: 807 keys each side, zero on-one-side-only. **Not verified: no
browser this session** — the seat-summary strip and seat `<select>`'s layout are calculated from the
existing add-member form's pattern, not observed, consistent with every prior UI-touching note.

**Open items for whoever builds Stage 46+**: `seat` is immutable after creation (remove + re-add is
the only way to reassign one), matching the pre-existing `is_head` precedent — no "update member"
endpoint exists for either. Stage 48's rapporteur/voting-member separation ("مقرر اللجنة does not
participate in the substantive vote unless the tashkil decision explicitly grants it") is the natural
consumer of the new `seat==='rapporteur'` value in `DecisionEligibility` — nothing in this stage wires
that restriction in, since STAGE_PLAN scopes it to Stage 48 explicitly. Stage 47's "confirm during
Stage 45's roster work whether [D] treats [Salaries & Benefits Dept] as deliberate scope narrowing"
note is still open — this stage's scope was the 5 named seats themselves, not a review of [D] vs. [A]
party lists.

---

### 2026-08-31 15:10 EET — Claude — Stage 44 complete (verify remaining [C] fidelity gaps)

Built per the plan below. Both flagged items were real, unbuilt gaps — closed rather than dismissed.

**`committee_candidates` (§2)**: `RequestResource` gained three `whenLoaded`/`when`-guarded fields —
`created_by` (id+name), `attachments_count` (via `->withCount('attachments')`, standing in for §2's
اكتمال الملف — derived, not fabricated, same signal `MeetingReadinessService` already uses for
file-readiness), and `proposed_meeting` (the latest `MeetingRequest` row's meeting, `{id, title,
scheduled_at, status, priority}` — the agenda item's own `priority` doubles as §2's الأولوية column,
which has no meaning for a candidate not yet on any agenda). All three are inert everywhere else that
reuses this shared resource — nothing eager-loads `createdBy`/`meetingRequests`/`withCount` outside
`CommitteeCandidateController::index()`, which now does. New `Request::meetingRequests()` HasMany.
Frontend: `CommitteeCandidatesView.vue`'s table gained نوع الطلب, الموظف, مدة الانتظار (computed
client-side from `submitted_at`, no backend field — it's just elapsed time), اكتمال الملف, الأولوية,
الاجتماع المقترح columns, plus a standalone فتح الملف action (`RouterLink` to `request_details`,
separate from the four comment-prompted committee actions). **Deliberately not added**: نتيجة الدراسة
— no backing field exists, and interpreting arbitrary stage-log comment text as a "result" isn't the
same semantic as a real study-outcome field; fabricating one would be worse than leaving the gap
flagged. Documented as a conscious skip in `docs/employee-committee-lifecycle/gap-analysis.md`.

**`MeetingLiveView` (§6)**: the runner had *zero* of [C]'s six quick-tabs before this stage — not an
inverted-order or partial-match case like Stage 36's minutes gap, a genuine absence. New read-only
`GET meetings/{meeting}/agenda/{agendaItem}/context` (`MeetingController::agendaItemContext()`,
`meeting_live,view`) returns request/employee/study/attachments/previous-requests in one payload;
422s for a non-`employee_request` item, matching `DecisionController::record()`'s own guard shape.
**الدراسة is the actual `request_stage_logs` rows recorded at the `observations` stage** (comment +
actor + date) — same "no field exists, so read the honest equivalent" reasoning as the candidates-
table skip above, just resolved differently since stage-log history genuinely is what "study" means
here. New `GET meetings/{meeting}/agenda/{agendaItem}/attachments/{attachment}`
(`meetings.agenda-item.attachment`, same gate) streams the file.

**The one real design decision this stage made, worth flagging explicitly**: neither new endpoint
reuses `RequestVisibility` (the gate `AttachmentController`/`RequestController` use for the direct
workspace — creator or current actionable assignee only). An R04 committee member reading an agenda
item mid-meeting is neither: only R03 (the decision-recording role) has a `workflow_transitions` row
at `receive_from_committee` that `RequestVisibility` would match, so gating the context/attachment
endpoints on it would 404 for every R04 member trying to read the very panel meant to inform their
vote. Both ride `meeting_live,view` instead — the same broad, committee-wide trust model the existing
discussion-notes/vote endpoints already use for this stage, not a new precedent.

Frontend: `MeetingLiveView.vue`'s current-item card gained a 6-tab strip (only for `employee_request`
items) — 5 real tabs fetching `context` (re-fetched only when `currentItem.value?.id` changes, not on
every 5s poll tick — watching the computed object itself would refire needlessly since `meeting.value`
is replaced wholesale each poll) plus a "notes" tab that folds in the pre-existing Stage 34 discussion
feed verbatim (moved, not duplicated in spirit — it now renders once, inside the tab, for request
items; non-request admin/emerging items keep the old always-visible `.discussion` section unchanged,
since they have no other tabs to share space with).

Verification: new `tests/Feature/MeetingAgendaItemContextTest.php` (4 tests — full context payload
incl. an R04 member successfully reading a request they didn't create; 422 on an admin item; the
attachment stream working for that same non-creator R04 member, proving the RequestVisibility-bypass
decision above is real; 404 for an attachment/request mismatch), plus one new test in
`CommitteeCandidatesDashboardTest.php` asserting `created_by`/`attachments_count`/`proposed_meeting`
round-trip correctly. Full suite **175 tests / 1042 assertions** green (was 170/1027), Pint clean on
every touched file, `npm run build` passes with both views' chunks (then reverted `frontend/dist`,
tracked in git, per every prior stage's note). Locale key-parity verified programmatically: 799 keys
each side, zero on-one-side-only. No migration — every new field/endpoint rides existing columns and
relations. **Not verified: no browser this session** — the tab strip's layout and the candidates
table's new columns are calculated from `MeetingLiveView`/`CommitteeCandidatesView`'s existing
patterns, not observed, consistent with every prior UI-touching note.

Next per STAGE_PLAN's suggested order: **Stage 45** (fixed 5-seat committee roster).

---

### 2026-08-31 14:20 EET — Claude — Stage 44 implementation plan (verify remaining [C] fidelity gaps)

Building Stage 44 per STAGE_PLAN.md Track I: confirm-or-close the two items the 2026-08-30 gap-analysis
flagged as "unconfirmed" rather than confirmed-missing — `committee_candidates`'s columns against [C]
§2, and `MeetingLiveView`'s tabs against [C] §6. Read both source sections
(`docs/employee-committee-lifecycle/meeting-screens-design.md` §2/§6) and the actual current code
first, rather than assuming either direction.

**Verdict for both: real, unbuilt gaps**, not false alarms — confirming this stage's own premise
("check before treating these as gaps") the honest way, by actually reading the code before writing
anything.

**`committee_candidates`**: [C] §2's columns are رقم الطلب|الموظف|نوع الطلب|الإدارة|تاريخ التقديم|
مدة الانتظار|نتيجة الدراسة|اكتمال الملف|الأولوية|الاجتماع المقترح|الإجراء, with a standalone فتح
الملف action. `CommitteeCandidatesView.vue` today has reference|title|department|status|submitted|
actions — missing الموظف, نوع الطلب, مدة الانتظار, نتيجة الدراسة, اكتمال الملف, الأولوية, الاجتماع
المقترح, and فتح الملف as its own action (only the four committee-status actions exist). Scoping
decision: add what's honestly derivable from existing data (الموظف via a new `createdBy` whenLoaded
field on the shared `RequestResource`; نوع الطلب — already returned by that resource, just not
rendered; مدة الانتظار computed client-side from the already-returned `submitted_at`, no backend
change; اكتمال الملف via `->withCount('attachments')`; الاجتماع المقترح + الأولوية via a new
`Request::meetingRequests()` relation reading the latest `MeetingRequest` row's meeting+priority — a
candidate can already ride a meeting's agenda without CommitteeStatusService's `place_on_agenda`
action ever firing, since `addAgendaItem()` doesn't call it, so this is a real, currently-invisible
state, not a hypothetical). **Not adding نتيجة الدراسة** — no field represents "study result" anywhere
in the schema, and interpreting arbitrary stage-log comment text as a structured "result" would be
fabricating data, not surfacing it; flagging as a conscious skip instead.

**`MeetingLiveView`**: [C] §6 names six quick-tabs (ملخص الطلب|بيانات الموظف|الدراسة|المرفقات|الطلبات
السابقة|مالحظات اللجنة) shown beside the current agenda item. The live runner today has *no* tabs at
all — heading, state controls, the Stage 35 decision panel, and an always-visible discussion feed.
Five of the six need real new data the runner doesn't fetch today (only `MeetingRequestResource`'s
thin `request` block — id/reference/title/status — is loaded); the sixth (مالحظات اللجنة) is already
served by the existing Stage 34 discussion feed and doesn't need rebuilding, just folding into the tab
strip. Mechanism: one new read-only endpoint, `GET meetings/{meeting}/agenda/{agendaItem}/context`
(`meeting_live,view`), returning request/employee/study/attachments/previous-requests in one payload —
**deliberately not gated by `RequestVisibility`** (the direct-workspace gate), since an R04 committee
member reading an item mid-meeting is neither the request's creator nor (unlike R03, whose
decision-recording action IS a `workflow_transitions` row at this stage) someone `RequestVisibility`
would recognize as a current actionable assignee; gating on it would 404 for exactly the audience this
panel is for. Attachments need their own new stream route for the same reason —
`AttachmentController::preview` is RequestVisibility-gated and would also 404 for R04. الدراسة reads
the request's own `request_stage_logs` rows recorded at the `observations` stage (comment/actor/date)
— the same "no dedicated field, so read the honest underlying history" call as نتيجة الدراسة above,
just resolved as buildable here since stage-log history genuinely *is* what "study" means, unlike a
free-text "result" summary.

**Verification plan**: new `tests/Feature/MeetingAgendaItemContextTest.php` covering the full context
payload (incl. an R04 non-creator member successfully reading it — the point of the RequestVisibility
bypass), the 422 on a non-request item, the attachment stream working for that same non-creator
member, and a 404 for a mismatched attachment/request pair; plus a new assertion in
`CommitteeCandidatesDashboardTest.php` for the three new `RequestResource` fields — then the full
PHPUnit suite, Pint, `npm run build` (reverting tracked `frontend/dist` afterward), and a locale
key-parity check. No migration — every addition rides existing columns/relations.

---

### 2026-08-31 12:50 EET — Claude — Stage 43 complete (move `decisions` out of the meetings sidebar group)

Built exactly per the plan below — one-line seeder change, no Vue/permission/migration change needed.
`ScreenSeeder.php`'s `decisions` row now has `group => null` instead of `'meetings_management'`
(kept at its original array position for a stable diff, per the plan). Confirmed by reading
`AppSidebar.vue`'s `entries` computed and `stores/screens.js`'s `navGroups`/`navItems` that both
already treat a null-group screen as a flat top-level item — no frontend code was touched, and no
`npm run build` was needed since nothing in `frontend/src` changed.

`tests/Feature/ScreenTest.php`: `test_the_meetings_management_group_seeds_all_nine_codes` renamed to
`..._eight_codes` with `decisions` dropped from its list (9→8), plus a new
`test_decisions_is_shared_not_grouped` asserting its group is null. The second test
(`test_a_committee_head_sees_the_group_in_their_screen_menu`) needed no change — its `newCodes` list
never included `decisions` in the first place.

Verification: full suite **170 tests / 1027 assertions** green (was 169/1027 — one test added, net
assertion count unchanged since the shrunk 9-code loop lost exactly the one assertion the new test
gained), Pint clean on both touched files, and `php artisan db:seed --class=ScreenSeeder --force` ran
clean against the real MySQL/Homestead database — confirmed via tinker that `decisions`' group is now
`NULL` and `meetings_management` has exactly 8 remaining screens.

Next per STAGE_PLAN's suggested order: **Stage 44** (verify remaining [C] fidelity gaps —
`committee_candidates`'s columns against [C] §2, and `MeetingLiveView`'s tabs against [C] §6).

---

### 2026-08-31 12:35 EET — Claude — Stage 43 implementation plan (move `decisions` out of the meetings sidebar group)

Building Stage 43 per STAGE_PLAN.md Track I: [C] is explicit that القرارات (decisions) is a shared
system-wide unit, not something to duplicate inside the meetings section
("وحدات مشتركة في النظام ولا نكررها داخل قسم الاجتماعات") — Stage 28 nested it under
`meetings_management` alongside the other 8 Track H screens, which this stage reverses.

**Mechanism is a one-line seeder change, not a sidebar rewrite.** `ScreenSeeder.php`'s `decisions`
row changes its `group` column from `'meetings_management'` to `null`; every other field (route,
icon, permissions) is untouched. `AppSidebar.vue`'s render-entries computed already treats a
null-group screen as a flat top-level item interleaved at its array position (confirmed by reading
`entries computed()` — a non-group screen just pushes `{type:'item'}` wherever it falls in
`navItems`, and the grouped block renders once at the position of its first surviving member), so no
Vue change is needed; `ICON_BY_CODE` in that same file already lists `decisions: 'check-circle'`
*outside* the "Stage 28 — meetings management group" comment block, i.e. it was already keyed as if
ungrouped — one more sign this is purely a data change. `ScreenRolePermissionSeeder` needs no change
(the plan's own "no permission change needed" line) since permissions are keyed by `code`, not
`group`. `tests/Feature/ScreenTest.php`'s two group tests need updating: the "seeds all nine codes"
test drops to 8 codes plus a new assertion that `decisions`' group is null, and the second test
(committee-head's screen menu) already excludes `decisions` from its `newCodes` list so needs no
change there.

**Verification plan**: update `ScreenTest.php` as above, run the full PHPUnit suite, Pint on the
touched seeder file, and re-seed screens against the real MySQL/Homestead database. No migration —
`screens.group` already exists and is nullable (added in Stage 28).

---

### 2026-08-31 12:10 EET — Claude — Stage 42 complete (decision-draft auto-generation)

Built per the plan below. New `App\Services\DecisionDraftComposer::compose()` interpolates 8 fixed
`{{token}}` placeholders (`reference_number`, `request_title`, `employee_name`, `department`,
`request_type`, `committee_name`, `meeting_date`, `decision_date`) into a `Template`'s bilingual
subject/body using `strtr()`, reading the agenda item's `request`/`createdBy`/`department`/
`requestType` and the meeting's `committee` — falling back cross-locale exactly like
`DecisionController::localName()` already does for export labels. New read-only endpoint
`GET meetings/{meeting}/agenda/{agendaItem}/decision-draft?template_id=&locale=`
(`DecisionController::draft()`, new `ShowDecisionDraftRequest`), gated `decisions,view` (same
grant as `filters()`/`pending()` — the draft discloses nothing not already visible on screen) rather
than `decisions,approve`, since it's a preview, not a write. `record()` itself is untouched — it
still just stores whatever `comment` text the client submits; the interpolation happens earlier, at
template-pick time, so the user can see and further edit the merged draft before recording.

Frontend: `AgendaItemDecisionPanel.vue`'s `useTemplate()` is now async and calls the new endpoint
instead of copying `template.body_ar`/`body_en` verbatim into the comment box — same edit-before-
submit UX, just with real data merged in. New `decisions.template.drafting`/`draftError` locale
keys. `TemplatesView.vue` gained a placeholder-tokens hint under the body textareas when
`category === 'decision'`, so an R08 admin knows what to type. **One frontend gotcha worth
flagging**: writing the literal `{{token}}` example strings directly inside a Vue template
interpolation (`{{ '{{' + token + '}}' }}`) breaks Vite's build — Vue's compiler scans for the
first `}}` to close the mustache and finds one inside the string literal first, producing an
"Unterminated string constant" parse error. Fixed by moving the concatenation into a plain script
function (`braced(token)`) and calling `{{ braced(token) }}` instead, so the template source itself
never contains a literal `{{`/`}}` pair inside an interpolation expression. The same problem existed
in the locale-file hint text I originally wrote (vue-i18n's own message syntax uses single-brace
`{name}` interpolation and chokes on embedded `{{...}}`); fixed by keeping the i18n string
description-only and rendering the actual token examples as separate `<code>` chips via `braced()`,
outside of `t()` entirely.

Verification: 4 new tests appended to `tests/Feature/DecisionOutcomeTemplateTest.php` (interpolation
pulls in the request's own reference/title/requester/department/committee and leaves no `{{` in the
output; the `locale=en` variant reads `body_en` and resolves department to its English name; a
non-`employee_request` agenda item is refused; a missing or inactive `template_id` 422s). Full suite
**169 tests / 1027 assertions** green (was 165/1014), Pint clean on every touched file, `npm run
build` passes with `AgendaItemDecisionPanel`/`TemplatesView` as their own chunks (then reverted
`frontend/dist`, tracked in git, per every prior stage's note — `git checkout -- frontend/dist &&
git clean -fd frontend/dist`). Locale key-parity verified programmatically: 765 keys each side, zero
on-one-side-only. No migration — this stage is pure service/controller/route/frontend, no schema
change. **Not verified: no browser this session** — the template-pick-fetches-a-draft interaction is
calculated from the existing `useTemplate()`/textarea pattern, not observed, consistent with every
prior UI-touching note.

Next per STAGE_PLAN's suggested order: **Stage 43** (move `decisions` out of the meetings sidebar
group).

---

### 2026-08-31 11:45 EET — Claude — Stage 42 implementation plan (decision-draft auto-generation)

Building Stage 42 per STAGE_PLAN.md Track I: picking a decision template currently just copies its
static `body_ar`/`body_en` verbatim into the comment textarea (`AgendaItemDecisionPanel.vue`'s
`useTemplate()`, Stage 35) — this stage makes that a real merge of the record's own data, per [C]
§7's "توليد مسودة القرار من بيانات المعاملة".

**Mechanism is a read-only preview endpoint, not a change to `record()`'s write path.** The user
needs to see (and still edit) the merged draft *before* submitting, so interpolation has to happen
at template-pick time, not at record time — `DecisionController::record()` stays exactly as-is,
storing whatever `comment` the client sends. New `App\Services\DecisionDraftComposer::compose()`
does `strtr()` over 8 fixed `{{token}}` placeholders against the agenda item's own
`request`/`createdBy`/`department`/`requestType` and the meeting's `committee`, reusing the
preferred/fallback bilingual-field rule `DecisionController::localName()` already has for export
labels. New `GET meetings/{meeting}/agenda/{agendaItem}/decision-draft` (`ShowDecisionDraftRequest`,
`template_id` required + active, `locale` optional) sits behind `decisions,view` — the same grant
`filters()`/`pending()` already use, since a draft built from data already visible on screen doesn't
need the head-only `approve` grant `record()` itself requires. Refuses (422) a non-`employee_request`
agenda item, matching `record()`'s own guard.

Frontend: `AgendaItemDecisionPanel.vue`'s `useTemplate()` becomes async, fetching the composed draft
and setting `decisionComment` from its `body` instead of the raw template field — same edit-before-
submit UX. `TemplatesView.vue` gets a placeholder-tokens hint under the body textareas (shown only
for `category === 'decision'`) so an R08 author knows what to type; the 8 tokens are static, not
server-driven — no endpoint exists to enumerate them and one row of constants isn't worth adding one
for.

**Verification plan**: extend `tests/Feature/DecisionOutcomeTemplateTest.php` (already covers
template recording from Stage 35) with cases proving the draft actually merges real data (reference
number, title, requester name, department, committee name all present, no `{{` left in the output),
an `locale=en` variant, a 422 for a non-request agenda item, and a 422 for a missing/inactive
`template_id` — plus the full PHPUnit suite, Pint, `npm run build`, and a locale key-parity check.
No migration — no schema change this stage.

---

### 2026-08-31 11:20 EET — Claude — Stage 41 complete (abstain vote option)

Built exactly per the plan below. One migration (`decisions.votes_abstain_count`, applied to the real MySQL/Homestead database), `abstain` added to `StoreVoteRequest`'s allowed vote values, and `DecisionController::record()` reads `$counts['abstain'] ?? 0` into a new `$abstainCount` local — no change to the tally/leader logic itself, since `$tally` was already scoped to `array_keys(self::ACTIONS)` and never saw `abstain` in the first place. `Decision`/`DecisionResource`/export `LABELS`/`exportRow()` all carry the new column through; `MeetingMinutesCompiler::voteTally()` gained the same key in both its decided-snapshot and live-count branches, since the compiled-minutes tally is a third place this six-key list was duplicated.

Frontend: `decisionOutcomes.js` gained `VOTE_OPTIONS = [...DECISION_OUTCOMES, 'abstain']`, kept separate from the unchanged six-entry `DECISION_OUTCOMES`. `AgendaItemDecisionPanel.vue`'s vote buttons/tally display switched to `VOTE_OPTIONS`, but its `predictedOutcome()` (which gates the signature pad) was changed to compute its max/leaders strictly over `DECISION_OUTCOMES` — worth flagging since the naive fix (leaving `predictedOutcome` reading from the same `tally()` object) would have let an abstain-heavy vote read as "leading" client-side while the server-side tally correctly never lets it win; a client/server split rather than a genuine bug, but one worth catching before it shipped. `DecisionsView.vue` (pending-tab tally/vote buttons, register table's tally column) and `MeetingMinutesView.vue`'s `tallyFor()` got the same `VOTE_OPTIONS` swap. New `decisions.tally.abstain`/`decisions.vote.abstain` locale keys in both `ar.json`/`en.json` (not `decisions.outcome.abstain` — abstain is never a recorded decision outcome), plus `ممتنع`/`Abstain` appended to `decisions.columns.tally`'s header string.

Verification: extended `DecisionVotingTest.php` with a three-voter scenario (approve/approve/abstain) proving the decision still records `outcome=approve`, `votes_approve_count=2`, `votes_abstain_count=1`. Full suite **165 tests / 1014 assertions** green (was 164/1005), Pint clean on every touched file, `npm run build` passes with `AgendaItemDecisionPanel`/`decisionOutcomes` as their own chunks (then reverted `frontend/dist`, tracked in git, per every prior stage's note — `git checkout -- frontend/dist && git clean -fd frontend/dist`). Locale key-parity verified programmatically: 762 keys each side, zero on-one-side-only. **Not verified: no browser this session** — the extra vote button/tally entry is calculated from the existing `AgendaItemDecisionPanel.vue` pattern, not observed, consistent with every prior UI-touching note.

Next per STAGE_PLAN's suggested order: **Stage 42** (decision-draft auto-generation).

---

### 2026-08-31 11:00 EET — Claude — Stage 41 implementation plan (abstain vote option)

Building Stage 41 per STAGE_PLAN.md Track I: a committee member who attended but doesn't want to vote either way should be representable, per [C] §7's tally line ("الحاضرون 7 | صوّت 7 | موافق 5 | غير موافق 1 | ممتنع 1" — present/voted/approve/reject/abstain are four distinct counts).

**`abstain` is a vote value, not a decision outcome — it must never win a plurality.** `StoreVoteRequest::rules()` gains `abstain` in its `Rule::in([...])` list alongside the existing six. `decisions` gains a `votes_abstain_count` column (new migration, same shape as the six existing `votes_*_count` columns). `DecisionController::record()` needs **no change to its tally/leader logic** — `$tally` is already built from `array_keys(self::ACTIONS)` (six keys, no `abstain`), so an abstain vote is automatically excluded from the plurality/tie computation exactly like it should be; the method only gains one more line reading `$counts['abstain'] ?? 0` into the `Decision::create()` payload, plus `abstain` added to `DecisionResource`, the export `LABELS`/`columns`, and `exportRow()`.

**Frontend mirrors the same split.** `decisionOutcomes.js` gains `VOTE_OPTIONS = [...DECISION_OUTCOMES, 'abstain']`, exported alongside the unchanged `DECISION_OUTCOMES` (still exactly the six outcomes a *decision* can carry — abstain is deliberately excluded from it, the same reason it's excluded from `ACTIONS` server-side). `AgendaItemDecisionPanel.vue`'s vote-cast buttons and its tally display loop over `VOTE_OPTIONS`; its `predictedOutcome()` (which decides whether to show the signature pad) must keep computing its counts from `DECISION_OUTCOMES` only, not `VOTE_OPTIONS` — otherwise an abstain-heavy vote could read as "leading" client-side while the server-side tally (correctly) never lets it win, a client/server disagreement worth avoiding explicitly. `DecisionsView.vue`'s pending-tab tally/vote-buttons switch from `DECISION_OUTCOMES` to `VOTE_OPTIONS` too, plus its register table's tally column/header gains the `votes_abstain_count` value. `MeetingMinutesCompiler::voteTally()` (both the decided-snapshot branch and the live-count branch) and `MeetingMinutesView.vue`'s `tallyFor()` also need the same `abstain` addition, since the compiled-minutes tally is a third place this same six-key list was duplicated.

New locale keys: `decisions.tally.abstain` and `decisions.vote.abstain` in both `ar.json`/`en.json` (not `decisions.outcome.abstain` — abstain is never a recorded decision outcome, so that map stays six entries), plus `ممتنع`/`Abstain` appended to `decisions.columns.tally`'s header string.

**Verification plan**: extend `DecisionVotingTest.php` with a three-voter scenario (approve/approve/abstain) asserting the decision still records `outcome=approve`, `votes_approve_count=2`, and `votes_abstain_count=1` — proving abstain is tallied but never a leader — plus the full PHPUnit suite, Pint, `npm run build` (reverting tracked `frontend/dist` afterward, per every prior stage's note).

---

### 2026-08-31 10:35 EET — Claude — Stage 40 complete (group-similar-by-request-type in the agenda builder)

Built exactly per the plan below. `MeetingController::agendaStats()` now takes a `group_by` query param (`department`, unchanged default, or `request_type`, new — 422 on anything else via `abort_unless`), eager-loads `request.requestType`, and keys each group by `type` (`{id, name_ar, name_en}`, null for an admin/emerging item with no request) instead of `department` when the new axis is selected. The response now echoes `group_by` so the frontend doesn't have to infer which shape each group carries. `MeetingAgendaBuilderView.vue` got a `groupBy` ref, a `loadStats()` helper split out of `loadMeeting()` (so toggling the axis only refetches stats, not the whole meeting), a two-button toggle next to the existing show/hide-groups control (styled after the existing `.type-toggle`), and the group-list template branching on `stats.group_by` to read `group.department` vs `group.type`. New locale keys `groupByDepartment`/`groupByRequestType`/`noRequestType` in both `ar.json`/`en.json`; the existing `showGroups`/`groupsTitle` copy was reworded from "…by department" to axis-neutral phrasing since it's no longer the only grouping.

Verification: extended `tests/Feature/MeetingAgendaBuilderTest.php` with two new tests — `?group_by=request_type` against a promotion request + a leave request + an admin item produces exactly 3 buckets (PROM/LEAV/null), and an unknown `group_by` value 422s — plus asserted `data.group_by` on the existing department-grouping test. Full suite **164 tests / 1005 assertions** green (was 162/994), Pint clean on both touched PHP files, `npm run build` passes with `MeetingAgendaBuilderView` as its own lazy chunk (then reverted `frontend/dist`, tracked in git, per every prior stage's note — `git checkout -- frontend/dist && git clean -fd frontend/dist`). Locale key-parity verified programmatically: 760 keys each side, zero on-one-side-only. No migration, no seeder change — pure read-endpoint + UI addition. **Not verified: no browser this session** — the toggle's layout/interaction is calculated from the existing `.type-toggle` pattern, not observed, consistent with every prior UI-touching note.

Next per STAGE_PLAN's suggested order: **Stage 41** (abstain vote option).

---

### 2026-08-31 10:10 EET — Claude — Stage 40 implementation plan (group-similar-by-request-type in the agenda builder)

Building Stage 40 per STAGE_PLAN.md Track I: `MeetingController::agendaStats()` currently only groups by "effective department" (Stage 31); [C] §4's example groups by request type instead ("جميع طلبات الترقية... في مجموعة واحدة" — all promotion requests in one group). Adding request-type as a second, selectable grouping axis rather than replacing department grouping, since both are legitimate views and nothing in [C] says department grouping should go away.

**Mechanism**: `agendaStats(Meeting $meeting)` gains a `Request $request` param (Illuminate's — this controller has no `App\Models\Request` import, so no alias needed, confirmed by grep) reading `group_by` from the query string, `department` (default, preserves every existing caller) or `request_type` (422 on anything else). Groups are keyed by department (unchanged shape, `group.department`) or by the agenda item's request's `requestType` (`group.type`, `{id, name_ar, name_en}`) — an admin/emerging item has no request and therefore no type, so it always lands in the `type: null` bucket under `request_type` grouping, mirroring how a department-less item already lands in `department: null` today. Response gains a `group_by` echo field so the frontend doesn't have to guess which key each group object carries. Eager-loads add `request.requestType:id,name_ar,name_en`.

**Frontend**: `MeetingAgendaBuilderView.vue` gets a `groupBy` ref (`department`/`request_type`) with two toggle buttons next to the existing show/hide-groups button, a `loadStats()` helper (split out of `loadMeeting()` so switching the toggle doesn't need a full meeting refetch) called on mount and on `groupBy` change, and the group-list template branches on `stats.group_by` to read `group.department` vs `group.type` for its header (both use the same `name()` bilingual helper already defined). New locale keys `meetingsUnit.agenda.stats.groupByDepartment/groupByRequestType/noRequestType` in both `ar.json`/`en.json`.

**Verification plan**: extend `MeetingAgendaBuilderTest::test_agenda_stats_totals_and_groups_by_effective_department` (or add a sibling test) with a third agenda item of a different request type (`LEAV` vs the existing helper's `PROM`) and assert `?group_by=request_type` produces 3 buckets (PROM/LEAV/null-for-the-admin-item) while the default department grouping is unchanged — plus the full PHPUnit suite, Pint, `npm run build` (reverting tracked `frontend/dist` afterward, per every prior stage's note).

---

### 2026-08-31 09:50 EET — Claude — Stage 39 complete (agenda drag-and-drop reorder)

Built exactly per the plan below. `MeetingAgendaBuilderView.vue`'s `moveItem(index, direction)` (adjacent-swap up/down buttons) is replaced by native HTML5 drag-and-drop: `draggedIndex`/`dragOverIndex` refs, `onDragStart`/`onDragOver`/`onDrop`/`onDragEnd` handlers, and a `canEditAgenda` computed (`auth.can('meeting_agenda', 'edit')`) driving the `draggable` attribute and the new drag-handle glyph — script-side because `draggable` can't be set by the existing `v-can` directive (it only toggles `display`). `onDrop` splices the dragged item to its new index, optimistically applies the reordered array so the drop feels instant, then PUTs the full id order to the **same, unchanged** `PUT /meetings/{meeting}/agenda/reorder` endpoint and calls `loadMeeting()` to reconcile — no backend change at all, confirming the plan's read that the endpoint already accepted an arbitrary full ordering, not just adjacent swaps.

Added a `grip-vertical` glyph to `AppIcon.vue`'s icon map (lucide-style, matching its documented "drop inner SVG markup under a new key" convention) as the drag handle — needed so dragging doesn't fight with clicking the priority/time inputs living in the same `<li>`. One new locale key each in `ar.json`/`en.json` (`meetingsUnit.agenda.dragToReorder`, used as both `title` and `aria-label` on the handle); verified programmatically that the two locale files still have zero keys present on only one side. `MeetingDetailView.vue`'s separate up/down agenda list was **not touched**, per the stage's explicit single-file scope.

Verification: `npm run build` passes clean with `MeetingAgendaBuilderView`'s own lazy chunk; `frontend/dist` (tracked in git) was reverted afterward via `git checkout -- frontend/dist && git clean -fd frontend/dist`, per every prior stage's note — rebuilding it wasn't in scope. No PHP touched, no migration, no seeder change, no Pint run needed. **Not verified: no browser this session** — the drag interaction itself (visual drag-over feedback, actual pointer-driven reorder) is calculated from the handler logic, not observed, consistent with every prior UI-touching note in this file.

Next per STAGE_PLAN's suggested order: **Stage 40** (group-similar-by-request-type in the agenda builder).

---

### 2026-08-31 09:35 EET — Claude — Stage 39 implementation plan (agenda drag-and-drop reorder)

Building Stage 39 per STAGE_PLAN.md Track I: replace `MeetingAgendaBuilderView.vue`'s up/down (`moveItem`) buttons with a drag-and-drop list, per [C] §4's design — same `PUT /meetings/{meeting}/agenda/reorder` write path underneath (`{ order: [ids in new order] }`), which needs no backend change at all since it already accepts an arbitrary full ordering, not just adjacent swaps.

**Scope is exactly one file**, per the stage's own Build bullet — `MeetingDetailView.vue`'s separate inline agenda list (its own up/down `moveItem`, a different component entirely) is explicitly *not* touched; its own docblock says it's deliberately kept as "the quick add/remove/reorder view," and Stage 39 only names the dedicated builder screen.

**Mechanism**: no new dependency — matches house precedent (no chart lib for the dashboard, no drag library anywhere in `package.json` today) — native HTML5 drag-and-drop (`draggable`, `dragstart`/`dragover`/`drop`/`dragend`) on each `<li>`, gated behind the same `v-can="'meeting_agenda.edit'"` the up/down buttons were already behind. A new `grip-vertical` glyph is added to `AppIcon.vue`'s icon map (matching its lucide-style-inline convention) as the drag handle, since dragging the whole row by any point of it would conflict with clicking priority/time inputs in the same `<li>`. On drop, the local `agenda_items` array order is spliced into its new position, the full id order array is PUTted to the existing endpoint, then `loadMeeting()` refetches — same round-trip `moveItem` already did, just computing an arbitrary target index instead of `index ± 1`. Two new locale keys (`meetingsUnit.agenda.dragToReorder`, an aria-label on the handle) in both `ar.json`/`en.json`.

**Verification**: `npm run build` (frontend has no test runner/linter, per AGENTS.md) plus manual code review of the drag handlers' index math — no browser available this session, consistent with every prior UI-touching note, so the actual drag interaction is calculated, not observed.

---

### 2026-08-31 09:15 EET — Claude — Stage 38 complete (source reconciliation — housekeeping only, no code)

Per STAGE_PLAN.md's Track I, Stage 38 is pure housekeeping ("[D]/[E] formally recorded as the standard process reference... no code change — this stage is the housekeeping the other 20 depend on"). Verified everything it asks for was already in place from the 2026-08-30 entry below: `docs/employee-committee-lifecycle/` exists locally with all 7 files (`README.md`, `gap-analysis.md`, `official-procedures-manual-index.md`, `official-detailed-flow.md`, `official-process-summary.md`, `lifecycle-proposal.md`, `meeting-screens-design.md`), confirmed git-ignored (`git check-ignore` matches it via the `/docs` rule in `.gitignore`), and AGENTS.md already carries the durable pointer (the "Employee Affairs Committee process standard" bullet in its Conventions section, added in the same prior session).

**The one thing genuinely new this stage**: added a `reference`-type memory entry (`employee_committee_process_standard.md` in the persistent cross-session memory store, indexed in `MEMORY.md`) pointing at `docs/employee-committee-lifecycle/README.md` as the authoritative process reference — this is what "committed to memory" in the stage's Build bullet meant, distinct from the git-ignored docs folder itself and from AGENTS.md's in-repo pointer. Effect: a future session working on `WorkflowService`/`CommitteeStatusService`/`meetings_management`/Track I will surface this reference automatically instead of only finding it by reading AGENTS.md in full or stumbling on Track I in STAGE_PLAN.md.

**Nothing else to build for Stage 38** — no migration, no seeder, no PHP/Vue touched, matching its own "no code change" scope. Whoever picks up Track I next should start at **Stage 39** (agenda drag-and-drop reorder, per the Suggested Order block at the bottom of STAGE_PLAN.md — group 1–2 is cheap design-fidelity work, do 39→44 before the heavier additive Stages 45+).

---

### 2026-08-30 — Claude — Employee Affairs Committee: 5-document comparison + Track I roadmap (no code changed)

User-requested comparison of the whole system against five documents describing the "لجنة شؤون الموظفين" process, ending with the user explicitly declaring two of them **the main reference standard**. Deliverables: `docs/employee-committee-lifecycle/` (git-ignored, local-only — `README.md` for the lineage, one file per source document, `gap-analysis.md` for the full comparison) and a new **Track I** (Stages 38–58) appended to [STAGE_PLAN.md](STAGE_PLAN.md). Nothing in the codebase was touched — this is planning/documentation only.

**The five documents, by authority** (full detail in `docs/employee-committee-lifecycle/README.md`): **[D]** `دليل إجراءات لجنة شؤون الموظفين` (142-page, 114-Article formal procedures manual, "الإصدار الأول يوليو 2026") and **[E]** `المسار التفصيلي المعتمد` (21-stage detailed flow + 7 supplementary diagrams) — **the user's declared standard**, same edition as **[A]** `مسار تقديم طلب وعمل لجنة` (a shorter 12-section summary of the same official process — useful but loses to [D]/[E] on specifics). **[B]** `1.pdf` and **[C]** `اجتماع لجنة.pdf` are a *different*, informal pair — confirmed as the actual direct source for the already-built Track H (meetings/committee subsystem, see the 2026-08-24 entry below) — kept for UI/UX reference only, never authoritative on process shape.

**Two corrections made mid-comparison, worth knowing about since they reverse an earlier assumption**: (1) the Aug-28-redesign's R10 "وكيل الديوان" role and its 3-way `administrative_routing` (HR/Diwan/Committee-secretary) looked invented against the "7 infographics" that redesign actually used — but [D] Art. 10/11 and [E] stage 03 show the *official standard itself* routes intake to exactly these same three parties (وكيل الديوان، مدير إدارة الموارد البشرية، رئيس قسم شؤون الموظفين), selected by subject matter. The shape is right; only the selection *rule* needs verifying (Track I Stage 56), not a rebuild. (2) `direct_manager_review`'s mandatory gate (forward / `return_to_employee`-with-required-comment / cancel) looked like it contradicted [A]'s "advisory only" framing — but [D] Art. 10 requires the manager to forward without unjustified delay *and* bars withholding without a written reason, which is close to what the current required-comment gate already does (Stage 55 — verify wording, not rebuild).

**What's still a real, unresolved conflict**: the pre-committee `ministry_endorsement` workflow stage (currently stage 8, before the committee ever sees the request) and the 4-level `decision_grade`-gated approval tail have no counterpart in *any* of the five documents — all of them put ministry/central involvement exclusively *after* the committee's decision, via a two-path model (mayor-only, or mayor→ministry when required). This is Track I Stage 57, flagged as needing its own migration design (in-flight requests currently sitting at `ministry_endorsement`/`approval_by_authority`/`local_governance_ministry` need a defined path) before any code changes — not something to fix alongside another stage.

**New findings not previously flagged anywhere**: [D] Art. 10 defines a **fixed 5-seat committee roster** (chair = وكيل ديوان البلدية، legal-office member، HR-director member، a **ministry delegate who sits on the committee itself but whose vote never substitutes for a separate central approval** (Art. 14), and مقرر اللجنة = رئيس قسم شؤون الموظفين) — current `committees`/`committee_members` has no seat concept at all, only an open R03/R04 headcount (Track I Stage 45). [D] also gives a canonical 20-status dictionary (Art. 38) that doesn't map cleanly onto the current 25-status enum (Stage 54b), a 6-question jurisdiction test (Art. 45, Stage 54), and a much fuller appeal/تظلم specification (Arts. 75–79, Stage 58) than [A] §9 alone had — appeal remains the single largest missing feature, comparable in size to all of Track H, not built here.

**Also found, independent of the [A]/[D]/[E] standard**: six concrete places where the already-built Track H doesn't match [B]/[C], the documents it was actually built from — agenda reorder is up/down buttons not drag-and-drop, "group similar" groups by department not request type, there's no abstain vote option, decision drafts don't auto-merge record data into the template, the `decisions` screen sits inside the `meetings_management` sidebar group when [C] explicitly says it shouldn't, and the minutes approval order is inverted (approve-then-sign vs. [C]'s sign-then-approve). These are Track I Stages 39–44 — cheap, low-risk, worth doing before the bigger [D]/[E]-alignment stages.

**Not done in this pass, and deliberately out of scope**: no PHP/Vue file was touched, no migration written, no seeder changed. `docs/employee-committee-lifecycle/official-procedures-manual-index.md` is explicitly a **structured index** of [D]'s 142 pages, not a verbatim transcription — it flags its own known gaps (a duplicate Article-103 numbering in the source PDF, appendix numbers reorganized by theme rather than preserved as-printed) and should be re-checked against the actual PDF before citing an exact article/appendix number for anything implementation-critical.

---

### 2026-08-28 21:45 EET — Claude — Transaction → Request rename complete

Built exactly per the plan below (`C:\Users\Dev\.claude\plans\transaction-and-transactions-should-calm-lerdorf.md`). Full literal rename of the core domain word across the entire stack: DB tables/columns (`transactions`→`requests`, `transaction_statuses`→`request_statuses`, `transaction_types`→`request_types`, `transaction_stage_logs`→`request_stage_logs`, `transaction_status_history`→`request_status_history`, `meeting_transactions`→`meeting_requests`, every `transaction_id`/`meeting_transaction_id` FK column), 6 Eloquent models (`Transaction`→`Request`, `TransactionStageLog`→`RequestStageLog`, `TransactionStatus`→`RequestStatus`, `TransactionStatusHistory`→`RequestStatusHistory`, `TransactionType`→`RequestType`, `MeetingTransaction`→`MeetingRequest`), `TransactionController`→`RequestController`, every controller/service/notification/command that referenced the model, 3 Form Requests, 3 Resources, every `routes/api.php` path and named route, the 3 `screens` codes (`transactions`→`requests`, `transaction_intake`→`request_intake`, `transaction_details`→`request_details`) and their Arabic/English labels, ~150 backend PHP files total, 4 frontend view/component files + router + 26 more frontend files with incidental references, and STAGE_PLAN.md/TEST_PLAN.md/TEST_PLAN.ar.md/AGENTS.md/docs/ (this file's own history untouched, per its own rule — this is the one new entry documenting the change).

**Mechanism, for whoever needs to do a rename like this again**: an ordered, scripted multi-pass `sed` sweep (not a single blind find-replace) — `{transaction}`→`{requestRecord}` (route wildcards) and `\$transaction\b`→`$requestRecord` (PHP variables) ran *before* the generic `TransactionRequest`→`Request` (FormRequest dedup) and `Transaction`/`transaction`/`TRANSACTION`→`Request`/`request`/`REQUEST` passes, so the collision-prone identifiers never round-tripped through the ambiguous generic rule. `DB::transaction(` (Laravel's real ACID-transaction API, ~10 files) and the literal mysqldump `--single-transaction` CLI flag were placeholder-protected before the generic passes and restored after — a blind rename would have silently produced a fatal `DB::request()` call and a broken backup command. The two conventions from the plan (`Illuminate\Http\Request as HttpRequest` alias in any file needing both classes; `$requestRecord` for a route-bound domain-model parameter, never `$request`) are now documented in AGENTS.md's Conventions section.

**Three classes of bug the mechanical pass didn't catch on its own, all found and fixed via full-suite runs and targeted greps, not assumed clean:** (1) `route('requests.attachments.preview', ['request' => ...])`/`route('requests.approvals.signature', ['request' => ...])` calls (in `AttachmentResource`, `ApprovalResource`, and three test files) needed their array key renamed to `requestRecord` to match the renamed route wildcard — Laravel's named-route URL generation matches by array key, and this doesn't fail until runtime (`UrlGenerationException: Missing required parameter`), so `php -l` and Pint both stayed clean while these were broken; only the full PHPUnit suite caught it. (2) Arabic gender agreement: `معاملة` (transaction) is grammatically feminine, `طلب` (request) is masculine, so every adjective/verb/possessive-suffix that agreed with the old noun (`جديدة`→`جديد`, `ملغاة`→`ملغى`, `نفسها`→`نفسه`, `أخرى`→`آخر`, `مرتبطة`→`مرتبط`, `موسومة`→`موسوم`, `معروضة`→`معروض`, `أُرسلت`/`انتقلت`/`تجاوزت`/`وصلت`/`ألغيت`→ their masculine forms, `رقمها`/`موعدها`→`رقمه`/`موعده`) needed a manual pass across exception messages, notifications, locale files, seeders, and tests — a mechanical word-for-word swap cannot know Arabic morphology, so every one of these was found by grepping `طلب` next to a tāʾ-marbūṭa-ending word and reviewed by hand, not assumed. One doubled phrase also surfaced this way: the `requests` screen's original label was literally "طلبات المعاملات"/"Transaction Requests" (already using both words with different meanings — "the *queue* of transaction *items*"), which the mechanical pass correctly-but-uselessly rendered as "طلبات الطلبات"/"Request Requests"; fixed by hand to "الطلبات"/"Requests" in both `ScreenSeeder.php` and the frontend locale files. (3) Two files (`app/Exceptions/MeetingOutputTransitionException.php`, `database/seeders/RoleSeeder.php`) contain الArabic "معامل" root with **no English "transaction" word nearby**, so they were invisible to the initial repo-wide discovery grep (which searched for the English word) and were skipped by the whole mechanical pass entirely until a final repo-wide `معامل` sweep (no English filter) caught them — worth remembering if anyone re-derives file lists for a similar rename by searching only one language.

**Deliberately NOT renamed, and why:** `AbuSaleemTransactions` (the repo/project folder name — renaming a live working directory is disruptive and wasn't asked for); "Abu Saleem Transactions" the literal English brand-name phrase in AGENTS.md/TEST_PLAN.md/docs/system-flow.md/docs/user-permissions-flow.md (restored after the mechanical pass mangled it to "Abu Saleem Requests" — it's the org's proper name, not the domain word); `https://transactions.scco.ly` in `frontend/.env.production` (the actual live production API hostname — a real DNS/deployment concern, completely outside a code-level rename); every `DB::transaction(`/`database transaction`/`--single-transaction` occurrence (Laravel's and MySQL's own ACID-transaction vocabulary, unrelated to the domain entity — confirmed by reading each one in context, not by pattern alone, since e.g. `NotificationDispatcher.php` and `WorkflowService.php` mix both meanings in the same file).

**DB approach**: wipe-and-reseed (the user's explicit choice) — edited every migration file's table/column names directly (git-mv'd 9 of them to matching new filenames, kept original timestamp prefixes), then `php artisan migrate:fresh --seed` + `php artisan db:seed --class=TestUserSeeder` against the real MySQL/Homestead database, run twice (once before, once after the RoleSeeder Arabic-grammar fix) — both clean, 15 users, matching screen/permission counts to before.

**Verification**: full PHPUnit suite **162 tests / 994 assertions green** (started at 994 before this change too — the rename is behavior-preserving), Pint clean (`--test` passes with zero diffs) on every touched file, `npm run build` passes with all renamed views as their own chunks (`RequestsView`, `RequestDetailView`, `RequestIntakeView`), locale key-parity verified programmatically (756 keys each side, zero on-one-side-only), and three separate residual sweeps came back clean: case-insensitive `transaction` (only the deliberately-preserved brand phrase and `DB::transaction(`/CLI-flag mentions remain), Arabic `معامل` root (zero, repo-wide, no language filter), and `Request Request`/`Requests Requests` doubled-phrase check (zero). `php -l` across the entire `app/`, `database/`, `routes/`, `tests/` trees (not just changed files) is clean, and a grep for every old class name (`TransactionController`, `App\Models\Transaction`, etc.) across the same trees returns nothing.

**Known follow-up, not done in this pass**: 3 of the 5 diagram SVGs under `docs/diagrams/` (`request-meeting-lifecycle.svg` — also renamed from `transaction-meeting-lifecycle.svg`, `role-permission-workflow.svg`, `supporting-operations.svg`) are pre-rendered images that still show the old English/Arabic text — their `.mmd` sources were text-updated by the same mechanical pass, but no local Mermaid CLI was available this session to regenerate the SVGs (same gap prior sessions have hit; `docs/` is git-ignored so this doesn't block anything tracked). **Nothing was committed** — the working tree has the full rename plus a rebuilt `frontend/dist` (tracked in git, so its churn is part of this change, not reverted like a routine verification build would be); committing is the user's call.

---

### 2026-08-28 21:15 EET — Claude — Transaction → Request rename — implementation plan

Full literal rename of the core domain word "transaction"/"Transaction" to "request"/"Request" everywhere: DB tables/columns, the `Transaction` Eloquent model and its 5 satellite models (`TransactionStageLog`, `TransactionStatus`, `TransactionStatusHistory`, `TransactionType`, `MeetingTransaction`), `TransactionController` and every controller/service/notification/command that references the model, Form Requests, API Resources, `routes/api.php` paths and screen-permission codes (`transactions`→`requests`, `transaction_intake`→`request_intake`, `transaction_details`→`request_details`), all ~150 backend + ~30 frontend files, and STAGE_PLAN.md/TEST_PLAN.md/TEST_PLAN.ar.md/AGENTS.md/docs (this file's own history stays untouched, per its own rule — only this new entry documents the change).

**Two naming decisions made with the user up front, load-bearing for the whole rename:** (1) `App\Models\Request` collides with `Illuminate\Http\Request`, imported in most controllers — resolved by aliasing the framework class as `use Illuminate\Http\Request as HttpRequest;` in any file that needs both (an unaliased `use` collision is a PHP fatal regardless of which method uses which), and by never naming a route-bound domain-model parameter `$request` (it would collide with the conventional Form-Request `$request` param) — use `$requestRecord` instead, with the route wildcard renamed to match (`{transaction}`→`{requestRecord}`) since Laravel's implicit binding matches by parameter name, not URL text. (2) Laravel's Form Request classes already carry a `Request` suffix, which would otherwise double into `StoreRequestRequest` — resolved by dropping the domain word entirely: `StoreTransactionRequest`→`StoreRequest`, `IndexTransactionRequest`→`IndexRequest`, `TransitionTransactionRequest`→`TransitionRequest`, living in `app/Http/Requests/Request/`. DB approach is wipe-and-reseed (edit table/column names directly in the existing migration files, then `php artisan migrate:fresh --seed` + `TestUserSeeder`), not a non-destructive rename — the user's explicit call, since the local database is routinely reseeded anyway.

Full plan with the complete backend/frontend/docs file inventory is at `C:\Users\Dev\.claude\plans\transaction-and-transactions-should-calm-lerdorf.md`. **Status: approved, implementation starting now in this session** — backend model/migration/seeder/FormRequest/Resource/controller/route layer first (highest naming-convention risk, done directly rather than delegated), then services/notifications/commands, tests, frontend, and docs. If this note is stale when read, check git log/status for how far the rename actually got before assuming any of it landed.

---

### 2026-08-28 20:05 EET — Codex — Transaction workspace safeguards complete

The direct transaction workspace now limits access to a creator's submissions or their current actionable assignments, returning 404 for unrelated records and attachments. Private PNG/JPG/PDF attachments have authenticated previews in the detail view and local pre-upload previews; DOC/DOCX remain downloads. WorkflowService blocks a creator from approving their own transaction across the generic, queue, and committee-decision paths, and the timeline arrow now mirrors correctly in Arabic. Full PHPUnit passed (162 tests, 994 assertions), changed-PHP Pint passed, and the frontend production build passed; no migration was added.

---

### 2026-08-28 19:49 EET — Codex — Transaction privacy and attachment-preview implementation plan

Implement direct-workspace privacy as creator-owned submissions plus work currently actionable by the signed-in user, with no broad R08 reporting bypass. A shared visibility service will protect the paginated transaction list, detail, transitions, notes, uploads, and the new private attachment-preview stream; reports and committee/register screens remain outside this scope. The same pass adds authenticated PNG/JPG/PDF previews, locale-aware timeline arrows, and a WorkflowService-level self-approval block so every approval entry point fails closed.

---

### 2026-08-28 — Claude — `manager_id` gap closed — the diagram-alignment redesign is fully wired end-to-end

Fixes the one flagged gap from the Phase 8 entry below: `StoreUserRequest`/`UpdateUserRequest` didn't validate `manager_id`, and `UserResource` didn't expose it, so the new manager picker in `UsersView.vue` was decorative — saving it was a no-op and editing a user always showed "no manager". Added `manager_id` to both requests' `rules()` (nullable, `exists:users,id`; `UpdateUserRequest` additionally rejects `manager_id === $user->id` via `Rule::notIn` — a user cannot be their own manager, which would make the `direct_manager_review` stage permanently unreachable for them), added a `manager` block to `UserResource` mirroring the existing `department` block, and eager-loaded `manager` alongside `department`/`roles` in every `UserController` action that returns one. Verified via tinker against the real database that the seeded `r01.employee@`'s `manager_id` (wired to `r02.reviewer@` in an earlier phase) now round-trips through `UserResource`. Full suite still 156 tests / 961 assertions green, Pint clean, no pending migrations.

**The redesign (all 8 phases, this fix included) is now complete and fully functional end-to-end** — submit → direct-manager review → 3-way routing → status-gated registration → the existing 11-stage chain, with the manager relationship actually settable and visible through the UI. Still pending, per the Phase 8 entry: `TEST_PLAN.md`/`TEST_PLAN.ar.md` and `docs/system-flow.md`/`docs/user-permissions-flow.md`/`docs/diagrams/*` still describe the old 11-stage/8-role model and need a documentation pass.

---

### 2026-08-28 — Claude — Phase 8 (frontend) complete — the diagram-alignment redesign is now fully built

Closes out `i-just-want-to-reactive-knuth.md` (the "Employee Affairs Committee request" infographic
alignment). All prior phases were already done and verified against the real MySQL/Homestead
database (156 tests / 961 assertions green, 14 stages / 57 transitions / 10 roles seeded) — see the
Phase 0, Phases 1–3, and "closes the plan" (Phases 4–7) entries below for the backend/schema work.
This entry covers Phase 8 only, and **no PHP file was touched**.

**Locale keys.** `TransactionDetailView.vue` was re-verified to render every workflow action
generically via `actionLabel = (action) => t('workflow.actions.' + action)` — confirmed by reading
the component directly, not assumed from the plan. Added the six new action keys
(`submit`, `route_to_hr`, `route_to_diwan`, `route_to_committee_secretary`, `register`,
`return_to_employee`) to `workflow.actions` in both `frontend/src/locales/ar.json` and `en.json`,
matching the existing entries' tone/style. Also confirmed no frontend file hardcodes a stage-code →
label map: `DashboardView.vue`'s `stageLabel()` and `ReportsView.vue`'s stage column both read
`order_no`/`name_ar`/`name_en` straight off the API row, so the 3 new stages need no separate frontend
change beyond the seeded data itself. Verified key-parity by flattening both JSON files and diffing
their key sets: 751 keys each side, zero keys present on only one side.

**Manager picker.** Added a `manager_id` field to `frontend/src/views/UsersView.vue`'s create/edit
form (a `<select>` styled like the existing department picker, reusing the already-loaded `/users`
list rather than a new endpoint), plus `users.manager`/`users.noManager` locale keys in both files.
A new `managerOptions` computed filters to active users and excludes whoever is currently being
edited, so the UI can't offer a user as their own manager — the self-loop the plan flagged as an open
question. `startEdit()` now seeds `form.manager_id` from `user.manager?.id` and `save()` includes
`manager_id` in the payload, matching the department field's shape exactly.

**Gap found and left unfixed, as instructed (backend was explicitly out of scope for this task):**
`manager_id` is fillable on the `User` model and the migration/relations exist (from Phase 2), but
**`StoreUserRequest`/`UpdateUserRequest` do not validate it and `UserResource` does not expose it.**
Concretely: (1) `UserController::store()`/`update()` both build their write payload from
`$request->safe()->except(...)`, which only ever contains *validated* fields — since `manager_id`
isn't in either FormRequest's `rules()`, it is silently dropped even though the frontend now sends
it, so setting a user's manager from this screen currently has no effect; (2) `UserResource::toArray()`
has no `manager` key at all, so `startEdit()`'s `user.manager?.id` will always read `undefined` today
— the picker will always default to "no manager" when editing an existing user, even one that already
has `manager_id` set at the database level (e.g. `r01.employee@` → `r02.reviewer@`, wired by
`TestUserSeeder` in the Phase 7 entry below). This is a two-or-three-line backend fix (add
`manager_id` to both FormRequests' rules and a `manager` block to `UserResource`, mirroring the
existing `department` block) but it's real backend work, so per this task's explicit
"do not touch any PHP file" instruction it was flagged here rather than fixed. The direct-manager
workflow itself (submission → `direct_manager_review`, gated on `WorkflowService::actorMayUse()`
reading `manager_id` directly off the model) is unaffected by this gap — it was already verified
end-to-end over real HTTP in the entry below, using `manager_id` set directly by the seeder, not
through this screen.

**Router.** Confirmed `frontend/src/router/index.js` needs no change — the `placeholderScreens`
mechanism was removed in Stages 25–27 and every seeded screen (including the meetings-unit ones) has
a real route already; nothing about the new stages/roles/actions changes routing since stage and role
data both come from the API at runtime.

**Verification.** `npm run build` passes clean (all existing lazy chunks, no new ones — `UsersView`'s
chunk just grew slightly). `frontend/dist` is tracked in git in this repo (confirmed via
`git ls-files`); after the build, `git status --short frontend/dist` showed the expected
churn (new content-hashed filenames), and it was reverted with `git checkout -- frontend/dist &&
git clean -fd frontend/dist` — `git status --short frontend/dist` is now clean, matching every prior
stage's convention of not committing rebuilt `dist/` output as a side effect of verification.

**Explicitly not done in this pass, per the task's own scope note** — `TEST_PLAN.md`/`TEST_PLAN.ar.md`
and `docs/system-flow.md`/`docs/user-permissions-flow.md`/`docs/diagrams/*` still describe the old
11-stage/8-role model and were **not** updated here; they are stale until a future documentation-
focused session does that sweep (it's explicitly named in the plan's "Files to touch" → "Docs"
section, roughly 40 stage-number references between the two TEST_PLAN files alone).

With this entry, all 9 phases of `i-just-want-to-reactive-knuth.md` (Phase 0 through Phase 8) are
built and verified except for the manager_id API-wiring gap flagged above and the docs sweep flagged
above — both are known, scoped follow-ups, not silent gaps.

---

### 2026-08-28 — Claude — Diagram-alignment redesign: intake auto-hop, notifications, permissions/test data (closes the plan)

Closed the one known gap left after Phases 0–5 landed (`i-just-want-to-reactive-knuth.md`), then built Phases 6–7. Baseline going in: 155 tests / 956 assertions, 14 stages / 57 transitions / 10 roles already seeded in the real database.

**The `store()` gap.** `TransactionController::store()` created a transaction at `receive_from_municipality` and stopped. It now performs one additional system hop into `direct_manager_review` inside the same `DB::transaction()`, right after the intake `TransactionStageLog`/`TransactionStatusHistory` writes (which are untouched — the truthful stage-1 origin is still recorded first). Added a shared helper on `WorkflowService`: `applyRule()` (private) now holds the write shape — comment/signature/deadline checks, the stage+status mutation, and the three history/ledger writes — factored out of `transition()`'s closure with zero behavior change (`transition()` now just resolves its actor-checked `$rule` and calls `applyRule()`). New public `applySystemTransition(Transaction, string $fromStageCode, string $action, User $actor, ?string $comment = null): Transaction` resolves the single non-exception rule matching `(from_stage, action)` with **no actor/role check at all**, calls `applyRule()`, and fires `stageChanged()` itself. This was necessary, not cosmetic: the seeded `submit` row is R01-gated, but `transaction_intake.add` is granted to R01–R06 — an R02+ clerk filing on someone else's behalf would 403 through `transition()`'s normal actor check even though the hop isn't *their* workflow action, it's the system's. `applySystemTransition()` filters to `is_exception=false` specifically so it always resolves the canonical R01 happy-path row, never the R08 override sibling that sits beside it at the same `(from_stage, action)`.

Updated `TransactionIntakeTest`'s post-create assertions (`current_stage.code` is now `direct_manager_review`, `status.code` is `in_review`) and added assertions for both the preserved intake stage log and the new `submit` stage log + both status-history rows. `DirectManagerRoutingTest` (already existed from the Phase 4/5 work) builds transactions directly at whatever stage via a private factory, bypassing `store()` entirely, so it needed no changes — it already covered manager/role/status-gate mechanics independent of the intake hop. `TransactionDetailTest` also builds transactions directly and was unaffected.

**Phase 6 — notifications.** `NotificationDispatcher::actorsForStage()` read only `required_role_id` from a destination stage's outbound non-exception rows, so `direct_manager_review` (whose only outbound row is manager-gated with `required_role_id = null`) resolved zero recipients — the manager would never get an `ActionRequiredNotification`. Fixed by also checking `requires_submitter_manager` on the outbound rows and, when set, resolving the creator's active manager (new private `creatorsActiveManager()`, deliberately mirroring — not reusing, these are two separate classes — `WorkflowService::actorIsCreatorsActiveManager()`'s exact resolution: read `manager_id`, confirm that row is `is_active=true`, dangling/deactivated means nobody) and adding them to the recipient collection alongside any role-based recipients. `transactionOverdue()` shares the same private method, so it picked up the fix automatically — verified by reading the call site, no separate test needed there since Phase 6's own scope was `direct_manager_review`. Added `test_landing_at_direct_manager_review_notifies_the_creators_manager` to `NotificationTest.php`.

**One real side effect discovered during HTTP smoke-testing, not fixed (flagging, not scope creep):** `TransactionController::store()` calls `$notifications->transactionCreated($transaction, $actor)` *after* the DB transaction commits, reading `$transaction->current_stage_id` at that point — which is now `direct_manager_review` (post-hop), not stage 1. Since `transactionCreated()`'s recipients also come from `actorsForStage()`, the manager now receives both a `TransactionCreatedNotification` *and* an `ActionRequiredNotification` for the same submission — mildly redundant (two messages for one event) but not incorrect, and arguably more honest than telling the manager "a new transaction exists" while implying it's still sitting at stage 1 waiting on nobody. Left as-is; worth a look if someone later wants to collapse it into one message.

**Phase 7 — permissions and test data.** `ScreenRolePermissionSeeder`: added R09 to `meetings_dashboard`/`committee_candidates`/`meeting_agenda`'s `add` and `edit` lists (the union of what R03+R04 already hold there) — `meetings`/`meeting_readiness`/etc. untouched, scope was exactly the three screens the plan named. **Verified `transactions`/`transaction_details` needed no seeder change**: both already grant `view => '*'`, and neither grants an explicit `edit` to anyone but R08 — including R05, so "mirror R05's shape" for those two screens means no new grant, since R05 doesn't have one either. The transition endpoint (`POST transactions/{transaction}/transition`) is gated by `screen.permission:transaction_details,view`, which R09/R10 already had for free the moment `RoleSeeder` created them (Stage 9's "any `'*'`-view screen" behavior, same as R06/R07). Confirmed live over HTTP: R05 attempting to register a diwan-routed file gets a 422 from `WorkflowService`, not a 403 from the screen gate — authorization for the *specific* workflow action was always going to live in `actorMayUse()`'s role/status check, never the screen permission.

`PermissionSeeder` (the older, now-dead `Permission`/`user.hasPermission()` catalogue — confirmed via grep that nothing in `app/Http/Controllers` calls `hasPermission()` any more; `screen_role_permissions` has been the real enforcement path since Stage 9): added R09/R10 to `transactions.view/edit/notes/attachments`, `transactions.forward`, `reports.view`, `audit.view`, matching R05's existing entries exactly, per the plan's explicit instruction to keep it in step even though it's not read by any enforcement code.

`TestUserSeeder`: added `r09.secretary@abusaleem.test` (CMT department, matching the other committee-side accounts) and `r10.diwan@abusaleem.test` (ABS/root, matching the other senior/administrative accounts). Wired `r01.employee@`'s `manager_id` to `r02.reviewer@`'s id — chose the existing reviewer account over adding a dedicated manager account, since manager-gated rows check `manager_id` only, never role, so this doesn't change anything r02.reviewer@ can already do and keeps the roster from growing for one relationship. Implemented by collecting each `User::updateOrCreate()` result into a `$users[$email]` map during the existing loop, then wiring the relation in one `save()` after. Updated the class docblock's role-chain description to the new front-of-chain shape.

**Verification.** `php artisan migrate --force`: nothing to migrate (Phases 0–5's migrations already applied). `php artisan db:seed --force` run twice: stable at 14 stages / 57 transitions / 10 roles / 27 statuses / 30 screens / 300 `screen_role_permissions` / 14 `permissions` both times. `php artisan db:seed --class=TestUserSeeder --force` run twice: stable at 15 users, `r01.employee@`'s `manager_id` correctly resolves to `r02.reviewer@`'s id both times. Full PHPUnit suite: **156 tests / 961 assertions, green** (was 155/956 — +1 test, +5 assertions from the intake-test additions and the new notification test). Pint clean on every touched file (one auto-fix applied to `PermissionSeeder.php` — array alignment spacing, not a manual edit); the repo-wide `pint --test` failures are the same pre-existing baseline drift in untouched files every prior note flags (`bootstrap/*`, `config/auth.php`, several `app/Models/*`, `UserFactory.php`).

**Full HTTP smoke test against the real Homestead/MySQL database**, the whole golden path in one run: logged in as `r01.employee@`, submitted a transaction — landed at `direct_manager_review` (not `receive_from_municipality`). Logged in as `r02.reviewer@` (the wired manager) — `available_actions` included `forward`; forwarded to `administrative_routing`; routed to Diwan (`route_to_diwan`) — landed at `receive_and_register` / `routed_to_diwan`. Logged in as `r05.manager@` (wrong registrar for this route) and attempted `register` — refused with 422 (`لا يملك المستخدم الدور المطلوب...`), proving the status gate is real, not decorative. Logged in as `r10.diwan@` (correct registrar) and registered successfully — landed at `requirements_check` / `registered`. Separately drained the 51 queued jobs that had piled up from the smoke run (`QUEUE_CONNECTION=database`, no worker was running) with `queue:work --stop-when-empty` and confirmed `r02.reviewer@` actually received the `ActionRequiredNotification` database row — the Phase 6 fix is real end-to-end, not just covered by the fake-notification unit test.

**Not touched, out of scope for this task**: Phase 8 (frontend — `UsersView.vue` manager picker, locale keys for the new action names) and the docs sweep (`TEST_PLAN.md`/`TEST_PLAN.ar.md`, `docs/system-flow.md`, etc.) named in the plan's "Files to touch" list were not part of this task's explicit scope, which stopped at Phase 7 plus the store() gap.

---

### 2026-08-28 — Claude — Phases 1–3 of the committee-secretary/direct-manager redesign complete (roles, schema, actorMayUse)

Built per `i-just-want-to-reactive-knuth.md` Phases 1–3, on top of Phase 0 (already landed, see the entry below). **Phase 1**: `RoleSeeder` gains R09 (أمين سر اللجنة / Committee Secretary) and R10 (وكيل الديوان / Diwan Deputy), purely additive — `PermissionSeeder`/`ScreenRolePermissionSeeder` untouched, per the task scope (that's Phase 7). **Phase 2**: two migrations — `users.manager_id` (nullable, self-referencing FK, `after('department_id')`, `nullOnDelete`) with `User::manager()`/`directReports()` relations added; `workflow_transitions.requires_submitter_manager` (boolean, default false) + `required_status_id` (nullable FK → `transaction_statuses`, `nullOnDelete`) with `WorkflowTransition::requiredStatus()` added and both columns wired into `$fillable`/`casts()`. **Phase 3**: extracted `WorkflowService::actorMayUse()` and wired it into both filter sites (`transition()`'s `ambiguousConfiguration()`-feeding filter, and `availableTransitions()`'s UI-preview filter) so they can never diverge — the load-bearing correctness property the plan calls out explicitly.

**One deviation from the plan's literal method signature, flagged as instructed.** The plan (and the task prompt copying it) specifies `actorMayUse(WorkflowTransition $rule, Transaction $transaction, Collection $actorRoleIds): bool` — three params, no actor identity. That's insufficient to implement "the actor IS the transaction creator's manager," which requires comparing a specific user id, not just a set of role ids. I added a fourth parameter, `User $actor`, positioned right after `$transaction` — both call sites already had `$actor` in scope (the `availableTransitions()` closure needed `use ($roleIds, $actor, $transaction)` added; `transition()`'s closure already captured `$actor`). Manager resolution (`actorIsCreatorsActiveManager()`) queries the creator's `manager_id` then confirms that manager row exists with `is_active=true` via a fresh `User::query()` — this naturally respects the default soft-delete global scope (a trashed manager is invisible to it) without needing `withTrashed()`/`trashed()` checks. R08 fallback (`actorHoldsR08()`) resolves the role id by code once and caches it on a private instance property (`$r08RoleId`), avoiding a repeated `roles` query per candidate row in a filter loop.

`WorkflowTransitionSeeder::seedException()` now takes the two new optional params (`bool $requiresSubmitterManager = false`, `?int $requiredStatusId = null`) plus a widened `?int $requiredRoleId` (was non-nullable — a manager-gated row has no fixed role), threaded into `updateOrCreate()`'s attributes. The main happy-path `$transitions` loop's `updateOrCreate()` call also got both new columns set explicitly (`false`/`null`) rather than left to defaults, since an upsert's UPDATE branch doesn't re-apply a schema default. Nothing seeded sets either new gate — confirmed via tinker (`requires_submitter_manager=true` count: 0, `required_status_id` non-null count: 0) after reseeding.

Verification: migrations ran clean against the real configured MySQL/Homestead database (`php artisan migrate --force`, both new migrations `DONE`). Full PHPUnit suite **148 tests / 903 assertions green, unchanged** before and after — confirms `actorMayUse()` is behavior-preserving for every existing row. Pint clean on all seven touched files (two auto-fixes applied to `User.php`/`WorkflowTransition.php` — import ordering and phpdoc alignment, not manual edits). `RoleSeeder` re-run twice against the real database: role count 8→10→10 stable, exactly one R09 row and one R10 row both times. `WorkflowTransitionSeeder` re-run twice: row count 40→40→40 stable, confirming the widened `seedException()` signature and the new attributes on the happy-path loop don't break the existing upsert key or duplicate rows.

**Not touched, by design (later phases in the same plan, explicitly out of scope for this task):** no new stages/statuses/transitions (Phase 4/5 — `workflow_stages` is still 11 rows, `workflow_transitions` still 40), no notifications (`NotificationDispatcher::actorsForStage()` doesn't yet know about `requires_submitter_manager`, Phase 6), no `ScreenRolePermissionSeeder`/`PermissionSeeder` grants for R09/R10 (Phase 7 — they currently inherit only whatever `'*'`-view screens grant for free, same as R06/R07 today), no `TestUserSeeder` accounts or `manager_id` wiring for the golden-path walkthrough (also Phase 7), no frontend (Phase 8). The two new schema columns and `actorMayUse()` mechanism are inert until Phase 4/5 actually seeds a manager-gated or status-gated row.

---

### 2026-08-28 — Claude — Phase 0 of the committee-secretary/direct-manager redesign complete (order_no → code refactor)

Behavior-preserving prep refactor for the plan at `i-just-want-to-reactive-knuth.md` (see its Phase 0 section for full rationale — `workflow_stages.order_no` is `unique()`, so later phases inserting new stages need every identity lookup re-keyed off the stable `code` string first). Converted four production sites from literal `order_no` integers to stage `code` lookups: `ApprovalController::LEVELS` + its two `order_no` comparisons, `TransactionController`'s `$screenByStage` map (plus one unlisted-but-same-category `WorkflowStage::where('order_no', 1)` in `store()`), `MeetingOutputsResource`'s `order_no > 7` (now compares against `receive_from_committee`'s `order_no` looked up by code, not a hardcoded `7`), and `WorkflowTransitionSeeder` (`keyBy('code')` plus every literal in `$transitions`/`$exceptions`/`$cancellationRoles`/the four stage-7 committee exceptions/the `deadline_expired` loop, now iterating an ordered array of the first 10 stage codes instead of `range(1, 10)`).

Also converted ~20 test files' identity-lookup usages (`WorkflowStage::where('order_no', N)`, `assertSame(N, ...->currentStage->order_no)`, `assertJsonPath('...current_stage.order_no', N)`) to `code`-based equivalents, including changing several test helper functions' parameter type from `int $stageOrder` to `string $stageCode`. **Left unconverted, deliberately**: bulk ordering assertions with no `where('order_no', ...)` lookup (e.g. `assertSame(range(1, 11), $rules->pluck('fromStage.order_no')->all())` in `WorkflowServiceTest`) — those test that the seeded chain forms a proper ascending sequence, which is an ordering property, not an identity lookup, and stays true under any renumbering.

**One real gotcha hit during this refactor, worth flagging for the next agent doing a similar mechanical rename**: converting a test helper's signature from `int $stageOrder` to `string $stageCode` is not enough by itself — every call site passing a literal int (`$this->transactionAt(7, 'decided')`) silently type-mismatches against a `where('code', 7)` query, which finds nothing, leaves `current_stage_id` null, and produces a business-logic-shaped failure (e.g. "committee actions can only run on a transaction at receive_from_committee stage") rather than a type error, since PHP doesn't enforce scalar type coercion strictly here. `CommitteeCandidatesDashboardTest.php` had four such stale call sites that were missed on the first pass and caught by a full suite run before being fixed. `WorkflowStage::query()->where('order_no', 1)` inside `TransactionController::store()` was converted too even though the task's file-by-file description only named the `$screenByStage` map — it's the same category of issue in the same file and the plan's own stated Phase-0 exit condition ("the only things that still care about the actual integers are order_no's ordering semantics and the docs") implied it.

Verified: clean baseline via `git stash` (148 tests / 903 assertions, all green) before any edits, then full suite green at the identical 148/903 after all changes, Pint clean on all 24 touched files. **Not touched, per the plan's explicit instruction**: `ReportMetricsService::byStage()` (already generic) and `WorkflowService::APPROVAL_LEVELS`/`destinationStageId()` (already keyed by code). No schema change, no seed-data change, no new stages/roles — this is prep only. Phases 1–8 of the redesign (new roles R09/R10, `users.manager_id`, the 3 new front-half stages, routing/registration enforcement, notifications, permissions, frontend) are still unbuilt.

### 2026-08-28 00:00 EET — Claude — Direct-manager review + 3-way routing + committee-secretary role — implementation plan (Stage 38+)

The user attached a municipality infographic (دورة حياة طلب لجنة شؤون الموظفين, 10 steps) and asked to confirm the transaction workflow matches it. Comparison found the committee-voting core (agenda/vote/decision) already matches, but three things the diagram shows have no equivalent in the code: no direct-manager review step, no 3-way administrative routing (HR/Diwan deputy/committee secretary), and no distinct committee-secretary role (R03 "Committee Head" currently owns both stage-7 receipt and decision authority). The user chose to change the code to match the diagram.

**Plan (full detail in the plan file used for this session, `i-just-want-to-reactive-knuth.md` — key points recorded here since that file isn't part of the repo):** Two new roles (R09 أمين سر اللجنة committee secretary, R10 وكيل الديوان Diwan deputy; HR routing reuses existing R05). New `users.manager_id` self-referencing FK + a new `workflow_transitions.requires_submitter_manager` boolean so `WorkflowService::transition()`/`availableTransitions()` can gate an action on "actor is the submitter's manager" (with an R08 fallback when unassigned) alongside the existing role check — both call sites share one new `actorMayUse()` predicate so the UI and the enforcement can never disagree. Three new stages inserted before `requirements_check` (`direct_manager_review`, `administrative_routing`, `receive_and_register`; 11 stages → 14), plus a new `workflow_transitions.required_status_id` column so the 3-way routing's convergent `register` action is actually enforced per-destination rather than decorative (R10 can't register a file routed to HR). The back-half approval chain (`approval_by_authority` → `final_approval_archiving`, 4 checkpoints) is deliberately **kept as-is**, not collapsed to match the diagram's single final box — collapsing it would delete real, already-shipped approval/signature/ledger machinery for no gain, since the diagram box is a coarser summary, not a contradiction.

**Mandatory first step, not optional prep:** a Phase 0 refactor re-keying `WorkflowTransitionSeeder`, `ApprovalController::LEVELS`, `TransactionController::actorCanApproveCurrentLevel()`'s stage map, and `MeetingOutputsResource`'s `order_no > 7` check from literal `order_no` integers to stage `code` strings — `workflow_stages.order_no` is a unique DB column, so inserting stages mid-sequence requires renumbering, and everything still keyed by the raw integer would silently break or collide. This must land and pass the full suite with zero behavior change before any new stage/role is seeded. ~20 test files also need this same `order_no`→`code` swap. A prior draft of this plan (before a second review pass) got the registration-convergence mechanism wrong — three `register` rows sharing one seeder upsert key would have silently collapsed into one row — so if reviving this plan later, use the corrected version's `required_role_id`-plus-`required_status_id` widened upsert key, not a naive "3 rows, same action" design.

**Status: planned only, nothing implemented yet.** Whoever picks this up next should re-derive the full phase breakdown from scratch if this note is stale, or ask the user whether the plan file still exists in `~/.claude/plans/`.

---

### 2026-08-28 15:30 EET — Claude — Diagram-alignment redesign, Phases 4–5 complete (new stages, routing, registration)

Built per the plan at `C:\Users\Dev\.claude\plans\i-just-want-to-reactive-knuth.md` (Phases 0–3 were already done in a prior session). `workflow_stages` now has 14 rows, not 11: `direct_manager_review`, `administrative_routing`, `receive_and_register` inserted as the new order 2/3/4, with `requirements_check`…`final_approval_archiving` renumbered to 5–14 (unchanged identity — stage ids are stable, upsert is on `code`). `WorkflowStageSeeder` renumbers in the two required passes (bulk `order_no + 1000` bump, then write final values) to never collide with `order_no`'s unique constraint — verified by seeding twice against the real MySQL/Homestead database and confirming both `workflow_stages` (14) and `workflow_transitions` (57) are stable across runs. `receive_from_committee`'s **display-only** `responsible_role_id` moved R03→R09 as planned; its `required_role_id` for the actual vote/decision actions is untouched (still R03, seeded separately).

`TransactionStatusSeeder` gained 4 statuses (`routed_to_hr`, `routed_to_diwan`, `routed_to_committee_secretary`, `registered`). `WorkflowTransitionSeeder` gained: a `submit` happy-path row (R01, receive_from_municipality→direct_manager_review) plus an R08 override sibling; the 3-way `route_to_*` branch out of `administrative_routing` (manager-gated, `is_exception=true` matching the `conditional_approve` precedent, `requires_comment=false` — the diagram doesn't ask for a reason on a routing choice); the 3 `register` rows out of `receive_and_register`, each gated on **both** its role (R05/R10/R09) and its matching `routed_to_*` status — this is what makes routing enforceable, not decorative, and is covered by a test that proves R10 is refused an HR-routed file. Both `seedException()`'s upsert key and the main happy-path loop's were widened to include `required_role_id` (register rows also add `required_status_id`), since several new rows deliberately share `(from_stage, action)` with a sibling that differs only by role/status — without the wider key they'd silently collapse into one surviving row on every re-seed.

**One real gap the plan's own text didn't spell out, caught before it became a dead end:** nothing seeded a transition from `direct_manager_review` to `administrative_routing` — the plan's Phase 5 text only described the `submit` hop and the `route_to_*` branch, leaving `administrative_routing` unreachable. Added one more row (action `forward`, manager-gated, `is_exception=false` — it's the deterministic single continuation, not a branch or a correction) to close that gap. No separate R08 sibling was needed for it (unlike `submit`): `actorMayUse()` already lets R08 through any `requires_submitter_manager` row automatically, which is also why no R08 siblings were seeded for the routing branch or the two manager-gated `cancel` rows either — only `submit` needed one, because *that* row is role-gated (R01), not manager-gated.

**Judgment calls, as flagged by the task:** registration convergence (3 `register` rows) is `is_exception=false` — exactly one legitimate actor exists for any given file once the status disambiguates it, so it's the normal path's continuation, just three-role-shaped, not a corrective/alternate outcome. `cancel` at `receive_and_register` is 3 role-gated rows (R05/R09/R10) with **no status gate** — any of the three legitimate receiving roles may cancel regardless of which route was actually taken, per the plan's own "simpler fallback" framing (cancelling doesn't need to prove which route was taken). `deadline_expired`'s escalation loop was extended to also cover the 3 new stages (any open stage before final archiving can breach SLA), unchanged target (`local_governance_ministry`) and role (R08).

**Explicitly NOT done, and deliberately out of this task's scope:** `TransactionController::store()` still does not auto-fire `submit` — a newly-created transaction sits at `receive_from_municipality` until its R01 creator (or R08) explicitly performs `submit` through the normal generic transition endpoint. The full plan's Phase 5 text describes this as a "system hop… inside the same DB::transaction" in `store()`; this task's instructions scoped Phase 4/5 to the seeder/stage-graph work only, so that controller wiring was left undone rather than assumed. This is a real gap for whoever does the manual smoke test/frontend pass (Phase 6-8): submitting a new request from the intake form will land it at stage 1, not stage 2, until either `store()` is wired or a user clicks "submit" themselves. Flagging explicitly rather than silently building around it.

Tests: fixed all 6 pre-existing failures the stage-graph change caused (`WorkflowServiceTest`'s happy-path walk, role-rejection test, the two structural seeded-rule-shape tests, and the ministry-skip test; `TransactionDetailTest`'s advance-from-detail-screen test) — all were legitimately outdated by the new front-half stages, not bugs in the fix; each fix is commented in-place explaining why. Added new `tests/Feature/DirectManagerRoutingTest.php` (7 tests) covering the manager-actor gate (stranger refused, assigned manager allowed, R08 override with no manager, soft-deleted/inactive manager falls through to R08 without a 500), all 3 routes landing with distinct statuses, the registration role+status double-gate (including the R10-cannot-register-an-HR-file proof), `return_to_employee`'s comment requirement, cancel at all 3 new stages, and `availableTransitions()`/`transition()` agreement for a manager-gated row. Full suite: **155 tests / 956 assertions**, up from 148/903 (6 fixed in place, 7 new). Pint clean on every touched file (one cosmetic `phpdoc_align` fix on `WorkflowStage.php`). Also updated the docblocks in `WorkflowStage.php` and the `workflow_stages` migration header (11→14 stages, updated stage-name table) since they were explicitly named in the plan's "Files to touch" list and were otherwise actively misleading.

**Open items for whoever does Phase 6 (notifications) and Phase 7 (permissions/test users):** `NotificationDispatcher::actorsForStage()` still won't notify a manager arriving at `direct_manager_review` (it only ever collected `required_role_id` from outbound rows, and this stage's outbound rows are all manager-gated with `required_role_id=null`) — the plan's Phase 6 fix is unbuilt. R09/R10 have no `ScreenRolePermissionSeeder`/`PermissionSeeder` grants yet beyond what Phase 1 (already done) gave them for free via `'*'` screens — Phase 7 still needs the explicit `transactions.*`/`reports.view`/`audit.view` grants and the `TestUserSeeder` manager_id wiring the plan calls for, without which the golden path is only walkable through R08 overrides in manual testing.

---

### 2026-08-27 08:00 EET — Codex — User-permission flow charts created

Created `docs/user-permissions-flow.md` with matching bilingual Mermaid and SVG pairs for permission enforcement and role/workflow handoff in `docs/diagrams/`. Both SVGs were rendered with `@mermaid-js/mermaid-cli@11.16.0`, parsed as XML, and visually reviewed; the Markdown links to each source and image. The `docs/` tree is ignored by Git, so these documentation files remain local unless that ignore policy changes.

---

### 2026-08-27 07:50 EET — Codex — User-permission flow-chart implementation plan

Create a separate bilingual reference under `docs/` for the seeded user-permission model, without changing the existing generic access-control diagram or any application code. It will contain one readable Markdown overview and two Mermaid/SVG pairs: the permission-resolution/enforcement path and the role-by-role workflow handoff. The charts will document seeded defaults only, including multi-role permission union and R08's default full access, because the matrix remains administrator-configurable at runtime.

---

### 2026-08-25 19:45 EET — Codex — System-flow documentation created

Created `docs/system-flow.md` and three matching Mermaid source files under `docs/diagrams/` for access control, the transaction/meeting lifecycle, and supporting operations. The files are intentionally left in the existing ignored `docs/` tree alongside the redesign material, so they are available locally but will not appear in `git status`; the Markdown embeds were checked against each source file. A local Mermaid CLI is not configured, so SVG output was not generated in this session.

---

### 2026-08-25 19:45 EET — Codex — System-flow documentation plan

Create reusable system-flow documentation from the implemented Laravel and Vue logic, rather than from the staged plan alone. The scope is one Markdown overview plus standalone Mermaid source files for access control, transaction/meeting lifecycle, and support operations. Verify that all Mermaid sources are present, structurally valid, and that the documented lifecycle matches the seeded transition map and current controllers.

---

### 2026-08-25 22:00 EET — Codex — Stage 37 complete (meeting outputs follow-up)

Built the final Track H stage as planned: `GET /meetings/{meeting}/outputs` now projects each request agenda item into the source-design table (request/requester, decision/date, current next action, responsible role or execution department, and live transaction status) plus the items→decisions→advanced/waiting/execution/closed summary; `POST .../outputs/{agendaItem}/complete` is an R03/edit-gated, locked status-only `in_execution`→`completed_closed` move that preserves stage 11 and appends status history. The Stage 29 reconciliation is complete: stage 11's existing level-6 `approve` transition now hands work to `in_execution`, that status is excluded from further WorkflowService moves and the final-approval queue, `completed_closed` is terminal/complete across workflow, SLA, committee, and reporting predicates, and legacy `archived` data remains supported.

Frontend: `MeetingOutputsView.vue` replaces the last placeholder with the meeting picker, six summary cards, exact eight-column RTL table, transaction links, 30-second refresh, semantic status pills, and the permission-gated completion action; `MeetingDetailView.vue` links into it and Arabic/English keys remain at exact parity (743 each). Verification: new `MeetingOutputsTest` has 4 tests / 44 assertions; focused Stage 37 + WorkflowService run was 14 / 139; full suite is **148 tests / 903 assertions** green; Pint passes on every touched/new PHP file (repository-wide Pint still reports older untouched baseline drift), `npm run build` passes with its own lazy `MeetingOutputsView` chunk and tracked `frontend/dist` was restored afterward, `git diff --check` passes, both routes register, MySQL has no pending migrations, and `TransactionStatusSeeder`/`WorkflowTransitionSeeder` were re-run with the live stage-11 row verified as `in_execution`. **Not visually verified:** the in-app browser runtime exposed no available browser binding; the attempted local Vite smoke session was stopped and its PDF/build temporary files were removed.

---

### 2026-08-25 21:10 EET — Codex — Stage 37 implementation plan (meeting outputs follow-up)

Building Stage 37 as a live, meeting-scoped read model rather than a second decisions table: a new `MeetingOutputsController` plus dedicated API resources will load each request agenda item with its decision, requester (`transactions.created_by`), current workflow stage/status, next action, and responsible role/department, with meeting-level counts matching the design's items→decisions→advanced/waiting funnel. The read endpoint will use `meeting_outputs,view`; a locked `MeetingOutputService` status-only completion action will use `meeting_outputs,edit`, verify the agenda item belongs to the selected meeting and has a decided transaction, preserve `current_stage_id`, and write the `in_execution`→`completed_closed` status history.

The Stage 29 reconciliation is completed without a new table or parallel stage machine: Stage 11's existing final `approve` workflow transition will now set `in_execution` (while still writing its level-6 approval and stage log), and the outputs action alone closes execution as `completed_closed`; legacy `archived` remains recognized as terminal/report-complete data. All terminal/open-work predicates that currently name only `archived` will be audited so an in-execution item cannot re-enter the final-approval queue and a completed-closed item cannot be moved, cancelled, or flagged overdue.

Frontend scope is the real `MeetingOutputsView.vue`: meeting picker/query-link support, summary cards, the exact request/employee/decision/date/next-action/responsible-body/status table from the source design, links into each transaction, and an `edit`-gated complete-execution action; `MeetingDetailView.vue` gains a discoverability link and Arabic/English locale keys stay in parity. Verification will add focused feature coverage for the scoped live read model, workflow entry into execution, status-only close/history/guards, and view-vs-edit permissions; then run the full PHPUnit suite, Pint, locale-key parity, the Vite production build (reverting tracked `dist/` output afterward), the workflow/status reseed against MySQL, and `php artisan migrate` to confirm no pending migrations.

---

### 2026-08-25 20:55 EET — Claude — Stage 36 complete (minutes preparation & approval)

Built exactly per the plan below. Three migrations applied to the real MySQL/Homestead database: `meeting_minutes` (json `content`, `status`, generated/reviewed/approved bookkeeping), `meeting_minute_signatures` (one row per required signer), and a third that drops `meetings.minutes` — the free-text field's only reader, `MeetingDetailView.vue`'s textarea, is now a `meeting_minutes,view`-gated link into the new screen (showing the live `minutes_status` badge `MeetingResource` now exposes). `ScreenRolePermissionSeeder` gained `'approve' => ['R03']` on `meeting_minutes` and was reseeded against the real database.

`MeetingMinutesCompiler::compile()` produces the structured snapshot exactly as planned (meeting info, present/absent attendance with an actual-attendance quorum distinct from `MeetingReadinessService`'s pre-meeting expected one, and per-item vote tally/decision/discussion-notes — from the `Decision` snapshot columns once decided, live `votes` counts otherwise). `MeetingMinutesController` has the three lifecycle actions plus `show`/`signatureImage`; the vacuous-auto-approve path (zero attended attendees) and the auto-advance-on-last-signature path both work as designed and are covered by tests.

**One real fix beyond the plan, caught by Laravel's own behavior, not scope creep:** `generate()`'s `MeetingMinutes::updateOrCreate()` made the endpoint answer 201 on the very first call and 200 on every regenerate after — Laravel infers the JSON-resource status code from the underlying model's `wasRecentlyCreated` flag when a bare resource is returned. An idempotent-feeling "recompile the draft" action answering two different codes for the same request shape is worse API design than the one line it took to force 200 explicitly (`MeetingMinutesController.php`, `generate()`) — every other write endpoint in this codebase sets its status code on purpose rather than leaning on that implicit inference, so this brings `generate()` in line with house convention rather than introducing a new one.

`MeetingController::update()`'s close gate gained the second, independent check exactly as planned — no exemption for an empty-agenda meeting, it still needs `generate` → `review(approve)` (which itself vacuously auto-approves with zero signers). Both `MeetingLiveRunnerTest` closing tests were updated in place to weave the minutes flow in before their final `assertOk()`, per the plan's own note that this is a legitimate update to pre-existing assertions describing behavior this stage deliberately changes, not scope creep.

Frontend: real `MeetingMinutesView.vue` replacing the Stage 28 placeholder (meeting picker, generate/regenerate, the compiled content rendered read-only reusing `DECISION_OUTCOMES` for per-item tallies, a review panel, and a signatures panel with `SignaturePad` reused exactly like `AgendaItemDecisionPanel`, plus bearer-authenticated signature-image thumbnails using the same blob-fetch pattern `ApprovalTrail.vue` established). `MeetingLiveView.vue` also gained a small `meeting_minutes,view`-gated link next to its close button for discoverability. New `meetingsUnit.minutes.*` locale keys replacing the two placeholder strings in both `ar.json`/`en.json`; key parity verified by diffing flattened key sets (0 keys only-in-one-side, matching every prior stage's check).

Verification: new `tests/Feature/MeetingMinutesTest.php` (7 tests — generate compiles the expected content shape and is blocked once status leaves draft; `changes_requested` requires a comment and leaves status/content in place; `approve` creates one signature row per attended attendee and moves to `pending_signatures`; zero attended attendees auto-approves on review; signing rejects a non-signer and a double-sign, and the last signature flips status to `approved` + stamps `approved_at`; the close gate 422s until minutes are approved, then succeeds; R04 can generate/sign but gets 403 on review), plus the two updated `MeetingLiveRunnerTest` tests, full suite **144 tests / 859 assertions** green (was 137/806), Pint clean on every touched/new PHP file, `npm run build` passes with `MeetingMinutesView` as its own lazy chunk (then reverted `frontend/dist`, tracked in git, per every prior stage's note), and all three migrations plus the `ScreenRolePermissionSeeder` reseed ran clean against the real MySQL/Homestead database. **Not verified: no browser this session** — the minutes screen's layout is calculated from `AgendaItemDecisionPanel.vue`/`ApprovalTrail.vue`'s existing patterns, not observed, consistent with every prior UI-touching note.

**Open item for whoever builds Stage 37:** `meeting_minutes.content` is a point-in-time JSON snapshot, not a live view — a decision recorded *after* minutes were generated won't appear until someone regenerates (blocked once review starts, so in practice: request changes, regenerate, re-review). That's the intended "frozen once reviewed" behavior, not a bug, but worth knowing before Stage 37's outputs tracker decides whether to read from `Decision`/`Vote` directly (live) or from a meeting's approved minutes snapshot (frozen) — they are not guaranteed to agree if anything changed in between.

---

### 2026-08-25 20:10 EET — Claude — Stage 36 implementation plan (minutes preparation & approval)

Building Stage 36 per STAGE_PLAN.md: auto-compiled minutes for a meeting plus a draft→head-review→member-signatures→approved lifecycle that gates meeting close, replacing the single free-text `meetings.minutes` field Stage 30 added.

**Two new tables, one per concern.** `meeting_minutes` (one row per meeting, unique `meeting_id`): `content` (json — a structured snapshot, not free text, see below), `status` (`draft|pending_signatures|approved`), `generated_by_user_id`/`generated_at`, `reviewed_by_user_id`/`reviewed_at`/`review_comment`, `approved_at`. `meeting_minute_signatures` (one row per required signer): `meeting_minutes_id`, `user_id`, `signature_path`, `signed_at`, unique per minutes+user. A third migration **drops `meetings.minutes`** (column, model fillable, `MeetingResource` field, `UpdateMeetingRequest` rule) — this is the literal "the single `minutes` text field → a generated, reviewed, signed, approved document" mechanism line from STAGE_PLAN, not an additive stage; the only consumer was `MeetingDetailView.vue`'s free-text textarea, which is replaced by a link into the new dedicated screen (same pattern as the Stage 31 "open agenda builder" link).

**Content is compiled JSON, not a text blob.** New `App\Services\MeetingMinutesCompiler::compile(Meeting $meeting): array` reads meeting fields, the attendee roster split into present/absent (`attended` flag) with an actual-attendance quorum (`ceil(active_members/2)` vs. present active members — deliberately different from `MeetingReadinessService`'s pre-meeting *expected* quorum off `invitation_status`, since this is a post-meeting record of who actually showed up), and every agenda item with its vote tally (from the `Decision` snapshot columns if decided, live `votes` counts otherwise), its decision outcome/comment, and its discussion notes. Storing structured data instead of prose is what lets the screen render it generically and keeps the same source of truth `AgendaItemDecisionPanel`/`DecisionResource` already established rather than re-deriving a text description.

**Lifecycle, three actions on `App\Http\Controllers\Api\MeetingMinutesController`, gated by three of the `meeting_minutes` screen's own action tiers** (`ScreenRolePermissionSeeder` gains `'approve' => ['R03']` on that screen, mirroring `decisions`' `add`/`approve` split — `add` stays `[R03,R04]` and now also covers casting a signature, the same "add" tier `decisions,add` uses for voting):
- `generate` (`meeting_minutes,add`) — (re)compiles content, `updateOrCreate`s the row, resets to `status=draft` and clears any prior `reviewed_*`/`review_comment`. Blocked (422) once `status !== draft` — once review has moved it past draft, only the reviewer can send it back (there's no separate "send back" action; the reviewer's `changes_requested` outcome just leaves it at `draft`, which is what re-opens `generate`).
- `review` (`meeting_minutes,approve`) — `{decision: approve|changes_requested, comment?}`. `changes_requested` requires a comment, stores it, leaves `status=draft` (reviewed_by/at stay null, so "never reviewed" and "sent back with feedback" are both `status=draft`, distinguished by whether `review_comment` is set). `approve` stamps `reviewed_by`/`reviewed_at`, moves to `pending_signatures`, and creates one `MeetingMinuteSignature` row per attendee with `attended=true` — **if there are none (a meeting nobody marked attended), it skips straight to `approved`** rather than getting stuck waiting for signers that don't exist, the same "vacuous" pattern the readiness/close gates already use for an empty agenda.
- `sign` (`meeting_minutes,add`) — one attendee's own signature row only (404 if the caller isn't an assigned signer, 422 if already signed or minutes aren't `pending_signatures` yet); stores the PNG via a new `ApprovalSignatureStorage::storeForMeetingMinutes()` sibling method (same disk, `meeting-minutes/{id}` prefix). Once every signature row has a `signed_at`, auto-advances to `approved` and stamps `approved_at` — no separate "finalize" click, mirroring how `DecisionController::record()` needs no separate confirm after the last input arrives.

**The close gate.** `MeetingController::update()` already blocks `status=completed` on an unresolved agenda item (Stage 34); this stage adds a second check right after it — `meetingMinutes === null || status !== 'approved'` → 422 with its own message. No exemption for an empty-agenda meeting: it still needs `generate` → `review(approve)` (which will itself vacuously auto-approve if nobody was marked attended) before it can close. `MeetingLiveRunnerTest`'s two tests that close a meeting via the real endpoint (`..._blocked_with_an_unresolved_item_then_succeeds`, `..._with_no_agenda_items_closes_freely`) now need the minutes flow woven in before their final `assertOk()` — legitimate updates to pre-existing tests whose assertions describe behavior this stage is deliberately changing, not scope creep (same category as Stage 35's `DecisionRegisterTest` update). The two other files matching `'completed'` (`MeetingReadinessTest`, `CommitteeCandidatesDashboardTest`) call `Meeting::update()` directly, bypassing the controller entirely — unaffected.

**Signature image read**: new `MeetingMinutesController::signatureImage()`, same shape as `ApprovalSignatureController` (`abort_unless` the signature belongs to this meeting's minutes, stream from the private disk), named route + `meeting_minutes,view` gate.

**Notification**: one new event, `minutes_approved` (added to `NotificationSetting::EVENT_TYPES`, in_app+email like `decision_recorded`), fired from `sign()` only on the transition into `approved`, recipients = the meeting's committee members (same shape as `NotificationDispatcher::decisionRecorded`). Skipped for `generate`/`review` — those are same-session, in-screen feedback loops between two people who are already looking at the screen together, not the kind of "tell people who aren't here" event the other five types exist for.

**Frontend**: real `MeetingMinutesView.vue` replacing the Stage 28 placeholder — meeting picker (same pattern as readiness/live/agenda-builder), a generate/regenerate button, the compiled content rendered read-only (meeting info, attendance+quorum, per-item vote tally reusing `DECISION_OUTCOMES` from `lib/decisionOutcomes.js`, decision outcome, discussion notes), a review panel (approve / request-changes-with-reason) shown only while `status=draft` and content exists, and a signatures panel (list of signers with signed/pending state, a `SignaturePad` for the signed-in user's own row while `pending_signatures`) reusing `SignaturePad.vue` exactly like `AgendaItemDecisionPanel` does. `MeetingDetailView.vue` loses its minutes textarea card (and `minutesValue`/`saveMinutes` wiring) in favor of a `meeting_minutes,view`-gated link to the new screen, matching the existing agenda-builder link. New `meetingsUnit.minutes.*` locale keys replacing the two placeholder strings in both `ar.json`/`en.json`; `meetings.minutes`/`meetings.saveMinutes` keys removed (their only consumer is gone).

**Verification plan**: new `tests/Feature/MeetingMinutesTest.php` — generate compiles the expected shape and is blocked once status moves past draft; review's `changes_requested` requires a comment and leaves status/content alone; review's `approve` creates one signature row per attended attendee and moves to `pending_signatures`; a meeting with zero attended attendees auto-approves on review; signing rejects a non-signer and a double-sign, and the last signature flips status to `approved` + stamps `approved_at`; the meeting-close gate 422s until minutes are `approved`, then succeeds; R04 can generate/sign but gets 403 on review — plus the two updated `MeetingLiveRunnerTest` closing tests, the full suite, Pint, `npm run build`, and `php artisan migrate` against the real MySQL/Homestead database.

---

### 2026-08-25 19:45 EET — Claude — Stage 35 complete (decision templates & richer outcomes)

Built exactly per the plan below. Two migrations applied to the real MySQL/Homestead database: `templates` gains nullable `category`; `votes.vote`/`decisions.outcome` widen `string(20)→string(30)` (plain `->change()`, no doctrine/dbal, per the Stage 31 precedent), and `decisions` gains `template_id` (FK, nullOnDelete) plus three more `votes_*_count` columns. Re-ran `TransactionStatusSeeder`/`WorkflowTransitionSeeder` against the real database afterward — confirmed 23 statuses / 40 transitions, with the three new stage-7 rows (`conditional_approve` 7→8, `request_legal_opinion`/`refer_to_another_body` both 7→7, all `requires_comment=true`) present via tinker.

`DecisionController::ACTIONS` grew from 3 to 6 entries; the tally/vote/register/export code was already written generically enough (`array_keys(self::ACTIONS)`, a `summaryPairs()` loop) that only the explicit `votes_*_count` list in `Decision::create()` and the LABELS arrays needed hand-editing. `WorkflowService` needed **zero changes** — the judgment call recorded below (none of the three new outcomes touch signatures or the Approval ledger) held up and kept this stage from touching the workflow engine at all, which is exactly what made it safe to build without re-deriving that service's invariants.

**One real bug caught by the new tests, not scope creep:** the first draft of `DecisionController::record()` read `$request->validated('template_id')` from inside the `DB::transaction()` closure, but `$request` was never in that closure's `use (...)` list (only `$comment`/`$actor`/etc. were) — a 500 on every decision carrying a template, caught immediately by `test_a_decision_records_and_returns_the_template_it_was_drafted_from`. Fixed by computing `$templateId` once outside the closure like the other captured values, the same pattern the method already used.

Frontend: the real deliverable here was the refactor Stage 34's own note called for — `AgendaItemDecisionPanel.vue` now owns the vote/tally/record-decision block for one agenda item (props `meetingId`/`item`/`templates`, emits `refresh`), and both `MeetingDetailView.vue` and `MeetingLiveView.vue` dropped their identical copies of that markup plus all its state (votingBusy, decisionComments, signaturePads Map, tally/predictedOutcome helpers) in favor of one line each. A new `lib/decisionOutcomes.js` exports the 6-key list once, consumed by the panel and by `DecisionsView.vue` (whose pending-tab tally/vote-buttons and register-tab tally column both went from hardcoded 3-key objects to a generic loop). `TemplatesView.vue` gained a `category` field (general/`decision`) so an admin can actually author templates the new screens will list — until one exists, `/decisions/filters`' `templates` array is simply empty, which is correct, not a bug.

Verification: new `tests/Feature/DecisionOutcomeTemplateTest.php` (6 tests — conditional approval reaches stage 8 with no `Approval` row; the two self-loops require a comment and land at their own statuses; a decision records and echoes back its `template_id`; an inactive template is rejected; `/decisions/filters` lists only active `category=decision` templates), plus one **pre-existing** test updated (`DecisionRegisterTest`'s filters assertion, which hardcoded the old 3-outcome array — a legitimate update, not a regression). Full suite **137 tests / 806 assertions** green, Pint clean on every touched/new PHP file, `npm run build` passes with `AgendaItemDecisionPanel` and `decisionOutcomes` as their own chunks (then reverted `frontend/dist`, tracked in git, per every prior stage's note), and both migrations + reseeds ran clean against the real database. Key-parity between `ar.json`/`en.json` verified by diffing their flattened key sets (0 keys only-in-one-side). **Not verified: no browser this session** — the template picker's layout inside the shared panel is calculated from the pre-existing record-decision markup, not observed, consistent with every prior UI-touching note.

**Open item for whoever builds Stage 36+:** decision templates are entirely inert until an R08 admin creates one with `category=decision` on the Templates screen — nothing seeds one automatically (the user's earlier "plain DB-backed, no starter content" call from Stage 27 applies here too, by the same reasoning). `meeting_minutes` (Stage 36) will likely want its own `Template::CATEGORY_*` constant and its own `/meeting-minutes/... ` filters-style endpoint rather than reusing `/decisions/filters`, following the same "the screen everyone can view, not the R08-only `/templates` CRUD" reasoning this stage used.

---

### 2026-08-25 19:10 EET — Claude — Stage 35 implementation plan (decision templates & richer outcomes)

Building Stage 35 per STAGE_PLAN.md: reuse `Template` for decision text, add three new committee-decision outcomes (conditional approval, request-legal-opinion, refer-to-another-body), and factor the vote/decision markup that Stage 34's own note flagged as duplicated ("worth factoring out into one `AgendaItemDecisionPanel.vue`... rather than maintaining two copies a third time") into exactly that shared component.

**Outcomes ride the same mechanism Stage 21 already built — six vote values instead of three, each still mapping onto one `workflow_transitions` row at stage 7.** `DecisionController::ACTIONS` grows from 3 to 6 entries: `conditional_approval → conditional_approve` (a new **forward** exception, `[7→8, R03, approved_with_conditions]`, mirroring `approve`'s destination but as an exception row so it renders with the secondary/warning button style `defer`/`cancel` already use), `legal_opinion → request_legal_opinion` and `refer_other_body → refer_to_another_body` (both new **self-loop** exceptions at stage 7, mirroring `defer`'s shape exactly — `[7→7, R03, <new status>, requires_comment]`). Three new `TransactionStatusSeeder` rows: `approved_with_conditions`, `legal_opinion_requested`, `referred_to_other_body`. `votes.vote` and `decisions.outcome` both grow from `string(20)` to `string(30)` via a plain `->change()` (Laravel 11 needs no doctrine/dbal for this, confirmed by the Stage 31 note) since `conditional_approval` doesn't fit in 20.

**Judgment call, flagged explicitly per house convention: none of the three new outcomes require a signature or write an `Approval` ledger row, even though `conditional_approve` moves the transaction forward to stage 8.** Reason: `TransactionDetailView.vue`'s exception-action modal (the generic per-transaction workflow screen every stage-7 `WorkflowTransition` row is *also* exposed through, per the existing `defer`/`cancel` precedent) only ever collects a reason, never a signature — only its *normal*-action path renders `SignaturePad`, gated on `action === 'approve'` specifically. Making `conditional_approve` demand a signature (the way a literal "still an approval" reading might suggest) would 422 with no way to attach one from that screen, a dead-end that isn't worth introducing for a sub-case the design doc doesn't explicitly call a signed approval. So `WorkflowService::approvalLevel()` is **unchanged** — all three new actions stay comment-only exceptions, same treatment as `defer`/`return_to_study`, and `DecisionController::record()`'s existing `$action === 'approve'` signature gate needs no widening either.

**Templates gain a nullable `category` column** (free string, not an enum — `Template` has exactly one real consumer today and may gain others later) so `DecisionController::filters()` can add a `templates` key (`Template::where('is_active', true)->where('category', 'decision')->get()`) to the payload every one of the three decision-recording screens already fetches from `/decisions/filters` (`decisions,view` is seeded `'*'` — the one endpoint every committee member, not just R08, can actually call; the CRUD `/templates` list is `'templates' => []`, R08-only, so it cannot be reused for this). `decisions` gains `template_id` (nullable FK, nullOnDelete) plus three more `votes_*_count` columns, all following the existing three columns' exact shape — no JSON blob, explicit columns stay the house style. `StoreDecisionRequest` accepts an optional `template_id` (validated active+exists); the client fills the comment textarea from the chosen template's localized body as a starting point the user can still edit, nothing is enforced server-side about what the comment contains.

**Frontend refactor**: new `frontend/src/components/AgendaItemDecisionPanel.vue` owns tally/vote/record-decision/signature-pad/template-picker for one agenda item (props: `meetingId`, `item`, `templates`; emits `refresh`), replacing the identical block duplicated in `MeetingDetailView.vue` and `MeetingLiveView.vue`. A small `frontend/src/lib/decisionOutcomes.js` exports the 6-key outcome list once, imported by the new component and by `DecisionsView.vue` (whose pending-tab vote buttons and register-tab tally column both grow from 3 to 6 keys, generically). `TemplatesView.vue` gains a `category` field (free select: general / `decision`) so an admin can author the templates this stage's screens will list. New `workflow.actions.{conditional_approve,request_legal_opinion,refer_to_another_body}` locale keys, matching the existing `defer`/`return_to_study` pattern, since these three also surface for free as exception buttons on the plain transaction-detail page.

**Verification plan**: new `tests/Feature/DecisionOutcomeTemplateTest.php` — each of the 3 new outcomes tallies correctly and drives its configured transition (`conditional_approve` lands at stage 8 with `approved_with_conditions`; the two self-loops stay at stage 7 with their own status); a decision records its `template_id` when supplied and rejects an inactive/nonexistent one; `/decisions/filters` returns only active `category=decision` templates; the vote endpoint accepts all 6 outcome values — plus the full PHPUnit suite, Pint on touched/new files, `npm run build`, and `php artisan migrate` against the real MySQL/Homestead database.

---

### 2026-08-25 15:10 EET — Claude — Stage 34 complete (live meeting runner)

Built exactly per the plan below. Two migrations (`item_state`/`state_changed_at` on `meeting_transactions`; new `meeting_discussion_notes`, shaped after `notes`), both applied to the real MySQL/Homestead database. `MeetingTransaction::isResolved()` (`item_state === 'complete' OR a decision exists`) is the one predicate shared by `MeetingTransactionResource`'s `is_resolved` field and `MeetingController::update()`'s new close-gate — closing a meeting to `status: completed` now 422s while any agenda item is unresolved, an empty agenda closes vacuously. `DecisionController::record()` stamps `item_state: complete` on the agenda item inside the same transaction as `Decision::create()`, so a request item's completion always traces back to a real recorded decision — the new `updateItemState()` endpoint (`PATCH meetings/{meeting}/agenda/{agendaItem}/state`, `meeting_live,edit`) explicitly refuses to set `complete` on an `employee_request` item directly, and refuses any state change at all once an item already has a decision. New `MeetingDiscussionNoteController` (`index`/`add`, `meeting_live,view`/`add`) is a plain append-only feed, no seeder changes — `meeting_live`'s Stage 28 grants already fit (`view=*`, `add=[R03,R04]`, `edit=[R03]`).

**One real bug this stage exposed and fixed, not scope creep:** `MeetingDetailView.vue`'s pre-existing status `<select>` already PUTs `status` through this same `update()` method, and until now no status change could ever fail — so its error handler never reset the dropdown's local `statusValue` back to the server's actual value on a rejected save. Now that closing can genuinely 422, that gap was live; fixed with one line in `saveMeetingFields()`'s catch block. Also added a small link from the meeting-detail agenda card to the new runner screen, alongside the existing agenda-builder link.

Frontend: real `MeetingLiveView.vue` replacing the Stage 28 placeholder — meeting picker, a not-convened banner linking to the Stage 33 readiness screen, a static (literally, not theme-token) video placeholder matching the SignaturePad/ApprovalTrail "fixed-look" precedent, a present-attendees list and clickable agenda sidebar (both reading data Stage 20 already provides — no new endpoints needed for either), a current-item panel with a `state_changed_at`-driven timer, chair-only state-advance buttons, and the vote/tally/record-decision block **lifted verbatim from `MeetingDetailView.vue`'s Stage 21 markup** (same endpoints, same `decisions.*` locale keys) rather than reinvented. Polls `GET /meetings/{id}` every 5s while mounted — the same read endpoint every other meeting screen already calls, so every attendee's runner view stays in sync with no new read endpoint and no socket. New `meetingsUnit.live.*` locale keys replacing the two placeholder strings, verified key-parity between `ar.json`/`en.json` by diffing their flattened key sets.

Verification: new `tests/Feature/MeetingLiveRunnerTest.php` (8 tests — state transitions persist and stamp `state_changed_at`; a request item rejects manual `complete`; an admin item reaches `complete` only through the manual endpoint; recording a decision auto-completes its item and then refuses further state changes; closing 422s with an unresolved admin item, again with an undecided request item, then succeeds once both are resolved; an empty agenda closes freely; discussion notes list/create scoped to the right agenda item; R04 gets 403 advancing state but can still post a note), full suite **131 tests / 771 assertions** green (was 123/738), Pint clean on every touched/new PHP file, `npm run build` passes with `MeetingLiveView` as its own lazy chunk (then reverted `frontend/dist` — tracked in git, per every prior stage's note), and `php artisan migrate --force` ran clean against the real MySQL/Homestead database. **Not verified: no browser this session** — the runner's layout (sidebar agenda list, current-item panel, timer, discussion feed) is calculated from `MeetingDetailView.vue`/`MeetingReadinessView.vue`'s existing patterns, not observed, consistent with every prior UI-touching note.

**Open items for whoever builds Stage 35+:** the timer is elapsed-since-`state_changed_at` only — there's no overrun warning against `estimated_minutes`, which STAGE_PLAN didn't ask for but a later polish pass might want. The runner has no explicit "start meeting" action beyond convening (Stage 33) — the first `item_state` advance is what actually begins the record. Stage 35's decision templates/richer outcomes will need to extend both the record-decision block here and the one in `MeetingDetailView.vue`, since this stage deliberately duplicated that markup rather than extracting a shared component — worth factoring out into one `AgendaItemDecisionPanel.vue` at that point rather than maintaining two copies a third time.

---

### 2026-08-25 14:30 EET — Claude — Stage 34 implementation plan (live meeting runner)

Building Stage 34 per STAGE_PLAN.md: a state-driven item-by-item runner that reuses Stage 21's vote/decision endpoints, plus a discussion-notes feed, and a real gate stopping a meeting from closing with unresolved agenda items.

**Runner state, on the existing `meeting_transactions` row, not a new table.** New migration adds `item_state` (string, default `presented`, values `presented|discussion|voting|deciding|complete`) and `state_changed_at` (nullable timestamp, stamped whenever `item_state` actually changes). `state_changed_at` is what the timer panel reads — elapsed = now − state_changed_at — so a polling client (any attendee's browser, not just the chair's) can render the same timer without a websocket, which is exactly what "polling" was already doing for votes.

**"Complete or decided" is one predicate, not two paths that can drift.** New `MeetingTransaction::isResolved(): bool` = `item_state === 'complete' OR a decision exists`. For an `employee_request` item, `item_state` can only reach `complete` as a side effect of `DecisionController::record()` (added there, inside the existing DB transaction, right after `Decision::create()`) — the new item-state endpoint explicitly refuses to set `complete` on a request item directly, so "complete" for a request item always means a real recorded decision, never a manual shortcut around the vote. Administrative/emerging items have no decision to record, so for those `complete` is only ever reached through the manual endpoint — this is the one case Stage 31 created (non-transaction agenda items) that the vote/decision machinery cannot resolve on its own.

**The close gate lives in `MeetingController::update()`, not a new endpoint.** The existing status `<select>` on `MeetingDetailView.vue` already PUTs `status` through this same method — reusing it means the gate protects both the (new) runner screen and the pre-existing detail-page dropdown from the same one code path, rather than adding a second "close meeting" action that the old dropdown could still bypass. When the validated payload sets `status: 'completed'`, the method loads the agenda (`with('decision')`, avoiding N+1) and rejects with 422 if any item's `isResolved()` is false. An empty agenda closes vacuously (nothing to block on). This does mean `MeetingDetailView`'s status dropdown can now genuinely 422 for the first time since Stage 20 — its `saveMeetingFields()` catch block didn't reset `statusValue` back to the server's actual value on failure (never observable before, since no status change could fail); fixing that one line is in scope here since this stage is what exposes the gap.

**New discussion-notes feed**, mirroring the existing `notes` table's shape exactly (`transaction_id` → `meeting_transaction_id`, same `created_by_user_id` nullOnDelete, no `is_internal` — a meeting's discussion feed has no public/private split). New `App\Models\MeetingDiscussionNote` + `App\Http\Controllers\Api\MeetingDiscussionNoteController` (`index`/`store`, its own controller per the "one controller per resource" convention rather than growing `MeetingController` further) + `MeetingDiscussionNoteResource`. Gated by the already-seeded `meeting_live` screen (`view`/`add` — no separate note-editing or deletion, an append-only feed matching `votes`' "who said what" spirit rather than `notes`' editable-body one).

**New item-state endpoint** `PATCH meetings/{meeting}/agenda/{agendaItem}/state`, gated `meeting_live,edit` (R03 chair only, matching `meetings,edit`'s scope) — the chair is the one driving `presented→discussion→voting→deciding` (no forced ordering; the chair can move an item back a step, e.g. to reopen discussion after a failed vote). Once an item is resolved (has a decision), the endpoint refuses any further state change on it — mirrors `DecisionEligibility`'s "voting closes once decided" rule, applied here to the runner's own state instead of votes.

**Frontend**: real `MeetingLiveView.vue` replacing the Stage 28 placeholder — meeting picker (same pattern as the readiness/agenda-builder screens), a banner blocking the runner controls until the meeting is convened (ties Stage 33's gate into this screen rather than duplicating a second one), a static video-placeholder panel, a present-attendees list (reusing `meeting.attendees` — no new endpoint, `attended=true` already exists from Stage 20), a progress readout (resolved/total), a current-item panel defaulting to the first unresolved item in agenda order but clickable to jump to any item, per-item state-advance buttons (chair only), the discussion feed + add-note box, and the vote/tally/record-decision block **lifted from `MeetingDetailView.vue`'s existing Stage 21 markup** (same `castVote`/`recordDecision`/`SignaturePad` logic, same `decisions.*` locale keys) rather than reinvented, per the stage's own "reuse the existing vote/decision endpoints" instruction. Polls `GET /meetings/{id}` every 5s while mounted (same endpoint the detail page already calls — no new read endpoint needed) so every attendee's runner view — timer, votes, notes, item state — stays in sync without a socket. New `meetingsUnit.live.*` locale keys replacing the two placeholder strings.

**Verification plan**: new `tests/Feature/MeetingLiveRunnerTest.php` — item-state transitions persist and stamp `state_changed_at`; a request item rejects a manual `complete`; recording a decision auto-completes its item; closing a meeting 422s with an unresolved admin item and again with an undecided request item, then succeeds once every item is resolved; discussion notes list/create scoped to the right agenda item; `meeting_live,view` can read but `add`/`edit` are refused for a role without them — plus the full PHPUnit suite, Pint on touched/new files, `npm run build`, and `php artisan migrate --force` against the real MySQL/Homestead database.

---

### 2026-08-25 13:55 EET — Claude — Stage 33 complete (meeting readiness control center)

Built exactly per the plan below. One migration (`convened_at`, `convened_by_user_id`, `readiness_override_reason` on `meetings`), `Meeting::convenedBy()`, `MeetingReadinessService::compute()` (the four percentages + quorum + exceptions list, per the definitions the plan below records), `ConveneMeetingRequest` (shape-only — the "reason required" check depends on the computed verdict, so it lives in the controller), and `MeetingReadinessController@show/@convene`, both riding `meeting_readiness`'s already-seeded grants (`view=*`, `edit=[R03]` — the edit gate alone satisfies "R03 exceptional override," no extra role check needed). `MeetingsDashboardMetrics::nextMeeting()` now injects the same service and reports `{ready, exceptions_count}` instead of its old ad-hoc `attendees_confirmed`/`attendees_total` pair, closing the Stage 32 note's own open item.

Frontend: real `MeetingReadinessView.vue` replacing the Stage 28 placeholder (meeting picker, a CSS conic-gradient donut for the overall verdict — no chart dependency, matching house precedent — the 4 percentage bars, quorum, the exceptions list rendered through per-code i18n messages with interpolated counts, and a convene button whose reason prompt is required only when not ready). `MeetingsDashboardView.vue`'s next-meeting card swapped its raw confirmed/total counts for a ready/not-ready pill plus a "check readiness" link into the new screen. New `meetingsUnit.readiness.*` locale keys replacing the placeholder pair, and `meetingsUnit.dashboard.nextMeeting.ready/notReady/checkReadiness` added, in both `ar.json`/`en.json`.

**One judgment call worth flagging beyond what's in the plan below:** `agenda_percentage` is defined as 0% (not the vacuous 100%) when the agenda is empty, since an empty agenda is itself always an `empty_agenda` exception regardless of percentage — the other three percentages (file/member/invitation) are vacuously 100% when there's nothing of that kind to check, but agenda emptiness needed its own always-fires exception rather than hiding behind a percentage.

Verification: new `tests/Feature/MeetingReadinessTest.php` (12 tests — a fully-prepared meeting reports zero exceptions and `ready:true`; each of the six exception codes fires in isolation against a hand-built fixture; convene succeeds without a reason when ready and stamps `convened_at`/`convened_by_user_id`; convene 422s without a reason when not ready then succeeds with one, storing it in `readiness_override_reason`; convene 422s once the meeting is no longer `scheduled`; R04 can view readiness but gets 403 on convene; the dashboard's `next_meeting.readiness` matches a direct call to the dedicated endpoint), full suite **123 tests / 738 assertions** green (was 111/695), Pint clean on every touched/new PHP file (the `--test` failures are the same pre-existing repo-wide drift every prior note flags, untouched files), `npm run build` passes with `MeetingReadinessView` as its own lazy chunk (then reverted `frontend/dist`, tracked in git, per every prior stage's note), and `php artisan migrate --force` ran clean against the real MySQL/Homestead database. Smoke-tested both new endpoints and the updated dashboard endpoint over real HTTP against Homestead as the seeded R03 test user (token minted and revoked within the same session) — readiness and dashboard both returned the expected shapes against the one real meeting row in that database (`ready:false`, three exceptions, matching tinker's direct `MeetingReadinessService::compute()` call). **Not verified: no browser this session** — the readiness screen's donut/bars/modal layout is calculated from `MeetingsDashboardView`/`CommitteeCandidatesView`'s existing patterns, not observed, consistent with every prior UI-touching note.

**Open item for whoever builds Stage 34+:** `convened_at` is a pure flag today — nothing reads it yet. The live runner (Stage 34) is the natural place to gate on it (a chair shouldn't be able to run agenda items on a meeting nobody convened), and per this stage's own "Mechanism" line ("nothing today → a pre-meeting gate") that gating was always meant to land there, not here. `MeetingDetailView.vue` was deliberately left untouched — STAGE_PLAN's Build bullets name only the dedicated readiness screen; a donut there too (the mockups show one) is unclaimed work for whoever next revisits that screen.

---

### 2026-08-25 13:15 EET — Claude — Stage 33 implementation plan (meeting readiness control center)

Building Stage 33 per STAGE_PLAN.md: `GET /meetings/{id}/readiness` (file %, member %, agenda completeness %, invitations %, expected quorum, exceptions-only list, ready/not-ready verdict) and a screen that gates "convene" on it, with an R03 override.

**The four percentages and quorum are not defined anywhere in the design prose beyond their names, so this is the judgment call being recorded before writing code.** One `App\Services\MeetingReadinessService::compute(Meeting $meeting): array` owns all of it, reused by both the readiness endpoint and the convene action (so the gate and the display can never disagree) and by `MeetingsDashboardMetrics::nextMeeting()` (closing the "wire the dashboard's next-meeting card to read the same computation" open item from the Stage 32 note, instead of duplicating percentage math there). Definitions: **file %** = share of `employee_request` agenda items whose transaction has ≥1 attachment (100% vacuously if there are no request items — nothing to check). **member %** = share of the committee's *current* active members who are actually on this meeting's attendee list — this is a roster-drift check (membership can change after a meeting is scheduled per `MeetingController::store()`'s own docblock), not attendance. **agenda %** = share of agenda items that have both `priority` and `estimated_minutes` set (both nullable at creation per Stage 31) — "completeness" here means fully briefed for a timed meeting, not merely present. **invitations %** = share of attendees whose `invitation_status` is `confirmed` or `declined` (an actual answer) rather than `pending`/`no_response`. **Quorum**: required = `ceil(active_members / 2)` (simple majority — no per-committee quorum field exists to read instead), met = count of `confirmed` attendees who are also current active committee members ≥ required. **Exceptions list**: one entry per unmet condition (`empty_agenda`, `quorum_not_met`, `missing_files`, `unconfirmed_members`, `incomplete_agenda_items`, `pending_invitations`), and **ready := the exceptions list is empty** — no separate blocking/non-blocking split, matching the plan's "an incomplete file blocks convening until resolved or overridden" done-when literally: every exception is a convening blocker.

**Convene mechanism**: new nullable `meetings` columns `convened_at`, `convened_by_user_id` (FK users, nullOnDelete), `readiness_override_reason`. `POST /meetings/{id}/convene` (behind `meeting_readiness,edit` = R03-only per the already-seeded matrix — this alone satisfies "R03 exceptional override," no extra role check needed in the controller) computes readiness; if ready, stamps `convened_at`/`convened_by_user_id` with no reason required; if not ready, requires a non-empty `reason` (422 via `ValidationException::withMessages` otherwise) and stores it in `readiness_override_reason`. Blocked (422) if the meeting isn't `status=scheduled` (already convened/completed/cancelled). No new meeting status yet — `convened_at` is a forward-compatible flag Stage 34's live runner can gate on ("nothing today → a pre-meeting gate" is this stage's own Mechanism line); Meeting is already in `AuditLog::AUDITED_MODELS`, so the convene write is auto-logged for free.

**Backend**: migration; `Meeting` gains the 3 fields + `convenedBy()` relation; `MeetingReadinessService`; `App\Http\Requests\Meeting\ConveneMeetingRequest` (validates `reason` as nullable string only — the "required if not ready" check depends on server-computed readiness, so it lives in the controller, not the FormRequest); new `MeetingReadinessController@show`/`@convene`; `MeetingResource` exposes the 3 new fields; `MeetingsDashboardMetrics::nextMeeting()` swaps its ad-hoc `attendees_confirmed`/`attendees_total` pair for the shared service's `ready`/exception-count so the dashboard can't disagree with the dedicated screen. Routes: `GET`/`POST meetings/{meeting}/readiness|convene`, both after the `meetings/{meeting}` wildcard (no literal-path ordering hazard — they're deeper paths, not siblings of it). No `ScreenRolePermissionSeeder` change — `meeting_readiness` already has `view=*, edit=[R03]` from Stage 28.

**Frontend**: real `MeetingReadinessView.vue` replacing the Stage 28 placeholder (meeting picker, same pattern as `MeetingAgendaBuilderView`; a readiness panel with a CSS conic-gradient donut for the overall ready/not-ready verdict — matching the mockups' "مستوى الجاهزية donut" without a chart dependency, per house precedent; the 4 percentage bars; quorum required/met; the exceptions list; a convene button opening a reason prompt only when not ready, reusing `CommitteeCandidatesView`'s modal pattern). `MeetingsDashboardView.vue`'s next-meeting card gets a small readiness badge (ready/not-ready + exception count) sourced from the updated `nextMeeting()` payload — the mockups show the donut on both the dashboard and meeting-detail screens, but a full breakdown on the dashboard tile would duplicate the dedicated screen; a badge linking to it is enough. `MeetingDetailView.vue` is left alone this stage (not named in STAGE_PLAN's Build bullets); its own donut, if wanted, is line-item work for whoever revisits that screen. New `meetingsUnit.readiness.*` locale keys replacing the placeholder pair in both `ar.json`/`en.json`.

**Verification**: new `tests/Feature/MeetingReadinessTest.php` — each exception fires independently against a hand-built fixture (empty agenda, missing file, quorum not met, a member missing from the attendee list, an agenda item missing priority/time, a pending RSVP), a fully-ready meeting reports zero exceptions, convene succeeds without a reason when ready, convene 422s without a reason when not ready and succeeds with one (stamping all 3 columns), convene 422s on an already-convened/completed meeting, R04 can view readiness but gets 403 on convene (view vs edit split), and the dashboard's `next_meeting.readiness` matches a direct call to the service — plus the full PHPUnit suite, Pint on touched/new files, `npm run build`, and `php artisan migrate --force` against the real MySQL database.

---

### 2026-08-25 12:45 EET — Claude — Stage 32 complete (candidate-requests worklist + command dashboard)

Built per the plan below, exactly as mapped: `CommitteeStatusService` gained `CANDIDATE_STATUSES` (`ready`/`in_meeting`/`nominated_for_committee`) and a `candidatesQuery()` reused by both the worklist and the dashboard, plus `require_completion`'s `from` list widened to also accept the three candidate statuses (not only mid-discussion). `WorkflowTransitionSeeder` gained the new `7→4 return_to_study` exception (R03, sets `returned`, requires a comment) — it rides the same generic table `defer` already does, so it also appears for free as an exception button on the existing generic transaction-detail page once `workflow.actions.return_to_study` was added to both locale files. New `CommitteeCandidateController` (`index`/`nominate`/`requestCompletion` via `CommitteeStatusService`; `defer`/`returnToStudy` via `WorkflowService`, both catching their DomainException into the same `ValidationException::withMessages(['action'=>[...]])` shape `TransactionController::transition` uses) and `MeetingsDashboardController` + `MeetingsDashboardMetrics` (kpis/funnel/nextMeeting). No migration — no schema change this stage, only a seeder addition and new code. Routes ride the Stage-28-seeded `committee_candidates`/`meetings_dashboard` screens' existing grants, no `ScreenRolePermissionSeeder` changes needed; `meetings/dashboard` is registered before the `meetings/{meeting}` wildcard, same reason `meetings/department-options` already is.

Frontend: real `CommitteeCandidatesView.vue` (status/department/search filter bar, a table styled after `MeetingAgendaBuilderView`'s item list, four per-row action buttons gated `v-can="committee_candidates.add"`/`"committee_candidates.edit"`, a shared reason-prompt modal for the three actions that can require a comment) and real `MeetingsDashboardView.vue` (6 KPI tiles + a CSS-only funnel bar styled after `DashboardView.vue`, a next-meeting card linking to `MeetingDetailView`) replacing both Stage 28 placeholders. The candidate-status filter's three options are a small static Arabic/English label map mirroring `TransactionStatusSeeder`'s names, not a new lookup endpoint — three fixed rows didn't justify one.

Verification: new `tests/Feature/CommitteeCandidatesDashboardTest.php` (8 tests — worklist excludes earlier-stage and post-committee transactions, nominate, R04-can-nominate-but-not-defer proving the add/edit permission split, defer/return-to-study/request-completion all reject a missing comment then succeed with one and land at the right status+stage, dashboard KPI/funnel arithmetic against a hand-built fixture, next-meeting null with none upcoming), full suite **111 tests / 695 assertions** green (was 103/654), Pint clean on all 9 touched/new PHP files, `npm run build` passes with both new views as separate lazy chunks (then reverted `frontend/dist` — tracked in git, rebuilding wasn't in scope, per every prior stage's note). `php artisan db:seed --class=WorkflowTransitionSeeder --force` ran clean against the real MySQL/Homestead database (confirmed the new `return_to_study` row via tinker: `7→4, R03, status 10, is_exception, requires_comment`), and both new endpoints were smoke-tested over real HTTP against Homestead as the seeded R03 test user — 200s with the expected JSON shape. **One test-writing gotcha worth flagging for whoever writes fixtures against `Transaction` next:** `overdue_at` is not mass-assignable (only the SLA sweep sets it in production), so `Transaction::update(['overdue_at' => ...])` silently no-ops in a test fixture — direct property assignment (`$t->overdue_at = ...; $t->save();`) is required, the same gotcha `FlagOverdueTransactions` exists to avoid in production code. **Not verified: no browser this session** — both new screens' layout/interactions are calculated from `MeetingAgendaBuilderView.vue`/`DashboardView.vue`'s existing patterns, not observed, consistent with every prior UI-touching note.

**Open items for whoever builds Stage 33+:** `MeetingsDashboardMetrics::nextMeeting()` is deliberately a lightweight count-based preview (agenda/attendee/confirmed counts only) — the real file/member/agenda/invitation percentage math and the ready/not-ready verdict is explicitly Stage 33's `GET /meetings/{id}/readiness` scope; don't duplicate it here when that stage lands, wire the dashboard's next-meeting card to read the same computation instead. The `return_to_study` destination (stage 4, `observations`) was a judgment call, not something the design PDFs were fed to this session — flagged in the plan below as the "action-to-mechanism mapping" decision; revisit if a later stage's design-doc reading turns up a different intended landing stage.

---

### 2026-08-25 12:00 EET — Claude — Stage 32 implementation plan (candidate-requests worklist + command dashboard)

Building Stage 32 per STAGE_PLAN.md: `GET /committee-candidates` (transactions in `ready`/`nominated_for_committee`, actions add-to-meeting/defer/return-to-study/request-completion) and `GET /meetings/dashboard` (KPIs + next-meeting readiness + the request lifecycle funnel), replacing the two Stage 28 placeholder screens.

**Action-to-mechanism mapping — this is the judgment call the STAGE_PLAN prose left implicit, so recording it explicitly.** The design doc's four verbs split across two write paths, not one: "add-to-meeting" (nominate) and "request completion" are pure committee bookkeeping (status only, stage untouched) so they go through `CommitteeStatusService` — nominate already exists (`ready`/`in_meeting` → `nominated_for_committee`); `require_completion`'s `from` list gets widened from `[under_discussion, awaiting_recommendation_approval]` to also include `[ready, in_meeting, nominated_for_committee]`, so the worklist can ask for missing material before a request is even nominated, not only mid-discussion. "Defer" and "return to study" both **change what stage the transaction is officially at** (defer keeps it at stage 7 but is modeled as a workflow self-loop since Stage 21; return-to-study sends it backward to stage 4 `observations` — the "study" checkpoint before ministry endorsement) — that crosses the exact boundary `CommitteeStatusService` refuses to cross by design, so both go through `WorkflowService::transition()` instead. `defer` already exists (Stage 21's `7→7` exception); `return_to_study` is a new exception row (`WorkflowTransitionSeeder`, `[7→4, return_to_study, R03, status=returned, requires_comment]`, mirroring the existing `4→3 request_edit` shape) — and because it rides the same generic `workflow_transitions` table, it will also show up for free as an exception button on the existing generic transaction-detail page once `workflow.actions.return_to_study` is added to the locale files, exactly like `defer` already does per the Stage 21 note.

**Candidate pool, defined once.** New `CommitteeStatusService::CANDIDATE_STATUSES = ['ready','in_meeting','nominated_for_committee']` (nominate's origin ∪ its destination) and a new `CommitteeStatusService::candidatesQuery(): Builder<Transaction>` (stage = `receive_from_committee` AND status in that list) — the single query both the worklist's `index()` and the dashboard's KPIs/funnel read, mirroring how Stage 25's `DecisionEligibility` keeps a worklist and its gate reading one predicate.

**Backend:** new `App\Http\Controllers\Api\CommitteeCandidateController` (`index`, `nominate`, `requestCompletion` via `CommitteeStatusService`; `defer`, `returnToStudy` via `WorkflowService`, each catching its DomainException into the same `ValidationException::withMessages(['action'=>[...]])` shape `TransactionController::transition` already uses) plus `App\Http\Requests\CommitteeCandidate\{Index,CommitteeCandidateAction}Request`. New `App\Services\MeetingsDashboardMetrics` (kpis: candidates/upcoming_meetings/meetings_held/pending_decisions/overdue_committee_items/decisions_this_month; funnel: 5 buckets by a committee-bound transaction's *current* status — candidates/on_agenda/in_discussion(under_discussion+awaiting_recommendation_approval+completion_required)/decided/closed(approved+final_approved+archived+completed_closed); nextMeeting: nearest future `status=scheduled` meeting with agenda/attendee/confirmed counts) — deliberately separate from Stage 24's `ReportMetricsService`, which answers a different question (whole-pipeline reporting, not the committee/meetings slice) — and `App\Http\Controllers\Api\MeetingsDashboardController` (thin, mirrors `DashboardController`). Both new routes ride their own Stage-28-seeded screens' grants (`committee_candidates`: view=`*`, add=[R03,R04] → nominate, edit=[R03] → defer/return-to-study/request-completion; `meetings_dashboard`: view=`*`) — no seeder changes needed. `GET meetings/dashboard` is registered before the `meetings/{meeting}` wildcard, same reason `meetings/department-options` already is. Readiness percentages are explicitly **not** computed here — `next_meeting` is a lightweight count-based preview; the real percentage/quorum math is Stage 33's `GET /meetings/{id}/readiness` scope.

**Frontend:** real `CommitteeCandidatesView.vue` (filter bar reusing `/transactions/filters`'s department/type lookups + a status filter restricted to the 3 candidate statuses, a table styled like `MeetingAgendaBuilderView`'s item list, per-row action buttons gated `v-can="committee_candidates.add"`/`"committee_candidates.edit"`, each opening a small reason prompt for the three that can require one) and real `MeetingsDashboardView.vue` (KPI tiles + a CSS-only funnel bar, styled after `DashboardView.vue` — no chart library, matching that stage's precedent) replacing both Stage 28 placeholders. New `meetingsUnit.candidates.*`/`meetingsUnit.dashboard.*` locale keys replacing the two `placeholder` stubs, plus `workflow.actions.return_to_study` in both `ar.json`/`en.json`.

**Verification:** new `tests/Feature/CommitteeCandidatesDashboardTest.php` — worklist lists only stage-7 candidate-status transactions and excludes others (earlier-stage `ready`, post-committee `approved`), nominate moves `ready`→`nominated_for_committee`, defer/return-to-study/request-completion all reject a missing comment then succeed with one, R04 can nominate but gets 403 on defer (add vs edit split), dashboard KPI/funnel arithmetic against a hand-built fixture, next-meeting block picks the nearest future `scheduled` meeting and is `null` with none upcoming — plus the full PHPUnit suite, Pint on touched/new files, `npm run build`, and `php artisan migrate --force` for the new `return_to_study` seed row (no migration/schema change this stage, seeder + code only).

---

### 2026-08-25 11:15 EET — Claude — Stage 31 complete (agenda builder enhancements)

Built per the plan below, with one mechanism correction: `transaction_id` moves to nullable via a plain `Schema::table(...)->change()` in a second `Schema::table` call in the same migration, not a raw `DB::statement('ALTER TABLE ... MODIFY ...')`. Reason: **Laravel 11 removed the `doctrine/dbal` requirement for `change()` entirely** — its schema grammars now compile column alterations natively for every driver, including SQLite — so the raw-SQL workaround the plan below assumed was unnecessary and, worse, MySQL-only syntax that broke the sqlite-backed PHPUnit suite outright (`near "MODIFY": syntax error`). Swapping to `->change()` fixed it with no driver branching. Second fix needed: a freshly `create()`d `employee_request` agenda item read `item_type` as PHP `null` (not the DB's `employee_request` default) until refetched — the same Eloquent gotcha Stage 27's `GuideArticle` hit — so `MeetingTransaction` now declares `protected $attributes = ['item_type' => 'employee_request']`, mirroring that fix.

Everything else landed as planned: the migration, the conditional `StoreMeetingAgendaRequest`/new `UpdateMeetingAgendaItemRequest`, `MeetingTransactionResource`'s null-safe `transaction` block plus the five new fields, `MeetingController::addAgendaItem/updateAgendaItem/agendaStats/departmentOptions`, the `DecisionEligibility`/`DecisionController` item_type guards (with `pendingVotesQuery()` filtering to `employee_request`), the route move from `meetings,edit` onto `meeting_agenda,view/edit`, and the real `MeetingAgendaBuilderView.vue` (meeting picker → stats/grouping panel → typed add-item form → editable item list) replacing the Stage 28 placeholder. `MeetingDetailView.vue` got the planned minimal fix (null-safe `agendaTransactionIds`/agenda rendering, priority/type/time badges, decision-block hidden for non-`employee_request` items, `v-can` renamed to `meeting_agenda.edit`) plus one addition not in the original plan: a small "open agenda builder" link on the agenda card, deep-linking to `/meetings/agenda?meeting={id}` — cheap to add once both screens existed side by side, and otherwise there was no path from the meeting workspace to the new dedicated screen.

Verification: new `tests/Feature/MeetingAgendaBuilderTest.php` (6 tests — admin item without a transaction, `subject` required for non-request items, stats totals/grouping arithmetic, PATCH updates priority/time, voting+deciding both rejected on an admin item, the pending-votes worklist excludes admin items), full suite **103 tests / 654 assertions** green (was 97/622 before this stage), Pint clean on every touched/new PHP file, `npm run build` passes (then reverted `frontend/dist` — tracked in git, rebuilding it wasn't in scope), and `php artisan migrate --force` ran clean against the real MySQL database — confirmed via `Schema::getColumns` that `transaction_id` is now nullable alongside the five new columns, and `route:list` confirms `meetings/department-options` still precedes the `meetings/{meeting}` wildcard. **Not verified: no browser this session** — the agenda-builder screen's layout and the meeting-detail screen's admin-item rendering are calculated from existing view patterns, not observed, consistent with every prior UI-touching note.

**Open items for whoever builds Stage 32+:** `agendaStats()`'s "group similar" is department-only, per the plan's explicit scope call — no free-text similarity matching. `item_type`/`transaction_id` are immutable after creation (remove-and-re-add is the only way to reclassify an item); if Stage 32's candidate-requests worklist or Stage 33's readiness screen end up wanting to count/surface admin items too, note that `Committee`/`Meeting` KPI-style aggregates elsewhere in the app (e.g. any future dashboard funnel) will need the same `item_type === 'employee_request'` filter this stage added to `DecisionEligibility`, or they'll silently include items that were never meant to enter the request lifecycle.

---

### 2026-08-25 10:00 EET — Claude — Stage 31 implementation plan (agenda builder enhancements)

Building Stage 31 per STAGE_PLAN.md: evolve `meeting_transactions` from a transaction-only ordered list into a typed, prioritized, timed agenda that also accepts non-transaction admin items, plus a dedicated agenda-builder screen (the `meeting_agenda` route Stage 28 scaffolded as a placeholder).

**Migration** (`meeting_transactions`): add `item_type` (string, `default('employee_request')`, values `employee_request|administrative|emerging`), `priority` (nullable string, `high|medium|low`), `estimated_minutes` (nullable unsigned int), `subject` (nullable string — the admin item's own title, since it has no transaction to borrow one from), `department_id` (nullable FK `departments`, `nullOnDelete`, mirroring `transactions.department_id`'s shape). `transaction_id` becomes nullable via a raw `DB::statement('ALTER TABLE ... MODIFY transaction_id BIGINT UNSIGNED NULL')` rather than `->nullable()->change()`, since `doctrine/dbal` isn't installed in this repo (checked — no `change()` calls exist anywhere in `database/migrations`) — the existing FK and unique(`meeting_id`,`transaction_id`) index are untouched by a plain MODIFY, and MySQL treats each NULL in that unique index as distinct, so multiple admin items per meeting are fine with no index change.

**Backend correctness point, not scope creep:** once `transaction_id` can be null, `DecisionController::vote()`/`record()` and `DecisionEligibility` (which currently dereference `$agendaItem->transaction` unconditionally) must reject voting/deciding on a non-`employee_request` item — those have nothing to run through `WorkflowService::transition()`. Adding that guard is part of "mixing item types safely," not a separate feature. `DecisionEligibility::pendingVotesQuery()` also gets a `where('item_type', 'employee_request')` filter so admin items never surface on the "awaiting my vote" worklist.

**Permission move:** `addAgendaItem`/`reorderAgenda`/`removeAgendaItem` currently ride the `meetings` screen's `edit` grant (Stage 20). Moving them, plus two new endpoints, onto the `meeting_agenda` screen Stage 28 already seeded — grants are identical today (`view=*, add=[R03,R04], edit=[R03], print=*` on both), so no role's actual access changes, but agenda-building is that screen's declared domain going forward. New endpoints: `GET meetings/{meeting}/agenda/stats` (`meeting_agenda,view` — total items, total estimated minutes, counts by priority/type, and groups by "effective department" = the item's own `department_id` for admin items or its transaction's `department_id` for requests — this is the "group similar" mechanism, kept to a simple existing dimension rather than text-similarity matching, which is out of scope) and `PATCH meetings/{meeting}/agenda/{agendaItem}` (`meeting_agenda,edit` — updates `priority`/`estimated_minutes`/`subject`/`department_id` only; `item_type`/`transaction_id` stay immutable after creation to avoid re-validating a type switch, matching the plan's "priority, time totals, group-similar" scope, not a full reclassify feature). Also adding `GET meetings/department-options` (`meeting_agenda,view`, mirrors `CommitteeController::userOptions()`) since the `departments` screen itself grants nobody access (`'departments' => []` in the seeder) and the agenda builder's admin-item form needs a department picker.

**`StoreMeetingAgendaRequest`** gains conditional rules: `transaction_id` required only when `item_type` is (or defaults to) `employee_request`; `subject` required for `administrative`/`emerging`; `priority`/`estimated_minutes`/`department_id` always optional. `MeetingTransactionResource` exposes the five new fields and stops assuming `transaction` is non-null (`whenLoaded('transaction', fn () => $this->transaction ? [...] : null)`).

**Frontend:** real `MeetingAgendaBuilderView.vue` replacing the Stage 28 placeholder — a meeting picker (reuses `GET /meetings`), then for the selected meeting: a stats panel (totals + a group-by-department view), an add-item form that switches between "search a transaction" (existing pattern) and "admin item" (subject/department/priority/time), and a list with inline priority/time editing plus the existing up/down reorder. `MeetingDetailView.vue`'s existing inline agenda list gets the minimal fix it now needs for correctness (guard `item.transaction` before dereferencing, render `item.subject`/department for admin items, small priority/type/time badges) but keeps deferring the actual admin-item authoring and priority editing to the new dedicated screen — its `v-can` gates move from `'meetings.edit'` to `'meeting_agenda.edit'` to match the routes above. `MeetingSchedulingWizard.vue` is left untouched — its step-1 candidate picker stays request-only by design; admin items are a agenda-builder-screen concept. New `meetingsUnit.agenda.*` locale keys replace the Stage 28 placeholder copy in both `ar.json`/`en.json`.

**Verification:** new `tests/Feature/MeetingAgendaBuilderTest.php` (admin item creation without a transaction, `subject` required when `item_type` isn't `employee_request`, stats totals/grouping arithmetic against a hand-built fixture, PATCH updates priority/time, voting/deciding rejected on a non-`employee_request` item, the pending-votes worklist excludes admin items), full PHPUnit suite, Pint on touched/new files, `npm run build`, `php artisan migrate` against the real MySQL database.

---

### 2026-08-24 18:55 EET — Claude — Stage 30 complete (meeting scheduling model + 5-step wizard)

Built per the plan below. Two additive migrations applied to the real MySQL database: `meetings` gains `meeting_number`, `meeting_type` (`regular|extraordinary|emergency`, default `regular`), `chairman_user_id`/`rapporteur_user_id` (nullable, `nullOnDelete`), `expected_duration_minutes`, `agenda_deadline` (dateTime), `description`; `meeting_attendees` gains `invitation_status` (`pending|confirmed|declined|no_response`, default `pending`) and `responded_at`. `Meeting`/`MeetingAttendee` models, both Form Requests, both Resources, and `MeetingController::loadDetail()` all extended to match — no logic change needed in `store()` itself, since the new meeting fields ride the existing `...$data` spread and `invitation_status` defaults via the DB column.

**New `MeetingController::sendInvitations()`** (`POST /meetings/{meeting}/send-invitations`, inside the existing `screen.permission:meetings,edit` group) re-dispatches `NotificationDispatcher::meetingScheduled()` to the meeting's current attendees — this is what makes the wizard's "invitations sent" step real rather than implicit, and it's also reusable later as a manual resend button. `UpdateMeetingAttendeeRequest`/`markAttendance()` now accept `invitation_status` as a second optional field alongside `attended` (both `sometimes` now); setting it stamps `responded_at = now()` — this is how staff record a member's RSVP by hand, there's no public confirm link in this stage.

**Frontend:** new `frontend/src/components/MeetingSchedulingWizard.vue` replaces `MeetingsView.vue`'s old single inline meeting form. Five steps, client-side only until the final one: (1) requests — search `/transactions?search=` and multi-select candidates; (2) details — committee/number/type/title/time/location/chairman/rapporteur/duration/agenda-deadline/description; (3) members/invitations — read-only auto-invited committee roster + an extra-invitee picker; (4) review/agenda — read-only details summary + up/down-reorderable selected requests; (5) approve/schedule — fires the actual calls in sequence: `POST /meetings` → `POST .../agenda` per selected transaction → `POST .../attendees` per extra invitee → `POST .../send-invitations`. No cross-call rollback — if a later call 422s the meeting already exists; the wizard just reports which phase failed via `submitPhase`/`submitError`. `MeetingDetailView.vue` got additive-only changes: new summary fields, a description card, a per-attendee invitation-status `<select>` (wired to the same `markAttendance` PATCH endpoint), and a header "Send invitations" resend button. New `meetings.wizard.*` + `meetings.attendance.invitation.*` + several new flat `meetings.*` keys in both `ar.json`/`en.json`, mirrored key-for-key (validated both parse as JSON).

**Verification:** new `tests/Feature/MeetingSchedulingWizardTest.php` (4 tests — new fields round-trip through the resource incl. `chairman`/`rapporteur` whenLoaded blocks, invalid `meeting_type` 422s, `send-invitations` notifies non-actor attendees only via `Notification::fake()`, marking `invitation_status` stamps `responded_at`), full suite **97 tests / 622 assertions** green, Pint clean on all 12 touched/new PHP files, `npm run build` passes (then reverted the regenerated `frontend/dist/` — that directory is tracked in git in this repo, and rebuilding it wasn't part of this task's scope; `git clean -fd frontend/dist` after `git checkout -- frontend/dist` removed the stray new-hash files). Migrations ran clean against the real Homestead/MySQL database this session. **Not verified: no browser this session** (consistent with every prior UI-touching note) — the wizard's step validation and layout are calculated from `MeetingsView.vue`/`MeetingDetailView.vue`'s existing patterns, not observed.

**Open items for whoever builds Stage 31+:** the wizard's step 1/step 4 agenda handling is intentionally minimal (plain multi-select + up/down reorder, no priority/time/item-type/grouping) — that's explicitly Stage 31's scope (`meeting_transactions` gains those columns then). `meeting_number` has no uniqueness constraint and no auto-numbering; if the design doc actually wants sequential per-committee-per-year numbers, that's a decision to make when building whichever stage first depends on it, not assumed here. `chairman_user_id`/`rapporteur_user_id` are nullable and not required by the wizard's step-2 validation — only committee/title/type/scheduled_at block "Next".

### 2026-08-24 18:10 EET — Claude — Stage 30 implementation plan (meeting scheduling model + 5-step wizard)

Building Stage 30 per STAGE_PLAN.md/AGENT_NOTES' Track H entry. Two additive migrations, `Schema::table(...)->after(...)` style matching the Stage 28 `screens.group` migration: `meetings` gains `meeting_number` (nullable string), `meeting_type` (string, `default('regular')`, values `regular|extraordinary|emergency` — English codes with Arabic labels via i18n, matching how `status` already works, not literal Arabic strings in the DB), `chairman_user_id`/`rapporteur_user_id` (nullable FK to `users`, `nullOnDelete`, mirroring `created_by_user_id`), `expected_duration_minutes` (nullable unsigned int), `agenda_deadline` (nullable **dateTime** — needs a time component to mean anything as a cutoff), `description` (nullable text). `meeting_attendees` gains `invitation_status` (string, `default('pending')`, values `pending|confirmed|declined|no_response`) and `responded_at` (nullable dateTime). No new tables.

**Backend:** extend `Meeting`/`MeetingAttendee` fillable+casts (+`chairman()`/`rapporteur()` BelongsTo on `Meeting`), extend `StoreMeetingRequest`/`UpdateMeetingRequest` with the new fields (Arabic messages, same per-field style), extend `MeetingResource`/`MeetingAttendeeResource` to expose them, extend `MeetingController::loadDetail()`'s eager-loads for `chairman`/`rapporteur`. `store()` itself needs no logic change — the new fields ride the existing `...$data` spread and `invitation_status` defaults via the DB column default, same as today's `attended`. New `MeetingController::sendInvitations()` (`POST /meetings/{meeting}/send-invitations`, inside the existing `screen.permission:meetings,edit` route group) re-dispatches the existing `NotificationDispatcher::meetingScheduled()` to the meeting's current attendees — this is what makes the wizard's step-5 "invitations sent" done-when criterion literal rather than implicit. `UpdateMeetingAttendeeRequest`/`markAttendance()` extended so `invitation_status` is a second optional field alongside `attended` (was `required`, becomes `sometimes`) — setting it stamps `responded_at = now()`, letting staff record a member's phone/email RSVP by hand (no public confirm link in this stage).

**Frontend:** new `frontend/src/components/MeetingSchedulingWizard.vue` (Stage 30 comment marker) replacing the inline single-form "Meetings" section of `MeetingsView.vue` — 5 steps per STAGE_PLAN: (1) **requests** — search transactions (reusing the existing `/transactions?search=` filter from Stage 20) and multi-select candidates for the agenda, client-side only, nothing posted yet; (2) **details** — committee, meeting_number, meeting_type, title, scheduled_at, location, chairman/rapporteur pickers (from `/committees/user-options`), expected_duration_minutes, agenda_deadline, description; (3) **members/invitations** — read-only list of the selected committee's active members (auto-invited) + a picker to add extra invitees from `/committees/user-options`; (4) **review/agenda** — read-only summary of step 2 + reorderable (up/down, matching `MeetingDetailView`'s existing pattern) list of step-1's selected requests; (5) **approve/schedule** — final confirm, and only here do the actual API calls fire in sequence: `POST /meetings` (details) → `POST /meetings/{id}/agenda` per selected transaction in order → `POST /meetings/{id}/attendees` per extra invitee → `POST /meetings/{id}/send-invitations`. No cross-call transaction/rollback — if a later call 422s, the meeting already exists and the wizard reports which step failed, same one-call-per-REST-action shape Stage 13's multi-attachment upload already accepts. `MeetingDetailView.vue` gets small additive changes (not a rewrite): new fields in the summary card, an invitation-status pill per attendee, and a "Send invitations" button (`v-can="'meetings.edit'"`) hitting the same new endpoint for post-creation resends. New `meetings.wizard.*` + extended `meetings.*`/`meetings.attendance.invitation.*` locale keys in both `ar.json`/`en.json`, mirrored key-for-key per existing convention.

**Verification:** new `tests/Feature/MeetingSchedulingWizardTest.php` (new fields persist and round-trip through the resource, `invitation_status` defaults `pending` on auto-invited attendees, `send-invitations` dispatches `MeetingScheduledNotification` via `Notification::fake()`, marking an attendee's `invitation_status` stamps `responded_at`, validation rejects a bad `meeting_type`), full PHPUnit suite, Pint on touched files, `npm run build`, `php artisan migrate`. No seeder changes — Stage 30 rides the `meetings` screen's existing grants exactly like Stage 20 did. No browser available this session (consistent with every prior note) — UI correctness is calculated from the existing `MeetingsView.vue`/`MeetingDetailView.vue` patterns, not observed.

### 2026-08-24 17:45 EET — Claude — Stage 29 complete (request lifecycle status expansion + CommitteeStatusService)

Built exactly per the plan below. `TransactionStatusSeeder` now seeds 20 statuses (13 → 20): the 5 committee sub-states (`nominated_for_committee`, `on_agenda`, `under_discussion`, `awaiting_recommendation_approval`, `completion_required`) that `CommitteeStatusService` drives, plus `in_execution`/`completed_closed` seeded but **deliberately unwired** — they're Stage 37's job, not this one, per the reconciliation note in the plan below. New `App\Services\CommitteeStatusService::move()` (hardcoded `ACTIONS` map: `nominate`, `place_on_agenda`, `remove_from_agenda`, `start_discussion`, `send_for_recommendation_approval`, `require_completion`, `resume_discussion`) and `App\Exceptions\CommitteeStatusTransitionException`. No migration, no controller, no routes — those are Stages 30–37.

**The load-bearing guarantee, verified by test:** every guarded move writes only `status_id` + a `transaction_status_history` row; `current_stage_id` and `transaction_stage_logs` are never touched. New `tests/Unit/CommitteeStatusServiceTest.php` (7 tests) proves the full nominate→on_agenda→under_discussion→awaiting_recommendation_approval walk leaves `current_stage_id` constant and `transaction_stage_logs` empty throughout, plus rejections for: wrong current status, wrong workflow stage (must be `receive_from_committee`), inactive actor, missing required comment (`require_completion`, `remove_from_agenda`), and a terminal (`cancelled`) transaction. Full suite **93 tests / 601 assertions** green, Pint clean on all 4 touched/new files. `TransactionStatusSeeder` was also re-run against the real MySQL database (Homestead reachable this session) — confirmed 20 rows via tinker.

**Open item for whoever builds Stage 30+:** `CommitteeStatusService` does no role/permission check itself (mirrors `DecisionEligibility`'s split — business-rule guard here, `screen.permission:<code>,<action>` enforcement in the controller) — when Stages 30–37 add controllers that call `move()`, wire them behind the already-seeded grants on the relevant meetings-unit screen (e.g. `committee_candidates,add` for `nominate`, `meeting_agenda,add`/`edit` for the agenda actions) rather than leaving the endpoints ungated.

### 2026-08-24 17:20 EET — Claude — Stage 29 implementation plan (request lifecycle status expansion + CommitteeStatusService)

Building Stage 29 per STAGE_PLAN.md/AGENT_NOTES' Track H entry below: 7 new statuses appended to `TransactionStatusSeeder` (`nominated_for_committee`, `on_agenda`, `under_discussion`, `awaiting_recommendation_approval`, `completion_required`, `in_execution`, `completed_closed` — 13 → 20 total), and a new `App\Services\CommitteeStatusService` as the sole write path for the first five. It hardcodes a small `ACTIONS` map (`nominate`, `place_on_agenda`, `remove_from_agenda`, `start_discussion`, `send_for_recommendation_approval`, `require_completion`, `resume_discussion`) rather than reading `workflow_transitions` — this state machine is fixed by design, not admin-configurable like `WorkflowService`'s. Every guarded move: (1) locks the transaction row (mirrors `WorkflowService::transition()`'s `lockForUpdate`), (2) requires the actor active, (3) requires the transaction's **current workflow stage to be `receive_from_committee`** (stage 7 — the only stage these sub-statuses are meaningful at; `forward_to_committee`→`receive_from_committee` is where the existing 6→7 rule already lands status `in_meeting`, which is why `nominate`'s allowed origin statuses are `['ready', 'in_meeting']`, covering both the plan's literal prose and the actual seeded transition), (4) requires the transaction's current status match the action's configured origin list, (5) writes **only** `status_id` + a `transaction_status_history` row — `current_stage_id` is never touched and no `transaction_stage_logs` row is written, which is the entire point of the guard. A new `App\Exceptions\CommitteeStatusTransitionException` (same static-factory-of-Arabic-messages shape as `WorkflowTransitionException`) carries the failure modes.

`in_execution` and `completed_closed` are seeded (schema-complete, so later stages have a row to point at) but **`CommitteeStatusService` does not manage transitions into them** — per the redesign note's reconciliation instruction, they map onto the existing `approved`/`final_approved`/`archived` statuses and stage 8–11 progression rather than being a parallel status track; wiring that is Stage 37's job (outputs → execution → close), not this one. No role/permission check inside the service itself — like `DecisionEligibility`, it centralizes the business-rule guard (valid state machine, stage invariant) and leaves *who* may call each action to the `screen.permission:<code>,<action>` route middleware the Stage 30+ controllers will register (the 9 screens' grants are already seeded from Stage 28). No migration (no schema change, only seeded row data + a service class), no controller, no routes, no UI — those are Stages 30–37. Verification: a new `tests/Unit/CommitteeStatusServiceTest.php` mirroring `WorkflowServiceTest`'s direct-service-call style — the full `ready`-equivalent→nominated→on_agenda→under_discussion→awaiting_recommendation_approval walk asserting `current_stage_id` is unchanged and `transaction_stage_logs` stays empty at every step, plus rejection cases (wrong stage, wrong current status, inactive actor, comment-required on `require_completion`) — full suite, Pint on touched files.

### 2026-08-24 16:30 EET — Claude — Stage 28 implementation plan (meetings-unit navigation shell)

Building Stage 28 per STAGE_PLAN.md/AGENT_NOTES' Track H entries below: a new nullable `screens.group` string column (deliberately NOT reusing the existing-but-unused `parent_id` self-reference — that would mean modelling the group heading itself as a headless `Screen` row, which complicates `ScreenController`'s visibility query and the permission seeder's per-screen loop for no benefit over a flat label), seeded `meetings_management` on all 9 Committee-block codes (`meetings_dashboard`, `committee_candidates`, `meetings`, `meeting_agenda`, `meeting_readiness`, `meeting_live`, `decisions`, `meeting_minutes`, `meeting_outputs`), matching `ScreenRolePermissionSeeder` grants (mirroring the existing `meetings` shape: `view=*, add=[R03,R04], edit=[R03], print=*`), `ScreenResource` exposing `group`, a new `navGroups` computed in `stores/screens.js`, `AppSidebar.vue` rendering an expanded-by-default collapsible group (new `nav-group-*` CSS, logical properties, reusing existing `--color-nav` tokens), 7 new flat `/meetings/...` routes + minimal scaffold views, a new `video` icon in `AppIcon.vue`, bilingual `nav.groups.*` + `meetingsUnit.*` locale keys, and a new `tests/Feature/ScreenTest.php` (no prior test touched `screens` at all). `meetings`/`decisions` existing rows, routes, and sidebar icon mappings are left untouched apart from gaining `group`. No business logic — Stages 29-37 build the actual content behind each of the 7 new empty slots. Full plan: `C:\Users\Dev\.claude\plans\start-stage-28-hashed-cat.md`.

### 2026-08-24 14:20 EET — Claude — Meetings Management Unit redesign — new logic & UI structure (design of record for Stages 28–37)

A redesign package (two Arabic PDFs + UI mockups + workflow infographics) reframes the committee side of the app from "meeting admin" into a full **request → study → committee → meeting → vote → decision → minutes → execution → close** pipeline — the "العمود الفقري" the design doc argues for. **Source files** (chat attachments, not yet in the repo — please commit them to `docs/meetings-redesign/` so the per-stage `Source:` citations in STAGE_PLAN Track H resolve): **[Design PDF]** `اجتماع لجنة.pdf` (the 9-screen Meetings-Unit design, §1–§9 + the sidebar restructure on p5); **[Lifecycle PDF]** `1.pdf` (the 12-stage request lifecycle table, the Stage-7 five-step wizard, the Stage-10 internal meeting path, and the request state-sequence on p2); **[Workflow infographics]** the 7 municipality infographics for دورة حياة المعاملة stages 5–11; **[UI mockups]** the 9 meeting-screen images.

**This entry is documentation only — no code, schema, seeder, or Vue changes were made.** It records the target so the next agent building Stages 28–37 isn't re-deriving it. The four scope decisions the user locked: (1) the staged plan lives **both** here and as a new **Track H** in [STAGE_PLAN.md](STAGE_PLAN.md); (2) the sidebar becomes a **grouped, collapsible "إدارة الاجتماعات" section** (needs a group concept on the `screens` table + nesting in `AppSidebar.vue`, which is flat today); (3) the live-meeting screen is a **state-driven item-by-item runner** (per-item timer, discussion notes, live vote panel by polling) with the video area a **static placeholder — no websockets/Reverb/broadcasting**; (4) the request state machine is **extended** (new statuses seeded + wired through the workflow), not relabelled in the UI.

**The 9-screen unit and the mechanism of change from today's code** (today = `MeetingsView` bare schedule form + `MeetingDetailView` agenda list with ↑/↓ reorder, inline voting, one free-text `minutes` field; Stages 20–21): **(1) لوحة قيادة الاجتماعات** — *new* dashboard endpoint aggregating meeting/candidate/decision counts + next-meeting readiness. **(2) الطلبات المرشحة للعرض** — *new* candidate-requests worklist over transactions eligible for committee; **replaces the ad-hoc debounced transaction-search inside `MeetingDetailView`** as the way items reach an agenda. **(3) إعداد وجدولة الاجتماع** — *evolve* the `MeetingsView` schedule form into a **5-step wizard**; add meeting fields (number, type دوري/استثنائي/طارئ, chairman, rapporteur, expected duration, agenda deadline) and per-attendee invitation status (confirmed/declined/no-response). **(4) إعداد جدول الأعمال** — *evolve* the agenda pivot with priority, estimated time, item type (employee_request/administrative/emerging — **admin items are non-transaction rows**), and "group similar", plus a dedicated builder screen. **(5) جاهزية الاجتماع** — *new* readiness endpoint + Pre-Meeting Control Center screen (file/member/agenda/invitation %, exceptions-only list, جاهز/غير جاهز verdict) that **gates "convene"**. **(6) مباشر الاجتماع** — *new* item-by-item runner reusing the existing vote/decision endpoints; **replaces inline agenda voting**; cannot close a meeting with unresolved items. **(7) التصويت والقرارات** — *evolve* Stages 21/25 with decision **templates** (reuse the `Template` model) and richer outcomes (conditional approval, request-legal-opinion, refer-to-another-body). **(8) المحاضر** — *new* auto-compiled minutes + an approval/signature lifecycle (draft → head review → member signatures → approved → meeting closed); **replaces the single `minutes` text field** and gates meeting close. **(9) مخرجات الاجتماعات** — *new* meeting-scoped outputs tracker linking each item → decision → next action → responsible body → execution status.

**Request state machine — extend, don't relabel.** Add 7 statuses to `TransactionStatusSeeder` (today 13): `nominated_for_committee` (مرشح للجنة), `on_agenda` (مدرج بجدول الأعمال), `under_discussion` (قيد المناقشة), `awaiting_recommendation_approval` (بانتظار اعتماد التوصية), `completion_required` (مطلوب استكمال), `in_execution` (قيد التنفيذ), `completed_closed` (مكتمل ومغلق). **The load-bearing constraint:** these are committee sub-states clustered around stages 6–8 and are mostly **status-only** moves — nomination, agenda placement/removal, discussion, and recommendation-approval must **not** move the workflow stage. Introduce a small `CommitteeStatusService` (a guarded status-only write path) so `WorkflowService::transition()` stays the **sole authority for stage changes**. The binding decision keeps using the existing `WorkflowService` 7→8 `approve` / 7→7 `cancel` / 7→7 `defer` transitions untouched; `in_execution` maps onto the existing downstream approval stages (8–11) and `completed_closed` onto final archival — **reconcile with the existing `approved`/`final_approved`/`archived` statuses rather than duplicating them.** Note the existing gotcha the runner/minutes screens inherit: the `approve` outcome path requires a signature file today (7→8 is an approval level in `WorkflowService::APPROVAL_LEVELS`), so `DecisionController::record()` 422s on approve without one.

### 2026-08-24 14:20 EET — Claude — Stages 28–37 (Track H) — the split; full text in STAGE_PLAN.md

The redesign above is split into **10 stages appended to [STAGE_PLAN.md](STAGE_PLAN.md) as Track H** (each in the repo's Goal → Build → Done-when style, with a `Source:` citation back to the design artifacts). Dependency order: **28** (grouped sidebar + 9 screen codes/permissions + empty route scaffolds) and **29** (the 7 new statuses + `CommitteeStatusService`, keeping `WorkflowService` the stage authority) are the enablers everything else needs, so build them first and don't rush 29 — it touches the correctness-sensitive workflow engine. Then **30** (meeting fields + 5-step scheduling wizard + invitation status), **31** (agenda builder: priority/time/item-type/admin-items/grouping), **32** (candidate-requests worklist + command dashboard — this is the لوحة/معاملات linking layer that replaces the ad-hoc agenda search), **33** (readiness control center + convene gate), **34** (state-driven live runner, video = placeholder), **35** (decision templates + richer outcomes), **36** (auto-compiled minutes + approval/signature lifecycle, gates meeting close), **37** (meeting outputs follow-up → execution → close). No permission-matrix surprises are expected beyond the new screen grants seeded in Stage 28, but re-check `ScreenRolePermissionSeeder` before assuming — every new endpoint must register per-verb `screen.permission:<code>,<action>` routes and every action button must carry `v-can`, per the house pattern. **Nothing here is built yet** — Track H is the plan, not a changelog.

### 2026-08-15 20:45 EET — Claude — Test-user picker is now backend-gated on `APP_ENV=local`; the 2026-08-11 20:30 note's mechanism is STALE

Built per the plan below. The panel's visibility moved from `import.meta.env.DEV` to `GET /api/dev/test-users`, which 404s unless `app()->environment('local')` — so **the earlier note's "Rollup drops the chunk, so the emails cannot ship" argument no longer describes the code**, and hoisting the import is no longer the hazard it warns about. The replacement guarantee is different but stronger: the component ships (a 2.66 kB lazy chunk, only fetched once the API says yes) and carries **no accounts at all** — list, shared password and seed command all arrive in the response, sourced from `TestUserSeeder::TEST_USERS`, which is now `public` and gained an English label per row. `DevTestUserPicker.vue` is deleted; the file is `frontend/src/components/TestUserPicker.vue`. Frontend + one thin controller — no migrations, no permission or seeder-data changes, no touching `AuthController`.

**Two decisions not to re-litigate.** (1) The environment check is a runtime `abort_unless` in the controller, *not* conditional route registration: `php artisan route:cache` run on a local machine and shipped would carry a boot-time condition with it, and the runtime check also makes the hidden case assertable from a suite that runs as `APP_ENV=testing`. (2) 404, not 403 — a 403 would confirm that a path enumerating known-password logins exists. This is the only endpoint in the system that enumerates accounts, and it stays a 404 everywhere that matters, so `AuthController::login()`'s account-enumeration defence is intact. The response also flags per account whether the row actually exists, so an unseeded database says "غير مزروع" plus the artisan command instead of answering "bad credentials" on every click.

Verified: new `tests/Feature/DevTestUserTest.php` (4 tests — 404 in testing *and* production, the local 200 listing all twelve with the inactive account still present, the unseeded case, and no `$2y$` hash anywhere in the payload); full suite **84 tests / 551 assertions** green; Pint clean on all four touched PHP files; `npm run build` passes and `dist/` greps clean for `abusaleem.test`. Smoke-tested live against Homestead: `http://abusaleem.test/api/dev/test-users` returns 12 users, all `seeded: true`. **Not verified: no browser this session** — the panel's rendering with the new `(غير مزروع)` flag has not been looked at. One consequence worth knowing: the production login page now fires one request that 404s on every visit; that is the design, not a bug.

### 2026-08-15 20:20 EET — Claude — Test-user picker moves from `import.meta.env.DEV` to the backend's `APP_ENV` — plan

The user wants the login screen's test-account panel shown whenever **Laravel's `APP_ENV=local`**, not only under `npm run dev`. Those are different machines' opinions: `import.meta.env.DEV` is decided when the SPA is *built*, so a `dist/` served against a local API can never show the panel today. Moving the decision to the server means the SPA has to *ask*, so: new public `GET /api/dev/test-users` returning 404 unless `app()->environment('local')`, and `LoginView` renders the panel only when that call succeeds. **The account list stops being hard-coded in the Vue component and comes from `TestUserSeeder::TEST_USERS`** (made `public`, plus an English label per row) — one source of truth, which also retires the "keep the two in step" hazard the 2026-08-11 note flagged, and keeps twelve known-password addresses out of the production bundle even though the component itself now ships in it. The endpoint also reports, per account, whether the row actually exists in the database, so an unseeded environment says so instead of answering "bad credentials" on every click. The guard is a runtime `abort_unless` in the controller rather than conditional route registration, deliberately: routes cached on a local machine and shipped to production would carry a boot-time condition with them, whereas the runtime check re-reads the environment wherever it runs — and it is also what makes the behaviour testable from PHPUnit (`APP_ENV=testing` there, so the default assertion is the 404). Verification: new `tests/Feature/DevTestUserTest.php` (404 outside local, 200 + shape inside local, no password hashes in the payload), full suite, Pint, `npm run build`, and a grep of `dist/` for `abusaleem.test` test addresses. No migrations, no seeder-data change beyond the added label, no permission/seeder changes.

### 2026-08-15 20:05 EET — Claude — Real API URL set, `deploy.zip` built; the placeholder note below is now STALE

The user hit the module-specifier black screen again and had `frontend.zip` in the repo root — I unzipped it: the whole **source** tree, 1805 `node_modules/` entries and the source `index.html`, i.e. exactly the wrong artifact, not a code bug. **The `.env.production` placeholder flagged in the 2026-08-11 note is resolved**: `VITE_API_BASE_URL` is now `https://transactions.scco.ly/api`, rebuilt, and verified baked into `dist/assets/_plugin-vue_export-helper-*.js` with no `abusaleem.test`, no `api.example.com` and no `DevTestUserPicker` chunk anywhere in `dist/`. Config + build only — no Vue source, no backend code, no migrations, no seeders.

**The deployment topology is now known and is the thing not to re-derive: Laravel *is* `transactions.scco.ly`** (that subdomain's docroot is Laravel's `public/`, and `/api/*` are its own `routes/api.php` routes) — the SPA goes on a **separate** subdomain. So this is cross-origin: `config/cors.php` allows exactly **one** origin from `env('FRONTEND_URL')`, and it must match the browser's `Origin` header character-for-character (scheme + host, no trailing slash, no path) or every request dies at preflight. `FRONTEND_URL` was missing from `.env.example` entirely — added there with that warning, since an unset value silently falls back to `http://localhost:5173`. Bearer tokens in `localStorage` mean no `SANCTUM_STATEFUL_DOMAINS` / session-cookie concerns.

`deploy.zip` (repo root, 433 KB, 68 entries) holds the **contents** of `dist/` — `.htaccess`, `index.html`, `favicon.svg`, `icons.svg`, `assets/` — for extracting straight into the SPA subdomain's docroot. **Not verified: still no server this session**, so the `.htaccess` rewrite has never been exercised against live Apache; the check after uploading is a hard refresh on a sub-route like `/departments`. `frontend.zip` and `deploy.zip` are both untracked in the repo root and I left them — `frontend.zip` is the bad upload and is safe to delete.

### 2026-08-11 22:35 EET — Claude — cPanel deploy fixed; `.env.production` still holds a PLACEHOLDER URL

Built per the plan below. **Frontend only — no backend, no migrations, no seeders, no Vue logic changes.** New `frontend/public/.htaccess` (SPA history fallback + cache policy), `frontend/.env.production`, `frontend/DEPLOYMENT.md`, and a durable pointer in AGENTS.md's build section. Verified against a real `npm run build`: `.htaccess` lands in `dist/` (Vite does copy dotfiles out of `public/`), `.env.production` overrides `.env`, and `abusaleem.test` no longer appears anywhere in the bundle.

**`frontend/.env.production` ships with `https://api.example.com/api`, which is a placeholder and will not work.** The user must replace it with the real API origin and rebuild — the value is baked into the JS at build time, so editing anything on the server has no effect. The matching backend step is `FRONTEND_URL=<spa origin>` in the Laravel `.env`; `config/cors.php` already reads it, so that is configuration, not code.

Two details worth not re-deriving. (1) The `.htaccess` has **no `RewriteBase`**, deliberately — every rule is relative to wherever the file lands, so the same file works at a domain root, on a subdomain, or in a subfolder. The `-f`/`-d` passthrough must stay *above* the catch-all or Apache would answer `/assets/*.js` with HTML, which the browser rejects on MIME type. (2) `index.html` is pinned `no-cache` while fingerprinted assets are `immutable` — it is the only unfingerprinted file, so a cached copy would keep pointing at a previous build's asset URLs after a redeploy.

Also fixed while here, in `LoginView.vue`: the connection-failure message told the reader to "make sure abusaleem.test is running", which is meaningless to a real user. It is now an `import.meta.env.DEV` ternary — the dev hint stays on a dev machine, and the internal hostname is folded out of the production bundle.

**Not verified: no server. The `.htaccess` rewrite and cache headers are correct by construction but have not been exercised against a live Apache** — the thing to actually check after uploading is a hard refresh on a sub-route like `/departments`. If the SPA ever moves into a subfolder rather than its own subdomain, `vite.config.js` needs `base` set; DEPLOYMENT.md covers it.

### 2026-08-11 22:10 EET — Claude — cPanel deployment plan (the "Failed to resolve module specifier 'vue'" black screen)

The user deployed to cPanel and got a black screen plus `Uncaught TypeError: Failed to resolve module specifier "vue"`. **Not a code bug — the source `frontend/` tree was uploaded instead of `frontend/dist/`.** Source `index.html` ends in `<script type="module" src="/src/main.js">`, and `main.js` opens with a bare `import … from 'vue'`; a dev server rewrites bare specifiers on the fly, plain Apache does not, so the browser fails on the first import and nothing ever mounts. Fix is procedural (upload `dist/`), but three real gaps make a correct upload fail anyway, and those are the implementation scope: (1) **no SPA history fallback** — the router is `createWebHistory()`, so a refresh on `/departments` asks Apache for a file that doesn't exist → 404; adding `frontend/public/.htaccess` (copied verbatim into `dist/` by Vite) with a rewrite to `index.html`, written **without `RewriteBase`** so it works at a domain root or in a subfolder unchanged. (2) **`VITE_API_BASE_URL` is baked in at build time** and currently reads `http://abusaleem.test/api`, a Homestead-only host that does not resolve for a visitor — adding `frontend/.env.production` as the build-time override. (3) documenting the deploy in `frontend/DEPLOYMENT.md`. The user confirmed: SPA on its **own subdomain** (so absolute `/assets/…` paths resolve correctly — **no `base` needed in `vite.config.js`**) and the API on a **separate domain**, which means CORS matters; `config/cors.php` already reads `env('FRONTEND_URL')`, so that is a backend `.env` value, not a code change. No migrations, no seeders, no Vue source changes.

### 2026-08-11 20:30 EET — Claude — One-click test-user login built; it is dev-build-only by construction

Built per the plan below. New `frontend/src/components/DevTestUserPicker.vue` (the twelve `TestUserSeeder` accounts, each one click) plus ~20 lines in `LoginView.vue`. **Frontend only — no backend, no migration, no seeder change, no new locale keys** (the panel's copy is a local `COPY.ar/COPY.en` map inside the component, so dev-tool strings stay out of the shipped locale bundles).

**The exclusion mechanism is the load-bearing part, so don't "tidy" it into a plain import.** `LoginView` reaches the panel through `defineAsyncComponent(() => import(...))` inside an `import.meta.env.DEV ? … : null` ternary. Vite replaces `DEV` with the literal `false` in a build, Rollup drops the dead branch, and the chunk is never emitted — I verified by grepping `dist/` after `npm run build`: no `DevTestUserPicker` chunk and no test email anywhere. Hoisting that import to the top of the file, or swapping the ternary for a `v-if`, would put twelve live addresses whose password is literally `password` into the production bundle. The component's own docblock says this too.

Clicking a row fills the real `email`/`password` refs and calls the existing `submit()` rather than posting on its own, so the shortcut runs the same `auth.login()` + error handling as typing — which is why the deliberately inactive account shows its "غير مفعّل" refusal instead of failing silently. The list is a hand-mirror of `TestUserSeeder::TEST_USERS` (an API that enumerates accounts would undo the account-enumeration defence `AuthController::login()` is carefully written to have); **keep the two in step if that seeder list changes.** The panel also states the shared password, the `throttle:6,1` limit (six accounts in a row will 429 — it looked like a bug until it said so), and the `db:seed --class=TestUserSeeder` command for a database that hasn't been seeded.

Verified: `npm run build` passes and leaks nothing; the dev server compiles both SFCs (HTTP 200 on the transformed modules). **Not verified: no browser this session, so the panel has not been looked at** — the layout (`.login-page` changed from a centring grid to a flex column so the card and panel stack) and the twelve-row scroll cap are calculated, not observed.

### 2026-08-11 20:15 EET — Claude — One-click test-user login (dev only) — plan

Making `TestUserSeeder`'s twelve accounts one-click on the login screen, so a QA pass through TEST_PLAN.md doesn't mean retyping `r05.manager@abusaleem.test` / `password` forty times. **Frontend only — no backend, no migration, no seeder change**; the account list is a mirror of `database/seeders/TestUserSeeder.php`, not a new API (an endpoint that enumerates accounts would be a real production hazard, and the list is static test data anyway). New `frontend/src/components/DevTestUserPicker.vue` holding the twelve entries; `LoginView.vue` pulls it in through `defineAsyncComponent` inside an `import.meta.env.DEV` ternary, so Rollup drops the branch **and its chunk** from `npm run build` — the panel and the known-password emails are not merely hidden in production, they are absent from the bundle. Clicking a row fills the real form fields and calls the existing `submit()`, so the one-click path exercises exactly the same `auth.login()` code as typing. The inactive account stays in the list on purpose (its 422 refusal is a thing to test), flagged so its failure doesn't read as a broken button. One gotcha to surface in the UI: `/api/auth/login` is `throttle:6,1`, and clicking six accounts in a row will hit 429 — the panel says so rather than letting it look like a bug. Verification: `npm run build`, plus grepping the built assets for `abusaleem.test` test emails to prove the exclusion.

### 2026-08-11 19:40 EET — Claude — Dark mode complete; colour literals are now a bug

Built per the plan below. **Frontend only — no backend, no migrations, no seeder changes.** New `frontend/src/lib/theme.js` owns the choice (`abs_theme` = `system|light|dark`, default `system`); the palettes live in `style.css`. The rule that matters going forward is now in AGENTS.md: **a hard-coded colour in a component is a bug**, because it is invisible to the theme. I replaced ~150 of them across 14 views/components.

**`--color-nav` no longer means what it used to.** It is now strictly the sidebar SURFACE; `--color-brand`/`--color-on-brand` is the button FILL pair and `--color-brand-text` is the accent green for TEXT. One green couldn't do all three in dark mode — a green dark enough to sit behind white sidebar labels is invisible as heading text on a dark page. If you write `color: var(--color-nav)` on a page surface it will look right in light mode and be unreadable in dark, which is exactly the mistake this split exists to prevent. Same shape as the pre-existing `--color-primary`/`--color-on-primary` gold pair.

Two details worth not re-litigating. (1) The dark palette is declared **twice** — `:root[data-theme='dark']` and `@media (prefers-color-scheme: dark) { :root:not([data-theme='light']) }` — so a visitor on a dark OS gets dark on the first painted frame with no JS involved, and someone who explicitly picks light keeps it. I verified the two blocks are declaration-for-declaration identical (63 each); keep them that way. `applyTheme('system')` **removes** the attribute rather than writing a resolved value, which is what hands control back to that media query. (2) Three whites are deliberately still literal and commented as such: `SignaturePad`'s canvas, `ApprovalTrail`'s signature `<img>` background (the signature is a stored document drawn in dark ink on white — theming it would erase it), and everything inside `AppSidebar`, which sits on a surface that is dark in both themes.

Also added: `color-scheme` on `:root` (without it a native `<select>` popup and the default scrollbar stay white in dark mode), a global `input/select/textarea { color: var(--color-foreground) }` because form controls don't inherit colour and several screens set a background without one, and a `@media print` block that **flattens the dark palette back to ink-on-white** — printing the decisions register or a guide article from dark mode would otherwise be light grey text on a background the printer won't render.

Verification: `npm run build` passes; a token cross-check found every `var(--color-*)` used anywhere is defined (the only undefined one is `--status-color`, which is set inline from the DB status colour); and I drove `theme.js` against a stubbed DOM to confirm the four states (fresh visit follows the OS with no attribute, an OS flip updates a `system` user live, the toggle moves off `system` in the direction the user can see, `system` clears the attribute). **Not verified: no browser was available this session, so nothing has been looked at.** The contrast targets are calculated, not observed — worth a pass over the screens in both themes, particularly the dashboard's bars and the audit log's action badges. The login screen has no toggle (it's outside `AppLayout`); it follows the OS, or the choice made earlier inside the app.

### 2026-08-11 19:02 EET — Claude — Dark mode implementation plan

Adding a light/dark theme to the SPA. **The design-token layer already exists** (`frontend/src/style.css`), so the work is 90% making the components actually use it: a survey found ~150 hard-coded colour literals across 14 views/components (`#fff` card backgrounds, `#0f5132` headings, `#b91c1c`/`#fef2f2` alerts, `#d1d5db` input borders), and every one of them would stay light-mode under a themed `:root`. Plan: (1) restructure `style.css` into a light palette plus a dark override declared **twice** — `:root[data-theme='dark']` for an explicit choice and `@media (prefers-color-scheme: dark) { :root:not([data-theme='light']) }` for the untouched default — so the OS preference works with no JS and there is no first-paint flash; the two blocks must stay in sync. (2) Split `--color-nav` into three brand tokens, because one green cannot be both a sidebar background and readable body text on a dark surface: `--color-nav` stays the sidebar surface, `--color-brand`/`--color-on-brand` is the primary-button fill pair, and `--color-brand-text` is the accent green for headings/links (dark mode lightens it to `#5cc98e`). (3) Add `--color-danger|success|warning|info-{fg,bg,border}` triples and `--color-overlay`, collapsing the several near-duplicate reds/ambers now in the views onto one set each. (4) New `frontend/src/lib/theme.js` mirroring `i18n/index.js`'s shape — a stored preference of `system|light|dark` under `abs_theme`, applied at import time, with a `matchMedia` listener so a `system` user follows the OS live; the topbar toggle sits next to the language switch and flips to the opposite of the *resolved* theme. (5) Sweep every view/component replacing literals with tokens. **Two whites stay hard-coded on purpose**: `SignaturePad`'s canvas fill and `ApprovalTrail`'s signature `<img>` background — the signature is a stored document artefact drawn in dark ink, so themeing its background would erase it. Verification: `npm run build`, plus a manual pass over every screen in both themes and both locales. No backend changes, no migrations.

### 2026-08-02 15:40 EET — Claude — Test users for every role + TEST_PLAN.md

Added `database/seeders/TestUserSeeder.php` (12 accounts, one per role plus three extra R04 seats, a dual-role R03+R04 account and an inactive one; all password `password`, all `@abusaleem.test`) and [TEST_PLAN.md](TEST_PLAN.md), a 16-section manual QA script. **The seeder is deliberately NOT wired into `DatabaseSeeder`** — run `php artisan db:seed --class=TestUserSeeder` explicitly, so a production seed can never mint known-password logins. It is idempotent and uses `sync()` (not `syncWithoutDetaching()` like `AdminUserSeeder`) because the role list in the file *is* the definition of these accounts — re-running must correct a role someone changed by hand rather than accumulate it. Already applied to the real MySQL: 13 users, 14 `role_user` rows, stable across three consecutive runs.

**Three R04 seats is load-bearing, not padding.** `DecisionController::record()` returns 422 on both a tie and zero votes, so with a single committee member every vote is head-vs-member and ties constantly — the Stage 21 decision path is effectively untestable. Three members make a 2-1 plurality reachable. Likewise `multi.role@abusaleem.test` (R03+R04) exists solely so a regression of `User::screenPermissions()` from union to intersection would have something to fail against; I verified it resolves `decisions.can_approve = true`, which is an R03-only grant.

**Gotcha worth knowing: `phone` cannot be set through the Users API.** It is `$fillable` on `User` and the column exists, but neither `StoreUserRequest` nor `UpdateUserRequest` accepts it — the only paths that write it are this seeder and the notification-preferences screen. That is why the seeder sets it directly; without a phone the Stage 23 SMS channel is dropped at resolution time and can't be exercised at all. Not changed, just documented in TEST_PLAN.md §14.

Verified over HTTP against Homestead rather than asserted: each role's `GET /api/screens` returns a different sidebar, and **each sees exactly its own approval screen** — R01/R04 eleven screens with none, R02/R03/R05/R06 twelve with one, R07 twelve with `authority_approval` + `final_approval` but **no `transaction_intake`**, R08 all 23. `inactive.user@` is refused with its own message. Full suite still **80 tests / 538 assertions** green and Pint clean on the new file — note the `defects` entries in `.phpunit.result.cache` are stale, the suite passes clean. A browsable copy of the plan is published as an Artifact; TEST_PLAN.md stays the source of truth.

**The plan is bilingual: [TEST_PLAN.ar.md](TEST_PLAN.ar.md) is the Arabic edition**, same 16 sections and same content, cross-linked from the English file's header. Arabic is the app's primary locale, so that is the copy to hand to an actual tester; keep the two in step if either changes. Both are also published as Artifacts (separate URLs, the Arabic one RTL).

### 2026-08-02 14:15 EET — Claude — Stages 25–27 complete; `placeholderScreens` is gone

Built per the plan below, and **STAGE_PLAN.md now has a TRACK G with Stages 25–27** so the stage numbers stay the source of truth. Two migrations (`backups`, `guide_articles`), both applied to the real MySQL — Homestead was reachable this session. **Zero seeder changes across all three screens**, which is the part worth remembering: every action they needed was already granted when `ScreenRolePermissionSeeder` was first written, so if a future screen seems to need a permissions change, check the matrix first. `frontend/src/views/PlaceholderView.vue` and the `placeholder.*` locale keys are **deleted** — every seeded screen now has a real component, and `placeholderScreens` no longer exists in the router. `style.css` gained a global `@media print` block; the load-bearing rules there are releasing `.shell`'s `height:100vh`/`overflow:hidden` and `.content`'s scroll, without which printing any screen gives you page one and nothing else.

**Stage 25** — `App\Services\DecisionEligibility` now owns the three vote-eligibility rules; `DecisionController::vote()` was refactored to call `reasonBlockingVote()` and the new `/decisions/pending` worklist is built from `pendingVotesQuery()`, which is the *same three conditions* as SQL. Do not let those two drift: a worklist offering an item the vote endpoint refuses is the specific bug this class exists to prevent. The register export reuses Stage 24's `ReportDocument`/`ReportExporter` untouched — a new document, not a new writer, exactly as that split intended. Export sits behind `decisions,export` (R08 only, since the screen seeds `print` to everyone but not `export`).

**Stage 26** — no restore, by the user's decision; the screen says so in `backup.restoreNote`. `App\Contracts\DatabaseDumper` is bound from a driver map in `AppServiceProvider` (mirroring Stage 23's `SMS_DRIVERS`) but **deliberately has no fallback** — a wrong-driver dumper would produce an archive that only fails when someone tries to restore from it, so it aborts at resolve time instead. `MysqlDumper` passes the password as `MYSQL_PWD` in the process environment, never argv. **Two gotchas for the next person:** (1) `mysqldump` is *not* on this host's PATH — it lives at `C:\xampp\mysql\bin\mysqldump.exe`, and I added `BACKUP_MYSQLDUMP_PATH` to `.env` (single-quoted; dotenv rejects backslashes inside double quotes with "unexpected escape sequence") and documented it in `.env.example`. (2) `Backup::create()` records a `failed` row *before* rethrowing, so a broken dump path shows up as a red row with the reason on the screen instead of vanishing into a 500 — I verified this by running `backup:run` before setting the path.

**Stage 27** — `guide_articles` on the `Template` model's bilingual shape. `GuideArticle` declares `protected $attributes = ['is_active' => true, 'sort_order' => 0]` because otherwise the 201 body answers `is_active: null` for a row the database has as published. `index()` hides `is_active = false` drafts from anyone lacking `user_guide,can_edit`. Article bodies render as **plain-text paragraphs, never `v-html`** — the content is API-editable and this is the one screen every role lands on, so HTML there would be a stored-XSS surface aimed at the whole organisation. No starter content was seeded (the user chose the plain DB-backed option), so the screen ships with an empty state.

Verification: full suite **80 tests / 538 assertions** green (21 new across `DecisionRegisterTest`, `BackupTest`, `GuideArticleTest`), `npm run build` passes with all three views lazy-loading, Pint clean on every touched file, no pending migrations, `schedule:list` shows `backup:run` at 01:30. Smoke-tested over HTTP against Homestead: all five new GET endpoints 200, and `decisions/export` produced a 7 KB xlsx and a 57 KB Arabic PDF from real data. `BackupTest` binds a **fake `DatabaseDumper`** and `Storage::fake('local')` — that contract exists precisely so the sqlite suite can test the archive/download/prune/permission surface without a MySQL server, and the fake storage keeps zips out of the repo. **Left behind in the real database from my smoke tests:** one `failed` backup row (the pre-`.env` run) and three real snapshots in `storage/app/private/backups` — delete them from the backup screen if you don't want them. Still unused, unchanged from Stage 24's note: the `transactions,export` grant on the list screen.

### 2026-08-02 13:20 EET — Claude — Stages 25–27 implementation plan (the three screens no stage owned)

The user noticed that `decisions`, `backup` and `user_guide` are still `PlaceholderView` routes. Confirmed: `backup` and `user_guide` appear **nowhere** in STAGE_PLAN.md — they were seeded into `screens` back in Stage 3 and never assigned a stage; `decisions` was left a stub deliberately in Stage 21 because the plan put the voting UI in the meeting screen, but that leaves a menu-visible screen that 404s into a stub for every role. Building all three as new Stages 25–27, which I'll append to STAGE_PLAN.md so stage numbers stay the source of truth. **No seeder changes anywhere** — every action these screens need is already granted by `ScreenRolePermissionSeeder`, which is the check that this scope is the scope they were originally designed for. **Stage 25** (`decisions`): a new `App\Services\DecisionEligibility` holding `reasonBlockingVote()` (the three checks currently inline in `DecisionController::vote()`) and `pendingVotesQuery()` built from the same three conditions — a worklist that lists an item the vote endpoint would reject is worse than no worklist, so both must read one predicate. `DecisionController` gains `index`/`pending`/`filters`/`export`; the export reuses `ReportDocument` + `ReportExporter` unchanged (a new document, not a new writer — that's what Stage 24's split was for). New `/decisions` screen with a register tab and an "awaiting my vote" tab; vote buttons post to the **existing** Stage 21 route, no new write endpoint. First use of the `print` grant anywhere in the app. **Stage 26** (`backup`): on-demand + nightly snapshots, **no restore from the UI** (the user's call — restoring is a server-side operation, not a web button). `App\Contracts\DatabaseDumper` bound from a driver map in `AppServiceProvider`, mirroring Stage 23's `SMS_DRIVERS`; `MysqlDumper` shells to `mysqldump` via Symfony Process passing the password as `MYSQL_PWD` in the environment, never argv. `BackupService` zips `database.sql` + the private attachments/signatures trees, records a `failed` row *before* rethrowing so a broken dump path shows on screen instead of vanishing into a 500. New `backup:run` command scheduled 01:30 with retention pruning. **Stage 27** (`user_guide`): DB-backed bilingual `guide_articles` on the `Template` model's shape, readable by all, R08-editable; bodies render as **plain text paragraphs, never `v-html`** — R08-editable content rendered as HTML would be a stored-XSS surface for every reader. Also empties `placeholderScreens` entirely, so `PlaceholderView.vue` and the `placeholder.*` locale keys get deleted. Verification: three new feature tests (the backup one binds a fake `DatabaseDumper` — that contract exists precisely so the sqlite suite can exercise archive/list/download/prune/permissions without mysqldump), full suite, Pint, `npm run build`, `php artisan migrate` (2 new migrations), `schedule:list`.

### 2026-08-02 12:05 EET — Claude — Stage 24 dashboard KPIs & reports complete

Built per the plan below; **no migrations**, every KPI is derived from existing tables. Two new composer deps, chosen with the user: `phpoffice/phpspreadsheet` (real `.xlsx`) and `mpdf/mpdf`. **Do not swap mPDF for dompdf** — dompdf does no Arabic shaping and prints Arabic as disconnected left-to-right letterforms; I verified mPDF embeds `XBRiyaz` for the Arabic runs, which is the whole reason it's the dependency. `composer audit` reports 3 advisories, all against `laravel/framework` and all pre-existing (fixed in 12.60+, i.e. a major upgrade, out of scope here). One `App\Services\ReportMetricsService` owns every aggregate, and the dashboard, the report table and the exported file all call it with the same filter array — that shared call is what makes a tile and a forwarded spreadsheet unable to disagree. Cycle time and the monthly buckets are computed **in PHP, deliberately**: `DATEDIFF`/`julianday` and `DATE_FORMAT`/`strftime` differ, and a KPI whose value depends on the driver is worse than a slightly less elegant query — if you optimise these into SQL, the sqlite test suite will silently stop matching MySQL. Caching is `Cache::remember` (5 min) under a key containing a generation counter that `App\Observers\ReportCacheObserver` bumps on any `Transaction` save/delete; note `Cache::increment` is a no-op on a missing key for several stores, hence the `Cache::add(...,0)` seed in `flush()`. New `DashboardController` (`GET /dashboard`) and `ReportController` (`/reports/filters`, `/reports/transactions`, `/reports/transactions/export`), plus `GET /audit-logs/export` — which closes Stage 22's open item, the seeded-but-unused `audit_log,export` grant. Export rendering is a `ReportDocument` DTO + `XlsxWriter`/`PdfWriter`/`ReportExporter`, so a third export is a new document, not a new writer. **Gotcha for the next person adding an export request:** a FormRequest cannot declare `format()` or it fatals against `Illuminate\Http\Request::format($default)` — hence `exportFormat()`/`exportLocale()`. `config/cors.php` now exposes `Content-Disposition`, without which the SPA cannot read the server's filename. Frontend: `DashboardView.vue` rewritten from the "who is signed in" placeholder into KPI tiles + CSS-only breakdown bars and a 12-month trend (no chart library added on purpose), new `ReportsView.vue` off the placeholder list, export buttons on the audit log, and a shared `lib/download.js` — exports must go through axios for the bearer token, so `<a href>` will not work, and it decodes blob error bodies back to JSON or a 403 would surface as nothing at all. New `tests/Feature/DashboardReportTest.php` (7 tests, hand-built fixture so every expected number is checkable by reading it). Full suite 59 tests / 403 assertions green, `npm run build` passes, Pint clean on everything touched (the repo-wide `--test` failures are the same pre-existing drift in `app/Models/*` etc., untouched), no pending migrations, and I smoke-tested `/api/dashboard` plus both export formats over HTTP against Homestead — 200s, 7.5 KB xlsx and 60 KB PDF. Not built: the `transactions,export` grant on the list screen is still unused; filtered exports belong on the reports screen.

**One thing I fixed that was not Stage 24:** the real MySQL database was missing Stage 21's `deferred` transaction status *and* its `7→7 defer` workflow transition — the Stage 21 note says Homestead was unreachable at the time and those seeds never landed. `WorkflowTransitionSeeder` would have died on `$statuses['deferred']`, so recording a "defer" committee decision was broken against the real database. I re-ran `TransactionStatusSeeder` and `WorkflowTransitionSeeder` (both idempotent `updateOrCreate`); the DB now has 13 statuses and 36 transitions including the defer row. Worth checking the other seeders against a real DB rather than assuming a green test suite covers them — PHPUnit builds its own sqlite schema and will never catch this class of drift.

### 2026-08-02 11:25 EET — Claude — Stage 24 implementation plan

Building dashboard KPIs & reports per STAGE_PLAN.md. **Two new composer deps**, chosen with the user: `phpoffice/phpspreadsheet` (real `.xlsx`, RTL sheet direction) and `mpdf/mpdf` — dompdf cannot shape Arabic text, so it is not an option for an Arabic-first app; mPDF has built-in Arabic shaping/ligatures. No new tables: every KPI is derivable from `transactions` + `transaction_status_history`. One `App\Services\ReportMetricsService` owns every aggregate so the dashboard cards and the exported report can never disagree — shared filter shape (`date_from`, `date_to`, `department_id`, `type_id`, `status`), and it computes total / pending (open = status not in `final_approved|archived|cancelled|rejected`) / completed / completion % / SLA breaches (`overdue_at` set and still open) / average cycle time, plus breakdowns by status, stage, department and month. Cycle time is measured from `submitted_at` to the earliest `transaction_status_history` row reaching `final_approved`/`archived` and averaged **in PHP**, not with `DATEDIFF`/`julianday`, so MySQL and the sqlite test connection return the same number. Results are cached (5 min) under a key that includes a generation counter bumped by a `Transaction` observer — a plain TTL would show a just-created transaction as missing, which reads as a bug rather than as caching. New `DashboardController@index` (`GET /dashboard`, `dashboard,view`) and `ReportController` (`/reports/filters`, `/reports/transactions` behind `reports,view`; `/reports/transactions/export` behind `reports,export`), with `format=xlsx|pdf` and an explicit `locale=ar|en` so the file matches the screen the user was looking at rather than the server default. Export writing is split into a `ReportDocument` DTO plus `XlsxWriter`/`PdfWriter` returning raw bytes, which is what lets the same document be emitted in either format and lets tests assert on the `PK`/`%PDF` signature. This also closes the open item Stage 22 left behind: `GET /audit-logs/export` behind the already-seeded but so far unused `audit_log,export` grant, reusing the same two writers. Out of scope: the equally unused `transactions,export` grant on the list screen — the reports screen is where filtered exports belong. Frontend: `DashboardView.vue` rewritten from the "who is signed in" placeholder into real KPI tiles + breakdown bars (plain CSS, no chart dependency), a new `ReportsView.vue` replacing the placeholder route, an export button on `AuditLogView.vue`, and a shared `lib/download.js` — exports must go through the axios instance so the bearer token is attached, so a plain `<a href>` will not work. Verification: new `tests/Feature/DashboardReportTest.php` (KPI arithmetic against a hand-built fixture, filters narrowing it, a fresh transaction defeating the cache, report rows, both export signatures/filenames, 403 for a role without `reports,export`, audit-log export), plus the full suite, Pint, `npm run build`. Nothing to migrate. Confirmed while surveying: the two Stage 23 follow-ups are already done — all migrations show `Ran` and all 8 roles now hold `notifications.edit`.

### 2026-08-02 10:40 EET — Claude — Stage 23 notifications complete

Built per the plan below. Backend: three migrations (`notifications` — Laravel's standard database-channel schema; `notification_settings`, sparse so a user with no row falls back to defaults; `users.phone`, nullable), `NotificationSetting` (owns the event registry, the defaults, and `channelsFor()`), an abstract `SystemNotification` whose `via()` is the **only** place preferences are consulted, and six event classes (`transaction_created`, `stage_changed`, `action_required`, `transaction_overdue`, `meeting_scheduled`, `decision_recorded`). Recipients are resolved in one `NotificationDispatcher`: creators get the informational events, and `action_required` goes to whoever holds the role on the non-exception `workflow_transitions` rows out of the *new* stage — the same table `WorkflowService` enforces, so the message and the working button can't drift apart. Hooked into `WorkflowService::transition()` (fires once for every path), `TransactionController::store()`, `FlagOverdueTransactions` (inside the `whereNull('overdue_at')` guard, so a breach is announced exactly once), `MeetingController::store()` and `DecisionController::record()`. **Note for anyone adding a notification:** `$afterCommit` cannot be declared as a property on a `Notification` — `Illuminate\Bus\Queueable` already declares it and PHP rejects the incompatible redeclaration with a fatal error; it is set instead by `NotificationDispatcher::send()` calling `$notification->afterCommit()`. SMS has no gateway: `App\Contracts\SmsSender` bound to `LogSmsSender` via `config('services.sms.driver')`, and `channelsFor()` drops the sms channel entirely for a user with no phone. Read/write API on the `notifications` screen, every method hard-scoped to `$request->user()`. Frontend: `stores/notifications.js` (one shared 60s badge poll owned by the store), a real bell dropdown in `AppTopbar.vue` replacing the hard-coded dot, and `NotificationsView.vue` replacing the placeholder route. `NotificationSetting` was deliberately **not** added to `AuditLog::AUDITED_MODELS` — personal preferences are low-consequence and would bury real activity; revisit if that turns out to be wrong. New `tests/Feature/NotificationTest.php` (6 tests) covers the done-when directly (a transition notifies creator + next actor on the enabled channels *only*), stored preferences narrowing channels, muting an event entirely, SMS gated on a phone number, real end-to-end in-app delivery, cross-user scoping, and preference validation. Full suite 52 tests / 337 assertions green, `npm run build` passes, and Pint is clean on everything new — the one reported failure is `app/Models/User.php`, which I verified fails identically on a stashed tree, i.e. it is the pre-existing repo-wide drift, not this change.

**Two things still to run when Homestead is back up** — `192.168.10.10:3306` is refusing connections again (same as the Stage 21 note), so `php artisan migrate` could not be applied to the real MySQL database; PHPUnit is unaffected since it runs on in-memory sqlite, which is also what proves the three new migrations are valid. (1) `php artisan migrate`. (2) `php artisan db:seed --class=ScreenRolePermissionSeeder` — Stage 23 adds `edit => '*'` on the `notifications` screen, and **without that reseed every non-R08 user gets a 403 when marking a notification read or saving preferences**, which will look like a broken bell rather than a permissions problem.

### 2026-08-02 09:56 EET — Claude — Stage 23 implementation plan

Building notifications per STAGE_PLAN.md. Three migrations: Laravel's standard `notifications` table (the in-app channel's store), `notification_settings` (`user_id`, `event_type`, `in_app`/`email`/`sms` booleans, unique per user+event — a missing row means "use the defaults", so no backfill is needed for existing users), and a nullable `phone` on `users` (the SMS channel has nowhere to send without one). Six event types, each with its own notification class over a shared abstract `App\Notifications\SystemNotification` (`implements ShouldQueue`, `$afterCommit = true`): `transaction_created`, `stage_changed`, `action_required`, `transaction_overdue`, `meeting_scheduled`, `decision_recorded`. The base class's `via()` is the single place `notification_settings` is honoured — it maps enabled flags onto `database` / `mail` / a custom `SmsChannel`, which is what makes "enabled channels only" true for every event without each class restating it. Notification constructors extract scalars from the models they're handed rather than holding the models, so a queued payload can't go stale or drag relations through serialization. SMS has no real gateway: `App\Contracts\SmsSender` with a `LogSmsSender` default bound in `AppServiceProvider`, selected by `config('services.sms.driver')` — a real gateway is a one-line binding swap, and `via()` drops the sms channel for a user with no phone rather than queueing a message that can't be delivered. Recipient resolution lives in one `App\Services\NotificationDispatcher`: the transaction's creator gets the "your work moved" events, and whoever holds the role configured on the non-exception `workflow_transitions` out of the *new* stage gets `action_required` — so the notification and the button that actually works are driven by the same table. Hook points: `WorkflowService::transition()` (the single write boundary, so every path — detail screen, approval queue, committee decision — fires exactly once), `TransactionController::store()`, `FlagOverdueTransactions`, `MeetingController::store()`, `DecisionController::record()`. Read/write API on the `notifications` screen: `GET /notifications`, `/notifications/unread-count`, `/notifications/settings` behind `notifications,view`; `POST /notifications/{id}/read`, `/notifications/read-all` and `PUT /notifications/settings` behind `notifications,edit` — which means `ScreenRolePermissionSeeder` gains `'edit' => '*'` on that screen, since marking your own notification read is not an admin capability. All those endpoints are hard-scoped to `$request->user()`, so the permission only decides whether the bell works at all, never whose rows are visible. `phone` is deliberately self-service on the preferences screen only (it's the person's own SMS contact detail) — the Users admin screen is left untouched. Frontend: `stores/notifications.js`, a real bell dropdown with an unread badge in `AppTopbar.vue` (replacing the hard-coded dot), and `NotificationsView.vue` replacing the placeholder route — list plus the channel-preference matrix. Verification: new `tests/Feature/NotificationTest.php` asserting a transition notifies on the enabled channels *only*, that clearing every flag sends nothing, that `action_required` reaches the role that can act and not bystanders, SMS skipped without a phone, and the endpoints' own-rows scoping; plus the full suite, Pint, `npm run build`, and `php artisan migrate`.

### 2026-07-31 12:10 EET — Claude — Stage 22 audit log complete

Built per the plan below. Backend: `audit_logs` migration (morph columns deliberately **without** a foreign key, so a deleted record can't take its trail with it), `AuditLog` model, and one generic `App\Observers\AuditObserver` registered in `AppServiceProvider::boot()` over `AuditLog::AUDITED_MODELS` (19 models — the registry lives on the model because the viewer's filter reads the same list). `updated` stores only the actually-changed attributes plus their previous values, skipping `created_at`/`updated_at`, so a no-op save writes no row; `password`/`remember_token` are replaced with `********` on both sides. `AuditLog::withoutAuditing()` wraps `DatabaseSeeder::run()` — an explicit flag rather than a `runningInConsole()` check, because PHPUnit also runs in console and feature tests must still produce rows. Read side is `GET /api/audit-logs` + `/filters` behind `screen.permission:audit_log,view` (screen and grants were already seeded, no seeder changes); the `model` filter speaks short registry keys (`transaction_stage_log`), mapped back to a class server-side, so no client-supplied class string ever reaches the query. Frontend: real `AuditLogView.vue` replacing the placeholder route (filter bar, table, expandable per-row old/new diff, AR/EN labels for models and common field names). **Not built:** the CSV/Excel export the seeded `audit_log.export` grant implies — exports are Stage 24's scope, so that grant is still unused. New `tests/Feature/AuditLogTest.php` (6 tests: seeding writes nothing, create+update diff with actor, no-op save records nothing, password redaction, viewer filters + class-string rejection, 403 for an unrolled user). Full suite 46 tests / 304 assertions green, Pint clean on every touched file, `npm run build` passes, and `php artisan migrate` **did** run this time — Homestead/MySQL is reachable again, and it also confirms Stage 21's `votes`/`decisions` tables had already been applied, so that open item from the note below is closed.

### 2026-07-31 11:30 EET — Claude — Stage 22 implementation plan

Building the audit log per STAGE_PLAN.md. One new table `audit_logs` (`user_id?`, `auditable_type`/`auditable_id` morph, `action` in `created|updated|deleted|restored`, `old_values`/`new_values` JSON, `ip_address`, `user_agent`, timestamps) written by a single generic `App\Observers\AuditObserver` registered in `AppServiceProvider::boot()` against a curated `AUDITED_MODELS` list (transactions, approvals, decisions, votes, stage/status history, departments, users, roles, committees, meetings + their pivots, settings, templates, transaction types, screen role permissions, notes, attachments) — one observer class rather than per-model ones, since the payload it writes is identical everywhere. `updated` writes only the actually-changed attributes (`getChanges()` + the matching `getOriginal()` keys), skipping no-op saves; a `SENSITIVE` key list (`password`, `remember_token`) is redacted rather than stored. A static `AuditLog::withoutAuditing(callable)` toggle wraps `DatabaseSeeder::run()` so `migrate:fresh --seed` doesn't write thousands of bootstrap rows — the flag is explicit rather than a `runningInConsole()` check, because PHPUnit itself runs in console and feature tests must still produce audit rows. Read side: `AuditLogController::index` (paginated, filters by user/action/model type/date range) + `filters()` lookup endpoint, `AuditLogResource`, `Audit\IndexAuditLogRequest` (Arabic messages), both gated by `screen.permission:audit_log,view` — the `audit_log` screen and its `view => '*'` / `export => [R06,R07]` grants are already seeded, so no seeder changes. Model type is filtered by short class name mapped back to FQCN server-side so the API never takes a raw class string from the client. Frontend: real `AuditLogView.vue` replacing the placeholder route (filter bar + table + expandable old/new value diff), router entry moved out of `placeholderScreens`, AR/EN locale keys. Not building the CSV/Excel export that the seeded `export` grant implies — exports are Stage 24's scope. Verification: new `tests/Feature/AuditLogTest.php` (create/update logged with old+new values, password redacted, approval creation logged, viewer filters, 403 without permission), full suite, Pint on touched files, `npm run build`, `php artisan migrate`.

### 2026-07-31 10:40 EET — Claude — Stage 21 committee voting & decisions complete

Built per the plan below: `votes`/`decisions` migrations+models hung off `MeetingTransaction`, `DecisionController` (`vote()` gated by `decisions,add`; `record()` gated by `decisions,approve`), and new routes `POST meetings/{meeting}/agenda/{agendaItem}/votes` and `.../decision`. `record()` tallies votes by plurality, maps the outcome onto `approve`/`cancel`(reject)/`defer` and calls `WorkflowService::transition()` directly inside the same DB transaction that writes the `Decision` row — ties and zero-vote tallies return 422 instead of guessing, matching STAGE_PLAN's "no manual intervention" requirement. Added the new `deferred` `TransactionStatus` and a `[7→7, defer, R03, is_exception, requires_comment]` row to `WorkflowTransitionSeeder` (self-loop, same shape as the existing `cancel` loop) — this also means "Defer" now shows up for free as an exception-action button on the Stage 15 generic transaction-detail page, no extra code needed there beyond the `workflow.actions.defer` locale key. `MeetingController::destroy()` got the guard AGENT_NOTES had flagged as an open item (422 if any agenda item has a recorded decision). Frontend: extended `MeetingDetailView.vue`'s agenda `<li>` in place (no new screen/route) with a vote/tally panel (`decisions.add`, R03/R04) and a record-decision panel with a conditionally-shown `SignaturePad` (`decisions.approve`, R03 only) — the `decisions` screen route stays a placeholder since STAGE_PLAN's "voting UI in the meeting screen" put the actual UI here, not on its own page. New `tests/Feature/DecisionVotingTest.php` (5 tests) covers the approve path (moves to stage 8, writes an `Approval` level-2 row, closes voting), the defer path (stays at stage 7, status `deferred`), a tied vote (422, no `Decision` row), the attendance/membership voting restriction, and the meeting-delete guard. Full suite (45 tests, 307 assertions), Pint on every new/touched file, and `npm run build` all pass. `php artisan migrate` could **not** be run against the configured MySQL/Homestead server — it's not reachable right now (connection timeout to `192.168.10.10:3306`), same as the Stage 10 note — so the two new tables (`votes`, `decisions`) still need `php artisan migrate` once Homestead is back up; PHPUnit itself is unaffected since it runs on an in-memory sqlite connection per `phpunit.xml`.

### 2026-07-31 10:00 EET — Claude — Stage 21 implementation plan

Building decisions & voting → workflow integration per STAGE_PLAN.md. Two new tables: `votes` (one row per committee member per agenda item — `meeting_transaction_id`, `user_id`, `vote` in `approve|reject|defer`, `comment?`, `voted_at`, unique per member+item) and `decisions` (the tallied, binding outcome — `meeting_transaction_id` unique, `outcome`, `votes_*_count` snapshot, `comment?`, `decided_by_user_id`, `decided_at`). Both hang off `MeetingTransaction` (the Stage 20 agenda pivot), exactly where that migration's docblock said Stage 21 would attach. Vote-casting is gated behind the already-seeded `decisions` screen's `add` permission (R03/R04, `ScreenRolePermissionSeeder` already has this row — no seeder changes needed there) and additionally requires the actor to be a `committee_members` row for this meeting's committee AND a `MeetingAttendee` with `attended=true`. Recording the binding decision is gated behind `decisions,approve` (R03 only, matching `workflow_transitions`' `required_role_id` at stage 7) and is done by a new `DecisionController::record()`: it tallies votes by plurality, maps the winning outcome onto an existing/new workflow action (`approve→approve`, `reject→cancel` — reusing the R03 self-loop `cancel` exception already seeded at stage 7, `defer→defer` — a **new** exception row), and calls `WorkflowService::transition()` directly inside the same DB transaction that creates the `Decision` row — ties or zero votes return a 422 instead of guessing. `approve` outcomes still need a signature file (WorkflowService's existing `approvalLevel`/`signatureRequired` check, since stage 7's `approve` action is `APPROVAL_LEVELS['receive_from_committee'] = 2`); `reject`/`defer` are exception rows so only need a comment, enforced the same way by `WorkflowService` itself — `DecisionController` does not duplicate that validation. New seed data: a `deferred` `TransactionStatus` row, and a `[7→7, defer, R03, is_exception, requires_comment]` row in `WorkflowTransitionSeeder` (self-loop, same shape as the existing `cancel` loop) — this also means "Defer" will automatically show up as an exception-action button on the existing generic transaction-detail page (Stage 15/16) for free, once the `workflow.actions.defer` locale key is added. `MeetingController::destroy()` gets the guard AGENT_NOTES already flagged as needed: blocked (422) if any agenda item has a recorded `decision`, mirroring `CommitteeController::destroy()`. Frontend: extend `MeetingDetailView.vue`'s existing agenda `<li>` block with a per-item vote/tally/record-decision panel (vote buttons for `decisions.add`, a `SignaturePad` shown only when the live vote tally's leading outcome is `approve`, record button for `decisions.approve`) rather than a new screen — STAGE_PLAN says "voting UI in the meeting screen" and the `decisions` screen code (already a placeholder route) only needs to gate the new endpoints, not host a separate page. Verification: new PHPUnit feature test covering cast-votes → tally → record-decision → transaction moves stage (+ the defer path leaves it at stage 7 with `deferred` status, + tie/zero-vote 422s), plus full suite, Pint, frontend build, and `php artisan migrate`.

### 2026-07-30 22:55 EET — Claude — Stage 20 committees & meetings complete

Built per the plan below: 5 migrations (`committees`, `committee_members`, `meetings`, `meeting_attendees`, `meeting_transactions`), their models, Form Requests, Resources, and `CommitteeController`/`MeetingController`, all riding the existing `meetings` screen's permissions (no seeder changes needed). Scheduling a meeting auto-invites the committee's active members; the agenda supports add/remove/up-down-reorder; attendance is marked per attendee. Added a `search` filter to `IndexTransactionRequest`/`TransactionController::index` (LIKE on reference_number/title) so the agenda builder can look up a transaction to add — reused by the existing `transactions` screen permission, no new grant needed. Frontend: real `MeetingsView.vue` (committees admin + membership + meeting scheduling, replacing the placeholder) and `MeetingDetailView.vue` (`/meetings/:id`, unlisted in the sidebar like `transaction_details`) for the agenda/attendance workspace; router and both locale files updated. New `tests/Feature/CommitteeMeetingTest.php` covers scheduling with auto-invite, agenda add/duplicate-reject/reorder/remove, attendance marking, and the delete-blocked-when-meetings-exist rule. Full suite (35 tests, 253 assertions), Pint on every new/touched file (clean; the pre-existing repo-wide formatting drift Stage 12's notes mention is still there and untouched), `npm run build`, and `php artisan migrate` all pass. Open item for whoever builds Stage 21: `MeetingController::destroy` currently has no history guard (see its docblock) — once decisions/votes hang off agenda items, a held meeting will likely need the same preserve-don't-erase treatment committees already have.

### 2026-07-30 22:10 EET — Claude — Stage 20 implementation plan

Building committees & meetings per STAGE_PLAN.md: migrations for `committees`, `committee_members`, `meetings`, `meeting_attendees`, `meeting_transactions` (the agenda pivot); models + relations; Form Requests (Arabic messages) and Resources per the one-per-write-operation convention. `committees` is master data (soft delete + toggle-active + "preserve, don't erase" block on delete when meetings exist, mirroring `DepartmentController`); `committee_members` enforces one head per committee. Controllers: `CommitteeController` (CRUD + member add/remove) and `MeetingController` (CRUD + agenda add/remove/reorder + attendee add/remove/mark-attended), all gated behind the existing `meetings` screen's permissions since the 22/23-screen sheet has no separate "committees" screen — same pattern as `departments` riding its own screen. A new static lookup `GET /meetings/user-options` (gated by `meetings,view`) returns active users for the member/attendee pickers, since the `users` screen itself is R08-only and committee heads (R03) shouldn't need that broader grant just to pick people. Scheduling a meeting auto-populates `meeting_attendees` from the committee's active members. Adding the agenda-search UI needs a `search` filter (LIKE on reference_number/title) added to `IndexTransactionRequest`/`TransactionController::index` — small, additive, reuses the existing `transactions` screen permission the picker already has. Frontend: real `MeetingsView.vue` (committees admin + meetings list/scheduling, replacing the Stage-20 placeholder route) and a new `MeetingDetailView.vue` (`/meetings/:id`, same `meetings` screenCode, unlisted in the sidebar like `transaction_details`) for the agenda builder (up/down reorder, not drag-and-drop) and attendance marking. Verification: new PHPUnit feature test covering schedule → agenda add/remove/reorder → mark attendance → delete-blocked-with-meetings, plus the full suite, Pint, frontend build, and migration status.

### 2026-07-30 21:55 EET — Codex — Stage 19 e-signature complete

Every new approval now requires a validated canvas-generated PNG on both queue and transaction-detail paths; the private file path is recorded in the atomic approval ledger and compensated on failed workflow moves. Transaction details render the chronological signed approval trail through a bearer-authenticated image endpoint, while legacy Stage 18 rows remain readable with an unavailable-signature state. The configured MySQL migration is applied, and all 32 PHPUnit tests (232 assertions), focused Pint, frontend production build, route/migration checks, and diff validation pass; browser attachment was unavailable for an additional rendered screenshot check.

### 2026-07-30 21:47 EET — Codex — Stage 19 implementation plan

Implement mandatory PNG signature capture on both approval entry points, store the private `signature_path` atomically with each approval, and add an authenticated signature read endpoint plus a visual approval trail on transaction details. Affected areas are the approvals migration/model/resource, workflow and controllers/requests/routes, a reusable Vue signature pad and both approval UIs, bilingual copy, and approval-chain feature coverage. Verify by applying the pending migration, running focused/full PHPUnit and Pint checks, building the SPA, and checking routes and migration status.

### 2026-07-30 21:38 EET — Codex — Stage 18 approval chain complete

Added the atomic `approvals` ledger, six permission-gated approval queues, required decision-grade intake, and strict reviewer/committee/admin/ministry-or-bypass/authority/final sequencing; grades below the type threshold skip ministry while at/above-threshold and legacy null grades take the conservative ministry path. Stage 11 now holds `final_approved` work until a final archival approval, and both queue and transaction-detail approval paths honor the matching screen permission before `WorkflowService` rechecks stage/role under lock. Configured MySQL has the migration applied and the stage/transition map reseeded (11 normal moves and 11 cancellation paths); all 29 PHPUnit tests (211 assertions), focused Pint, frontend production build, migration status, routes, and diff checks pass.

### 2026-07-30 21:16 EET — Codex — Stage 17 SLA and deadline escalation complete

Transaction intake now derives `due_date` from each type's `default_sla_days`; the nightly `transactions:flag-overdue` command backfills legacy dates and records an idempotent `overdue_at` flag only for open transactions. Seeded `deadline_expired` exception transitions let R08 route flagged work to ministry oversight with a mandatory reason, while the detail UI shows the breach and the action only after the sweep flags it. The configured MySQL database has the migration applied and workflow map reseeded; full PHPUnit (23 tests), focused Pint, the frontend production build, migration/schedule checks, and diff validation pass.

### 2026-07-30 21:00 EET — Codex — Stage 16 exception flows complete

Seeded missing-document return (2→1), review rejection (3→2), edit request (4→3), and role-gated cancellation at every open stage; all require a reason, write stage/status history, and cancelled/archived transactions are terminal. The detail API now exposes exception metadata and the bilingual SPA renders warning/destructive buttons with mandatory-reason modals. Configured MySQL has 13 exception rows; all 21 PHPUnit tests, focused Pint, production frontend build, routes, migration status, and diff checks pass.

### 2026-07-30 21:00 EET — Codex — Stage 15 transaction workspace complete

Added the protected transaction detail/transition API, a full SPA detail workspace (current state, stage timeline, attachment metadata/uploads, notes), and workflow buttons computed for the signed-in actor then enforced again atomically by `WorkflowService`. New feature coverage verifies detail data, successful R02 advancement, and an unauthorized-role 422; all 14 PHPUnit tests, focused Pint, production frontend build, routes, and migration status pass. The Stage 14 map only has `forward` and `approve` actions; Stage 16 will seed exceptions such as reject and its UI labels/modal flow, while this screen already renders returned action codes generically.

### 2026-07-30 20:23 EET — Codex — Stage 14 workflow happy path complete

Added and seeded the ten generic stage 1→11 workflow rules plus an atomic, row-locking `WorkflowService` that enforces active actors, configured roles, type-specific overrides, required comments, and append-only stage/status histories. The configured MySQL database now contains the ten ordered happy-path transitions; all 12 PHPUnit tests (including the full workflow walk), focused Pint, migration status, and idempotent reseeding pass. Stage 15 can expose this service through its transaction transition endpoint and translate `WorkflowTransitionException` into a 422 response.

### 2026-07-30 — Codex — Stage 13 intake flow complete

The transaction-intake placeholder is now a full multipart form that creates a stage-1, `new` transaction, records its initial stage/status history, stores up to ten validated private attachments, and returns a locked, yearly department reference (`YYYY-DEPT-000001`). The notes API and reusable `TransactionNotes` component are also ready for the Stage 15 detail screen; frontend build, all 9 PHPUnit tests, routes, Pint on Stage 13 PHP files, and migration status pass. `TransactionStatusHistory` now explicitly maps to the pre-existing singular `transaction_status_history` table, which the new initial history write surfaced.

### 2026-07-30 — Codex — Stage 12 file uploads complete

Added the private `POST /api/transactions/{transaction}/attachments` endpoint, guarded by `notes_attachments.add`, with PDF/DOC/DOCX/JPG/PNG validation and a 20 MB cap; it persists both the private file and attachment metadata. The reusable `FileUpload` Vue component is available at `frontend/src/components/FileUpload.vue` and is currently reachable from each transaction-list row through a permission-gated modal, ready for Stage 13 intake reuse. Full PHPUnit (6 tests), production frontend build, route check, and migration check pass; `vendor/bin/pint --test` still reports pre-existing formatting drift outside this stage, while all Stage 12 PHP files and its route were formatted.

### 2026-07-30 — Codex — Stage 10 settings and templates complete

Added the `settings` key/value registry and bilingual `templates` CRUD, with models, Form Requests (Arabic validation), API Resources, Stage-9-style per-verb permissions, and real Settings/Templates Vue screens with `v-can` action gates. Both placeholder routes now lazy-load their screens; Arabic/English labels were added. `npm run build`, focused Pint, route listing, and an isolated in-memory SQLite migration pass; the configured MySQL/Homestead server was refusing connections, so the real local database still needs `php artisan migrate` after that service is started.

### 2026-07-30 — Claude — STAGE_PLAN.md added, resolves the stage 12/19 mystery

Added [STAGE_PLAN.md](STAGE_PLAN.md), the user's original 24-stage plan doc — it's now the source
of truth for what each stage number means, linked from AGENTS.md's "Staged build-out" bullet.
This retroactively explains two stage numbers that had zero code comments referencing them: stage
12 is the file-upload component (independent, buildable any time after Stage 5) and stage 19 is
e-signature capture on approvals. One wording mismatch to know about: the plan's Stage 1 describes
a `backend/` subfolder, but the actual repo has Laravel at the root (see AGENTS.md's architecture
section) — that ship sailed by the time Stage 1 was actually built, so treat STAGE_PLAN.md as the
goal/scope reference per stage, not literal file-layout instructions.

### 2026-07-30 — Claude — Stage 9 done, heads-up for Stage 10+

Enforcement is real now, both sides, reading `screen_role_permissions` as the single source of
truth. Backend: `User::screenPermissions()`/`hasScreenPermission()` resolve the union across a
user's roles; `CheckScreenPermission` middleware (aliased `screen.permission` in
`bootstrap/app.php`) is applied per HTTP verb in `routes/api.php` as
`screen.permission:<screen_code>,<action>` (e.g. `users,edit`) — `apiResource()` got split into
individual verb routes for `departments`/`users` specifically so each verb can require a different
action. `UserResource` now includes a `permissions` map, but ONLY for the caller's own record
(`$request->user()?->is($this->resource)`) to avoid N extra queries when `UserController::index()`
wraps every other user in the same resource. `/roles` and `/screens` were deliberately left without
a `screen.permission` check — see the comments above them in `routes/api.php` for why. Frontend:
`auth.js` gained `permissions`/`can(screenCode, action)`; the router guard
(`router/index.js`) now blocks navigation when `can(meta.screenCode, 'view')` is false; a new
`v-can="'screen.action'"` directive (`frontend/src/directives/can.js`, registered in `main.js`)
hides buttons the user can't act on — applied to the add/edit/delete buttons on Users, Departments,
and the Save button on Roles & Permissions. When Stage 10+ adds a new screen's CRUD, follow this
same pattern: split verb routes with `screen.permission:<code>,<action>` instead of a bare
`apiResource()`, and add `v-can` to its action buttons.
### 2026-07-30 — Codex — Stage 11 complete and migrated
Stage 11 adds the transactions schema, protected filterable/paginated list API, and the SPA list screen; its SQLite feature test and production frontend build pass. The configured MySQL server at `192.168.10.10:3306` is reachable and now has all pending migrations through `2026_07_30_000007_create_notes_table` applied.
