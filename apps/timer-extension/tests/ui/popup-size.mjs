// popup-size.mjs — the three pages fit their fixed sizes, with a fake `chrome`.
//
// Opens popup, prompt and options from file:// for three canned worker views
// (not paired; running; auto-paused) and checks there is no horizontal
// overflow anywhere and no vertical overflow in the popup and the prompt.
// Also checks the running popup shows the right H:MM:SS and "left today".
//
// Run: npm run test:ui   (PLAYWRIGHT_BROWSERS_PATH must point at the browsers)

import { chromium } from '/home/claude/goodtechies-hq/node_modules/playwright/index.mjs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';
import { formatHms, formatDuration } from '../../src/lib/formula.js';

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src');
const NOW = Date.parse('2026-10-05T10:00:00Z');
const iso = (ms) => new Date(ms).toISOString();

const ACTIVITY = {
    idle_prompt_seconds: 120,
    idle_pause_minutes: 5,
    meeting_default_minutes: 60,
    pending_idle: null,
    last_sample_minute: null,
    last_sample_source: 'extension',
};
const DEVICE = { id: 3, name: 'Chrome extension', paired_at: iso(NOW - 86400000) };

function pairedView(running, activity, prompt) {
    return {
        state: {
            server_time: iso(NOW),
            running,
            today: { date: '2026-10-05', counted_seconds: 9120, pending_seconds: 0, target_seconds: 18000 },
            heartbeat_seconds: 60,
            heartbeat_timeout_minutes: 5,
            manual_time_requires_approval: true,
            activity,
            device: DEVICE,
        },
        state_at: NOW,
        server: 'https://erp.goodtechies.com',
        token: true,
        device: DEVICE,
        prompt,
        manualMeetingUntil: null,
        queueSize: 0,
    };
}

const ENTRY = {
    id: 41,
    client_uuid: '6f1c2c1e-9a8f-4c55-9d43-1d3f3c2a7b10',
    task: { id: 7, name: 'Homepage redesign: hero section and navigation' },
    project: { id: 2, name: 'Woodford website' },
    started_at: iso(NOW - 3723000),
    paused_at: null,
    paused_seconds: 0,
    elapsed_seconds: 3723,
    state: 'running',
};

const FIXTURES = {
    'not-paired': {
        state: null,
        state_at: 0,
        server: 'https://erp.goodtechies.com',
        token: false,
        device: null,
        prompt: null,
        manualMeetingUntil: null,
        queueSize: 0,
    },
    running: pairedView(ENTRY, ACTIVITY, null),
    'auto-paused': pairedView(
        { ...ENTRY, state: 'paused', paused_at: iso(NOW - 360000) },
        { ...ACTIVITY, pending_idle: { idle_from: iso(NOW - 360000), auto_paused_at: iso(NOW - 60000) } },
        { idle_from: iso(NOW - 360000), windowId: 12, notificationId: null },
    ),
};

const PAGES = [
    { name: 'popup', path: 'popup/popup.html', width: 320, height: 560, vertical: true },
    { name: 'prompt', path: 'prompt/prompt.html', width: 440, height: 400, vertical: true, idle: true },
    { name: 'options', path: 'options/options.html', width: 800, height: 900, vertical: false },
];

/** Installed before any page script: a fake `chrome` answering gt-cmd messages from the fixture. */
function fakeChrome(view) {
    const tasks = [
        { id: 7, title: 'Homepage redesign: hero section and navigation', project: 'Woodford website' },
        { id: 8, title: 'Weekly report', project: null },
    ];
    const store = {};
    const reply = (cmd) => {
        switch (cmd) {
            case 'getState':
            case 'sync':
            case 'meeting':
            case 'setServer':
                return view;
            case 'getTasks':
                return { ok: true, status: 200, data: { tasks }, view };
            default:
                return { ok: true, status: 200, data: null, view };
        }
    };
    window.chrome = {
        runtime: {
            sendMessage: (msg) => Promise.resolve(structuredClone(reply(msg.cmd))),
            openOptionsPage: () => Promise.resolve(),
        },
        storage: {
            local: {
                get: (key) => Promise.resolve(key in store ? { [key]: store[key] } : {}),
                set: (obj) => Promise.resolve(Object.assign(store, obj)),
            },
        },
        permissions: { request: () => Promise.resolve(true) },
    };
}

const browser = await chromium.launch({ args: ['--allow-file-access-from-files'] });
let failed = 0;

for (const pageSpec of PAGES) {
    for (const [fixtureName, view] of Object.entries(FIXTURES)) {
        const context = await browser.newContext({ viewport: { width: pageSpec.width, height: pageSpec.height } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.clock.setFixedTime(NOW);
        await page.addInitScript(fakeChrome, view);

        let url = pathToFileURL(join(SRC, pageSpec.path)).href;
        if (pageSpec.idle) {
            url += '?idle_from=' + encodeURIComponent(iso(NOW - 130000));
        }
        await page.goto(url);
        await page.waitForLoadState('load');
        await page.waitForTimeout(400);

        // documentElement and body both: a page that makes body its own scroller
        // (overflow on html and body) hides its overflow from documentElement.
        const size = await page.evaluate(() => ({
            scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
            scrollHeight: Math.max(document.documentElement.scrollHeight, document.body.scrollHeight),
            innerWidth,
            innerHeight,
        }));
        const label = `${pageSpec.name} ${fixtureName}`;
        const over = Math.max(
            size.scrollWidth - size.innerWidth,
            pageSpec.vertical ? size.scrollHeight - size.innerHeight : 0,
        );
        if (over > 0) {
            failed++;
            console.log(`${label} overflow ${over}px`);
        } else {
            console.log(`${label} ok`);
        }

        if (errors.length) {
            failed++;
            console.log(`${label} page error: ${errors.join(' | ')}`);
        }

        if (pageSpec.name === 'popup' && fixtureName === 'running') {
            const elapsed = view.state.running.elapsed_seconds;
            const today = view.state.today;
            const wantCounter = formatHms(elapsed);
            const wantLeft = `${formatDuration(today.target_seconds - (today.counted_seconds + elapsed))} left today`;
            const counter = (await page.textContent('#counter')).trim();
            const left = (await page.textContent('#run [data-left]')).trim();
            const ok = counter === wantCounter && left === wantLeft;
            if (!ok) {
                failed++;
            }
            console.log(`${label} counter "${counter}" left "${left}" ${ok ? 'ok' : `want "${wantCounter}" "${wantLeft}"`}`);
        }

        await context.close();
    }
}

await browser.close();
console.log(failed ? `# ui fail ${failed}` : '# ui pass');
process.exit(failed ? 1 : 0);
