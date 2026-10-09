// Keeps AGENT_NOTES.md under a size cap so CLAUDE.md's @AGENT_NOTES.md import
// stays cheap. The oldest entries move, byte-for-byte unedited, into
// agent-notes-archive/YYYY-MM.md (newest first, same as the live file).
// Nothing is ever deleted or rewritten; under the cap this is a no-op.
// Usage: node scripts/archive-agent-notes.mjs
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

const CAP = 150 * 1024;
const root = join(import.meta.dirname, '..');
const notesPath = join(root, 'AGENT_NOTES.md');
const archiveDir = join(root, 'agent-notes-archive');

const text = readFileSync(notesPath, 'utf8');
const bytes = (s) => Buffer.byteLength(s, 'utf8');

if (bytes(text) <= CAP) {
    console.log(`AGENT_NOTES.md is ${bytes(text)} bytes, under the ${CAP} cap. Nothing to do.`);
    process.exit(0);
}

// The preamble (rules + format) ends at the first line that is exactly '---'.
const sep = text.match(/^---\r?\n/m);
if (!sep) throw new Error('No --- line separating the preamble from the entries.');
const preamble = text.slice(0, sep.index + sep[0].length);
const body = text.slice(preamble.length);

// Each chunk runs from one '### ' heading to the next, trailing '---' and blank lines included.
const chunks = body.split(/^(?=### )/m);

let size = bytes(preamble);
let cut = 0;
while (cut < chunks.length && (cut === 0 || size + bytes(chunks[cut]) <= CAP)) {
    size += bytes(chunks[cut]);
    cut++;
}
const kept = chunks.slice(0, cut);
const moved = chunks.slice(cut);

if (kept.join('') + moved.join('') !== body) throw new Error('Split does not reproduce the original text.');

// Group by the heading's month; an undated heading inherits the previous entry's month.
const byMonth = new Map();
let month = null;
for (const chunk of moved) {
    const m = chunk.match(/^### (\d{4})-(\d{2})/);
    if (m) month = `${m[1]}-${m[2]}`;
    if (!month) throw new Error(`Cannot date entry: ${chunk.slice(0, 80)}`);
    if (!byMonth.has(month)) byMonth.set(month, []);
    byMonth.get(month).push(chunk);
}

mkdirSync(archiveDir, { recursive: true });
for (const [key, list] of byMonth) {
    const file = join(archiveDir, `${key}.md`);
    const header =
        `# Agent notes archive — ${key}\n\n` +
        'Entries moved unedited from AGENT_NOTES.md by scripts/archive-agent-notes.mjs. Newest first.\n\n---\n';
    // Newly moved entries are newer than anything already archived, so they go on top.
    const existing = existsSync(file) ? readFileSync(file, 'utf8').slice(header.length) : '';
    writeFileSync(file, header + list.join('') + existing);
}
writeFileSync(notesPath, preamble + kept.join(''));

console.log(`Kept ${kept.length} entries (${size} bytes); moved ${moved.length} into ${[...byMonth.keys()].join(', ')}.`);
