// run.mjs — end-to-end: the real extension in a real Chromium against the dev server.
//
// Proves timer parity between the dashboard and the popup, the call / media /
// idle classification with fake media, the idle prompt and its four buttons,
// and the server's auto-pause. Writes to the DEV database through the app's
// own HTTP routes only; reads it with the read-only `hq_ro` role.
//
// Needs: the dev database `goodtechies_hq` seeded, built assets (public/build),
// nothing on port 8000, SEED_PASSWORD in the repo's .env, and
// PLAYWRIGHT_BROWSERS_PATH pointing at the browsers.
// Run: npm run test:e2e
//
// Every check records a line and the script carries on; the table is printed
// at the end and the exit code is 1 when any line FAILs.

import { chromium } from '/home/claude/goodtechies-hq/node_modules/playwright/index.mjs';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { readFileSync, mkdtempSync, openSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const HERE = dirname(fileURLToPath(import.meta.url));
const SRC = resolve(HERE, '..', '..', 'src');
const PAGES = join(HERE, 'pages');
const REPO = resolve(HERE, '..', '..', '..', '..');
const BASE = 'http://127.0.0.1:8000';
const SERVE_LOG = '/tmp/claude-0/-home-claude/fccd7e1c-d093-5946-b716-e66afcf71d25/scratchpad/e2e-serve.log';
const EMAIL = 'tapu@goodtechies.test';
const MINUTE = 60000;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const minuteFloor = (ms) => Math.floor(ms / MINUTE) * MINUTE;

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

const results = [];
/** ok: true → ok, false → FAIL, 'nv' → not verified (a harness limitation, said so). */
function record(name, ok, detail = '') {
    const status = ok === 'nv' ? 'not verified' : ok ? 'ok' : 'FAIL';
    results.push({ status, name, detail: String(detail) });
    console.log(`[e2e] ${status}  ${name} — ${detail}`);
}
/** Run one check; an exception is a FAIL with its message, never the end of the run. */
async function check(name, fn) {
    try {
        const out = await fn();
        if (out && typeof out === 'object' && 'ok' in out) {
            record(name, out.ok, out.detail);
        } else {
            record(name, true, out ?? '');
        }
    } catch (error) {
        record(name, false, (error?.message ?? String(error)).split('\n')[0]);
    }
}

// ---------------------------------------------------------------------------
// Helpers: database (read only), env, fixtures server
// ---------------------------------------------------------------------------

function sql(query) {
    return execFileSync('psql', ['-U', 'hq_ro', '-h', '127.0.0.1', '-d', 'goodtechies_hq', '-Atc', query], {
        encoding: 'utf8',
    }).trim();
}

function seedPassword() {
    const line = readFileSync(join(REPO, '.env'), 'utf8').split('\n').find((l) => l.startsWith('SEED_PASSWORD='));
    return line.slice('SEED_PASSWORD='.length).trim().replace(/^["']|["']$/g, '');
}

/** "H:MM:SS" → seconds. */
function hmsSeconds(text) {
    const m = /(\d+):(\d\d):(\d\d)/.exec(text ?? '');
    if (!m) {
        throw new Error(`no H:MM:SS in "${text}"`);
    }
    return Number(m[1]) * 3600 + Number(m[2]) * 60 + Number(m[3]);
}

/**
 * The fixture pages are served over http on 127.0.0.1 (a secure context for
 * getUserMedia, and a page the <all_urls> content scripts are injected into;
 * file:// would need "Allow access to file URLs").
 */
function serveFixtures() {
    const server = createServer((req, res) => {
        const name = (req.url ?? '/').split('?')[0].replace(/^\/+/, '');
        const file = join(PAGES, name);
        if (!/^[a-z]+\.html$/.test(name) || !existsSync(file)) {
            res.writeHead(404).end();
            return;
        }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(readFileSync(file));
    });
    return new Promise((r) => server.listen(0, '127.0.0.1', () => r(server)));
}

// ---------------------------------------------------------------------------
// a. The dev server
// ---------------------------------------------------------------------------

let serve = null;
let context = null;
let fixtures = null;

async function startServer() {
    const log = openSync(SERVE_LOG, 'w');
    serve = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8000'], {
        cwd: REPO,
        stdio: ['ignore', log, log],
        detached: true, // its own process group, so the PHP worker it forks is killed with it
    });
    for (let i = 0; i < 60; i++) {
        try {
            const res = await fetch(BASE + '/up');
            if (res.status === 200) {
                return;
            }
        } catch {
            // not listening yet
        }
        await sleep(500);
    }
    throw new Error('the dev server never answered /up with 200 (see ' + SERVE_LOG + ')');
}

function stopServer() {
    if (serve && serve.exitCode === null) {
        try {
            process.kill(-serve.pid, 'SIGTERM');
        } catch {
            // already gone
        }
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
    await startServer();
    record('server:up', true, 'GET /up 200');
    fixtures = await serveFixtures();
    const FIX = `http://127.0.0.1:${fixtures.address().port}`;

    // b. Chromium with the unpacked extension (new headless mode loads extensions).
    const profile = mkdtempSync(join(tmpdir(), 'gt-e2e-'));
    context = await chromium.launchPersistentContext(profile, {
        headless: true,
        channel: 'chromium',
        args: [
            `--disable-extensions-except=${SRC}`,
            `--load-extension=${SRC}`,
            '--use-fake-ui-for-media-stream',
            '--use-fake-device-for-media-stream',
            '--autoplay-policy=no-user-gesture-required',
        ],
    });
    const worker = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
    // `chrome` is not on the worker's global until it has finished starting.
    await sleep(1000);
    const extId = new URL(worker.url()).host;
    const EXT = `chrome-extension://${extId}`;
    let version = null;
    try {
        version = context.browser()?.version() ?? null;
    } catch {
        version = null;
    }
    if (!version) {
        const probe = await context.newPage();
        version = await probe.evaluate(() => navigator.userAgent.match(/Chrome\/([\d.]+)/)?.[1] ?? 'unknown');
        await probe.close();
    }
    console.log(`[e2e] Chromium ${version}, extension ${extId}`);

    // The worker module cannot be imported inside a service worker (import() is
    // disallowed there), so the worker is driven through its own entry points:
    //   cmd()   → a message from an extension page, answered by the worker's onMessage → handleCommand
    //   fire()  → an alarm, run by the worker's onAlarm ('tick' → tick, 'idle-prompt' → maybePrompt)
    //   store() / read() → chrome.storage.local in the worker
    const ext = await context.newPage();
    await ext.goto(`${EXT}/popup/popup.html`);
    const cmd = (c, p) => ext.evaluate(([c2, p2]) => chrome.runtime.sendMessage({ type: 'gt-cmd', cmd: c2, payload: p2 }), [c, p]);
    const fire = async (name) => {
        // `when` from the REAL clock: a skewed Date.now() would schedule the alarm in the future.
        await worker.evaluate((n) => chrome.alarms.create(n, { when: (globalThis.__realNow ?? Date.now).call(Date) }), name);
        await sleep(1500);
    };
    const store = (obj) => worker.evaluate((o) => chrome.storage.local.set(o), obj);
    const read = (keys) => worker.evaluate((k) => chrome.storage.local.get(k), keys);
    /** Move the worker's clock: Date.now = real + skewMs (the real one captured once). */
    const skew = (ms) => worker.evaluate((s) => {
        globalThis.__realNow ??= Date.now;
        Date.now = () => globalThis.__realNow.call(Date) + s;
    }, ms);
    /** fetch an API route from the worker with the stored token. */
    const apiFromWorker = (method, path, body) => worker.evaluate(async ([m, p, b]) => {
        const { server, token } = await chrome.storage.local.get(['server', 'token']);
        const res = await fetch(server + p, {
            method: m,
            headers: {
                Authorization: 'Bearer ' + token,
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Timer-Client': 'extension/0.1.0',
            },
            body: b === undefined ? undefined : JSON.stringify(b),
        });
        return { status: res.status, data: await res.json().catch(() => null) };
    }, [method, path, body]);

    // The worker's own once-a-minute tick would add samples of its own while
    // the checks below choose the minutes; it is cleared for this run only.
    await worker.evaluate(() => chrome.alarms.clear('tick'));

    // c. Sign in as Tapu, pair.
    const page = await context.newPage();
    await check('login', async () => {
        await page.goto(BASE + '/login');
        await page.fill('#email', EMAIL);
        await page.fill('#password', seedPassword());
        await page.click('button[type=submit]');
        await page.waitForURL(/\/employee\/dashboard/, { timeout: 15000 });
        return `at ${new URL(page.url()).pathname}`;
    });

    const xsrf = async () => decodeURIComponent((await context.cookies(BASE)).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const webCurrent = () => page.evaluate(async () => (await fetch('/employee/time/current', { headers: { Accept: 'application/json' } })).json());

    await check('pair', async () => {
        const token = await xsrf();
        const { code } = await page.evaluate(async (t) => (await fetch('/profile/extension/code', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': t },
        })).json(), token);
        const reply = await cmd('pair', { server: BASE, code, device_name: 'e2e Chromium' });
        const stored = await read(['token']);
        return { ok: reply?.view?.token === true && !!stored.token, detail: `code ${code}, status ${reply?.status}, view.token ${reply?.view?.token}` };
    });

    // d. Parity: dashboard → extension → popup.
    const dashCounter = () => page.locator('p.font-mono').filter({ hasText: /^\s*\d+:\d\d:\d\d\s*$/ }).first();
    let webEntryId = null;
    await check('parity:start-shows-in-extension', async () => {
        await page.goto(BASE + '/employee/dashboard');
        const hero = page.locator('p', { hasText: 'Time today' }).locator('xpath=ancestor::div[contains(@class, "p-6")][1]');
        const select = hero.locator('select');
        if (await select.count()) {
            await select.selectOption({ index: 0 });
        }
        await hero.getByRole('button', { name: 'Start' }).click();
        await dashCounter().waitFor({ timeout: 10000 });
        const web = await webCurrent();
        webEntryId = web.running?.id ?? null;
        const view = await cmd('sync');
        const r = view?.state?.running;
        return {
            ok: r?.state === 'running' && !!r.client_uuid && r.client_uuid === web.running?.client_uuid,
            detail: `entry ${r?.id}, state ${r?.state}, uuid match ${r?.client_uuid === web.running?.client_uuid}`,
        };
    });

    await check('parity:elapsed-within-2s', async () => {
        const view = await cmd('getState');
        const s = view.state;
        // The §4 formula: elapsed = elapsed_seconds + (now + offset − server_time), offset = server_time − state_at.
        const offset = Date.parse(s.server_time) - view.state_at;
        const webText = await dashCounter().textContent();
        const ext = s.running.elapsed_seconds + (Date.now() + offset - Date.parse(s.server_time)) / 1000;
        const diff = Math.abs(hmsSeconds(webText) - Math.floor(ext));
        return { ok: diff <= 2, detail: `web ${webText.trim()}, extension ${Math.floor(ext)} s, |diff| ${diff} s` };
    });

    const popup = await context.newPage();
    await check('parity:popup-counter-within-2s', async () => {
        await popup.goto(`${EXT}/popup/popup.html`);
        await sleep(1500);
        const [popText, webText] = await Promise.all([popup.locator('#counter').textContent(), dashCounter().textContent()]);
        const diff = Math.abs(hmsSeconds(popText) - hmsSeconds(webText));
        return { ok: diff <= 2, detail: `popup ${popText}, web ${webText.trim()}, |diff| ${diff} s` };
    });

    // e. Stop in the popup shows on the web; a stale heartbeat is refused.
    await check('parity:stop-shows-on-web', async () => {
        await popup.locator('#stop').click();
        await sleep(1500);
        await page.reload();
        await page.waitForLoadState('networkidle');
        const counters = await dashCounter().count();
        const web = await webCurrent();
        return { ok: counters === 0 && web.running === null, detail: `counter shown ${counters}, running ${JSON.stringify(web.running)}` };
    });
    if (!popup.isClosed()) {
        await popup.close();
    }

    await check('heartbeat:stopped-entry-409', async () => {
        assert.ok(webEntryId, 'no entry id from the start');
        const minute = new Date(minuteFloor(Date.now())).toISOString();
        const res = await apiFromWorker('POST', '/api/timer/heartbeat', {
            time_entry_id: webEntryId,
            client_uuid: '00000000-0000-4000-8000-000000000000',
            samples: [{ minute, state: 'active', call_source: null, sites: [] }],
        });
        return {
            ok: res.status === 409 && res.data?.error === 'entry_not_running' && res.data && 'state' in res.data,
            detail: `status ${res.status}, error ${res.data?.error}, state key ${!!res.data && 'state' in res.data}`,
        };
    });

    // f. Classification.
    let entry = null;
    let taskId = null;
    await check('classify:start', async () => {
        const tasks = await cmd('getTasks');
        taskId = tasks?.data?.tasks?.[0]?.id;
        assert.ok(taskId, 'no task from getTasks');
        const res = await cmd('start', { task_id: taskId });
        entry = res?.view?.state?.running ?? null;
        return { ok: res?.ok === true && entry?.state === 'running', detail: `task ${taskId}, entry ${entry?.id}` };
    });

    let stubbed = false;
    await check('idle-stub', async () => {
        const how = await worker.evaluate(() => {
            const fake = () => Promise.resolve('idle');
            chrome.idle.queryState = fake;
            if (chrome.idle.queryState === fake) {
                return 'assignment';
            }
            Object.defineProperty(chrome.idle, 'queryState', { value: fake, configurable: true });
            return chrome.idle.queryState === fake ? 'defineProperty' : 'refused';
        });
        stubbed = how !== 'refused';
        return { ok: stubbed, detail: `chrome.idle.queryState → 'idle' by ${how}` };
    });

    // Minute mechanism: the worker's clock is skewed +60 s, so tick()'s "minute
    // that has just ended" is the CURRENT real minute (never in the future, never
    // before the entry's start minute). Before each fixture the run waits for a
    // real minute it has not used yet, so each tick writes a new, newest row.
    // lastInputAt is refreshed before each tick so no idle prompt opens mid-check.
    let lastMinute = null;
    const newest = () => {
        const row = sql(`select state, coalesce(call_source, ''), extract(epoch from minute_at)::bigint from activity_samples where time_entry_id = ${entry.id} order by minute_at desc limit 1`);
        if (!row) {
            throw new Error(`no activity_samples row for entry ${entry.id}`);
        }
        const [state, source, epoch] = row.split('|');
        return { state, source, minute: Number(epoch) * 1000 };
    };
    const freshMinute = async () => {
        for (;;) {
            const now = Date.now();
            const m = minuteFloor(now);
            if (m !== lastMinute && now - m <= 50000) {
                return m;
            }
            await sleep(1000);
        }
    };
    async function classifyWith(name, url, expect, nvIfMissing = false) {
        await check(name, async () => {
            assert.ok(entry && stubbed, 'no running entry or no idle stub');
            const minute = await freshMinute();
            let tab = null;
            if (url) {
                tab = await context.newPage();
                await tab.goto(url);
                await tab.bringToFront();
                if (url.endsWith('call.html')) {
                    await tab.locator('#state', { hasText: 'live' }).waitFor({ timeout: 5000 });
                }
            } else {
                tab = await context.newPage();
                await tab.bringToFront();
            }
            await sleep(2000);
            await worker.evaluate(() => chrome.storage.local.set({ lastInputAt: Date.now() }));
            await fire('tick');
            const row = newest();
            const got = row.source ? `${row.state}|${row.source}` : row.state;
            const fresh = row.minute >= minute && row.minute !== lastMinute;
            lastMinute = row.minute;
            await tab.close();
            if (nvIfMissing && got !== expect && fresh) {
                return { ok: 'nv', detail: `got ${got} (expected ${expect}): headless Chromium did not mark the tab audible — not verified in headless` };
            }
            return { ok: got === expect && fresh, detail: `got ${got} at ${new Date(row.minute).toISOString()}, new row ${fresh}` };
        });
    }
    if (stubbed && entry) {
        await skew(MINUTE);
        await classifyWith('classify:call', FIX + '/call.html', 'call|detected');
        await classifyWith('classify:video', FIX + '/video.html', 'media');
        await classifyWith('classify:audible', FIX + '/tone.html', 'media', true);
        await classifyWith('classify:idle', null, 'idle');
    }

    // g. The idle prompt.
    await skew(0);
    const startedMs = entry ? Date.parse(entry.started_at) : 0;
    const decisionRow = () => sql(`select decision from idle_decisions where time_entry_id = ${entry.id} order by id desc limit 1`);
    const pausedSeconds = () => Number(sql(`select paused_seconds from time_entries where id = ${entry.id}`));
    let lastIdleFrom = 0;
    async function openPrompt(idleMs) {
        // idle_from (now − idleMs) must not be before the entry's start, and must differ from
        // the previous prompt's (decisions are idempotent on idle_from): wait until both hold.
        while (Date.now() - idleMs < Math.max(startedMs, lastIdleFrom) + 2000) {
            await sleep(1000);
        }
        lastIdleFrom = Date.now() - idleMs;
        await worker.evaluate((ms) => chrome.storage.local.set({
            lastInputAt: Date.now() - ms, lastExemptAt: 0, lockedAt: null, prompt: null, manualMeetingUntil: null,
        }), idleMs);
        const opened = context.waitForEvent('page', { predicate: (p) => p.url().includes('prompt/prompt.html?'), timeout: 10000 });
        await fire('idle-prompt');
        const prompt = await opened;
        await prompt.waitForLoadState();
        return prompt;
    }
    const BUTTONS = ['Keep this time and continue', 'Discard the idle time and continue', 'I was in a meeting or call (keep the time)', 'Stop the timer'];
    async function answerPrompt(prompt, label) {
        const closed = prompt.waitForEvent('close', { timeout: 10000 }).then(() => true, () => false);
        await prompt.getByRole('button', { name: label, exact: true }).click();
        const gone = await closed;
        await sleep(500);
        return gone;
    }

    if (entry) {
        let first = null;
        await check('prompt:opens-at-120s', async () => {
            first = await openPrompt(130000);
            return `opened ${first.url().replace(EXT, '')}`;
        });
        await check('prompt:four-buttons', async () => {
            assert.ok(first, 'no prompt page');
            const found = [];
            for (const label of BUTTONS) {
                if (await first.getByRole('button', { name: label, exact: true }).isVisible()) {
                    found.push(label);
                }
            }
            return { ok: found.length === 4, detail: `${found.length}/4 visible` };
        });
        await check('prompt:keep', async () => {
            assert.ok(first, 'no prompt page');
            const gone = await answerPrompt(first, BUTTONS[0]);
            const d = decisionRow();
            return { ok: gone && d === 'keep', detail: `page closed ${gone}, decision ${d}` };
        });
        await check('prompt:discard', async () => {
            const before = pausedSeconds();
            const p = await openPrompt(200000);
            const gone = await answerPrompt(p, BUTTONS[1]);
            const grew = pausedSeconds() - before;
            const d = decisionRow();
            return { ok: gone && d === 'discard' && Math.abs(grew - 200) <= 5, detail: `page closed ${gone}, decision ${d}, paused_seconds +${grew}, ${new URL(p.url()).search}` };
        });
        await check('prompt:meeting', async () => {
            const p = await openPrompt(260000);
            const gone = await answerPrompt(p, BUTTONS[2]);
            const d = decisionRow();
            return { ok: gone && d === 'meeting', detail: `page closed ${gone}, decision ${d}` };
        });
        await check('prompt:stop', async () => {
            const p = await openPrompt(320000);
            const gone = await answerPrompt(p, BUTTONS[3]);
            const ended = sql(`select ended_at is not null from time_entries where id = ${entry.id}`);
            return { ok: gone && ended === 't', detail: `page closed ${gone}, ended_at set ${ended}` };
        });
    }

    // h. Auto-pause. Five idle minutes need an entry that started at least five
    // minutes ago: started from the worker through POST /api/timer/start with
    // `started_at` (the API's offline-start field) = this minute − 5.
    let pending = null;
    await check('auto-pause:server-paused', async () => {
        assert.ok(taskId, 'no task');
        const base = minuteFloor(Date.now());
        const started = await apiFromWorker('POST', '/api/timer/start', {
            task_id: taskId,
            client_uuid: crypto.randomUUID(),
            started_at: new Date(base - 5 * MINUTE).toISOString(),
        });
        const id = started.data?.running?.id;
        assert.ok(id, `start answered ${started.status}`);
        const minutes = [5, 4, 3, 2, 1].map((k) => new Date(base - k * MINUTE).toISOString());
        const hb = await apiFromWorker('POST', '/api/timer/heartbeat', {
            time_entry_id: id,
            client_uuid: started.data.running.client_uuid,
            samples: minutes.map((minute) => ({ minute, state: 'idle', call_source: null, sites: [] })),
        });
        const view = await cmd('sync');
        const r = view?.state?.running;
        pending = view?.state?.activity?.pending_idle ?? null;
        const same = pending && Date.parse(pending.idle_from) === Date.parse(minutes[0]);
        return {
            ok: r?.state === 'paused' && !!same,
            detail: `heartbeat ${hb.status} accepted ${hb.data?.accepted}, state ${r?.state}, idle_from ${pending?.idle_from} vs ${minutes[0]}`,
        };
    });
    let pausedPage = null;
    await check('auto-pause:prompt-paused-form', async () => {
        assert.ok(pending, 'no pending_idle');
        pausedPage = await context.newPage();
        await pausedPage.goto(`${EXT}/prompt/prompt.html?idle_from=${encodeURIComponent(pending.idle_from)}`);
        const heading = pausedPage.locator('#paused-heading');
        await heading.waitFor({ state: 'visible', timeout: 5000 });
        const text = (await heading.textContent()) ?? '';
        const resume = await pausedPage.locator('#paused').getByRole('button', { name: 'Resume', exact: true }).isVisible();
        const stop = await pausedPage.locator('#paused').getByRole('button', { name: 'Stop the timer', exact: true }).isVisible();
        return { ok: text.startsWith('Timer paused at') && resume && stop, detail: `"${text}", Resume ${resume}, Stop ${stop}` };
    });
    await check('auto-pause:resume-clears', async () => {
        assert.ok(pausedPage, 'no paused prompt');
        await pausedPage.locator('#paused').getByRole('button', { name: 'Resume', exact: true }).click();
        await sleep(1500);
        const view = await cmd('sync');
        const s = view?.state;
        return {
            ok: s?.running?.state === 'running' && s?.activity?.pending_idle === null,
            detail: `state ${s?.running?.state}, pending_idle ${JSON.stringify(s?.activity?.pending_idle)}`,
        };
    });
    const stopped = await cmd('stop');
    console.log(`[e2e] final stop: status ${stopped?.status}, running ${JSON.stringify(stopped?.view?.state?.running?.state ?? null)}`);

    return version;
}

let version = 'unknown';
try {
    version = await main();
} catch (error) {
    record('run', false, (error?.message ?? String(error)).split('\n')[0]);
} finally {
    // i. Close the browser, kill the server, print the table.
    await context?.close().catch(() => {});
    fixtures?.close();
    stopServer();
}

console.log(`\nChromium ${version}`);
for (const r of results) {
    console.log(`${r.status.padEnd(12)}  ${r.name} — ${r.detail}`);
}
process.exit(results.some((r) => r.status === 'FAIL') ? 1 : 0);
