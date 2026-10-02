// Brief 013: the composer's pure helpers — pasted names, the clipboard reader, the message file
// type check, and the client-built reply quote.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    MESSAGE_FILE_ACCEPT,
    clipboardImage,
    messageFileRejection,
    pastedFileName,
    replyExcerpt,
    replyReference,
} from '../../resources/js/Components/Messages/messages.ts';
import type { ThreadAttachment, ThreadMessage } from '../../resources/js/Components/Messages/messages.ts';

const AT = new Date(2026, 9, 2, 9, 5, 7);

test('a pasted image is named for when it was pasted, with the extension from its type', () => {
    assert.equal(pastedFileName('image/png', AT), 'pasted-20261002-090507.png');
    assert.equal(pastedFileName('image/jpeg', AT), 'pasted-20261002-090507.jpg');
    assert.equal(pastedFileName('image/svg+xml', AT), 'pasted-20261002-090507.svg');
    assert.equal(pastedFileName('', AT), 'pasted-20261002-090507.png');
    assert.equal(pastedFileName('application/octet-stream', AT), 'pasted-20261002-090507.png');
});

function item(kind: string, type: string, file: File | null) {
    return { kind, type, getAsFile: () => file };
}

test('the clipboard reader takes the first image item, then files[0], and nothing from text', () => {
    const png = new File(['x'], 'image.png', { type: 'image/png' });
    const gif = new File(['y'], 'b.gif', { type: 'image/gif' });

    assert.equal(clipboardImage({ items: [item('string', 'text/plain', null), item('file', 'image/png', png)] }), png);
    assert.equal(clipboardImage({ items: [item('file', 'application/pdf', null)], files: [gif] }), gif);
    assert.equal(clipboardImage({ items: [item('string', 'text/plain', null)], files: [] }), null);
    assert.equal(clipboardImage(null), null);
});

test('message attachments are checked by type only, with the brief 012 additions', () => {
    for (const name of ['a.svg', 'b.mp3', 'c.mp4', 'd.zip', 'e.apk', 'f.PNG', 'g.pdf']) {
        assert.equal(messageFileRejection({ name }), null, name);
    }

    assert.match(messageFileRejection({ name: 'x.exe' }) ?? '', /^EXE files are not accepted/);
    assert.match(messageFileRejection({ name: 'README' }) ?? '', /^Extensionless/);

    for (const extension of ['.svg', '.mp3', '.mp4', '.zip', '.apk']) {
        assert.ok(MESSAGE_FILE_ACCEPT.split(',').includes(extension), extension);
    }
});

function message(extra: Partial<ThreadMessage> = {}): ThreadMessage {
    return {
        id: 7,
        body: null,
        author: { id: 3, name: 'Rafi' },
        is_mine: false,
        created_at: '2026-10-01T10:00:00Z',
        attachments: [],
        mentions: [],
        mentions_me: false,
        edited_at: null,
        is_deleted: false,
        can_edit: false,
        can_delete: false,
        reactions: [],
        seen: null,
        reply_to: null,
        ...extra,
    };
}

function attachment(kind: ThreadAttachment['kind'], name = 'plan.pdf'): ThreadAttachment {
    return { id: 1, name, kind, duration_seconds: null } as unknown as ThreadAttachment;
}

test('a reply quote is built like MessageResource::replyTo()', () => {
    const long = 'a'.repeat(200);

    assert.deepEqual(replyReference(message({ body: 'Hello' })), {
        id: 7,
        author: { id: 3, name: 'Rafi' },
        excerpt: 'Hello',
        kind: 'text',
        is_deleted: false,
    });
    assert.equal(replyReference(message({ body: long })).excerpt.length, 120);
    assert.equal(replyReference(message({ attachments: [attachment('voice')] })).excerpt, 'Voice message');
    assert.equal(replyReference(message({ attachments: [attachment('image')] })).excerpt, 'Photo');
    assert.equal(replyReference(message({ attachments: [attachment('file', 'plan.pdf')] })).excerpt, 'plan.pdf');

    const deleted = replyReference(message({ body: 'gone', is_deleted: true }));

    assert.equal(deleted.excerpt, '');
    assert.equal(deleted.is_deleted, true);
    assert.equal(replyExcerpt(deleted), 'Deleted message');

    const mine = replyReference(message({ is_mine: true, author: { id: 9, name: null }, body: 'x' }), 'Tapu');

    assert.deepEqual(mine.author, { id: 9, name: 'Tapu' });
});
