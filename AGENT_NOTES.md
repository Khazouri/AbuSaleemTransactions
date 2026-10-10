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
«قسم شؤون الموظفين». Verified: 4 new `RequestResponsibilityTest` cases (RED then GREEN), full suite green, Pint clean,
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
### 2026-09-26 20:02 EET — Claude — Implementation plan: «المهام المعلقة» lists every pending duty

User: the inbox must hold every action waiting on the user (e.g. a manager approving an employee's request, which is
missing today). All 125 write endpoints were sorted into *pending duties* (state says someone must act next) and
*on-demand* actions; the user chose to cover every duty group. `PendingTaskCollector` gains six sources, each task
carrying an `action` code: `workflow_step` (non-exception rules other than approve/submit, read through
`WorkflowService::availableTransitions` so the inbox and the buttons agree), `overdue` (R08's `deadline_expired`,
minus the ministry self-loop), `meeting_duty` (adopt agenda, convene, record decision, generate/review minutes,
close the sitting), `post_decision` (soundness, execute, both archives, close, approval return/referral, lift
suspension), `open_record` (corrections — never to their recorder — conflicts, special cases, withdrawals) and
`appeal` (each next step + nomination). Each condition is the endpoint's own refusal list as SQL, scoped by
`RequestVisibility`/`MeetingVisibility`. No migration. Verify: MyTasksTest per source, full PHPUnit, Pint, build
(revert `dist`), locale parity, live smoke.

---
### 2026-09-26 19:44 EET — Claude — Dashboard distribution labels show the full name

