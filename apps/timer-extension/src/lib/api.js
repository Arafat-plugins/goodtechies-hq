// api.js — the HTTP client for docs/extension-api.md §1–§3.
//
// `request` never throws on an HTTP error: it resolves {ok, status, data}
// (plus `reconnect: true` on 401). A network failure (fetch rejects with a
// TypeError) resolves {ok: false, status: 0, data: null, offline: true}.
// `fetchImpl` is injected so tests can pass a fake.

/** Sent on every request so the server can tell which client spoke. */
export const CLIENT_HEADER = 'extension/0.1.0';

/** The §2 routes. */
export const endpoints = Object.freeze({
    exchange: '/api/extension/exchange',
    disconnect: '/api/extension/disconnect',
    state: '/api/timer/state',
    tasks: '/api/timer/tasks',
    start: '/api/timer/start',
    pause: '/api/timer/pause',
    resume: '/api/timer/resume',
    stop: '/api/timer/stop',
    heartbeat: '/api/timer/heartbeat',
    idleDecision: '/api/timer/idle-decision',
});

/** The origins the manifest grants up front (host_permissions). */
export function allowedOrigins() {
    return ['https://erp.goodtechies.com', 'http://127.0.0.1:8000', 'http://localhost:8000'];
}

/** "https://erp.goodtechies.com/anything" → "https://erp.goodtechies.com". */
export function originOf(server) {
    return new URL(server).origin;
}

/** True when the server is not one of the manifest origins (needs chrome.permissions.request). */
export function needsPermission(server) {
    return !allowedOrigins().includes(originOf(server));
}

/**
 * One API call.
 * @returns {Promise<{ok:boolean, status:number, data:any, reconnect?:true, offline?:true}>}
 */
export async function request(fetchImpl, { server, token, method = 'GET', path, body }) {
    const headers = {};
    if (token) {
        headers.Authorization = 'Bearer ' + token;
    }
    headers.Accept = 'application/json';
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }
    headers['X-Timer-Client'] = CLIENT_HEADER;

    const init = { method, headers };
    if (body !== undefined) {
        init.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetchImpl(String(server).replace(/\/+$/, '') + path, init);
    } catch (error) {
        if (error instanceof TypeError) {
            return { ok: false, status: 0, data: null, offline: true };
        }
        throw error;
    }

    const data = await parseJson(response);
    const result = { ok: response.ok, status: response.status, data };
    if (response.status === 401) {
        result.reconnect = true;
    }
    return result;
}

/** The body as JSON, or null when empty (204) or not JSON. */
async function parseJson(response) {
    let text = '';
    try {
        text = await response.text();
    } catch {
        return null;
    }
    if (!text) {
        return null;
    }
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}
