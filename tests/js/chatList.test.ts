// 2026-10-04: the Telegram-style chat list (`chatListEntries`), its row times
// (`formatListTime`) and which conversations get the two-sided layout (`threadLayout`),
// run by Node's own runner.
//
// The process is pinned to Dhaka before anything reads a date, so "today" and "this week" are
// decided in a fixed zone whatever machine runs this.
process.env.TZ = 'Asia/Dhaka';

import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    type ChatListEntry,
    type ConversationSummary,
    chatListEntries,
    formatListTime,
    threadLayout,
} from '../../resources/js/Components/Messages/messages.ts';

function row(partial: Partial<ConversationSummary> & Pick<ConversationSummary, 'id' | 'type' | 'label'>): ConversationSummary {
    return { group: null, unread_count: 0, last_message: null, ...partial };
}

function message(createdAt: string): ConversationSummary['last_message'] {
    return { author: 'Rahim', is_mine: false, excerpt: 'Hello', created_at: createdAt };
}

function chatIds(entries: ChatListEntry[]): (number | 'projects')[] {
    return entries.map((entry) => (entry.kind === 'chat' ? entry.row.id : 'projects'));
}

test('newest activity comes first', () => {
    const entries = chatListEntries([
        row({ id: 1, type: 'dm', label: 'Rahim', last_message: message('2026-10-04T10:00:00+06:00') }),
        row({ id: 2, type: 'team', label: 'Team', last_message: message('2026-10-04T11:00:00+06:00') }),
    ]);

    assert.deepEqual(chatIds(entries), [2, 1]);
});

test('projects collapse into one folder that only holds projects with messages', () => {
    const entries = chatListEntries([
        row({ id: 10, type: 'project', label: 'A', unread_count: 2, last_message: message('2026-10-04T09:00:00+06:00') }),
        row({ id: 11, type: 'project', label: 'B' }),
        row({ id: 12, type: 'project', label: 'C', unread_count: 1, last_message: message('2026-10-04T12:00:00+06:00') }),
        row({ id: 1, type: 'dm', label: 'Rahim', last_message: message('2026-10-04T10:00:00+06:00') }),
    ]);

    assert.deepEqual(chatIds(entries), ['projects', 1]);

    const folder = entries[0];
    assert.equal(folder.kind, 'projects');
    if (folder.kind !== 'projects') {
        return;
    }

    assert.deepEqual(folder.rows.map((r) => r.id), [12, 10]);
    assert.equal(folder.unread, 3);
    assert.equal(folder.latestAt, '2026-10-04T12:00:00+06:00');

    const everywhere = entries.flatMap((entry) => (entry.kind === 'chat' ? [entry.row.id] : entry.rows.map((r) => r.id)));
    assert.ok(!everywhere.includes(11));
});

test('there is no folder when no project has a message', () => {
    const entries = chatListEntries([
        row({ id: 10, type: 'project', label: 'A' }),
        row({ id: 1, type: 'dm', label: 'Rahim', last_message: message('2026-10-04T10:00:00+06:00') }),
    ]);

    assert.deepEqual(chatIds(entries), [1]);
});

test('rows without any message keep their order after the others', () => {
    const entries = chatListEntries([
        row({ id: 3, type: 'group', label: 'Design' }),
        row({ id: 4, type: 'dm', label: 'Karim' }),
        row({ id: 1, type: 'dm', label: 'Rahim', last_message: message('2026-10-04T10:00:00+06:00') }),
    ]);

    assert.deepEqual(chatIds(entries), [1, 3, 4]);
});

test('formatListTime writes the time as Telegram does', () => {
    const now = new Date('2026-10-04T12:00:00+06:00');

    assert.equal(formatListTime('2026-10-04T09:03:00+06:00', now), '9:03 am');
    assert.equal(formatListTime('2026-10-03T09:00:00+06:00', now), 'Sat');
    assert.match(formatListTime('2026-09-20T09:00:00+06:00', now), /^20 Sep/);
    assert.ok(formatListTime('2025-09-20T09:00:00+06:00', now).includes('2025'));
    assert.equal(formatListTime(null, now), '');
});

test('only a task discussion keeps the stacked layout', () => {
    assert.equal(threadLayout('task'), 'stacked');
    assert.equal(threadLayout('team'), 'sided');
    assert.equal(threadLayout('dm'), 'sided');
});
