// A Recurring project's deadline preview, run by Node's own runner:
//   npm run test:js
//
// The same arithmetic as App\Support\ProjectRecurrenceFrequency::deadlineFrom (tested in
// tests/Feature/Admin/ProjectRecurringTest.php). The process is forced into a zone with a DST
// change so a local-time Date would show drift.
process.env.TZ = 'America/New_York';

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { recurringDeadline } from '../../resources/js/lib/recurrence.ts';

const CASES: Array<[string, string, string]> = [
    ['2026-10-05', 'daily', '2026-10-06'],
    ['2026-10-05', 'weekly', '2026-10-12'],
    ['2026-10-05', 'biweekly', '2026-10-19'],
    ['2026-10-05', 'monthly', '2026-11-05'],
    ['2027-01-31', 'monthly', '2027-02-28'],
    ['2028-01-31', 'monthly', '2028-02-29'],
    ['2026-12-31', 'monthly', '2027-01-31'],
    ['2026-12-25', 'weekly', '2027-01-01'],
];

for (const [start, frequency, deadline] of CASES) {
    test(`${frequency} from ${start} is due ${deadline}`, () => {
        assert.equal(recurringDeadline(start, frequency), deadline);
    });
}

test('a blank or invalid start, or no frequency, has no deadline', () => {
    assert.equal(recurringDeadline('', 'weekly'), null);
    assert.equal(recurringDeadline('2026-02-30', 'weekly'), null);
    assert.equal(recurringDeadline('2026-10-05', null), null);
    assert.equal(recurringDeadline('2026-10-05', 'yearly'), null);
});
