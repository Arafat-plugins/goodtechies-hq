// Brief 009: a re-read of a thread reuses what is already drawn (`mergeThreadMessages`).
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { mergeThreadMessages } from '../../resources/js/Components/Messages/messages.ts';
import type { ThreadAttachment, ThreadMessage } from '../../resources/js/Components/Messages/messages.ts';

const FAR = '2999-01-01T00:00:00Z';

function file(id: number, url: string, expires = FAR): ThreadAttachment {
    return { id, url, url_expires_at: expires, kind: 'image', duration_seconds: null } as unknown as ThreadAttachment;
}

function message(id: number, extra: Partial<ThreadMessage> = {}): ThreadMessage {
    return {
        id,
        body: `m${id}`,
        author: { id: 1, name: 'A' },
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
        ...extra,
    };
}

test('nothing changed: the same array comes back', () => {
    const current = [message(1), message(2)];
    const incoming = [message(1), message(2)];

    assert.equal(mergeThreadMessages(current, incoming), current);
});

test('unchanged rows keep their object; a new one is appended', () => {
    const current = [message(1), message(2)];
    const fresh = message(3);
    const merged = mergeThreadMessages(current, [message(1), message(2), fresh]);

    assert.notEqual(merged, current);
    assert.equal(merged[0], current[0]);
    assert.equal(merged[1], current[1]);
    assert.equal(merged[2], fresh);
});

test('an edited, deleted, seen or reacted row is replaced', () => {
    const current = [message(1), message(2), message(3), message(4)];
    const merged = mergeThreadMessages(current, [
        message(1, { body: 'changed', edited_at: '2026-10-01T10:05:00Z' }),
        message(2, { is_deleted: true }),
        message(3, { seen: true }),
        message(4, { reactions: [{ emoji: '👍', count: 1, mine: true, names: ['A'] }] }),
    ]);

    merged.forEach((row, index) => assert.notEqual(row, current[index]));
    assert.equal(merged[0].body, 'changed');
});

test('a re-signed url on the same file keeps the first url', () => {
    const current = [message(1, { attachments: [file(9, 'https://x/a?sig=1')] })];
    const merged = mergeThreadMessages(current, [message(1, { attachments: [file(9, 'https://x/a?sig=2')] })]);

    assert.equal(merged, current);
    assert.equal(merged[0].attachments[0].url, 'https://x/a?sig=1');
});

test('a link about to lapse takes the fresh url', () => {
    const soon = '2026-10-01T10:00:10Z';
    const current = [message(1, { attachments: [file(9, 'old', soon)] })];
    const merged = mergeThreadMessages(
        current,
        [message(1, { attachments: [file(9, 'new')] })],
        Date.parse('2026-10-01T10:00:30Z'),
    );

    assert.notEqual(merged, current);
    assert.equal(merged[0].attachments[0].url, 'new');
});

test('a changed row keeps an unchanged attachment object', () => {
    const kept = file(9, 'first');
    const current = [message(1, { attachments: [kept] })];
    const merged = mergeThreadMessages(current, [message(1, { body: 'edited', attachments: [file(9, 'second')] })]);

    assert.equal(merged[0].attachments[0], kept);
});
