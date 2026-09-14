# AGENTS.md

Guidance for coding agents working in this repository.

## What this is

Abu Saleem Transactions — an internal workflow/request-approval system for
a government-style organization (departments, roles, multi-stage approvals,
committees, decisions). Arabic-first (RTL), with English as a secondary
locale.

Two codebases in one repo:

- **Backend** — Laravel 11 API at the repo root. Stateless, Sanctum
  bearer-token auth, PHP 8.2, MySQL. Served locally through Vagrant/Homestead
  at `http://abusaleem.test`.
- **Frontend** — Vue 3 SPA in [frontend/](frontend/), built with Vite. Not the
  root `resources/js` Blade/Vite scaffold — that's the unused Laravel default
  install and should be left alone; the real UI lives entirely under
  `frontend/`.

The two only talk over HTTP: the SPA calls `/api/*` with a bearer token. There
is no server-rendered Blade UI.

## Architecture

```
routes/api.php          → only routing surface that matters (routes/web.php
                           just serves the unused Blade welcome page)
app/Http/Controllers/Api → one controller per resource, thin — validation is
                           delegated to Form Requests, shaping to API Resources
app/Http/Requests/<Res>/ → Store*/Update*Request per resource, validation only
app/Http/Resources/      → response shaping (snake_case DB → JSON payload)
app/Models/              → Eloquent models

frontend/src/
  views/       → one component per route/screen
  layouts/     → AppLayout (sidebar + topbar shell for signed-in routes)
  components/  → shared UI pieces (AppSidebar, AppTopbar, AppIcon, ...)
  stores/      → Pinia stores, setup style (refs=state, computed=getters,
                 functions=actions) — see stores/auth.js as the reference
  router/      → single route table + the auth navigation guard
  lib/api.js   → axios instance, base URL from VITE_API_BASE_URL, token
                 injection, 401 → auto-logout interceptor
  i18n/        → vue-i18n setup (index.js only — the message files are one
                 level up, at frontend/src/locales/ar.json and en.json)
  locales/     → ar.json and en.json; keep the two key sets identical
  style.css    → design tokens as CSS custom properties (ported from the SCCO
                 dashboard template's Tailwind @theme — this SPA does NOT use
                 Tailwind; frontend/postcss.config.js is deliberately empty to
                 stop PostCSS walking up into the root Tailwind config)
```

Key architectural facts worth knowing before changing things:

- **Auth**: Sanctum bearer tokens, not cookies/sessions. Token lives in
  `localStorage` on the frontend; `auth:sanctum` middleware on the backend.
  Login is rate-limited (`throttle:6,1`).
- **The login screen's test-account picker is gated by the BACKEND**, not the
  build: `GET /api/dev/test-users` (`DevTestUserController`) is public but
  answers 404 unless `app()->environment('local')`, and the SPA shows the panel
  only when that call succeeds. So the same `dist/` shows it against a local
  API and hides it against a real one. The account list lives in exactly one
  place — `TestUserSeeder::TEST_USERS`, which the controller reads — so nothing
  needs keeping in step, and no known-password address is ever compiled into
  the bundle.
- **Departments** are master data (referenced by users/requests), so
  deletion is blocked whenever children or users still reference a row —
  deactivate (`toggle-active`) instead of delete. This "preserve, don't erase"
  pattern is likely to recur for other master-data resources (roles,
  request types, workflow stages) — check for it before assuming a plain
  `destroy()` is safe.
- **Screens/permissions**: `screens`, `roles`, `permissions`,
  `screen_role_permissions` model a menu-and-permission matrix, and it is
  enforced on both sides (Stage 9): the API's `screen.permission:<code>,
