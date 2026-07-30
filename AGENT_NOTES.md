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