User (/frontend-design): the status, stage and department bars cut names off. The label was a fixed `minmax(90px, 34%)`
column with an ellipsis. In DashboardView each panel now sizes one shared label column (subgrid) to its longest name,
capped at 60%, and a longer name wraps instead of truncating. CSS only. Screenshots at ar/en 1280 and ar 375 show no
clipped label and no page overflow; build passes (`dist` reverted). (Clock: the machine reads 19:44, earlier than the
entry below; this entry's position shows the order.)

---
### 2026-09-26 20:35 EET — Claude — The manager approves in one click (complete)

Built per the plan below; no migration. The manager's `forward` at `direct_manager_review` now lands on
`receive_and_register` + `routed_to_hr`, and the button reads «موافقة وإحالة» / «Approve & forward». Reseeded on the
real MySQL and confirmed by query. It still holds 2 files at `administrative_routing`; they leave through the kept
`route_to_hr` row. `RequestTimeCard` T1/T2 split at HR arrival when a file never passed routing (new test). Full suite
**754 / 4816**, Pint clean on touched files, build passes (`dist` reverted), parity 2024.

---
### 2026-09-26 20:15 EET — Claude — Implementation plan: the manager approves in one click

User: the employee's manager should approve with one button that moves the request on. Today it is two clicks —
`forward` (to `administrative_routing`) then `route_to_hr` — and since Stage 96 the second has only one option. Plan:
the manager-gated `forward` row lands straight on `receive_and_register` with `routed_to_hr` (the status R12's
`register` row requires). `administrative_routing`'s rows stay, so a file already parked there can still leave
(Stage 102 precedent). `RequestTimeCard` T1/T2 fall back to arrival at `receive_and_register`; the button label
becomes «موافقة وإحالة». Verify: tests updated, full PHPUnit, Pint, build (revert `dist`), parity, reseed.

---
### 2026-09-26 19:55 EET — Claude — Department hierarchy view on the Users screen (complete)

Built per the plan below. One migration (`departments.manager_user_id`), applied to the real MySQL. The Users screen has
a «Table / By department» tab switch; `DepartmentHierarchy.vue` shows the tree, the head card and the staff, with
make-head, a «move to» select, and HTML5 drag onto a tree node (the grip is hidden on touch, where the select is the
path). The tree flattening moved to `lib/departmentTree.js`, shared with DepartmentsView, whose form gained a head picker.
Full suite **753 / 4817** (+7 `DepartmentHeadTest`), Pint clean, build passes (`dist` reverted), parity 2024. A
headless click-through ran make-head, the select and a real drop, then restored the data. That left only audit rows.
`check-layout.mjs`: 440 views, failing only on the pre-existing `/decisions` 1024px overflow. The head is a label only (AGENTS.md).

---
### 2026-09-26 19:10 EET — Claude — Implementation plan: department hierarchy view on the Users screen

User: a department-first view of staff where add/edit/delete is easier; decisions put to the user and answered:
an **explicit department head** (not derived from `manager_id`), plus a «make head» button and drag-and-drop moves
inside the view. Plan: migration `departments.manager_user_id` (nullable FK); `UpdateDepartmentRequest` requires the
head to be an active member of that department; `UserController::update`/`destroy` clear a head slot the user leaves.
The head is a **label only** — approvals still follow each employee's own `manager_id`. SPA: a Table/Hierarchy
switch on UsersView; the new `components/DepartmentHierarchy.vue` reuses the existing modal and the partial
`PUT users/{id}` / `PUT departments/{id}` (no new endpoint); native HTML5 drag-and-drop with a keyboard «move to»
fallback; head picker in the Departments form. Verify: new feature test, full PHPUnit, Pint, build (revert `dist`),
locale parity, `check-layout.mjs`, migrate the real DB.

---
### 2026-09-26 18:50 EET — Claude — Timeline documents redesigned (complete)

Built per the plan below. `TimelineDocuments.vue` now renders the documents of every «سجل سير العمل» entry in both views; each view's own list and its CSS are gone. **Gotcha:** the global `.ltr` helper also sets `text-align: left`, which in Arabic throws a filename to the far side of its row, so the component uses `direction: ltr; justify-self: start` instead. Build passes (`dist` reverted). `check-layout.mjs` fails only on the pre-existing `/decisions` 1024px overflow; it skips `/requests/:id`, so that page was checked by screenshot (ar light and en dark at 1280px, ar at 375px). Not seen rendered: a decision row, since no seeded timeline entry carries one.

---
### 2026-09-26 18:25 EET — Claude — Implementation plan: redesign the documents under each timeline entry

User ran /frontend-design on the «سجل سير العمل» attachment rows fixed just below. Plan: one new `components/TimelineDocuments.vue` used by RequestDetailView and RequestTrackingView (they had drifted — the tracking copy dropped kind and reference). A tinted tray per entry; each row = kind icon (file-text / scale, kind named for screen readers) + label over its LTR reference + folder name at the inline end. A committee decision is the one row in `--color-brand-text`. Tokens only, no new locale keys. Verify: build (revert `dist`), `check-layout.mjs`.

---
### 2026-09-26 18:10 EET — Claude — Timeline attachment rows no longer draw the connector line

Bug fix, CSS only. In «سجل سير العمل» (RequestTrackingView and RequestDetailView) the rail rules were `.timeline li`, which also matched each entry's nested `.entry-documents li`, so every attachment row got the timeline grid, padding and vertical connector line. Both now target direct children only; the workspace also gained the `.entry-documents` styles it never had (it rendered as a bulleted list). Build passes (`dist` reverted).

---
### 2026-09-26 17:55 EET — Claude — Forms beside tables are now modals (complete)

Built per the plan below; frontend only, no PHP touched (so no PHPUnit/Pint run). New `AppModal.vue` wraps the create/edit
forms of Departments, Users, Request Types, Settings, Templates, Meetings (committee form, scheduling wizard, and the
whole «manage members» panel, which was an expanded table row), Appeals (create + upload step, and six action
panels), Agenda Builder's add-item box and Meeting Detail's propose-date and add-to-agenda boxes. Form logic is
unchanged: each view's own open flag drives the modal, and 422s now render inside it. Escape closes and a backdrop
click deliberately does not. New `common.close`; `committees.hideMembers` removed (parity 2007). The convention is in AGENTS.md.
Verified: build passes (`dist` reverted); a headless click-through of all 12 triggers × {en, ar} × {1280, 375} found
every dialog opening, focused and fitting, closing on Escape and returning focus, with no console errors.
`check-layout.mjs` fails only on the pre-existing `/decisions` 1024px overflow. Not exercised: propose-date, because
no seeded meeting is in a proposable state.

---
### 2026-09-26 17:19 EET — Claude — Implementation plan: forms beside tables become modals

User: "all the pages that have a form and something else (table), the form should be a modal". Scope, confirmed with
the user: Departments, Users, Request Types, Settings, Templates, Meetings (committee form, scheduling wizard,
add-member), Appeals (create + six action panels), and the add boxes in Agenda Builder and Meeting Detail. Plan: one
new `components/AppModal.vue` (Teleport, Escape closes, backdrop click does not, focus in/restore) and a
`.modal-wide` plus desktop scrolling in `style.css`; each form is wrapped in it with its state logic unchanged.
Frontend only. Verify: build (revert `dist`), locale parity, `check-layout.mjs`.

---
### 2026-09-26 17:10 EET — Claude — Deliberation requires a convened meeting too (complete)

Built per the plan below; this closes the "Not gated" point in the 16:55 entry. Item state and study-sequence
changes now return `MeetingController::MEETING_NOT_CONVENED` before convening. That check comes before the adoption
check, so `CommitteeOwnActsTest`'s adoption test now convenes its meeting first. A new test there covers the rule.
Full suite **746 / 4798**; Pint is clean on the touched files. No frontend change: the live runner shows the Arabic 422.

---
### 2026-09-26 17:04 EET — Claude — Implementation plan: deliberation requires a convened meeting too

User confirmed the open point from the entry below. Plan: `updateItemState` and `updateStudySequence` in
`MeetingController` refuse (422, new `MEETING_NOT_CONVENED`) until `convened_at` is set, next to their
`AGENDA_NOT_ADOPTED` check. Agenda adoption itself stays allowed before convening, because Art. 12 (أ) 3 puts it
«قبل الاجتماع». Verify: refusal test, fixture updates found by the full suite, and Pint.

---
### 2026-09-26 16:55 EET — Claude — A vote requires a convened meeting (complete)

Built per the plan below. `DecisionEligibility` now refuses a vote with `MEETING_NOT_CONVENED` (422) until
`convened_at` is set, and `pendingVotesQuery()` filters on the same column. New
`StudySequenceTest` case reproduced the bug first (201 before the fix). Full suite **745 / 4793**; Pint is clean on
the touched files. Frontend and locales unchanged. **Not gated:** the study-sequence and item-state endpoints still
check agenda adoption only, so a chair can tick Art. 85 steps before convening; the vote itself still waits for convening.

---
### 2026-09-26 16:48 EET — Claude — Implementation plan: a vote requires a convened meeting

User: "Voting doesn't check that the meeting was convened." Confirmed: nothing on the vote path reads
`meetings.convened_at` (set only by `MeetingReadinessController::convene()`). Plan: one condition in
`DecisionEligibility` — both `reasonBlockingVote()` and its SQL twin `pendingVotesQuery()`, so the endpoint and
the worklist still agree. `record()` needs no check: zero votes are already refused. Fixtures: `RunsStudySequence`
also stamps `convened_at`, and `StudySequenceTest`'s fixture convenes. Verify: a new refusal test, full PHPUnit,
Pint. (Clock note: the machine reads 16:48 while the entry below is stamped 17:25; position gives the order.)

---
### 2026-09-26 17:25 EET — Claude — The مقرر is told of every answer to a proposed date (complete)

Built per the plan below, no migration. Each `respond` sends `meeting_invitation_response` to the committee's
`rapporteur` seat holder (the responder excluded), carrying `response` and `meeting_confirmed`; the last
acceptance says the meeting is confirmed. It links to the meeting through the existing `meeting_id` handling.
Full suite **744 / 4783**, Pint clean on touched files, build passes (`dist` reverted), locale parity 2007.

---
### 2026-09-26 17:10 EET — Claude — Implementation plan: tell the مقرر when a member accepts or declines the date

User: "a notification tells the مقرر when someone declines and approves". Plan: new event
`meeting_invitation_response` (in-app + email, the `meeting_scheduled` defaults) and
`MeetingInvitationResponseNotification`, sent from `MeetingController::respond()` through a new
`NotificationDispatcher::invitationAnswered()` to the committee's `rapporteur` seat holder (never the
responder). The body names the member and the answer, and says so when that answer confirmed the
meeting. Locale label in both files. Verify: extend `CommitteeSinglePathTest`, full PHPUnit, Pint,
build, parity. No migration.

