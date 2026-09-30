// Brief 010: the top-bar search looks only in the section the person is in, and the whole app
// only on a Dashboard (or a page with no searchable type of its own).
//   npm run test:js
//
// The server half (`GET /search?type=…`) is proved by tests/Feature/Search/SearchTypeFilterTest;
// this is the pure route → types decision the palette makes before it asks.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { searchScopeFor, searchUrl } from '../../resources/js/lib/searchScope.ts';

const typesOf = (url: string) => searchScopeFor(url).types;

test('task pages search tasks, on both surfaces', () => {
    for (const url of [
        '/admin/tasks',
        '/admin/tasks/board',
        '/admin/tasks/calendar',
        '/admin/tasks/gantt',
        '/admin/tasks/42',
        '/admin/my-tasks',
        '/employee/tasks',
        '/employee/tasks/board?project_id=3',
        '/employee/tasks/7',
        '/employee/my-tasks',
    ]) {
        assert.deepEqual(typesOf(url), ['task'], url);
    }

    assert.equal(searchScopeFor('/admin/tasks/board').noun, 'tasks');
    assert.equal(searchScopeFor('/admin/tasks/board').label, 'Tasks');
});

test('each section searches its own type', () => {
    const cases: Record<string, string[]> = {
        '/admin/projects': ['project'],
        '/admin/projects/12': ['project'],
        '/admin/projects/12/edit': ['project'],
        '/employee/projects': ['project'],
        '/employee/projects/12': ['project'],
        '/admin/clients': ['client'],
        '/admin/clients/3': ['client'],
        '/messages': ['message'],
        '/messages/9': ['message'],
        '/meetings': ['meeting'],
        '/meetings/4/edit': ['meeting'],
        '/team': ['employee'],
        '/admin/employees': ['employee'],
        '/admin/employees/5': ['employee'],
        '/finance/income': ['income'],
        '/finance/income/create': ['income'],
        '/finance/expenses': ['expense'],
        '/finance/expenses/3/edit': ['expense'],
        '/finance': ['income', 'expense'],
        '/finance/report?month=2026-09': ['income', 'expense'],
    };

    for (const [url, types] of Object.entries(cases)) {
        assert.deepEqual(typesOf(url), types, url);
    }
});

test('a dashboard searches everything', () => {
    for (const url of ['/admin/dashboard', '/employee/dashboard', '/accountant/dashboard']) {
        assert.equal(typesOf(url), null, url);
        assert.equal(searchScopeFor(url).label, 'Everything');
    }
});

test('a page with no searchable type of its own falls back to everything', () => {
    for (const url of [
        '/attendance',
        '/attendance/3',
        '/admin/attendance',
        '/leave',
        '/admin/leave/calendar',
        '/payroll',
        '/payslip',
        '/salaries',
        '/admin/settings',
        '/admin/reports/attendance',
        '/employee/reports',
        '/profile',
        '/notifications',
        '/admin/time',
        '/admin/timesheet/2',
        '/admin/workload',
        '/admin/audit-log',
        '/accountant/projects',
        '/',
    ]) {
        assert.equal(typesOf(url), null, url);
    }
});

test('a prefix is a whole path segment, never a substring', () => {
    assert.equal(typesOf('/admin/tasksx'), null);
    assert.equal(typesOf('/meetingsroom'), null);
    assert.equal(typesOf('/financeiro'), null);
});

test('the request carries the scope, or no type at all for everything', () => {
    assert.equal(searchUrl('buff mod', searchScopeFor('/admin/dashboard')), '/search?q=buff%20mod');
    assert.equal(searchUrl('buff', searchScopeFor('/admin/tasks')), '/search?q=buff&type=task');
    assert.equal(searchUrl('rent', searchScopeFor('/finance')), '/search?q=rent&type=income%2Cexpense');
});
