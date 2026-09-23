---
name: new-screen
description: Checklist for adding a new screen, or new permission-gated endpoints on an existing screen, in this Laravel + Vue repo - screen seeder row, permission-matrix grants, per-verb screen.permission routes, Vue route with meta.screenCode, v-can buttons, ar/en locale keys, tests and the role test plan. Use whenever a stage adds a screen, a sidebar entry, a CRUD resource or a new action on a screen.
---

# Add a screen (or new gated endpoints)

A screen here is **data plus code in several places that must agree**. A typo in one leaves
a screen that "vanished for everyone" or a button the API refuses. Work through every item
below; the reference implementations are `departments` and `request_types`.

## Backend

1. **Screen row** — `database/seeders/ScreenSeeder.php`, in the `$screens` array at the
   position it should appear in the sidebar (array order is `sort_order`):
   `['code', 'اسم عربي', 'English name', '/vue-path', 'icon', group]`.
   - `code` is snake_case and is the key everything else uses.
   - `icon` must be a key in `frontend/src/components/AppIcon.vue`'s `icons` map; add one
     there (inline SVG paths, no library) if none fits.
   - `group` is `null` unless the screen belongs in a sidebar group such as
     `meetings_management`.
   - Screens reached only in context (a path with `:id`) are filtered out of the menu, but
     still need a row so their permissions exist.
   - Update the count in the class docblock ("= 35 total") if you keep that sentence.
2. **Matrix grants** — `ScreenRolePermissionSeeder::DEFAULTS`:
   `'code' => ['view' => [...], 'add' => [...], ...]`. The actions are
   view, add, edit, delete, approve, print, export. `'*'` means all roles. Leaving the screen
   out means R08 only. Least privilege: grant only what the stage's Source says, and write
   the *why* in a comment above the line, citing [D] Article/Appendix, like the entries
   around it.
3. **Routes** — `routes/api.php`, inside the `auth:sanctum` group. Use **per-verb** routes,
   each with `->middleware('screen.permission:<code>,<action>')`, not a bare
   `apiResource()`. Declare explicit routes (e.g. `toggle-active`) **before** wildcard ones.
   Put a doc comment above the block and a `// Stage N — …` marker.
4. **Controller / Requests / Resource** — thin controller in `app/Http/Controllers/Api`;
   `App\Http\Requests\<Resource>\Store…Request` / `Update…Request` with `authorize(): true`
   (the middleware is the gate) and **Arabic** `messages()`; always return an
   `Http\Resources` class, never a model. For master data, prefer deactivate
   (`toggle-active`) over delete, and block delete while anything references the row.
   If the resource touches `App\Models\Request`, follow the `HttpRequest` alias /
   `$requestRecord` / `{requestRecord}` rule in AGENTS.md.
5. **Row-level scope** — the screen grant says who may use the screen, not which rows they
   see. If rows are per-actor, scope them in the controller or a visibility service
   (`RequestVisibility`, `MeetingVisibility`) and test the scoping.

## Frontend

6. **Route** — `frontend/src/router/index.js`, as a child of the `AppLayout` route:
   `{ path, name: '<code>', component: () => import('../views/XView.vue'), meta: { screenCode: '<code>' } }`,
   with a `// Stage N — …` comment. The `beforeEach` guard checks `auth.can(code, 'view')`.
7. **View** — `frontend/src/views/XView.vue`, `<script setup>`, calls go through
   `lib/api.js`. Every action button gets `v-can="'<code>.<action>'"`, using the same action
   as its route's middleware. Colours only via tokens in `style.css` (no hex literals);
   logical CSS properties only (RTL). For visual direction, use the `frontend-design` skill,
   bounded by the tokens (see CLAUDE.md).
8. **Strings** — add every key to **both** `frontend/src/locales/ar.json` and `en.json`
   (Arabic first). Sidebar labels come from the screen row, not the locale files.
   Check with `node scripts/check-locale-parity.mjs`.

## Tests and docs

9. **Feature test** in `tests/Feature/`: seed `DatabaseSeeder`, and for each action assert
   that a granted role gets 2xx and an ungranted role gets **403**. Add row-scoping tests
   where step 5 applies. Reuse the traits in `tests/` (`SitsOnCommittee`,
   `PassesControlGates`, …) instead of rebuilding fixtures.
10. **`TEST_PLAN.roles.md` and `TEST_PLAN.roles.ar.md` Appendix A** — re-derive the matrix
    rows from the seeders; don't hand-edit (the appendix explains why).
11. **Apply locally** — `php artisan db:seed --class=ScreenSeeder` then
    `--class=ScreenRolePermissionSeeder` against the real MySQL, and confirm with a query.
    Note that the second one **resets every grant to its defaults** on your local DB.

## Production caveat — raise it, don't guess

On an existing production database there is currently **no maintenance-console command
that adds one screen's matrix rows without resetting the rest**. The console's
"Re-seed reference data" runs the full `DatabaseSeeder`, which rewrites every grant to its
defaults and discards anything customised through Roles & Permissions (see OPEN_ITEMS.md).
When a new screen has to reach production, tell the user and agree the route first.
One option is a data migration that inserts the screen row and its grants with
`insertOrIgnore`.

Finish with the `verify-and-commit` skill.