---
### 2026-09-26 16:45 EET — Claude — Stage 102 complete (the single committee path)

Built per the plan below; **no migration**. R02's approve now lands on `receive_from_committee` + `registered`;
the rows out of stages 6–8 are removed by a targeted delete, so a re-seed cleans old databases too. The pending
list is now the agenda's only source. It also takes `reopened_by_appeal` and `reopened_for_representation`:
without them, a file that reopenAtStage() sent back to the committee could never be scheduled again. Seats are
now role-bound and required, only R02 schedules, and there is one meeting per month. New status
`pending_confirmation`, plus the `POST meetings/{m}/respond` endpoint and a «مهامي» inbox source. Ad-hoc
attendees, staff-set RSVPs, admin/emerging items and `nominate` are gone.

Checks: full suite **743 / 4772** (the pre-existing tests were updated in place, each with a comment, and the new
`CommitteeSinglePathTest` was added). Pint is clean, the build passes (`dist` reverted), locale parity is 2006,
and checkbox parity is 362. The real MySQL was reseeded and confirmed by query: 38 rules, 0 touching stages 6–8.
A live HTTP smoke run on Homestead also passed, and its fixtures, tokens, jobs and audit rows were removed.
`check-layout.mjs` fails only on the pre-existing `/decisions` overflow at 1024px.

