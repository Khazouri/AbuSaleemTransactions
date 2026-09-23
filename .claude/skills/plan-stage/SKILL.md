---
name: plan-stage
description: Use before starting any STAGE_PLAN.md stage or other implementation work in this repo. Gathers the stage's scope and open items, then writes the mandatory dated implementation-plan entry at the top of AGENT_NOTES.md before any code changes.
---

# Plan a stage

AGENTS.md ("Implementation plans first") requires a written plan in AGENT_NOTES.md before implementation.

1. Read the stage's section in `STAGE_PLAN.md` (goal, build, done-when, Source line). If it cites [D]/[E], read the verbatim text in `docs/employee-committee-lifecycle/` rather than a paraphrase.
2. Read `OPEN_ITEMS.md` for entries naming this stage or its area, and the newest entries of `AGENT_NOTES.md`. Search `agent-notes-archive/` when an older stage's reasoning matters.
3. Orient with `graphify query "<question>"` before grepping; read the code the change touches end to end.
4. Insert one entry directly under the preamble's `---` line of `AGENT_NOTES.md`:

   `### YYYY-MM-DD HH:MM TZ — Claude — Implementation plan: Stage N (<title>)`

   Cover scope, affected files/areas, decisions (and which were put to the user), what is deliberately not built, and the verification plan (tests, Pint, build, migrations, reseeds).
5. Ask the user about any real process decision the source leaves open instead of guessing. Then implement, and finish with `verify-and-commit`.
