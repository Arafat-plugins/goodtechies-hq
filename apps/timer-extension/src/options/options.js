// options.js — the options page. Talks to the worker only through send().

import { TEXT } from '../ui/text.js';
import { send } from '../ui/client.js';

const $ = (id) => document.getElementById(id);

let view = null;
let serverFilled = false;

function applyText() {
    for (const el of document.querySelectorAll('[data-text]')) {
        el.textContent = TEXT[el.dataset.text];
    }
    document.title = TEXT.OPTIONS_TITLE;
    $('install-steps').replaceChildren(...TEXT.INSTALL_STEPS.map((step) => {
        const li = document.createElement('li');
        li.textContent = step;
        return li;
    }));
}

function adopt(reply) {
    if (reply?.view) {
        view = reply.view;
    } else if (reply && 'token' in reply && 'server' in reply) {
        view = reply;
    }
    render();
}

function render() {
    if (!view) {
        return;
    }
    const paired = !!view.token;
    $('paired').hidden = !paired;
    $('not-paired').hidden = paired;
    $('disconnect').hidden = !paired;
    $('device-name').textContent = view.device?.name ?? '';

    $('server').disabled = paired;
    $('save').disabled = paired;
    $('server-note').hidden = !paired;
    if (!serverFilled || paired) {
        $('server').value = view.server ?? '';
        serverFilled = true;
    }
}

async function onSave(event) {
    event.preventDefault();
    $('server-status').textContent = '';
    $('server-status').className = '';
    const reply = await send('setServer', { server: $('server').value.trim() }).catch(() => null);
    if (!reply || reply.error) {
        $('server-status').textContent = reply?.error === 'invalid_server' ? TEXT.ERROR_INVALID_SERVER : TEXT.ERROR_GENERIC;
        $('server-status').className = 'error';
        adopt(reply);
        return;
    }
    serverFilled = false;
    adopt(reply);
    $('server-status').textContent = TEXT.SAVED;
}

async function onDisconnect() {
    $('disconnect').disabled = true;
    $('device-status').textContent = '';
    const reply = await send('disconnect').catch(() => null);
    $('disconnect').disabled = false;
    adopt(reply);
    $('device-status').textContent = reply ? TEXT.DISCONNECTED : TEXT.ERROR_GENERIC;
}

async function init() {
    applyText();
    $('server-form').addEventListener('submit', onSave);
    $('disconnect').addEventListener('click', onDisconnect);
    adopt(await send('getState').catch(() => null));
}

init();
