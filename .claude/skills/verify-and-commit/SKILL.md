---
name: verify-and-commit
description: Use when a task or stage in this repo is finished, before claiming it done. Runs the full "Verified" checklist from AGENTS.md (PHPUnit, Pint, frontend build, migrations, locale parity, graphify, notes archive), writes the completion note, and commits.
---

# Verify and commit

AGENTS.md ("Commit finished work") defines verified. Run each step and report real output; a failing step is reported, not skipped.

1. `vendor/bin/phpunit` — full suite. Compare the test/assertion counts with the last completion note in `AGENT_NOTES.md`; explain the delta.
2. `vendor/bin/pint --test <every touched PHP file>` (repo-wide drift in untouched files is not yours).
3. From `frontend/`: `npm run build`, then from the repo root `git checkout -- frontend/dist && git clean -fd frontend/dist` (dist is tracked; revert unless the build is the deliverable).
4. `php artisan migrate` against the real database; reseed any seeder you changed and confirm the rows by query.
5. `node scripts/check-locale-parity.mjs` — must report equal key counts.
6. `graphify update .`
7. Write the completion entry at the top of `AGENT_NOTES.md` (what was built, counts, deviations from the plan, open items). Add every open item to `OPEN_ITEMS.md`.
8. `node scripts/archive-agent-notes.mjs` (no-op under 150 KB).
9. Commit the work and the notes entry together. Branch first if on `main`. Do not push or open a PR unless asked.
