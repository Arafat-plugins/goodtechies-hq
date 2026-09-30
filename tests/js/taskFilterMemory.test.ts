// Brief 008: which Tasks visits get the saved filters back, and what is saved.
//   npm run test:js
//
// The router wiring in app.ts and the no-flash proof are measured in a real browser by the
// brief's Playwright run; this is the pure decision half.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    filtersOf,
    isTaskListPath,
    readSavedFilters,
    restoreFor,
    storageKey,
    withFilters,
    writeSavedFilters,
} from '../../resources/js/lib/taskFilterMemory.ts';

const url = (path: string) => new URL(path, 'http://hq.test');
const saved = { tag_id: '3', overdue: '1' };

test('the four list views on both surfaces are Tasks list paths; a task page is not', () => {
    for (const path of ['/admin/tasks', '/admin/tasks/board', '/admin/tasks/calendar', '/employee/tasks/gantt']) {
        assert.equal(isTaskListPath(path), true, path);
    }

    for (const path of ['/admin/tasks/12', '/admin/my-tasks', '/admin/dashboard', '/admin/tasks/board/x']) {
        assert.equal(isTaskListPath(path), false, path);
    }
});

test('only filter keys are kept: never detail, new, group_by, the calendar window or search', () => {
    assert.deepEqual(
        filtersOf('scope=mine&bucket=open&detail=4&new=1&group_by=project&date_from=2026-09-01&search=x&tag_id=3'),
        { scope: 'mine', bucket: 'open', tag_id: '3' },
    );
    assert.deepEqual(filtersOf('status=&overdue=1&archived=1'), { overdue: '1', archived: '1' });
    assert.deepEqual(filtersOf('project_id=<script>'), {});
});

test('a visit into Tasks from elsewhere without filters gets the saved set', () => {
    assert.deepEqual(restoreFor(url('/admin/tasks/board'), '/admin/dashboard', saved), saved);
    // A first full load has no "from".
    assert.deepEqual(restoreFor(url('/admin/tasks'), null, saved), saved);
    // Overlay state is not a filter: a notification's ?detail= link still restores.
    assert.deepEqual(restoreFor(url('/admin/tasks?detail=9'), '/notifications', saved), saved);
});

test('an explicit filter in the URL wins', () => {
    assert.equal(restoreFor(url('/admin/tasks?scope=mine&bucket=open'), '/admin/dashboard', saved), null);
    assert.equal(restoreFor(url('/admin/tasks?overdue=1'), null, saved), null);
});

test('a move within Tasks is never restored: Clear all and a view switch keep what they carry', () => {
    assert.equal(restoreFor(url('/admin/tasks/board'), '/admin/tasks/board', saved), null);
    assert.equal(restoreFor(url('/admin/tasks/calendar'), '/admin/tasks', saved), null);
});

test('nothing saved, or an empty set, restores nothing', () => {
    assert.equal(restoreFor(url('/admin/tasks'), '/admin/dashboard', null), null);
    assert.equal(restoreFor(url('/admin/tasks'), '/admin/dashboard', {}), null);
    assert.equal(restoreFor(url('/admin/projects'), '/admin/dashboard', saved), null);
});

test('restoring keeps every other parameter', () => {
    assert.equal(withFilters(url('/admin/tasks?detail=9&group_by=project'), saved).search, '?detail=9&group_by=project&tag_id=3&overdue=1');
});

test('storage is per user id, validated on read, and survives a throwing localStorage', () => {
    const store = new Map<string, string>();
    const g = globalThis as unknown as { window?: unknown };

    g.window = {
        localStorage: {
            getItem: (key: string) => store.get(key) ?? null,
            setItem: (key: string, value: string) => void store.set(key, value),
        },
    };

    writeSavedFilters(7, saved);
    assert.equal(storageKey(7), 'hq.tasks.filters.7');
    assert.deepEqual(readSavedFilters(7), saved);
    assert.equal(readSavedFilters(8), null);

    store.set(storageKey(9), JSON.stringify({ tag_id: '2', search: 'secret', detail: '4', status: 5 }));
    assert.deepEqual(readSavedFilters(9), { tag_id: '2' });
    store.set(storageKey(9), 'not json');
    assert.equal(readSavedFilters(9), null);

    g.window = {
        localStorage: {
            getItem: () => {
                throw new Error('blocked');
            },
            setItem: () => {
                throw new Error('blocked');
            },
        },
    };

    assert.equal(readSavedFilters(7), null);
    assert.doesNotThrow(() => writeSavedFilters(7, saved));

    delete g.window;
});
