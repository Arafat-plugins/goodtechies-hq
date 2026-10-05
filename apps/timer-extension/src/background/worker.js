// worker.js — the extension's MV3 service worker.
//
// Built to docs/extension-api.md. Two hard rules for an MV3 worker:
//   1. It dies after ~30 s without events, so NOTHING lives in module globals
//      and no JS timers are used: every value is in
//      chrome.storage.local (§9) and time is driven by chrome.alarms.
//   2. Listeners are registered synchronously at the top level, so Chrome can
//      wake the worker for them.
//
// Privacy: the worker reads `tab.url` only to derive a host (sites.js). Pages
// never send it anything but two booleans (messages.js). Nothing about sites
// is tracked while the timer is paused or stopped.
//
// Commands from the extension's pages (D4) arrive as
// {type: 'gt-cmd', cmd, payload} and are answered by handleCommand():
//   getState / sync / setServer / meeting → the view (see view())
//   getTasks, start, pause, resume, stop, idleDecision, pair, disconnect
//     → the API result {ok, status, data, offline?, reconnect?} plus `view`
//   pair with a non-manifest server not yet granted → {needsPermission: '<origin>/*'}

import { classify, callSource } from '../lib/classifier.js';
import { idleClock, exemptNow } from '../lib/idleClock.js';
import { classifyUrl, applyEvent, summarise, rollWindow } from '../lib/sites.js';
import { clockOffset, formatDuration } from '../lib/formula.js';
import { enqueue, take, drop, size } from '../lib/queue.js';
import { parseMediaMessage, parseCommand } from '../lib/messages.js';
import { request, endpoints, originOf, needsPermission } from '../lib/api.js';
import { read, write, DEFAULTS } from '../lib/storage.js';
import { badgeFor } from '../lib/badge.js';

const MINUTE = 60000;
const IDLE_DETECTION_SECONDS = 60;
const PROMPT_PAGE = 'prompt/prompt.html';

// ---------------------------------------------------------------------------
// Storage helpers
// ---------------------------------------------------------------------------

/** Everything in storage, with the §9 defaults filled in for missing keys. */
async function load() {
    const stored = await read(null);
    const s = structuredClone(DEFAULTS);
    for (const key of Object.keys(DEFAULTS)) {
        if (stored[key] !== undefined) {
            s[key] = stored[key];
        }
    }
    return s;
}

/** Write back only the listed keys of `s` (so concurrent writers of other keys are not clobbered). */
function save(s, keys) {
    const patch = {};
    for (const key of keys) {
        patch[key] = s[key];
    }
    return write(patch);
}

/** The plain data the UI pages read. */
function view(s) {
    return {
        state: s.state,
        state_at: s.state_at,
        server: s.server,
        token: !!s.token,
        device: s.device,
        prompt: s.prompt,
        manualMeetingUntil: s.manualMeetingUntil,
        queueSize: size(s.queue),
    };
}

/** True while the server says the entry is running (not paused, not stopped). */
function isRunning(s) {
    return s.state?.running?.state === 'running';
}

/** One API call with the stored server and token. */
function api(s, method, path, body) {
    return request(fetch, { server: s.server, token: s.token, method, path, body });
}

