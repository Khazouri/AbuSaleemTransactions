---
name: new-screen
description: Use when adding a new screen, route or permission-gated endpoint to this app. Checklist for the seeder rows, per-verb screen.permission routes, Vue route meta, v-can buttons, bilingual locale keys and Arabic validation that every screen needs.
---

# Add a screen

Rules come from AGENTS.md ("Screens/permissions", "Conventions"). Every item applies.

Backend
- `database/seeders/ScreenSeeder.php`: one row (code, route, group, sort). Update the count in its docblock if it states one.
- `database/seeders/ScreenRolePermissionSeeder.php`: grants per action; an empty entry means R08 only. Check the matrix before assuming a change is needed.
- `routes/api.php`: per-verb routes with `screen.permission:<code>,<action>`, not a bare `apiResource()`. Explicit routes (e.g. `toggle-active`) go before any `{wildcard}`.
- Thin controller in `app/Http/Controllers/Api/`; `Store*/Update*Request` with `authorize(): true` and Arabic `messages()`; wrap output in an `Http/Resources` class.
- Stage marker comment on each new entry point, e.g. `// Stage N — <feature>.`
- Feature test covering allowed and refused roles.

Frontend
- `frontend/src/router/index.js`: route with `meta.screenCode`, plus the stage marker.
- `v-can="'<code>.<action>'"` on every action button.
- Sidebar icon in `AppSidebar.vue`'s `ICON_BY_CODE` (add a glyph to `AppIcon.vue` if needed).
- Keys in both `frontend/src/locales/ar.json` and `en.json`; no colour literals (tokens in `style.css`); logical CSS properties only.

Then reseed `ScreenSeeder` + `ScreenRolePermissionSeeder` against the real DB (this resets the matrix on a live install) and finish with `verify-and-commit`.
