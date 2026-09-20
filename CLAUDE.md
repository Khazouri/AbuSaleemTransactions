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