/** Every 401 clears the pairing. */
function afterApi(s, res) {
    if (res.status === 401) {
        s.token = null;
        s.device = null;
    }
    return res;
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

function setup() {
    chrome.idle.setDetectionInterval(IDLE_DETECTION_SECONDS);
    chrome.alarms.create('tick', { periodInMinutes: 1 });
}

async function onInstalled() {
    const stored = await read(null);
    const missing = {};
    for (const [key, value] of Object.entries(DEFAULTS)) {
        if (stored[key] === undefined) {
            missing[key] = structuredClone(value);
        }
    }
    if ((stored.install_uuid ?? null) === null) {
        missing.install_uuid = crypto.randomUUID();
    }
    // An unknown input time would start the idle clock in 1970.
    if ((stored.lastInputAt ?? 0) === 0) {
        missing.lastInputAt = Date.now();
    }
    await write(missing);
    setup();
}

async function onStartup() {
    const stored = await read(['lastInputAt']);
    // An unknown input time would start the idle clock in 1970.
    if ((stored.lastInputAt ?? 0) === 0) {
        await write({ lastInputAt: Date.now() });
    }
    setup();
}

// ---------------------------------------------------------------------------
// State from the server
// ---------------------------------------------------------------------------

/**
 * Adopt a State from a response (mutates `s`; the caller saves).
 * Not running → close the site segment, drop the queue (the server stores
 * nothing for a paused/stopped entry), and close the prompt unless the server
 * has auto-paused for idle (then the prompt page shows that message itself).
 */
async function applyState(s, data) {
    if (!data || typeof data !== 'object') {
        return;
    }
    const now = Date.now();
    s.state = data;
    s.state_at = now;

    if (!data.running || data.running.state !== 'running') {
        s.sites = applyEvent(s.sites, { type: 'stop' }, now);
        for (const entryId of Object.keys(s.queue ?? {})) {
            s.queue = drop(s.queue, entryId);
        }
        if (s.prompt && !data.activity?.pending_idle) {
            await closePrompt(s.prompt);
            s.prompt = null;
        }
    }
}

const STATE_KEYS = ['state', 'state_at', 'sites', 'queue', 'prompt', 'token', 'device'];

// ---------------------------------------------------------------------------
// Sites
// ---------------------------------------------------------------------------

/** Where the person is right now: Chrome not focused → other_app, else the active tab. */
async function currentPlaceEvent() {
    const win = await chrome.windows.getLastFocused().catch(() => null);
    if (!win || !win.focused) {
        return { type: 'unfocus' };
    }
    const [tab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
    const { kind, host } = classifyUrl(tab?.url, { incognito: tab?.incognito === true });
    return { type: 'focus', kind, host };
}

/** Re-read where the person is and update the open segment (only while running). */
async function refocus() {
    const s = await load();
    if (!s.token || !isRunning(s)) {
        return;
    }
    s.sites = applyEvent(s.sites, await currentPlaceEvent(), Date.now());
    await save(s, ['sites']);
}

/** Chrome lost focus (another app is in front). */
async function unfocus() {
    const s = await load();
    if (!s.token || !isRunning(s)) {
        return;
    }
    s.sites = applyEvent(s.sites, { type: 'unfocus' }, Date.now());
    await save(s, ['sites']);
}

async function forgetFrames(tabId) {
    const s = await load();
    if (s.frames[tabId] !== undefined) {
        delete s.frames[tabId];
        await save(s, ['frames']);
    }
}

// ---------------------------------------------------------------------------
// The minute tick
// ---------------------------------------------------------------------------

async function tick() {
    const s = await load();
    const now = Date.now();

    if (!s.token || !s.state?.running) {
        await chrome.action.setBadgeText({ text: '' });
        if (s.token) {
            await handleCommand({ cmd: 'sync', payload: {} });
        }
        return;
    }

    // The minute that has just ended.
    const minuteStart = Math.floor((now - MINUTE) / MINUTE) * MINUTE;

    // Inputs.
    const idleState = await chrome.idle.queryState(IDLE_DETECTION_SECONDS);
    if (idleState === 'active') {
        s.lastInputAt = now;
    }
    const audibleTabs = (await chrome.tabs.query({ audible: true })).length > 0;
    let videoPlaying = false;
    let streamLive = false;
    for (const frames of Object.values(s.frames ?? {})) {
        for (const frame of Object.values(frames ?? {})) {
            videoPlaying = videoPlaying || frame?.videoPlaying === true;
            streamLive = streamLive || frame?.streamLive === true;
        }
    }
    const inputs = { idleState, audibleTabs, videoPlaying, streamLive, manualMeetingUntil: s.manualMeetingUntil, now };
    const minuteState = classify(inputs);
    s.lastExemptAt = exemptNow(s.lastExemptAt, { state: minuteState, now });

    if (isRunning(s)) {
        // Sites: bring the open segment up to date, summarise the minute, then forget it.
        s.sites = applyEvent(s.sites, await currentPlaceEvent(), now);
        const sitesSummary = minuteState === 'idle' ? [] : summarise(s.sites, minuteStart, minuteStart + MINUTE);
        s.sites = rollWindow(s.sites, minuteStart + MINUTE);

        const running = s.state.running;
        s.queue = enqueue(s.queue, running.id, {
            minute: new Date(minuteStart).toISOString(),
            state: minuteState,
            call_source: minuteState === 'call' ? callSource(inputs) : null,
            sites: sitesSummary,
        });
    }

    const unauthorised = await flush(s);
    await save(s, ['lastInputAt', 'lastExemptAt', ...STATE_KEYS]);

    if (unauthorised) {
        await chrome.action.setBadgeText({ text: '!' });
        return;
    }

    await maybePrompt();
    await setBadge();
}

/**
 * Send the queue, one heartbeat per entry (mutates `s`).
 * @returns {Promise<boolean>} true when the server answered 401.
 */
async function flush(s) {
    for (const entryId of Object.keys(s.queue ?? {})) {
        const { batch, rest } = take(s.queue, entryId, 120);
        if (batch.length === 0) {
            continue;
        }
        const res = afterApi(s, await api(s, 'POST', endpoints.heartbeat, {
            time_entry_id: Number(entryId),
            client_uuid: s.state?.running?.client_uuid,
            samples: batch,
        }));

        if (res.ok) {
            // Stored: keep only what was not in this batch, adopt the returned state.
            s.queue = rest;
            await applyState(s, res.data);
        } else if (res.status === 409) {
            // Entry no longer running: its samples can never be stored, adopt the returned state.
            s.queue = drop(s.queue, entryId);
            await applyState(s, res.data?.state);
        } else if (res.status === 422 || res.status === 404) {
            // 422: this batch is invalid, drop it (not retried every minute); 404: unknown entry, drop its whole queue.
            s.queue = res.status === 404 ? drop(s.queue, entryId) : rest;
            continue;
        } else if (res.offline) {
            // Keep the queue; it is replayed on the next tick.
            return false;
        } else if (res.status === 401) {
            // token and device are cleared by afterApi; nothing else is kept.
            s.queue = {};
            s.sites = applyEvent(s.sites, { type: 'stop' }, Date.now());
            return true;
        } else {
            // Any other refusal (429, 5xx, …): keep the queue unchanged and retry on the next tick.
            continue;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Idle prompt
// ---------------------------------------------------------------------------

function promptAfterMs(s) {
    return (s.state?.activity?.idle_prompt_seconds ?? 120) * 1000;
}

function pauseAfterMs(s) {
    return (s.state?.activity?.idle_pause_minutes ?? 5) * MINUTE;
}

async function scheduleIdlePrompt() {
    const s = await load();
    const now = Date.now();
    const { idleSince } = idleClock({
        lastInputAt: s.lastInputAt,
        lastExemptAt: s.lastExemptAt,
        lockedAt: s.lockedAt,
        now,
        promptAfterMs: promptAfterMs(s),
        pauseAfterMs: pauseAfterMs(s),
    });
    chrome.alarms.create('idle-prompt', { when: Math.max(now + 30000, idleSince + promptAfterMs(s)) });
}

/** Is the stored prompt still on screen? */
async function promptStillOpen(prompt) {
    if (prompt.windowId !== null && prompt.windowId !== undefined) {
        return chrome.windows.get(prompt.windowId).then(() => true, () => false);
    }
    if (prompt.notificationId) {
        const all = await chrome.notifications.getAll();
        return Object.prototype.hasOwnProperty.call(all, prompt.notificationId);
    }
    return false;
}

/** Close the prompt window or notification, ignoring one that is already gone. */
async function closePrompt(prompt) {
    if (!prompt) {
        return;
    }
    if (prompt.windowId !== null && prompt.windowId !== undefined) {
        await chrome.windows.remove(prompt.windowId).catch(() => {});
    }
    if (prompt.notificationId) {
        await chrome.notifications.clear(prompt.notificationId).catch(() => {});
    }
}

/**
 * Show "Are you still working?" when the idle clock says so.
 * `override.idleSince` is used on unlock: the stretch measured before the
 * person came back (their input has just reset lastInputAt).
 */
async function maybePrompt(override = null) {
    const s = await load();
    const now = Date.now();
    if (!s.token || !isRunning(s)) {
        return;
    }
    const clock = idleClock({
        lastInputAt: s.lastInputAt,
        lastExemptAt: s.lastExemptAt,
        lockedAt: s.lockedAt,
        now,
        promptAfterMs: promptAfterMs(s),
        pauseAfterMs: pauseAfterMs(s),
    });
    const idleSince = override ? override.idleSince : clock.idleSince;
    const promptDue = override ? true : clock.promptDue;
    if (!promptDue) {
        return;
    }
    if (s.prompt !== null && (await promptStillOpen(s.prompt))) {
        return;
    }

    const idleFrom = new Date(idleSince).toISOString();
    let windowId = null;
    let notificationId = null;
    try {
        const win = await chrome.windows.create({
            url: chrome.runtime.getURL(PROMPT_PAGE) + '?idle_from=' + idleFrom,
            type: 'popup',
            width: 440,
            height: 400,
            focused: true,
        });
        windowId = win.id;
    } catch {
        notificationId = await chrome.notifications.create({
            type: 'basic',
            iconUrl: 'icons/icon-128.png',
            title: 'Are you still working?',
            message: 'Your goodERP timer is running but there has been no activity for '
                + formatDuration((now - idleSince) / 1000) + '.',
            buttons: [{ title: 'Keep this time and continue' }, { title: 'Discard the idle time and continue' }],
            requireInteraction: true,
        });
    }

    s.prompt = { idle_from: idleFrom, windowId, notificationId };
    await save(s, ['prompt']);
}

// ---------------------------------------------------------------------------
// Badge
// ---------------------------------------------------------------------------

async function setBadge() {
    const s = await load();
    if (!s.token || !s.state) {
        await chrome.action.setBadgeText({ text: '' });
        return;
    }
    const { text, color } = badgeFor(s.state, Date.now(), clockOffset(s.state.server_time, s.state_at));
    await chrome.action.setBadgeText({ text });
    if (color) {
        await chrome.action.setBadgeBackgroundColor({ color });
    }
}

// ---------------------------------------------------------------------------
// Commands from the extension's pages
// ---------------------------------------------------------------------------

/** An API result for the page, with the view after it was applied. */
function answer(s, res) {
    return { ...res, view: view(s) };
}

const NOT_PAIRED = { ok: false, status: 0, data: null, error: 'not_paired' };
const NO_ENTRY = { ok: false, status: 0, data: null, error: 'no_timer_entry' };

async function handleCommand({ cmd, payload = {} }) {
    const s = await load();
    const now = Date.now();

    switch (cmd) {
        case 'getState':
            return view(s);

        case 'sync': {
            if (!s.token) {
                return view(s);
            }
            const res = afterApi(s, await api(s, 'GET', endpoints.state));
            if (res.ok) {
                await applyState(s, res.data);
            }
            await save(s, STATE_KEYS);
            return view(s);
        }

        case 'getTasks': {
            if (!s.token) {
                return NOT_PAIRED;
            }
            const res = afterApi(s, await api(s, 'GET', endpoints.tasks));
            await save(s, ['token', 'device']);
            return answer(s, res);
        }

        case 'start': {
            if (!s.token) {
                return NOT_PAIRED;
            }
            const res = afterApi(s, await api(s, 'POST', endpoints.start, {
                task_id: payload.task_id,
                client_uuid: crypto.randomUUID(),
            }));
            if (res.ok) {
                await applyState(s, res.data);
            }
            await save(s, STATE_KEYS);
            return answer(s, res);
        }

        case 'pause':
        case 'resume':
        case 'stop': {
            if (!s.token) {
                return NOT_PAIRED;
            }
            const running = s.state?.running;
            if (!running) {
                return NO_ENTRY;
            }
            const res = afterApi(s, await api(s, 'POST', endpoints[cmd], { time_entry_id: running.id }));
            if (res.ok) {
                await applyState(s, res.data);
            } else if (res.status === 409) {
                await applyState(s, res.data?.state);
            }
            await save(s, STATE_KEYS);
            return answer(s, res);
        }

        case 'idleDecision': {
            if (!s.token) {
                return NOT_PAIRED;
            }
            const running = s.state?.running;
            if (!running) {
                return NO_ENTRY;
            }
            const res = afterApi(s, await api(s, 'POST', endpoints.idleDecision, {
                time_entry_id: running.id,
                idle_from: payload.idle_from,
                idle_to: payload.idle_to,
                decision: payload.decision,
            }));
            s.lastInputAt = now;
            await closePrompt(s.prompt);
            s.prompt = null;
            if (res.ok) {
                await applyState(s, res.data);
            } else if (res.status === 409) {
                await applyState(s, res.data?.state);
            }
            await save(s, ['lastInputAt', ...STATE_KEYS]);
            return answer(s, res);
        }

        case 'meeting': {
            if (payload.cancel === true) {
                s.manualMeetingUntil = null;
                await chrome.alarms.clear('meeting-end');
            } else {
                const minutes = Number(payload.minutes) > 0
                    ? Number(payload.minutes)
                    : (s.state?.activity?.meeting_default_minutes ?? 60);
                s.manualMeetingUntil = now + minutes * MINUTE;
                s.lastExemptAt = now;
                chrome.alarms.create('meeting-end', { when: s.manualMeetingUntil });
            }
            await save(s, ['manualMeetingUntil', 'lastExemptAt']);
            return view(s);
        }

        case 'pair': {
            let origin;
            try {
                origin = originOf(payload.server);
            } catch {
                return { ok: false, status: 0, data: null, error: 'invalid_server' };
            }
            // chrome.permissions.request must run from the page's user gesture,
            // so the page asks and then sends `pair` again.
            if (needsPermission(origin) && !(await chrome.permissions.contains({ origins: [origin + '/*'] }))) {
                return { needsPermission: origin + '/*' };
            }
            if (s.install_uuid === null) {
                s.install_uuid = crypto.randomUUID();
            }
            const res = await request(fetch, {
                server: origin,
                token: null,
                method: 'POST',
                path: endpoints.exchange,
                body: { code: payload.code, device_name: payload.device_name, install_uuid: s.install_uuid },
            });
            if (res.ok && res.data?.token) {
                s.server = origin;
                s.token = res.data.token;
                s.device = res.data.device ?? null;
                await applyState(s, res.data.state);
            }
            await save(s, ['server', 'install_uuid', ...STATE_KEYS]);
            return answer(s, res);
        }

        case 'disconnect': {
            let res = { ok: true, status: 0, data: null };
            if (s.token) {
                res = await api(s, 'POST', endpoints.disconnect);
            }
            await closePrompt(s.prompt);
            s.token = null;
            s.device = null;
            s.state = null;
            s.queue = {};
            s.prompt = null;
            s.sites = applyEvent(s.sites, { type: 'stop' }, now);
            await save(s, STATE_KEYS);
            await chrome.action.setBadgeText({ text: '' });
            return answer(s, res);
        }

        case 'setServer': {
            if (s.token) {
                return view(s);
            }
            try {
                s.server = originOf(payload.server);
            } catch {
                return { ok: false, status: 0, data: null, error: 'invalid_server', view: view(s) };
            }
            await save(s, ['server']);
            return view(s);
        }

        default:
            return { ok: false, status: 0, data: null, error: 'unknown_command' };
    }
}

// ---------------------------------------------------------------------------
// Event handlers
// ---------------------------------------------------------------------------

async function onIdleStateChanged(idleState) {
    const now = Date.now();
    const s = await load();

    if (idleState === 'active') {
        // A prompt that came due while the screen was locked is shown now, on unlock,
        // for the stretch measured before this input reset the clock.
        const before = idleClock({
            lastInputAt: s.lastInputAt,
            lastExemptAt: s.lastExemptAt,
            lockedAt: null,
            now,
            promptAfterMs: promptAfterMs(s),
            pauseAfterMs: pauseAfterMs(s),
        });
        const pendingOnUnlock = s.lockedAt !== null && isRunning(s) && before.promptDue;

        s.lastInputAt = now;
        s.lockedAt = null;
        await save(s, ['lastInputAt', 'lockedAt']);
        if (pendingOnUnlock) {
            await maybePrompt({ idleSince: before.idleSince });
        }
        return;
    }

    if (idleState === 'idle') {
        // Fires after 60 s without input: input was last seen at least 60 s ago.
        s.lastInputAt = Math.min(s.lastInputAt, now - IDLE_DETECTION_SECONDS * 1000);
        await save(s, ['lastInputAt']);
        await scheduleIdlePrompt();
        return;
    }

    if (idleState === 'locked') {
        s.lockedAt = now;
        await save(s, ['lockedAt']);
        await chrome.alarms.clear('idle-prompt');
    }
}

async function onMediaMessage(media, sender) {
    if (!sender.tab) {
        return;
    }
    const s = await load();
    const tabId = String(sender.tab.id);
    const frameId = String(sender.frameId ?? 0);
    s.frames[tabId] = { ...(s.frames[tabId] ?? {}), [frameId]: media };
    await save(s, ['frames']);
}

/** Commands are accepted only from the extension's own pages. */
function fromExtensionPage(sender) {
    return sender.id === chrome.runtime.id
        && typeof sender.url === 'string'
        && sender.url.startsWith(chrome.runtime.getURL(''));
}

async function onNotificationButton(notificationId, index) {
    const s = await load();
    if (!s.prompt || s.prompt.notificationId !== notificationId) {
        return;
    }
    const decision = index === 0 ? 'keep' : index === 1 ? 'discard' : null;
    if (!decision) {
        return;
    }
    await handleCommand({
        cmd: 'idleDecision',
        payload: { decision, idle_from: s.prompt.idle_from, idle_to: new Date().toISOString() },
    });
}

async function onWindowRemoved(windowId) {
    const s = await load();
    if (s.prompt && s.prompt.windowId === windowId) {
        s.prompt = null;
        await save(s, ['prompt']);
    }
}

/** Run an async handler and log (never throw out of) its failure. */
function guard(fn) {
    return (...args) => {
        fn(...args).catch((error) => console.error('[goodERP Timer]', error));
    };
}

// ---------------------------------------------------------------------------
// Listeners — registered synchronously at the top level.
// ---------------------------------------------------------------------------

chrome.runtime.onInstalled.addListener(guard(onInstalled));
chrome.runtime.onStartup.addListener(guard(onStartup));

chrome.alarms.onAlarm.addListener(guard(async (alarm) => {
    if (alarm.name === 'tick') {
        await tick();
    } else if (alarm.name === 'idle-prompt') {
        await maybePrompt();
    } else if (alarm.name === 'meeting-end') {
        await write({ manualMeetingUntil: null });
    }
}));

chrome.idle.onStateChanged.addListener(guard(onIdleStateChanged));

chrome.tabs.onActivated.addListener(guard(async () => refocus()));
chrome.tabs.onUpdated.addListener(guard(async (tabId, changeInfo, tab) => {
    if (changeInfo.status === 'loading') {
        await forgetFrames(String(tabId));
    }
    if (tab?.active && (changeInfo.url || changeInfo.status === 'loading')) {
        await refocus();
    }
}));
chrome.tabs.onRemoved.addListener(guard(async (tabId) => forgetFrames(String(tabId))));

chrome.windows.onFocusChanged.addListener(guard(async (windowId) => {
    if (windowId === chrome.windows.WINDOW_ID_NONE) {
        await unfocus();
    } else {
        await refocus();
    }
}));
chrome.windows.onRemoved.addListener(guard(onWindowRemoved));

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    const media = parseMediaMessage(msg);
    if (media) {
        guard(onMediaMessage)(media, sender);
        return false;
    }
    const command = parseCommand(msg);
    if (command && fromExtensionPage(sender)) {
        handleCommand(command).then(sendResponse, (error) => {
            console.error('[goodERP Timer]', error);
            sendResponse({ ok: false, status: 0, data: null, error: 'internal_error' });
        });
        return true; // the response is sent asynchronously
    }
    return false;
});

chrome.notifications.onButtonClicked.addListener(guard(onNotificationButton));
