# AGENTS.md

Guidance for coding agents working in this repository.

## What this is

Abu Saleem Transactions — an internal workflow/transaction-approval system for
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
  i18n/        → vue-i18n setup; locales/ar.json and locales/en.json
  style.css    → design tokens as CSS custom properties (ported from the SCCO
                 dashboard template's Tailwind @theme — this SPA does NOT use
                 Tailwind; frontend/postcss.config.js is deliberately empty to
                 stop PostCSS walking up into the root Tailwind config)
```

Key architectural facts worth knowing before changing things:

- **Auth**: Sanctum bearer tokens, not cookies/sessions. Token lives in
  `localStorage` on the frontend; `auth:sanctum` middleware on the backend.
  Login is rate-limited (`throttle:6,1`).
- **Departments** are master data (referenced by users/transactions), so
  deletion is blocked whenever children or users still reference a row —
  deactivate (`toggle-active`) instead of delete. This "preserve, don't erase"
  pattern is likely to recur for other master-data resources (roles,
  transaction types, workflow stages) — check for it before assuming a plain
  `destroy()` is safe.
- **Screens/permissions**: `screens`, `roles`, `permissions`,
  `screen_role_permissions` model a menu-and-permission matrix. The frontend
  router already carries a `screenCode` on every route in anticipation of
  checking it against the signed-in user's permissions (not fully wired yet —
  check `frontend/src/router/index.js` for the current state before assuming
  it's enforced).
- **RTL/i18n**: layout CSS uses logical properties (`margin-inline-start`,
  `text-align: start`, etc.), not `left`/`right`, so the UI mirrors
  automatically when `vue-i18n` flips `dir` on `<html>`. Don't introduce
  physical-direction CSS in new components.
- **Staged build-out**: `frontend/src/router/index.js`'s `placeholderScreens`
  list is the map of what's built vs. stubbed. A screen not yet implemented
  renders `PlaceholderView`; as each stage lands, move its entry out of that
  list into a real route + view, matching the `departments` entry as the
  template.

## Conventions

- **Comment the *why*, not the *what***. This codebase leans heavily on doc
  comments that explain non-obvious reasoning (why a check exists, why an
  order matters, why a shortcut is safe) — see
  `app/Http/Controllers/Api/DepartmentController.php` or
  `frontend/src/stores/auth.js` for the house style. Match it: no comments
  that just restate the code.
- **Backend namespacing**: `App\Http\Requests\<Resource>\Store<Resource>Request`
  / `Update<Resource>Request`, one Form Request per write operation.
  `authorize()` returns `true` — access control is via route middleware, not
  request-level checks.
- **Validation error messages are Arabic**, matching the resource's primary
  audience (see `UpdateDepartmentRequest::messages()`). Follow that pattern for
  new resources unless told otherwise.
- **Route registration order matters**: explicit routes (e.g.
  `toggle-active`) are declared *before* `apiResource(...)` so a wildcard
  route can't shadow them.
- **Resources over raw models**: controllers never return Eloquent models
  directly; always wrap in an `Http\Resources` class.
- **Pinia stores use setup-style** (not options-style) — refs for state,
  computed for getters, plain functions returned as actions.
- **Vue components**: `<script setup>` SFCs.
- **No Tailwind in `frontend/`** — style with the CSS custom properties
  defined in `frontend/src/style.css`. The root-level `tailwind.config.js` only
  applies to the unused Laravel Blade scaffold.
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

## Cross-agent handoff (Codex ↔ Claude Code)

This repo is worked on by both Codex and Claude Code, in alternating
sessions, neither of which sees the other's conversation. `AGENTS.md` (this
file) is the shared, stable reference both read automatically — Codex natively,
Claude Code via the `@AGENTS.md` import in `CLAUDE.md`.

For anything short-lived that the *other* agent should know before continuing
— a decision made, a workaround left in place, something left half-done, a
question you couldn't resolve — leave a note in [AGENT_NOTES.md](AGENT_NOTES.md)
instead of cramming it in here. Rules:

- **Check `AGENT_NOTES.md` at the start of a session** for anything the other
  agent left. Claude Code gets it automatically (also imported in
  `CLAUDE.md`); Codex should read it explicitly if not surfaced automatically.
- **Append, don't rewrite history** — newest entry at the top.
- **Format**: `### YYYY-MM-DD — <Codex|Claude> — <short title>` followed by 2-4
  sentences: what happened, why it matters to the next agent, any open
  question.
- **Prune when stale** — once a note's concern is resolved or no longer
  relevant, delete it. This is a handoff log, not a permanent record; durable
  facts belong in `AGENTS.md` proper, and history belongs to git.
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