**Watch:** the live committee #16 cannot schedule until its five seats are filled. Four open items are in
OPEN_ITEMS.md.

---
### 2026-09-26 15:35 EET — Claude — Implementation plan: Stage 102 (the single committee path)

User: after the مقرر approves a request it joins a pending list; the مقرر schedules one meeting a month,
invites the five Art. 10 (أ) seats, picks requests from that list and proposes a date; every member must
accept the date; the sitting discusses and votes — «this path is the only path». **Decisions put to the user
and answered:** drop the extras (one meeting type, only the five seats invited, no administrative/emerging
items) but keep appeal items; R02's approve goes straight to `receive_from_committee` (stages 6–8 unreachable,
rows kept); a decline keeps the meeting `pending_confirmation` and a date change resets every answer; seats
bound to roles chair→R03, legal→R11, hr_director→R12, ministry_delegate→R04, rapporteur→R02 (R10 stays
duty-less). Plan: seeder rewiring + targeted deletes, candidate list widened and made the agenda's only
source, `POST meetings/{m}/respond`, a monthly and all-seats-filled check on `store()`, R12 gains the voting
grants, `meetings,add` → R02 only, SPA pickers switched to `/committee-candidates`. Verify: new
`CommitteeSinglePathTest`, full PHPUnit (baseline 742/4743), Pint, build, parity, reseed on the real MySQL.

---
### 2026-09-26 EET — Claude — R08 unsticks manager-less files; admin re-submit fixed (complete)

