#!/usr/bin/env node
/**
 * Moves older AGENT_NOTES.md entries, verbatim, into agent-notes/YYYY-MM.md.
 *
 * Why this exists: CLAUDE.md imports AGENT_NOTES.md with `@AGENT_NOTES.md`, so
 * every byte of it is loaded into every Claude Code session before the first
 * prompt. By 2026-09-23 it had grown to 1.3 MB (~340K tokens) — larger than a
 * whole 200K context window. Only the recent entries are handoff material;
 * the rest is history, which must be kept but need not be loaded.
 *
 * Nothing is rewritten or dropped: each entry is moved byte-for-byte, newest
 * first, into the archive file for the month in its heading. The run refuses
 * to write anything unless (kept + archived) reassembles to exactly the
 * original entries.
 *
 * Usage (from the repo root):
 *   node scripts/archive-agent-notes.mjs            # keep the newest 20 entries
 *   node scripts/archive-agent-notes.mjs --keep 30
 *   node scripts/archive-agent-notes.mjs --dry-run
 */
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'

const args = process.argv.slice(2)
const keepIdx = args.indexOf('--keep')
const KEEP = keepIdx >= 0 ? Number(args[keepIdx + 1]) : 20
const DRY = args.includes('--dry-run')
if (!Number.isInteger(KEEP) || KEEP < 1) {
  console.error('--keep must be a positive integer')
  process.exit(1)
}

const ROOT = process.cwd()
const NOTES = join(ROOT, 'AGENT_NOTES.md')
const ARCHIVE_DIR = join(ROOT, 'agent-notes')
const MARKER = '<!-- entries: newest first; moved verbatim from AGENT_NOTES.md -->\n'

const text = readFileSync(NOTES, 'utf8')

// An entry starts at a line like "### 2026-09-21 22:30 EET — Claude — ...".
// The header's own format example ("### YYYY-MM-DD ...") has no digits, so it
// is never mistaken for an entry.
const heading = /^### (\d{4})-(\d{2})-\d{2}/gm
const starts = [...text.matchAll(heading)].map((m) => ({
  index: m.index,
  month: `${m[1]}-${m[2]}`,
}))

if (starts.length <= KEEP) {
  console.log(`AGENT_NOTES.md has ${starts.length} entries (keep ${KEEP}) — nothing to archive.`)
  process.exit(0)
}

const header = text.slice(0, starts[0].index)
const entries = starts.map((s, i) => ({
  month: s.month,
  body: text.slice(s.index, i + 1 < starts.length ? starts[i + 1].index : text.length),
}))

const kept = entries.slice(0, KEEP)
const moved = entries.slice(KEEP)

// Group moved entries by month, preserving file order (newest first).
const byMonth = new Map()
for (const e of moved) {
  if (!byMonth.has(e.month)) byMonth.set(e.month, [])
  byMonth.get(e.month).push(e.body)
}

// Lossless check before touching disk.
const reassembled = header + kept.map((e) => e.body).join('') + moved.map((e) => e.body).join('')
if (reassembled !== text) {
  console.error('Refusing to write: split does not reassemble to the original file.')
  process.exit(1)
}

const nextNotes = header + kept.map((e) => e.body).join('')

if (DRY) {
  console.log(`Would keep ${kept.length}, archive ${moved.length}:`)
  for (const [m, list] of byMonth) console.log(`  agent-notes/${m}.md  +${list.length}`)
  process.exit(0)
}

if (!existsSync(ARCHIVE_DIR)) mkdirSync(ARCHIVE_DIR)

for (const [month, bodies] of byMonth) {
  const file = join(ARCHIVE_DIR, `${month}.md`)
  const block = bodies.join('')
  let out
  if (existsSync(file)) {
    // Entries being moved now are newer than anything already archived for
    // this month, so they go directly under the marker, above the old ones.
    const current = readFileSync(file, 'utf8')
    const at = current.indexOf(MARKER)
    if (at < 0) {
      console.error(`${file} has no entries marker — refusing to guess where to insert.`)
      process.exit(1)
    }
    const cut = at + MARKER.length
    out = current.slice(0, cut) + block + current.slice(cut)
  } else {
    out =
      `# Agent notes archive — ${month}\n\n` +
      'Entries moved out of [AGENT_NOTES.md](../AGENT_NOTES.md) by\n' +
      '`scripts/archive-agent-notes.mjs`, unedited. When code or docs say\n' +
      '"see AGENT_NOTES.md" for something older than the live file, it is here.\n\n' +
      MARKER +
      block
  }
  writeFileSync(file, out)
  console.log(`agent-notes/${month}.md  +${bodies.length}`)
}

writeFileSync(NOTES, nextNotes)
console.log(`AGENT_NOTES.md now holds ${kept.length} entries (${moved.length} archived).`)
