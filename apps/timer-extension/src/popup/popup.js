// popup.js — the toolbar popup. Talks to the worker only through send().

import { TEXT } from '../ui/text.js';
import { send, every, fmt } from '../ui/client.js';
import { needsPermission, originOf } from '../lib/api.js';

const $ = (id) => document.getElementById(id);

let view = null;          // the worker's last view
let reconnect = false;    // a reply said the token is gone
let tasksLoaded = false;  // the task list is fetched once per "nothing running" stretch
let formFilled = false;   // server/device fields are prefilled once, then left to the person
let busy = false;

// ---------------------------------------------------------------------------
// Text
// ---------------------------------------------------------------------------

function applyText() {
    for (const el of document.querySelectorAll('[data-text]')) {
        el.textContent = TEXT[el.dataset.text];
    }
}

function defaultDeviceName() {
    const brands = navigator.userAgentData?.brands ?? [];
    return brands.some((b) => /edge/i.test(b.brand)) ? TEXT.DEVICE_DEFAULT_EDGE : TEXT.DEVICE_DEFAULT_CHROME;
}

function errorText(reply) {
    if (reply?.data?.message) {
        return reply.data.message;
    }
    switch (reply?.error) {
        case 'invalid_server':
            return TEXT.ERROR_INVALID_SERVER;
        case 'not_paired':
            return TEXT.ERROR_NOT_PAIRED;
        case 'no_timer_entry':
            return TEXT.ERROR_NO_ENTRY;
        default:
            return TEXT.ERROR_GENERIC;
    }
}

function showError(message) {
    $('error').textContent = message ?? '';
    $('error').hidden = !message;
}

// ---------------------------------------------------------------------------
// Replies
// ---------------------------------------------------------------------------

/** Adopt any reply: a bare view, or an API result {ok, status, data, offline?, reconnect?, view?}. */
function handle(reply) {
    if (!reply || typeof reply !== 'object') {
        return reply;
    }
    if (reply.view) {
        view = reply.view;
    } else if ('token' in reply && 'server' in reply) {
        view = reply;
    }
    if (reply.reconnect) {
        reconnect = true;
    }
    if (reply.offline) {
        $('offline').hidden = false;
    } else if (reply.ok === true) {
        $('offline').hidden = true;
    }
    if (view?.token) {
        reconnect = false;
    }
    render();
    return reply;
}

/** Run one command with the controls disabled; show the server's message on failure. */
async function run(cmd, payload) {
    if (busy) {
        return null;
    }
    busy = true;
    setDisabled(true);
    showError('');
    try {
        const reply = await send(cmd, payload);
        if (reply && reply.ok === false && !reply.offline) {
            showError(errorText(reply));
        }
        if (reply && reply.ok === false && !reply.view) {
            handle(await send('getState'));
        }
        return handle(reply);
    } catch {
        showError(TEXT.ERROR_GENERIC);
        return null;
    } finally {
        busy = false;
        setDisabled(false);
    }
}