<action>` route middleware (`App\Http\Middleware\CheckScreenPermission`) and
  the Vue router's `beforeEach` guard (checking `meta.screenCode` against
  `auth.can()`) both read it, so a blocked route and a rejected request always
  agree. New CRUD endpoints should register per-verb routes with
  `screen.permission:<screen_code>,<action>` rather than a bare
  `apiResource()`, and new action buttons should carry
  `v-can="'<screen_code>.<action>'"` (`frontend/src/directives/can.js`).
- **Manager-gated transitions have NO admin override.** A
  `workflow_transitions` row with `requires_submitter_manager` may be used by
  exactly one person: the submitter's own active, non-deleted manager
  (`users.manager_id`). `WorkflowService::actorMayUse()` deliberately has no
  R08 fallback, so a request whose creator has no live manager **cannot be
  delegated, returned or cancelled at all** — it stalls at
  `direct_manager_review` with an empty action list for every actor,
  including an admin, until a manager is assigned on the Users screen. This is
  the rule as specified, not a gap: don't "fix" it by re-adding an override.
  The six gated rows are `forward`/`return_to_employee`/`cancel` at
  `direct_manager_review` and the three `route_to_*` plus `cancel` at
  `administrative_routing`. `RequestVisibility` still lets R08 *see* such a
  request (read-only, so a stall can be diagnosed) — visibility and capability
  are deliberately separate here.
- **RTL/i18n**: layout CSS uses logical properties (`margin-inline-start`,
  `text-align: start`, etc.), not `left`/`right`, so the UI mirrors
  automatically when `vue-i18n` flips `dir` on `<html>`. Don't introduce
  physical-direction CSS in new components.
- **Maintenance console**: the `maintenance` screen (`/maintenance`, R08-only)
  runs deployment commands from the browser, because the production target is
  cPanel shared hosting with no SSH — without it there is no way to run
  `php artisan migrate` after uploading a release.
  `App\Services\Maintenance\MaintenanceCommandCatalog` is a **fixed allowlist,
  and that is the whole security model**: the client sends a command *code* and
  nothing else, so no caller-supplied string ever reaches `Artisan::call()` or
  `Symfony\Component\Process`. Never add a free-text command/arguments field —
  that turns an operations screen into a remote shell. Adding a command means
  adding an entry to that const array. Note the two kinds: `artisan` runs
  in-process and therefore always works, while `shell` (composer/npm) needs
  `proc_open`, which shared hosting frequently disables — `EnvironmentProbe`
  reports which of the two this host can do. `GET|POST /api/maintenance/bootstrap`
  is the **only unauthenticated endpoint that can change the database** — it
  breaks the chicken-and-egg of a console that needs its own migration run,
  is gated on a 24-character `MAINTENANCE_BOOTSTRAP_TOKEN`, 404s on every
  failure, and **self-disables** once the console is reachable. It seeds
  screen *definitions* only on an existing database; running the full
  `DatabaseSeeder` there would reset the whole permission matrix and discard
  anything customised through Roles & Permissions.
- **Staged build-out**: [STAGE_PLAN.md](STAGE_PLAN.md) is the source of truth
  for what each stage number means (goal, what gets built, done-when) —
  consult it before starting or referencing a stage. **Tracks A–L (Stages
  1–84) are all built**; a stage's own bullet states the counts that were true
  when it was written, so verify any figure against the seeders rather than
  quoting it. There is no built-vs-stubbed map any more: Stages 25–27 gave the
  last three screens real UIs and deleted both `PlaceholderView` and the
  `placeholderScreens` list that used to track them (a tombstone comment at the
  top of `frontend/src/router/index.js` records this). **Every seeded screen
  now has a real route and a real view** — `database/seeders/ScreenSeeder.php`
  is the screen roster, and adding a screen means a seeder row plus a route,
  not an entry on a stub list.
- **Employee Affairs Committee process standard**: for anything touching the
  committee/workflow subsystem (`WorkflowService`, `CommitteeStatusService`,
  the `meetings_management` screen group, STAGE_PLAN.md Track I), the
  authoritative process reference is the pair of documents indexed at
  `docs/employee-committee-lifecycle/README.md` (git-ignored, local-only) —
  `دليل إجراءات لجنة شؤون الموظفين` (114-Article procedures manual) and its
  companion 21-stage detailed flow, both dated "الإصدار الأول يوليو 2026".
  Two older, informal design documents drove the meetings/committee UI
  actually built in Track H and remain useful for screen layout, but are
  **not** authoritative on process shape, roles, or stage sequencing where
  they disagree with the pair above.

## Conventions

- **Implementation plans first**: before making implementation changes, write
  a concise implementation plan that identifies the intended scope, affected
  areas, and verification approach. Update the plan if new findings materially
  change the work. add the plan to AGENT_NOTES.md with prefix of date,time and the agent before starting the implementation.
- **Comment the _why_, not the _what_**. This codebase leans heavily on doc
  comments that explain non-obvious reasoning (why a check exists, why an
  order matters, why a shortcut is safe) — see
  `app/Http/Controllers/Api/DepartmentController.php` or
  `frontend/src/stores/auth.js` for the house style. Match it: no comments
  that just restate the code.
- **Backend namespacing**: `App\Http\Requests\<Resource>\Store<Resource>Request`
  / `Update<Resource>Request`, one Form Request per write operation.
  `authorize()` returns `true` — access control is via route middleware, not
  request-level checks. The one resource this pattern doesn't apply to
  verbatim is `App\Models\Request` (the core domain entity, renamed from
  `Transaction`) — see the next bullet.
- **`App\Models\Request` vs. `Illuminate\Http\Request`**: the domain model is
  named plain `Request`, which collides with Laravel's own HTTP request
  class. Any file that needs both imports the framework one aliased —
  `use Illuminate\Http\Request as HttpRequest;` — and leaves
  `use App\Models\Request;` bare, so the domain model's name stays
  consistent everywhere. A route-bound `Request` instance is never assigned
  to a variable named `$request` (it would collide with the conventional
  Form Request parameter of the same name); use `$requestRecord` instead,
  and make sure the route's own wildcard segment matches
  (`{requestRecord}`, not `{request}`) — Laravel's implicit route-model
  binding matches by parameter name, not by type. Form Request classes for
  this one resource drop the doubled word rather than becoming
  `StoreRequestRequest`: `app/Http/Requests/Request/StoreRequest.php`,
  `IndexRequest.php`, `TransitionRequest.php`.
- **Validation error messages are Arabic**, matching the resource's primary
  audience (see `UpdateDepartmentRequest::messages()`). Follow that pattern for
  new resources unless told otherwise.
- **Route registration order matters**: explicit routes (e.g.
  `toggle-active`) are declared _before_ `apiResource(...)` so a wildcard
  route can't shadow them.
- **Resources over raw models**: controllers never return Eloquent models
  directly; always wrap in an `Http\Resources` class.
- **Pinia stores use setup-style** (not options-style) — refs for state,
  computed for getters, plain functions returned as actions.
- **Vue components**: `<script setup>` SFCs.
- **No Tailwind in `frontend/`** — style with the CSS custom properties
  defined in `frontend/src/style.css`. The root-level `tailwind.config.js` only
  applies to the unused Laravel Blade scaffold.
- **Never hard-code a colour in a component.** The SPA has light and dark
  themes, and both palettes live in `frontend/src/style.css` — a literal hex in
  a component is invisible to the theme and will stay light-mode on a dark
  page. Use the tokens (`--color-surface`, `--color-border`,
  `--color-black-*`, `--color-danger|success|warning|info-{fg,bg,border}`,
  `--color-overlay`); if the shade you want isn't there, add a token rather
  than a literal. The brand green is three tokens, not one: `--color-nav` is
  the sidebar SURFACE, `--color-brand`/`--color-on-brand` is the button FILL
  pair, and `--color-brand-text` is the accent green for TEXT on a page
  surface — picking the wrong one is invisible in light mode and unreadable in
  dark. The dark palette is declared twice (`:root[data-theme='dark']` and the
  `prefers-color-scheme` block) and the two must stay identical. The theme
  itself is chosen in `frontend/src/lib/theme.js`, which mirrors
  `i18n/index.js`: a stored preference, one `apply` function, applied at import
  time.
- **New feature comment marker**: when you add a new feature (a new
  route/endpoint, a new UI screen, a new store action, a new significant
  capability — not a bug fix or refactor), add a one-line comment directly on
  or immediately above the entry-point line noting which stage/feature it
  belongs to, e.g. `// Stage 6 — department tree management.` (see the
  `departments` route in `frontend/src/router/index.js` for the pattern).
  This keeps the stage-by-stage build-out traceable in the code itself.

