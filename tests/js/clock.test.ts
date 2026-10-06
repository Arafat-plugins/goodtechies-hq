// Polish 026: server wall-clock times (HH:mm) are shown as am/pm.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { clock12 } from '../../resources/js/lib/clock.ts';

test('clock12 writes HH:mm as a 12-hour time', () => {
    assert.equal(clock12('09:37'), '9:37 am');
    assert.equal(clock12('00:05'), '12:05 am');
    assert.equal(clock12('12:00'), '12:00 pm');
    assert.equal(clock12('15:20'), '3:20 pm');
    assert.equal(clock12('23:59:30'), '11:59 pm');
    assert.equal(clock12(null), '');
    assert.equal(clock12('still in'), 'still in');
});