Built per the plan below, logic only (no migration, seeder rows, frontend or locale change). `actorMayUse()` gains
`adminMayUnstick()`, and a new `pickRule()` replaces the "exactly one match" check in both `availableTransitions()`
and `transition()`, so the preview and the endpoint cannot disagree. AGENTS.md's manager-gate bullet is rewritten; it also
listed "three `route_to_*`", stale since Stage 96. Full suite **742 / 4743** green, Pint clean on touched files.
**On the real DB nothing changes for the four open files:** their subject (`r01.employee@`) has a live manager
(`r02.reviewer@`), so they are that manager's to move, not the admin's. Not done: stalled files don't notify R08,
and the task inbox doesn't list them; an admin finds them on the requests list.

---
### 2026-09-26 EET — Claude — Implementation plan: R08 may unstick a manager-less request; admin re-submit un-ambiguated

User: "admins can not submit or approve requests". Probed the real DB: both R08 accounts hold every screen grant
but `availableTransitions()` offers them nothing, by design (manager-gated rows have no override; approve rows
are R02/R03/R05/R06/R07). **User decision, put to them and answered "unstick only"** — this narrows AGENTS.md's
"no admin override" rule rather than removing it: `actorMayUse()` lets R08 use a manager-gated row ONLY when
صاحب العلاقة has no live manager (none, inactive, or deleted) and R08 is not an interested party itself. A live
manager still owns the step exclusively; approvals stay role-segregated. **Bug fixed alongside:** an R08 filer
re-submitting its own returned request matched both `submit` rows (creator row + R08 exception) → "ambiguous";
now the normal-path row wins when exactly one non-exception row is allowed. Verify: DirectManagerRoutingTest's
two no-override tests inverted to the new rule, a re-submit test, full PHPUnit, Pint, AGENTS.md bullet updated.

---
### 2026-09-23 EET — Claude — scripts/check-layout.mjs added (the frontend's one automated check)

Built per the plan below; AGENTS.md's build section now points at it. Full run: 29 routes × {375, 768, 1024, 1280,
1440} × {ar, en}, 430 views incl. every tab, ~12 min. **It fails today, for a real reason:** `/decisions` register
at 1024px overflows its card in both locales (logged in OPEN_ITEMS.md, not fixed here). Proven both ways: a bogus
token makes every page fail "landed on /login" instead of passing. **Worth knowing:** with the Chrome now in
`~/.cache/puppeteer`, dropping the proxy no longer reproduces the 09-22 block, so the proxy stays as insurance, and
the URL guard is what actually protects the check. Also: this session's Bash/PowerShell sandbox blocks all network
(even example.com), so the check and `curl abusaleem.test` need the sandbox off; Homestead itself was fine.

---
### 2026-09-23 EET — Claude — Implementation plan: save the headless-Chrome layout check as scripts/check-layout.mjs

Two sessions (2026-09-22) each wrote a throwaway Puppeteer sweep for sideways overflow; one found the SPA
silently bounces to /login when Chrome blocks `localhost:5173 → abusaleem.test`, so a naive sweep "passes" on
the login page. Plan: one `scripts/check-layout.mjs`, **no new dependency** — Puppeteer is resolved from the
npx cache (mermaid-cli already put it there) or `PUPPETEER_PATH`. It logs in as R08 via Node `fetch`, serves
every API call from Node through `setRequestInterception`, reads the static routes from `router/index.js`
(so it cannot drift from the route table), and fails any page whose final URL is not the one requested.
Desktop widths check page- and element-level overflow; 375/768 check the page only (in-card table scroll is
accepted there). Verify: run it against Homestead + `npm run dev`, and prove it fails on an injected overflow.

---
### 2026-09-23 EET — Claude — Vulnerable dependencies patched; now on Laravel 12.61.1

