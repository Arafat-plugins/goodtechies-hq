import { test } from 'node:test';
import assert from 'node:assert/strict';
import { request, needsPermission, originOf, allowedOrigins, endpoints } from '../src/lib/api.js';

/** A fake fetch that records its calls and answers with `status` and `body`. */
function fakeFetch(status, body) {
    const calls = [];
    const impl = async (url, init) => {
        calls.push({ url, init });
        const text = body === undefined ? '' : JSON.stringify(body);
        return { ok: status >= 200 && status < 300, status, text: async () => text };
    };
    impl.calls = calls;
    return impl;
}

test('headers are exactly as step 10 (with token and body)', async () => {
    const f = fakeFetch(200, { server_time: 'x' });
    const res = await request(f, {
        server: 'https://erp.goodtechies.com',
        token: 'abc',
        method: 'POST',
        path: endpoints.start,
        body: { task_id: 1 },
    });
    assert.equal(f.calls[0].url, 'https://erp.goodtechies.com/api/timer/start');
    assert.equal(f.calls[0].init.method, 'POST');
    assert.deepEqual(f.calls[0].init.headers, {
        Authorization: 'Bearer abc',
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Timer-Client': 'extension/0.1.0',
    });
    assert.equal(f.calls[0].init.body, JSON.stringify({ task_id: 1 }));
    assert.deepEqual(res, { ok: true, status: 200, data: { server_time: 'x' } });
});

test('headers without token or body', async () => {
    const f = fakeFetch(204);
    const res = await request(f, { server: 'http://localhost:8000', token: null, method: 'GET', path: endpoints.state });
    assert.deepEqual(f.calls[0].init.headers, { Accept: 'application/json', 'X-Timer-Client': 'extension/0.1.0' });
    assert.equal(f.calls[0].init.body, undefined);
    assert.equal(res.data, null);
});

test('401 → reconnect', async () => {
    const f = fakeFetch(401, { error: 'token_invalid', message: 'x' });
    const res = await request(f, { server: 'https://erp.goodtechies.com', token: 'old', method: 'GET', path: endpoints.state });
    assert.equal(res.ok, false);
    assert.equal(res.status, 401);
    assert.equal(res.reconnect, true);
    assert.equal(res.data.error, 'token_invalid');
});

test('HTTP errors never throw (409 carries the state)', async () => {
    const f = fakeFetch(409, { error: 'entry_not_running', state: { running: null } });
    const res = await request(f, { server: 'https://erp.goodtechies.com', token: 't', method: 'POST', path: endpoints.heartbeat, body: {} });
    assert.equal(res.status, 409);
    assert.deepEqual(res.data.state, { running: null });
    assert.equal(res.reconnect, undefined);
});

test('network TypeError → {offline: true}', async () => {
    const f = async () => {
        throw new TypeError('Failed to fetch');
    };
    const res = await request(f, { server: 'https://erp.goodtechies.com', token: 't', method: 'GET', path: endpoints.state });
    assert.equal(res.offline, true);
    assert.equal(res.ok, false);
});

test('needsPermission: 192.168.0.5:8000 true, erp.goodtechies.com false', () => {
    assert.equal(needsPermission('http://192.168.0.5:8000'), true);
    assert.equal(needsPermission('https://erp.goodtechies.com'), false);
    assert.equal(needsPermission('http://localhost:8000/'), false);
    assert.equal(needsPermission('http://127.0.0.1:8000'), false);
    assert.equal(originOf('https://erp.goodtechies.com/some/path'), 'https://erp.goodtechies.com');
    assert.equal(allowedOrigins().length, 3);
});
