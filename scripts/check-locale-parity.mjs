#!/usr/bin/env node
/**
 * Checks that frontend/src/locales/ar.json and en.json have identical key sets.
 *
 * AGENTS.md makes key parity part of "verified", and vue-i18n's fallback hides
 * a missing Arabic key behind English text (and a missing English key behind
 * the raw key name), so a gap never shows up as an error — only as the wrong
 * language on one screen. Until now the check was done ad hoc per session;
 * this makes it one command with a clear answer.
 *
 * Usage (from the repo root):
 *   node scripts/check-locale-parity.mjs          # exit 0 = in parity, 1 = diffs listed
 *   node scripts/check-locale-parity.mjs --hook   # Claude Code Stop hook mode (see below)
 *
 * Hook mode: registered as a Stop hook in .claude/settings.json. Exit code 2
 * with a message on stderr tells Claude Code to keep going and fix the gap
 * before ending its turn. If the hook already fired once this turn
 * (stop_hook_active), it reports but exits 0, so it can never trap a session
 * in a loop.
 */
import { readFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const HOOK = process.argv.includes('--hook')
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const LOCALES = join(ROOT, 'frontend', 'src', 'locales')

/** Flatten nested messages into dotted leaf keys: { a: { b: 'x' } } → ['a.b']. */
function leafKeys(obj, prefix = '', out = new Set()) {
  for (const [k, v] of Object.entries(obj)) {
    const key = prefix ? `${prefix}.${k}` : k
    if (v !== null && typeof v === 'object' && !Array.isArray(v)) leafKeys(v, key, out)
    else out.add(key)
  }
  return out
}

function load(name) {
  const file = join(LOCALES, `${name}.json`)
  try {
    return leafKeys(JSON.parse(readFileSync(file, 'utf8')))
  } catch (e) {
    return { error: `${name}.json: ${e.message}` }
  }
}

let stopHookActive = false
if (HOOK) {
  try {
    stopHookActive = !!JSON.parse(readFileSync(0, 'utf8') || '{}').stop_hook_active
  } catch {
    // No or malformed stdin — treat as a first firing.
  }
}

const ar = load('ar')
const en = load('en')

let problems = []
if (ar.error || en.error) {
  problems = [ar.error, en.error].filter(Boolean)
} else {
  const onlyAr = [...ar].filter((k) => !en.has(k))
  const onlyEn = [...en].filter((k) => !ar.has(k))
  if (onlyAr.length) problems.push(`Missing from en.json (${onlyAr.length}):\n  ${onlyAr.join('\n  ')}`)
  if (onlyEn.length) problems.push(`Missing from ar.json (${onlyEn.length}):\n  ${onlyEn.join('\n  ')}`)
}

if (!problems.length) {
  if (!HOOK) console.log(`Locale parity OK — ${ar.size} keys in each of ar.json and en.json.`)
  process.exit(0)
}

const message = `Locale key parity broken (frontend/src/locales):\n${problems.join('\n')}`

if (HOOK) {
  if (stopHookActive) process.exit(0)
  console.error(`${message}\nAdd the missing keys (Arabic first, then English) before finishing.`)
  process.exit(2)
}

console.error(message)
process.exit(1)
