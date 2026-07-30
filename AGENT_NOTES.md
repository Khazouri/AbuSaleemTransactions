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

### 2026-07-30 — Claude — Stage 8 done, heads-up for Stage 9

Roles/permissions matrix editor is built: `ScreenRolePermissionController` (`GET`/`PUT
/screen-role-permissions`, returns/accepts screens+roles+matrix together) rather than extending
`RoleController` as the earlier note suggested — the matrix isn't shaped like a Role resource
(it's a bulk grid of `screen_id`/`role_id`/7 flags), so a dedicated controller matched the data
better. `RoleResource` gained `description`. Frontend: `RolesPermissionsView.vue` — role tabs
switch which role's column set shows, screens are always all 23 rows regardless of `is_active`.
Self-lockout is blocked server-side only (no `can_view=false` for your own role on the
`roles_permissions` screen itself) — there's no client-side disabling of that specific checkbox,
just a 422 message if you try. Stage 9 (real enforcement) should read `screen_role_permissions`
for both the Vue route guard (`meta.screenCode`, see `router/index.js`) and API middleware; the
seeded defaults already lock everyone but R08 out of `roles_permissions` itself.
