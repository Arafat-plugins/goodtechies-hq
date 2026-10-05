// Polish 017: an upload that stops moving is cut and reported as UPLOAD_STALLED (safe to resend);
// a drop after every byte went up is NOT, because the server may already have stored the message.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { messageRequest, UPLOAD_STALLED } from '../../resources/js/Components/Messages/messages.ts';

type Handler = ((event?: unknown) => void) | null;

class FakeXhr {
    static last: FakeXhr | null = null;
    upload: { onprogress: Handler; onload: Handler } = { onprogress: null, onload: null };
    onload: Handler = null;
    onerror: Handler = null;
    ontimeout: Handler = null;
    onabort: Handler = null;
    onloadend: Handler = null;
    status = 0;
    responseText = '';
    timeout = 0;
    withCredentials = false;
    aborted = false;

    constructor() {
        FakeXhr.last = this;
    }

    open(): void {}
    setRequestHeader(): void {}
    send(): void {}

    abort(): void {
        this.aborted = true;
        this.onabort?.();
        this.onloadend?.();
    }

    progress(loaded: number, total: number): void {
        this.upload.onprogress?.({ lengthComputable: true, loaded, total });
    }

    fail(): void {
        this.onerror?.();
        this.onloadend?.();
    }

    answer(status: number, body: unknown): void {
        this.status = status;
        this.responseText = JSON.stringify(body);
        this.onload?.();
        this.onloadend?.();
    }
}

(globalThis as unknown as { XMLHttpRequest: unknown }).XMLHttpRequest = FakeXhr;
(globalThis as unknown as { FormData: unknown }).FormData = class {};

const form = (): FormData => new (globalThis as unknown as { FormData: new () => FormData }).FormData();
const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

test('an upload that stops moving is aborted and rejected as a stall', async () => {
    const request = messageRequest('POST', '/x', form(), 't', () => {}, 0, 40);
    const xhr = FakeXhr.last!;

    xhr.progress(100, 1000);
    await wait(20);
    xhr.progress(150, 1000); // still moving: the watchdog restarts
    await wait(30);
    assert.equal(xhr.aborted, false);

    await assert.rejects(request, new Error(UPLOAD_STALLED));
    assert.equal(xhr.aborted, true);
});

test('a connection that drops while the body is still going up is a stall', async () => {
    const request = messageRequest('POST', '/x', form(), 't', () => {}, 0, 1000);
    const xhr = FakeXhr.last!;

    xhr.progress(150, 1000);
    xhr.upload.onload?.(); // Chrome fires this even on a drop
    xhr.fail();

    await assert.rejects(request, new Error(UPLOAD_STALLED));
});

test('a drop after every byte went up is a network error, never resent', async () => {
    const request = messageRequest('POST', '/x', form(), 't', () => {}, 0, 1000);
    const xhr = FakeXhr.last!;

    xhr.progress(1000, 1000);
    xhr.upload.onload?.();
    xhr.fail();

    await assert.rejects(request, new Error('network'));
});

test('a normal upload resolves, reports bytes, and leaves no timer behind', async () => {
    const seen: number[][] = [];
    const request = messageRequest('POST', '/x', form(), 't', (p, l, t) => seen.push([p, l, t]), 0, 30);
    const xhr = FakeXhr.last!;

    xhr.progress(500, 1000);
    xhr.progress(1000, 1000);
    xhr.upload.onload?.();
    await wait(50); // waiting on the server is not a stall
    xhr.answer(201, { message: { id: 1 } });

    assert.deepEqual(await request, { status: 201, json: { message: { id: 1 } } });
    assert.deepEqual(seen, [
        [50, 500, 1000],
        [100, 1000, 1000],
    ]);
    assert.equal(xhr.aborted, false);
});
