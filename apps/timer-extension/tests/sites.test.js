import { test } from 'node:test';
import assert from 'node:assert/strict';
import { classifyUrl, normaliseHost, applyEvent, summarise, rollWindow } from '../src/lib/sites.js';

const S = 1000;
const T0 = 1_759_640_400_000; // a whole minute

test('https://www.Docs.Google.com/document/d/abc?x=1#y → site docs.google.com', () => {
    assert.deepEqual(classifyUrl('https://www.Docs.Google.com/document/d/abc?x=1#y'), { kind: 'site', host: 'docs.google.com' });
});

test('http://localhost:8000/x → localhost:8000', () => {
    assert.deepEqual(classifyUrl('http://localhost:8000/x'), { kind: 'site', host: 'localhost:8000' });
});

test('http://127.0.0.1:8000/ → 127.0.0.1:8000', () => {
    assert.deepEqual(classifyUrl('http://127.0.0.1:8000/'), { kind: 'site', host: '127.0.0.1:8000' });
});

test('https://example.com:8443/ → example.com (port dropped)', () => {
    assert.equal(normaliseHost('https://example.com:8443/'), 'example.com');
});

test('https://user:pw@x.com/ → x.com (credentials dropped)', () => {
    assert.deepEqual(classifyUrl('https://user:pw@x.com/'), { kind: 'site', host: 'x.com' });
});

test('non-ASCII host comes out as punycode', () => {
    assert.equal(normaliseHost('https://bücher.de/'), 'xn--bcher-kva.de');
});

test('chrome://newtab, about:blank, file:///a, chrome-extension://id/popup.html → browser_internal', () => {
    for (const url of ['chrome://newtab', 'about:blank', 'file:///a', 'chrome-extension://id/popup.html', '', undefined, 'data:text/plain,x']) {
        assert.deepEqual(classifyUrl(url), { kind: 'browser_internal', host: '' }, String(url));
    }
});

test('incognito → private', () => {
    assert.deepEqual(classifyUrl('https://bank.com/', { incognito: true }), { kind: 'private', host: '' });
});

test('scripted sequence: a.com 0 s, b.com 20 s, unfocus 45 s, end 60 s', () => {
    let t = { open: null, closed: [] };
    t = applyEvent(t, { type: 'focus', kind: 'site', host: 'a.com' }, T0);
    t = applyEvent(t, { type: 'focus', kind: 'site', host: 'b.com' }, T0 + 20 * S);
    t = applyEvent(t, { type: 'unfocus' }, T0 + 45 * S);
    const out = summarise(t, T0, T0 + 60 * S);
    assert.deepEqual(out, [
        { kind: 'site', host: 'b.com', seconds: 25 },
        { kind: 'site', host: 'a.com', seconds: 20 },
        { kind: 'other_app', host: '', seconds: 15 },
    ]);
    // Same three rows as [{site a.com 20},{site b.com 25},{other_app 15}], longest first.
    assert.equal(out.reduce((a, e) => a + e.seconds, 0), 60);
});

test('focus on the same place keeps the open segment; stop closes it', () => {
    let t = applyEvent({ open: null, closed: [] }, { type: 'focus', kind: 'site', host: 'a.com' }, T0);
    t = applyEvent(t, { type: 'focus', kind: 'site', host: 'a.com' }, T0 + 30 * S);
    assert.deepEqual(t.open, { kind: 'site', host: 'a.com', since: T0 });
    assert.equal(t.closed.length, 0);
    t = applyEvent(t, { type: 'stop' }, T0 + 40 * S);
    assert.equal(t.open, null);
    assert.deepEqual(t.closed, [{ kind: 'site', host: 'a.com', from: T0, to: T0 + 40 * S }]);
});

test('a segment spanning two minutes is clipped per minute', () => {
    let t = applyEvent({ open: null, closed: [] }, { type: 'focus', kind: 'site', host: 'a.com' }, T0 + 30 * S);
    t = applyEvent(t, { type: 'focus', kind: 'site', host: 'b.com' }, T0 + 90 * S);
    assert.deepEqual(summarise(t, T0, T0 + 60 * S), [{ kind: 'site', host: 'a.com', seconds: 30 }]);
    assert.deepEqual(summarise(t, T0 + 60 * S, T0 + 120 * S), [
        { kind: 'site', host: 'a.com', seconds: 30 },
        { kind: 'site', host: 'b.com', seconds: 30 },
    ]);
});

test('sum never > 60 (overlapping segments are scaled down)', () => {
    const t = {
        open: null,
        closed: [
            { kind: 'site', host: 'a.com', from: T0, to: T0 + 60 * S },
            { kind: 'site', host: 'b.com', from: T0, to: T0 + 60 * S },
        ],
    };
    const out = summarise(t, T0, T0 + 60 * S);
    assert.ok(out.reduce((a, e) => a + e.seconds, 0) <= 60);
    assert.deepEqual(out.map((e) => e.seconds), [30, 30]);
});

test('> 20 hosts fold into other_app (at most 20 entries, nothing lost)', () => {
    let t = { open: null, closed: [] };
    for (let i = 0; i < 25; i += 1) {
        t = applyEvent(t, { type: 'focus', kind: 'site', host: `h${i}.com` }, T0 + i * 2 * S);
    }
    t = applyEvent(t, { type: 'stop' }, T0 + 50 * S);
    const out = summarise(t, T0, T0 + 60 * S);
    assert.ok(out.length <= 20);
    assert.equal(out.reduce((a, e) => a + e.seconds, 0), 50);
    assert.ok(out.some((e) => e.kind === 'other_app' && e.host === ''));
    assert.ok(out.every((e) => e.seconds > 0));
});

test('rollWindow drops sent segments', () => {
    const t = {
        open: { kind: 'site', host: 'c.com', since: T0 + 50 * S },
        closed: [
            { kind: 'site', host: 'a.com', from: T0, to: T0 + 20 * S },
            { kind: 'site', host: 'b.com', from: T0 + 20 * S, to: T0 + 60 * S },
            { kind: 'site', host: 'd.com', from: T0 + 55 * S, to: T0 + 65 * S },
        ],
    };
    const rolled = rollWindow(t, T0 + 60 * S);
    assert.deepEqual(rolled.closed, [{ kind: 'site', host: 'd.com', from: T0 + 55 * S, to: T0 + 65 * S }]);
    assert.deepEqual(rolled.open, t.open);
});

test('a summary never carries a path, query, fragment or url/title key', () => {
    const t = applyEvent({ open: null, closed: [] }, { type: 'focus', ...classifyUrl('https://www.example.com/a/b?q=1#f') }, T0);
    const json = JSON.stringify(summarise(t, T0, T0 + 60 * S));
    assert.ok(!/[/?#@]/.test(json.replace(/"/g, '')));
    assert.ok(!/"(url|title|hostname)"/.test(json));
});