function setDisabled(on) {
    for (const el of document.querySelectorAll('main button, main input, main select')) {
        if (el.id === 'settings' || el.id === 'disclosure-ok') {
            continue;
        }
        el.disabled = on;
    }
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

function mode() {
    if (!view) {
        return null;
    }
    if (!view.token) {
        return reconnect || view.device ? 'reconnect' : 'pair';
    }
    const state = view.state?.running?.state;
    return state === 'running' || state === 'paused' ? 'run' : 'idle';
}

function render() {
    const m = mode();
    $('pair').hidden = m !== 'pair' && m !== 'reconnect';
    $('idle').hidden = m !== 'idle';
    $('run').hidden = m !== 'run';

    if (m === 'pair' || m === 'reconnect') {
        $('pair-heading').textContent = m === 'reconnect' ? TEXT.RECONNECT_HEADING : TEXT.PAIR_HEADING;
        $('reconnect-line').hidden = m !== 'reconnect';
        if (!formFilled) {
            $('server').value = view.server ?? '';
            $('device-name').value = defaultDeviceName();
            formFilled = true;
        }
    }

    if (m !== 'idle') {
        tasksLoaded = false;
    } else if (!tasksLoaded) {
        tasksLoaded = true;
        loadTasks();
    }

    if (m === 'run') {
        const running = view.state.running;
        $('task-name').textContent = running.task?.name ?? '';
        const paused = running.state === 'paused';
        $('pause').hidden = paused;
        $('resume').hidden = !paused;
    }

    const chip = $('status-chip');
    if (m === 'run') {
        const paused = view.state.running.state === 'paused';
        chip.textContent = paused ? TEXT.STATUS_PAUSED : TEXT.STATUS_RUNNING;
        chip.dataset.state = view.state.running.state;
        chip.hidden = false;
    } else {
        chip.hidden = true;
    }

    tick();
}

/** The per-second part: counter, today, left today, meeting countdown. */
function tick() {
    if (!view?.token || !view.state) {
        return;
    }
    const state = view.state;
    const now = Date.now();
    const offset = fmt.clockOffset(state.server_time, view.state_at);
    const elapsed = fmt.elapsedSeconds(state, now, offset);
    $('counter').textContent = fmt.formatHms(elapsed);

    const counted = (Number(state.today?.counted_seconds) || 0) + elapsed;
    const target = state.today?.target_seconds;
    const todayText = target === null || target === undefined
        ? `${TEXT.TODAY_LABEL} ${fmt.formatDuration(counted)}`
        : `${TEXT.TODAY_LABEL} ${fmt.formatDuration(counted)} ${TEXT.TODAY_OF} ${fmt.formatDuration(target)}`;
    const left = fmt.leftTodaySeconds(state, now, offset);
    for (const el of document.querySelectorAll('[data-today]')) {
        el.textContent = todayText;
    }
    for (const el of document.querySelectorAll('[data-left]')) {
        el.textContent = left === null ? '' : `${fmt.formatDuration(left)} ${TEXT.LEFT_TODAY}`;
        el.hidden = left === null;
    }

    const until = view.manualMeetingUntil;
    const inMeeting = typeof until === 'number' && until > now;
    $('meeting').hidden = inMeeting;
    $('meeting-line').hidden = !inMeeting;
    if (inMeeting) {
        const rest = Math.ceil((until - now) / 1000);
        const mmss = `${String(Math.floor(rest / 60)).padStart(2, '0')}:${String(rest % 60).padStart(2, '0')}`;
        $('meeting-left').textContent = TEXT.MEETING_LEFT.replace('MM:SS', mmss);
    }
}

async function loadTasks() {
    const select = $('task');
    const reply = await send('getTasks').catch(() => null);
    handle(reply);
    const tasks = reply?.ok ? (reply.data?.tasks ?? []) : [];
    select.replaceChildren();
    const placeholder = new Option(TEXT.TASK_PLACEHOLDER, '');
    placeholder.disabled = true;
    placeholder.selected = true;
    select.add(placeholder);
    for (const task of tasks) {
        const label = task.project ? `${task.title} — ${task.project}` : task.title;
        select.add(new Option(label, String(task.id)));
    }
    $('no-tasks').hidden = !reply?.ok || tasks.length > 0;
    if (reply && reply.ok === false && !reply.offline) {
        showError(errorText(reply));
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

async function onConnect(event) {
    event.preventDefault();
    const server = $('server').value.trim();
    const code = $('code').value.replace(/\s+/g, '').toUpperCase();
    const deviceName = $('device-name').value.trim() || defaultDeviceName();
    $('code').value = code;
    if (code.length !== 8) {
        showError(TEXT.CODE_TOO_SHORT);
        $('code').focus();
        return;
    }
    const payload = { server, code, device_name: deviceName };
    $('connect').textContent = TEXT.CONNECTING;
    try {
        // Ask for a non-manifest server's permission here, in the click, before any
        // await on the worker: Chrome only grants it during the user gesture.
        let missing = false;
        try {
            missing = needsPermission(server);
        } catch {
            missing = false; // not a valid address: the worker answers invalid_server
        }
        if (missing) {
            const granted = await chrome.permissions.request({ origins: [originOf(server) + '/*'] });
            if (!granted) {
                showError(TEXT.PERMISSION_DENIED);
                return;
            }
        }
        let reply = await send('pair', payload);
        if (reply?.needsPermission) {
            const granted = await chrome.permissions.request({ origins: [reply.needsPermission] });
            if (!granted) {
                showError(TEXT.PERMISSION_DENIED);
                return;
            }
            reply = await run('pair', payload);
        } else {
            if (reply && reply.ok === false && !reply.offline) {
                showError(errorText(reply));
            }
            handle(reply);
        }
        if (reply?.ok) {
            $('code').value = '';
            showError('');
        }
    } catch {
        showError(TEXT.ERROR_GENERIC);
    } finally {
        $('connect').textContent = TEXT.CONNECT;
    }
}

function onStart(event) {
    event.preventDefault();
    const taskId = Number($('task').value);
    if (!taskId) {
        $('task').focus();
        return;
    }
    run('start', { task_id: taskId });
}

// ---------------------------------------------------------------------------
// Disclosure, shown once
// ---------------------------------------------------------------------------

async function showDisclosureOnce() {
    let seen = false;
    try {
        const stored = await chrome.storage.local.get('disclosure_seen');
        seen = stored?.disclosure_seen === true;
    } catch {
        seen = false;
    }
    if (seen) {
        return;
    }
    $('disclosure').hidden = false;
    // Once the person moves on to any control, the notice has been seen: hide it,
    // so an error line or the help text never pushes the popup past 560 px.
    const onFocus = (event) => {
        if (!$('disclosure').contains(event.target)) {
            $('disclosure').hidden = true;
            document.removeEventListener('focusin', onFocus);
        }
    };
    document.addEventListener('focusin', onFocus);
    try {
        await chrome.storage.local.set({ disclosure_seen: true });
    } catch {
        // Shown again next time; nothing else depends on it.
    }
}

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------

async function init() {
    applyText();

    $('code').addEventListener('input', (event) => {
        const el = event.target;
        el.value = el.value.replace(/\s+/g, '').toUpperCase();
    });
    $('pair-form').addEventListener('submit', onConnect);
    $('start-form').addEventListener('submit', onStart);
    $('pause').addEventListener('click', () => run('pause'));
    $('resume').addEventListener('click', () => run('resume'));
    $('stop').addEventListener('click', () => run('stop'));
    $('meeting').addEventListener('click', () => run('meeting', { minutes: 60 }));
    $('meeting-cancel').addEventListener('click', () => run('meeting', { cancel: true }));
    $('settings').addEventListener('click', () => chrome.runtime.openOptionsPage());
    $('disclosure-ok').addEventListener('click', () => {
        $('disclosure').hidden = true;
    });

    const syncing = send('sync');
    handle(await send('getState'));
    await showDisclosureOnce();
    every(1000, tick);
    every(5000, () => send('sync').then(handle, () => {}));
    handle(await syncing.catch(() => null));
}

init();
