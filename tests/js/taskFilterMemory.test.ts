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
    savableFilters,
    storageKey,
    withFilters,
    writeSavedFilters,
} from '../../resources/js/lib/taskFilterMemory.ts';

const url = (path: string) => new URL(path, 'http://hq.test');
const saved = { tag_id: '3', archived: '1' };

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

test('"Show subtasks" is a saved filter key (flow F2)', () => {
    assert.deepEqual(filtersOf('subtasks=1&status=todo'), { subtasks: '1', status: 'todo' });
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
    assert.equal(withFilters(url('/admin/tasks?detail=9&group_by=project'), saved).search, '?detail=9&group_by=project&tag_id=3&archived=1');
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

test('brief 017: overdue=1 and a due_today/overdue bucket are never saved or restored', () => {
    assert.deepEqual(savableFilters({ overdue: '1', bucket: 'due_today', tag_id: '3' }), { tag_id: '3' });
    assert.deepEqual(savableFilters({ bucket: 'overdue', scope: 'overdue' }), { scope: 'overdue' });
    assert.deepEqual(savableFilters({ bucket: 'open' }), { bucket: 'open' });

    const store = new Map<string, string>();
    const g = globalThis as unknown as { window?: unknown };

    g.window = {
        localStorage: {
            getItem: (key: string) => store.get(key) ?? null,
            setItem: (key: string, value: string) => void store.set(key, value),
        },
    };

    // An old saved set that carries them: those keys are dropped on read.
    store.set(storageKey(5), JSON.stringify({ overdue: '1', bucket: 'overdue', tag_id: '2' }));
    assert.deepEqual(readSavedFilters(5), { tag_id: '2' });

    // One that carried nothing else restores nothing.
    store.set(storageKey(6), JSON.stringify({ overdue: '1' }));
    assert.equal(restoreFor(url('/admin/tasks/board'), '/admin/dashboard', readSavedFilters(6)), null);

    // A page carrying them does not save them.
    writeSavedFilters(5, filtersOf('overdue=1&bucket=due_today&status=todo'));
    assert.deepEqual(JSON.parse(store.get(storageKey(5)) ?? '{}'), { status: 'todo' });

    // The server still reads them, so a link carrying one is still an explicit filter.
    assert.equal(restoreFor(url('/admin/tasks?overdue=1'), null, { tag_id: '2' }), null);

    delete g.window;
});