Built per the plan below: laravel/framework 11.55.0 → **12.61.1**, guzzle 7.15.1 → 7.15.5, commonmark
2.8.3 → 2.10.3 (both came in through the same `-W` resolve), and nanoid/postcss via `npm audit fix` (lockfile
only). `composer audit` and `npm audit` both report zero. **No application code changed**: none of the Laravel 12
breaking-change surfaces apply here (no `HasUuids`, no `image` rule, and the `local` disk root is already explicit).
Full suite **741 / 4738**, the same as the Laravel 11 baseline taken first. `npm run build` passes (`dist` reverted).
Pint only flags the pre-existing `scripts/build-guide-pdf.php`. **Gotcha:** 12.61 moved
`ReflectsClosures` to `Illuminate\Reflection`, and the first `composer require` left a stale optimized classmap
(fatal "trait not found"); a plain `composer install` re-dumped it. **The next cPanel release must ship the new `vendor/`.**
The host still needs only PHP ≥ 8.2. Homestead was down, so there was no live HTTP smoke run. Machine-level:
global Composer TLS re-enabled, and Composer itself was self-updated 2.7.6 → 2.10.3; the old version was also flagged by `diagnose`.

---
### 2026-09-23 EET — Claude — Implementation plan: patch vulnerable dependencies (Laravel 11 → 12)

`composer audit`: 15 advisories in laravel/framework, guzzlehttp/guzzle, league/commonmark; `npm audit`
(frontend): nanoid (high), postcss (moderate). **laravel/framework has no fixed 11.x release** — every
advisory is fixed only in ≥12.60/12.61.1 or 13.x, and 13 needs PHP 8.3 (this machine: 8.2.12), so the
fix is `laravel/framework ^12.61.1` plus whatever 12 needs of its companions, then `composer update`
for guzzle/commonmark and `npm audit fix` in `frontend/`. Machine-level: the global Composer config had
`disable-tls=true`/`secure-http=false`; both were unset *before* downloading anything. Verify: both
audits clean, full PHPUnit, Pint, `npm run build` (revert `dist`).

---
### 2026-09-23 EET — Claude — Agent-tooling cleanup complete

Built per the plan below. This file went 1.37 MB → 148 KB: 31 entries kept, 197 moved unedited into
`agent-notes-archive/2026-07|08|09.md` (the script refuses to write unless kept + moved reproduces the original body
byte for byte; entry counts 228 = 31 + 197; a rerun is a no-op). **Search the archive before concluding history is
missing** — AGENTS.md now says so. `graphify-out/20*/` snapshots are untracked (15 files) and ignored;
`.git-ftp-ignore` now skips graphify-out, tests, scripts, `.claude`, the archive and the root planning docs.
`scripts/check-locale-parity.mjs` reports 2011 keys each side; in `--hook` mode it was proven to exit 2 on a probe
key and 0 on non-locale files. Hooks: parity after `Edit|Write`, `graphify update .` on Stop (confirmed it runs).
No PHP/Vue touched, so no PHPUnit/Pint/build run was warranted.

---
### 2026-09-23 EET — Claude — Implementation plan: agent-tooling cleanup (notes archive, ignores, skills, hooks)

User approved: this file was 1.37 MB (~340K tokens) and `CLAUDE.md` imports it every session. Plan: new
`scripts/archive-agent-notes.mjs` keeps it under 150 KB by moving the oldest entries **unedited** into tracked
`agent-notes-archive/YYYY-MM.md` (not `docs/` — that folder is git-ignored, so Codex would never see it); AGENTS.md's
"never prune" rule becomes "never edit or delete; archive past 150 KB". Also: stop tracking dated
`graphify-out/20*/` snapshots, widen `.git-ftp-ignore`, add `scripts/check-locale-parity.mjs`, four project skills
(`plan-stage`, `verify-and-commit`, `new-screen`, `cpanel-release`) and two hooks (parity after locale edits,
`graphify update .` on Stop). Verify: the script's own byte-identity check, parity script exit codes, settings JSON.

---
### 2026-09-22 EET — Claude — A concluded meeting is read-only (complete)

Built per the plan below: one guard in `CheckMeetingMembership` (any non-safe method on a `completed` meeting → 422
`meeting`). New `ConcludedMeetingTest`; `MeetingReadinessTest`'s "convene refused once not scheduled" case now uses a
`cancelled` meeting, since a completed one is refused by the new guard first. Full suite 741/4738 green, Pint clean.
No frontend change: edit buttons on a completed meeting still render and now surface the Arabic 422.

