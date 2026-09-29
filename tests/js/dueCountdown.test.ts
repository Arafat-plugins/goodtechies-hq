// The Board card's countdown, run by Node's own runner:
//   node --experimental-strip-types --test tests/js/
//
// The process is forced into New York before anything reads a date, so every case below proves
// the label is computed in the APP's zone (Asia/Dhaka, from the fixture) and never the
// browser's. The cases are shared with tests/Feature/Tasks/DueCountdownAgreementTest.php, which
// holds the server's isOverdue / scopeOverdue to the same `overdue` answers.
process.env.TZ = 'America/New_York';

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { dueCountdown, isOverdueAt } from '../../resources/js/lib/dueCountdown.ts';

interface Case {
    name: string;
    due_date: string | null;
    status: string;
    now: string;
    expected: { label: string | null; overdue: boolean };
}

const fixture = JSON.parse(
    readFileSync(new URL('../fixtures/due-countdown-cases.json', import.meta.url), 'utf8'),
) as { timezone: string; cases: Case[] };

test('the process runs in a zone that is not the app zone', () => {
    assert.equal(Intl.DateTimeFormat().resolvedOptions().timeZone, 'America/New_York');
    assert.notEqual(fixture.timezone, 'America/New_York');
});

for (const c of fixture.cases) {
    test(c.name, () => {
        const now = Date.parse(c.now);
        const result = dueCountdown(c.due_date, c.status, now, fixture.timezone);

        assert.equal(result?.label ?? null, c.expected.label);
        assert.equal(isOverdueAt(c.due_date, c.status, now, fixture.timezone), c.expected.overdue);

        if (result !== null) {
            assert.equal(result.overdue, c.expected.overdue);
        }
    });
}

test('never prints a zero', () => {
    for (let seconds = -120; seconds <= 120; seconds += 1) {
        const now = Date.parse('2026-10-25T00:00:00+06:00') + seconds * 1000;
        const label = dueCountdown('2026-10-24', 'todo', now, fixture.timezone)?.label ?? '';

        assert.doesNotMatch(label, /\b0\b/);
    }
});

test('a malformed due date renders nothing', () => {
    assert.equal(dueCountdown('not a date', 'todo', Date.now(), fixture.timezone), null);
    assert.equal(isOverdueAt('not a date', 'todo', Date.now(), fixture.timezone), false);
});