## Build / run / test commands

Backend (run from repo root):

```bash
composer install                # install PHP deps
php artisan migrate              # run migrations (MySQL: abu_saleem, or sqlite for quick local use)
php artisan db:seed              # seed departments/roles/permissions/screens/etc.
composer dev                     # serve + queue:listen + pail logs + vite, concurrently
php artisan serve                # backend alone, if not using Homestead at abusaleem.test
vendor/bin/phpunit                # run the full test suite
vendor/bin/phpunit --filter=Name  # run a single test
vendor/bin/pint                  # fix code style (Laravel Pint)
vendor/bin/pint --test           # check code style without fixing
```

Frontend (run from `frontend/`):

```bash
npm install                      # install JS deps
npm run dev                      # Vite dev server (expects API at VITE_API_BASE_URL, see frontend/.env)
npm run build                    # production build
npm run preview                  # preview a production build locally
```

There is currently no JS test runner or linter configured in `frontend/` —
don't assume `npm test` or `npm run lint` exist.

**Deploying the SPA**: see [frontend/DEPLOYMENT.md](frontend/DEPLOYMENT.md).
Only `frontend/dist/` is deployable — uploading the source tree serves an
`index.html` that points at `/src/main.js`, whose bare `import … from 'vue'`
no plain web server can resolve, giving a black screen. `VITE_API_BASE_URL` is
baked in at **build** time from `frontend/.env.production`, so it cannot be
changed on the server; and `frontend/public/.htaccess` (the `createWebHistory()`
fallback) must reach the server or every sub-route 404s on refresh.

