// AGENTS.md requires ar.json and en.json to carry identical key sets.
// Usage: node scripts/check-locale-parity.mjs          -> report, exit 1 on mismatch
//        node scripts/check-locale-parity.mjs --hook   -> Claude Code PostToolUse hook:
//        reads the tool call on stdin, acts only on locale files, exit 2 feeds the diff back.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const hook = process.argv.includes('--hook');
if (hook) {
    let input = {};
    try { input = JSON.parse(readFileSync(0, 'utf8')); } catch { process.exit(0); }
    const file = String(input.tool_input?.file_path ?? '').replaceAll('\\', '/');
    if (!/frontend\/src\/locales\/[^/]+\.json$/.test(file)) process.exit(0);
}

const dir = join(import.meta.dirname, '..', 'frontend', 'src', 'locales');
const flatten = (obj, prefix = '') =>
    Object.entries(obj).flatMap(([k, v]) =>
        v && typeof v === 'object' && !Array.isArray(v) ? flatten(v, `${prefix}${k}.`) : [`${prefix}${k}`]);

let ar, en;
try {
    ar = new Set(flatten(JSON.parse(readFileSync(join(dir, 'ar.json'), 'utf8'))));
    en = new Set(flatten(JSON.parse(readFileSync(join(dir, 'en.json'), 'utf8'))));
} catch (e) {
    console.error(`Locale file does not parse: ${e.message}`);
    process.exit(hook ? 2 : 1);
}

const onlyAr = [...ar].filter((k) => !en.has(k));
const onlyEn = [...en].filter((k) => !ar.has(k));

if (!onlyAr.length && !onlyEn.length) {
    if (!hook) console.log(`Locale parity OK: ${ar.size} keys each side.`);
    process.exit(0);
}

const report = [
    `Locale parity FAILED (ar ${ar.size} keys, en ${en.size} keys).`,
    ...onlyAr.map((k) => `  only in ar.json: ${k}`),
    ...onlyEn.map((k) => `  only in en.json: ${k}`),
].join('\n');
console.error(report);
process.exit(hook ? 2 : 1);
