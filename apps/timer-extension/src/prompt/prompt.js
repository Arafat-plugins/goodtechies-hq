// prompt.js — "Are you still working?". Opened by the worker as a 440 × 400 popup window
// with ?idle_from=<ISO time>. Talks to the worker only through send().

import { TEXT } from '../ui/text.js';
import { send, every } from '../ui/client.js';

const $ = (id) => document.getElementById(id);
const MINUTE = 60000;

const idleFrom = new URLSearchParams(location.search).get('idle_from');
const idleFromMs = Date.parse(idleFrom);

let view = null;
let busy = false;

function applyText() {
    for (const el of document.querySelectorAll('[data-text]')) {
        el.textContent = TEXT[el.dataset.text];
    }
    document.title = TEXT.PROMPT_TITLE;
}

/** Local "HH:MM" for a timestamp. */
function hhmm(ms) {
    const d = new Date(ms);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/** "M:SS" for a number of seconds. */
function mss(seconds) {
    const total = Math.max(0, Math.floor(seconds));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

function pendingIdle() {
    return view?.state?.activity?.pending_idle ?? null;
}

function render() {
    const pending = pendingIdle();
    const wasPaused = !$('paused').hidden;
    $('ask').hidden = !!pending;
    $('paused').hidden = !pending;
    if (pending) {
        $('paused-heading').textContent = TEXT.PROMPT_PAUSED_HEADING.replace('HH:MM', hhmm(Date.parse(pending.idle_from)));
        if (!wasPaused) {
            $('resume').focus();
        }
    }
    tick();
}

function tick() {
    if (pendingIdle() || Number.isNaN(idleFromMs)) {
        return;
    }
    const now = Date.now();
    $('inactive').textContent = TEXT.PROMPT_INACTIVE.replace('M:SS', mss((now - idleFromMs) / 1000));
    const pauseMinutes = view?.state?.activity?.idle_pause_minutes ?? 5;
    $('pauses-at').textContent = TEXT.PROMPT_PAUSES_AT.replace('HH:MM', hhmm(idleFromMs + pauseMinutes * MINUTE));
}

/** Send one command, then close the window. */
async function answer(cmd, payload) {
    if (busy) {
        return;
    }
    busy = true;
    for (const button of document.querySelectorAll('button')) {
        button.disabled = true;
    }
    try {
        await send(cmd, payload);
    } catch {
        // The worker answers every command; a failure here still closes the window.
    }
    window.close();
}

async function refresh() {
    try {
        const reply = await send('getState');
        if (reply && typeof reply === 'object') {
            view = reply;
            render();
        }
    } catch {
        // Keep showing the last known form.
    }
}

function init() {
    applyText();

    for (const button of document.querySelectorAll('[data-decision]')) {
        button.addEventListener('click', () => answer('idleDecision', {
            decision: button.dataset.decision,
            idle_from: idleFrom,
            idle_to: new Date().toISOString(),
        }));
    }
    $('resume').addEventListener('click', () => answer('resume'));
    $('stop').addEventListener('click', () => answer('stop'));

    // The prompt must be answered or left open: Escape does nothing.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    tick();
    document.querySelector('[data-decision]').focus();
    every(1000, tick);
    every(5000, refresh);
    refresh();
}

init();
