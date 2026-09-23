---
name: verify-and-commit
description: Finish a stage or change in this repo - run the full "Verified" checklist from AGENTS.md (PHPUnit, Pint, SPA build + dist revert, locale parity, migrations on the real DB, graphify update), write the completion note and open items, and commit. Use when implementation is done, or the user says "verify", "finish", "wrap up" or "commit".
---

# Verify and commit

AGENTS.md defines "verified" and says to commit finished, verified work. This skill runs
that definition in order and stops at the first failure. **Don't report success for a step
you didn't run** — if a step can't run here (e.g. Homestead's MySQL is down), say so in the
completion note instead of skipping silently.

## 1. Checks (run all; fix and re-run on failure)

From the repo root unless noted:

1. **Migrations applied** — if any migration was added or pulled: `php artisan migrate`
   against the real MySQL (`abu_saleem`). If a seeder changed, run that seeder class only
   (`php artisan db:seed --class=XSeeder`) and confirm the result **by query**
   (`php artisan tinker` or SQL). Never run the full `db:seed` just to pick up one change:
   `ScreenRolePermissionSeeder` resets the whole matrix to its defaults.
2. **Full PHPUnit** — `vendor/bin/phpunit`. Runs on in-memory sqlite per `phpunit.xml`, so it
   works even when Homestead is down. Record `N tests / M assertions` and compare with the
   baseline in the newest completion note in `AGENT_NOTES.md`. Every changed pre-existing
   test needs a comment in place saying why the change is legitimate.
3. **Pint** — `vendor/bin/pint --test` on every touched PHP file (list them with
   `git diff --name-only HEAD -- '*.php'` plus untracked ones). Fix with `vendor/bin/pint <files>`.
4. **SPA build** — in `frontend/`: `npm run build`. Then revert the tracked output unless the
   build *is* the deliverable:
   `git checkout -- frontend/dist && git clean -fd frontend/dist`
5. **Locale parity** — `node scripts/check-locale-parity.mjs` (exit 0 = parity). The Stop
   hook also runs it, but run it explicitly so the count goes in the note.
6. **Graph** — `graphify update .` (AST-only, no API cost). The dated snapshot folders it
   writes are git-ignored; only the top-level graph files are committed.

## 2. Record

1. **Completion note** at the top of `AGENT_NOTES.md` (under the header, above the newest
   entry — never edit older ones):

   ```
   ### YYYY-MM-DD HH:MM EET — Claude — Stage N complete (<short name>)

   What was built, naming classes/routes/seeders. Deviations from the plan and why.
   Pre-existing tests that changed, and why each is legitimate. Full suite **N / M**
   (baseline N0 / M0), Pint clean, build passes (`dist` reverted), locale parity K keys,
   migration: <none | name, applied to MySQL, confirmed by query>.
   **Open item:** … (if any)
   ```
2. **OPEN_ITEMS.md** — every open, deferred or unclear point gets its own entry at the top
   (`### <title> — Stage N — opened YYYY-MM-DD`: what's open, why, what would close it).
   Items this work closes are marked **Resolved (date, Stage N)** in place, not deleted.
3. **STAGE_PLAN.md** — mark the stage `— **built**` and add a short "Built YYYY-MM-DD." line
   in its section, as Stages 98–101 do.
4. **Durable facts** (a convention, a standing gotcha) go into AGENTS.md itself.
5. If `AGENT_NOTES.md` now has more than ~25 entries: `node scripts/archive-agent-notes.mjs`.

## 3. Commit

- If on `main`, create a branch first.
- The notes, open items and stage plan go in **the same commit** as the code.
- Message: `Stage N — <what it does>` as the subject, with a body listing the main changes,
  as in the "Stage 101 — …" and "Stage 6: …" commits. No bare "test", "fix" or "upload"
  subjects: they make the history unreadable for the next agent.
- **Don't push or open a PR** unless the user asks in this session.
