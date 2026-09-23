// Headless-Chrome layout check: no screen may scroll sideways (AGENT_NOTES 2026-09-22).
// The frontend has no test runner; this is its one automated check, and it adds no dependency.
//
// Usage: node scripts/check-layout.mjs      -> exit 1 on any overflow or failed page (~12 min)
//   needs the API up (Homestead, abusaleem.test) and `npm run dev` in frontend/.
//   Run it outside any network sandbox: it talks to abusaleem.test and localhost directly.
//   APP_URL        SPA origin (default http://localhost:5173)
//   PUPPETEER_PATH a puppeteer package dir; by default the newest one in the npx cache
//                  (mermaid-cli puts it there — `npx -y @mermaid-js/mermaid-cli --version` once if missing).
//
// Two traps this script is built around, both of which made earlier sweeps "pass" on nothing:
// 1. Some Chrome builds block localhost:5173 -> abusaleem.test (ERR_NETWORK_ACCESS_DENIED; the
//    disable-features flags don't help), the API call fails, and the SPA bounces to /login. So every
//    API request is answered from Node instead, and a page whose final URL isn't the one asked for
//    (an expired token does the same) is a failure, never a pass.
// 2. A tab's content only exists once it is open, so every [role=tab] on a page is clicked through.
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';

const root = join(import.meta.dirname, '..');
const APP = (process.env.APP_URL ?? 'http://localhost:5173').replace(/\/$/, '');
const API = readFileSync(join(root, 'frontend', '.env'), 'utf8').match(/^VITE_API_BASE_URL=(.+)$/m)[1].trim();
const API_ORIGIN = new URL(API).origin;
const LOGIN = { email: 'r08.sysadmin@abusaleem.test', password: 'password' }; // TestUserSeeder; R08 sees every screen

// Desktop must not scroll sideways anywhere; below 1024px a wide table may scroll inside its own
// card (accepted 2026-09-22), so there only the page itself is checked.
const WIDTHS = [375, 768, 1024, 1280, 1440];
const DESKTOP = 1024;

// Every static route in the SPA's route table, so a new screen is checked without editing this file.
const ROUTES = [...readFileSync(join(root, 'frontend', 'src', 'router', 'index.js'), 'utf8')
    .matchAll(/path: '([a-z][^':]*)'/g)].map((m) => m[1]);

function loadPuppeteer() {
    let dir = process.env.PUPPETEER_PATH;
    if (!dir) {
        const npx = join(execSync('npm config get cache', { encoding: 'utf8' }).trim(), '_npx');
        const version = (d) => JSON.parse(readFileSync(join(d, 'package.json'), 'utf8')).version.split('.').map(Number);
        dir = (existsSync(npx) ? readdirSync(npx) : [])
            .map((h) => join(npx, h, 'node_modules', 'puppeteer'))
            .filter(existsSync)
            .sort((a, b) => { const [x, y] = [version(a), version(b)]; return x[0] - y[0] || x[1] - y[1] || x[2] - y[2]; })
            .pop();
    }
    if (!dir) throw new Error('No puppeteer found. Set PUPPETEER_PATH, or run `npx -y @mermaid-js/mermaid-cli --version` once.');
    return createRequire(import.meta.url)(dir);
}

// Answer the SPA's API calls from Node, where Chrome's local-network block does not apply.
async function proxyApi(page) {
    await page.setRequestInterception(true);
    page.on('request', async (req) => {
        if (!req.url().startsWith(API_ORIGIN + '/')) return req.continue();
        const cors = {
            'Access-Control-Allow-Origin': APP, 'Access-Control-Allow-Headers': '*',
            'Access-Control-Allow-Methods': '*', 'Access-Control-Expose-Headers': '*',
        };
        if (req.method() === 'OPTIONS') return req.respond({ status: 204, headers: cors });
        try {
            const headers = { ...req.headers() };
            delete headers.origin;
            const res = await fetch(req.url(), { method: req.method(), headers, body: req.postData() });
            req.respond({
                status: res.status,
                headers: { ...cors, 'content-type': res.headers.get('content-type') ?? 'application/json' },
                body: Buffer.from(await res.arrayBuffer()),
            });
        } catch {
            req.abort();
        }
    });
}

// Runs in the page: what scrolls sideways. `elements` = also any scrollable box, not just the page.
function overflow(elements) {
    const out = [];
    const doc = document.documentElement;
    if (doc.scrollWidth > doc.clientWidth + 1) out.push(`page ${doc.scrollWidth}>${doc.clientWidth}`);
    if (!elements) return out;
    for (const el of document.querySelectorAll('body *')) {
        const ox = getComputedStyle(el).overflowX;
        if ((ox === 'auto' || ox === 'scroll') && el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1) {
            out.push(`${el.tagName.toLowerCase()}.${[...el.classList].join('.')} ${el.scrollWidth}>${el.clientWidth}`);
        }
    }
    return out;
}

async function main() {
    try {
        await fetch(APP);
    } catch {
        throw new Error(`SPA not reachable at ${APP} — run \`npm run dev\` in frontend/.`);
    }
    const login = await fetch(`${API}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(LOGIN),
    }).catch(() => null);
    if (!login?.ok) throw new Error(`Login to ${API} failed (${login?.status ?? 'unreachable'}) — is Homestead up and seeded?`);
    const { token } = await login.json();

    const browser = await loadPuppeteer().launch({ headless: true });
    const failures = [];
    let views = 0;
    try {
        const page = await browser.newPage();
        await proxyApi(page);
        await page.goto(`${APP}/login`);
        for (const locale of ['ar', 'en']) {
            await page.evaluate((t, l) => {
                localStorage.setItem('abs_token', t);
                localStorage.setItem('abs_locale', l);
            }, token, locale);
            for (const width of WIDTHS) {
                await page.setViewport({ width, height: 900 });
                for (const route of ROUTES) {
                    const where = `${locale} ${width}px /${route}`;
                    await page.goto(`${APP}/${route}`, { waitUntil: 'networkidle0' });
                    const landed = new URL(page.url()).pathname;
                    if (landed !== `/${route}`) {
                        failures.push(`${where}: landed on ${landed}, not checked`);
                        continue;
                    }
                    const tabs = await page.$$eval('[role="tab"]', (t) => t.length);
                    for (let i = 0; i < Math.max(tabs, 1); i++) {
                        // A fixed pause, not waitForNetworkIdle: against the Vite dev server that never
                        // settles after a click, although every API call has long finished.
                        if (tabs) await page.evaluate((n) => document.querySelectorAll('[role="tab"]')[n]?.click(), i);
                        await new Promise((r) => setTimeout(r, tabs ? 900 : 200));
                        const found = await page.evaluate(overflow, width >= DESKTOP);
                        views++;
                        if (found.length) failures.push(`${where}${tabs ? ` tab ${i + 1}` : ''}: ${found.join(' | ')}`);
                    }
                }
            }
        }
    } finally {
        await browser.close();
        await fetch(`${API}/auth/logout`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    }

    console.log(`${ROUTES.length} screens × ${WIDTHS.length} widths × 2 locales: ${views} views checked.`);
    if (failures.length) {
        console.error(failures.join('\n'));
        process.exit(1);
    }
    console.log('No sideways scrolling.');
}

main().catch((e) => {
    console.error(e.message);
    process.exit(1);
});
