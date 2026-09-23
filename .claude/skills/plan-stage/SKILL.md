---
name: plan-stage
description: Start a stage or any implementation task in this repo the house way - read STAGE_PLAN.md, OPEN_ITEMS.md and recent handoff notes, then write the timestamped "Implementation plan" entry at the top of AGENT_NOTES.md before touching code. Use when the user says "start stage N", "work on stage N", "implement X", or before any non-trivial code change here.
---

# Plan a stage (before writing code)

AGENTS.md requires a written plan in AGENT_NOTES.md **before** implementation. This skill
is that step, done the same way every time. It ends with a plan entry on disk — not code.

## 1. Gather context (read, don't skim)

1. **The stage itself.** If the task names a stage, read its section in `STAGE_PLAN.md`
   (`### Stage N — …`): Goal → Build → Done when → Source. Counts inside a stage are
   *as of when it was written* — verify any figure against the seeders, never quote it.
   If the task has no stage yet and is feature-sized, it needs one: follow
   STAGE_PLAN.md's "Adding a stage" (next number, right track, same four-line shape,
   a `Source:` line naming the [D] Article/Appendix it implements).
2. **Open items.** Search `OPEN_ITEMS.md` for the stage number, the screen code, and the
   service names involved. An open item may already decide part of the scope.
3. **Recent notes.** `AGENT_NOTES.md` holds only the newest ~20 entries; older ones are in
   `agent-notes/YYYY-MM.md`. If a code comment says "see AGENT_NOTES.md" and the entry
   isn't in the live file, search `agent-notes/`.
4. **The code.** Use graphify first (`graphify query "<question>"`, `graphify explain
   "<Class>"`) — see CLAUDE.md. Read the real classes before claiming anything about them.
5. **Process authority.** Anything touching WorkflowService, CommitteeStatusService or the
   meetings screens: the [D] manual indexed at `docs/employee-committee-lifecycle/README.md`
   (local-only, git-ignored) is authoritative. Cite Article/Appendix numbers.

## 2. Ask before planning when it is genuinely the user's call

Put a question to the user when the stage text is ambiguous about **who** may do something
(roles, matrix cells), **whether** an existing rule changes, or when two readings of [D]
differ. Record the answer in the plan as "put to the user and answered — do not
re-litigate", as earlier entries do. Don't ask about things the code or [D] settles.

## 3. Write the plan entry

Insert it directly **under the header block, above the newest entry** — never edit or
reorder older entries. Timestamp in EET (local time, UTC+2):

```
### YYYY-MM-DD HH:MM EET — Claude — Implementation plan: Stage N (<short name>)

<Scope in one paragraph: what changes, and the headline — e.g. "No migration, no new
screen" or "One migration, one new screen".>

**Built.** (1) … (2) … — name the real classes, routes, seeders, views and screen codes.

**Deliberately not built.** … and why (so the next agent doesn't "fix" it).

**Calls put to the user.** … (only if any)

**Verify.** New/changed tests (file names); full PHPUnit with the current baseline
(tests / assertions, from the newest completion note); Pint on touched files;
`npm run build` (revert `frontend/dist`); `node scripts/check-locale-parity.mjs`;
any migration/seeder applied to the real MySQL, confirmed by query.

---
```

Match the house voice of existing entries: dense, specific, explains *why*.

## 4. Housekeeping

- If `AGENT_NOTES.md` now holds more than ~25 entries, run
  `node scripts/archive-agent-notes.mjs` (moves older entries verbatim to `agent-notes/`).
- Then implement. When done, use the `verify-and-commit` skill.