**Run pending migrations whenever there are any** — after creating a new
migration file, or after pulling changes that added one — run
`php artisan migrate` before testing or handing off the backend. Don't leave
migrations unapplied for the next agent or the user to discover as a runtime
error.

## Cross-agent handoff (Codex ↔ Claude Code)

This repo is worked on by both Codex and Claude Code, in alternating
sessions, neither of which sees the other's conversation. `AGENTS.md` (this
file) is the shared, stable reference both read automatically — Codex natively,
Claude Code via the `@AGENTS.md` import in `CLAUDE.md`.

For anything short-lived that the _other_ agent should know before continuing
— a decision made, a workaround left in place, something left half-done, a
question you couldn't resolve — leave a note in [AGENT_NOTES.md](AGENT_NOTES.md)
instead of cramming it in here. Rules:

- **Check `AGENT_NOTES.md` at the start of a session** for anything the other
  agent left. Claude Code gets it automatically (also imported in
  `CLAUDE.md`); Codex should read it explicitly if not surfaced automatically.
- **Add new entries at the top, don't rewrite history** — keep the newest
  entry first.
- **Preserve every previous entry** — adding a handoff note means inserting
  one new note in descending chronological order only. Never remove, replace,
  rewrite, or prune an older note unless the user explicitly asks for it.
- **Format**: `### YYYY-MM-DD HH:MM TZ — <Codex|Claude> — <short title>` followed by 2-4
  sentences: what happened, why it matters to the next agent, any open
  question.
- **Mark stale notes through a newer entry** — if an older concern is resolved
  or no longer relevant, record that resolution in a new top entry instead of
  deleting the original note. Durable facts still belong in `AGENTS.md`
  proper.
- If something you learn is actually durable (a convention, an architectural
  fact, a standing gotcha) — put it in the relevant section of `AGENTS.md`
  itself instead of (or in addition to) a note, so it doesn't get lost when
  the note is pruned.

## Gotchas

- Don't touch `resources/js`, `resources/css`, root `vite.config.js`, or root
  `tailwind.config.js` for frontend work — those belong to the unused Laravel
  default scaffold, not the real SPA in `frontend/`.
- `database/database.sqlite` exists but the real `.env` points at MySQL
  (`abu_saleem`); don't assume sqlite is the active connection.
- `frontend/postcss.config.js` is intentionally empty — don't "fix" it by
  adding Tailwind or removing it; the comment in the file explains why.
