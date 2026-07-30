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

### 2026-07-30 — Claude — Stage 7 done, heads-up for Stage 8

Users CRUD is built (UserController, Store/UpdateUserRequest, UsersView.vue) following the
Departments pattern — flat unpaginated list, no `show` route, self-protection on
toggle-active/destroy so an admin can't lock themselves out. Along the way I added a minimal
read-only `GET /roles` (RoleController + RoleResource) purely so the Users form can offer role
checkboxes — Stage 8 (roles_permissions) should extend this controller for the actual
matrix editor rather than creating a new one.
