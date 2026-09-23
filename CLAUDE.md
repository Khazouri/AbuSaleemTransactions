@AGENTS.md
@AGENT_NOTES.md

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## Project skills

The repeatable procedures from AGENTS.md, as skills. Use them rather than re-deriving
the steps from prose each session:

- `plan-stage` — before any implementation: reads STAGE_PLAN / OPEN_ITEMS / recent notes and
  writes the required "Implementation plan" entry in AGENT_NOTES.md.
- `verify-and-commit` — the full "Verified" checklist (PHPUnit, Pint, build + dist revert,
  locale parity, migrations, graphify), then the completion note, open items and commit.
- `new-screen` — every place a screen or gated endpoint must agree: seeder row, matrix grants,
  per-verb `screen.permission` routes, Vue route, `v-can`, locales, tests, role test plan.
- `cpanel-release` — shipping to the no-SSH cPanel production (build, vendor/, git-ftp,
  maintenance console) and the traps that go with it.

A Stop hook (`.claude/settings.json`) runs `scripts/check-locale-parity.mjs` at the end of
every turn and sends Claude back to fix any ar/en key gap.

`AGENT_NOTES.md` (imported above) holds only the newest ~20 entries; older ones are in
`agent-notes/YYYY-MM.md`. Keep it that way — see "Archive, don't prune" in AGENTS.md.

`.claude/skills/frontend-design` — aesthetic direction (palette, type, layout) when building or
reshaping UI. Pair it with `agent-skills:frontend-ui-engineering`, which builds the accessible,
responsive result. Direction first, then build.

**It does not override this repo's design system.** frontend-design tells you to make opinionated
palette choices; AGENTS.md says never hard-code a colour, and both themes live in
`frontend/src/style.css`. The tokens win — express the direction by *adding or re-picking tokens*
there, never by putting a literal in a component.

Brainstorming comes from `superpowers:brainstorming` (the plugin). The old vendored copy under
`.claude/skills/brainstorming` was removed — it predated the HARD-GATE and the
Spike/Bounded/Architectural classification.