---
### 2026-09-22 EET — Claude — Implementation plan: a concluded meeting is read-only

User: "a meeting that has concluded should not be modified in any way". Plan: every meeting-bound route already
passes through `CheckMeetingMembership` (`meeting.member`), so one guard there refuses any non-read request on a
meeting whose `status` is `completed` (422, Arabic) — covers update/delete, attendees, agenda, readiness, live
runner, votes/decisions, conflict declarations, memo and minutes in one place. **Deliberately exempt:**
`outputs/{item}/execute` (not behind `meeting.member`; execution happens after the sitting by design). `cancelled`
is not treated as concluded. Verify: new feature test, full suite, Pint.

---
### 2026-09-22 EET — Claude — No sideways scrolling on desktop (≥1024px)

User: side-scrolling pages are not acceptable on desktop. Plan and result, CSS only: the eight views that forced
`.data-table { min-width: 720–1150px }` now apply it only under `@media (max-width: 1023px)` (phones keep the
in-card scroll the entry below accepted), `style.css` lets `th` wrap, breaks long cell text anywhere and wraps `.tabs`
from 1024px up, and DecisionsView's 13-number vote tally lost its `nowrap` (it alone held the register at 893px in a
670px card). Verified in headless Chrome: 29 screens × {1024, 1280, 1440} × {AR, EN}, plus every tab of registers
(12), reports, decisions — zero page- or element-level horizontal overflow. **Gotcha:** current Chrome blocks
`localhost:5173 → abusaleem.test` in Puppeteer (`ERR_NETWORK_ACCESS_DENIED`, the disable-features flags didn't help)
and the SPA silently bounces to /login, so a naive sweep "passes" on the login page — proxy the API through
`page.setRequestInterception` + Node `fetch` instead, and assert the URL isn't /login.

---
### 2026-09-22 EET — Claude — SPA made responsive / mobile-friendly (CSS only)

User request: "turn this system responsive and mobile friendly". Plan: the shell already had an off-canvas
sidebar below 1024px and the viewport meta, so this is a CSS pass on what breaks at 375px, not a rebuild.
Done: one `@media (max-width: 640px)` block in `style.css` (tighter `.card-pad`/`.page`/table cells,
stacked `.heading`, single-column `.field-grid`, scrollable modals with wrapping actions, 2.75rem `.btn`,
and a 16px floor on form fields — `!important` because scoped `font: inherit` rules outrank it, and iOS
zooms any field under 16px); `.shell` gets `100dvh`; the topbar tightens and truncates a long title; the
four tables with no horizontal scroll (Users, Departments, both in MeetingsView) now scroll sideways; and
every `auto-fit` grid minimum of 220–300px became `min(Npx, 100%)` so it can't exceed the phone width.
**Deliberately not done:** wide registers stay tables that scroll sideways (card layouts would be a rewrite
of 16 views). `npm run build` passes (`dist` reverted); Verified afterwards in headless Chrome (Puppeteer from
the npx cache): 29 screens × {375, 768}px × {AR, EN} = 116 page views with no page-level sideways overflow, and
the drawer opens/closes on the correct side in both languages. That pass found **a real pre-existing bug, fixed**:
AppSidebar's `:global(html[dir='rtl']) .sidebar.mobile` compiled to a bare `html[dir='rtl']` (Vue drops
everything after `:global()`), so below 1024px in Arabic the whole document was translated off-screen — every
page blank since the 2026-07-30 shell restyle. Now `:root[dir='rtl'] .sidebar.mobile:not(.open)`. **Never put a
descendant selector after `:global()` in a scoped block.** The dashboard trend also scrolls in its own card now. Note found in passing: the print
block's `.shell { height: auto }` is outranked by AppLayout's scoped `.shell[data-v]`, pre-existing and untouched.

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

