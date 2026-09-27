<?php

use App\Models\RecurringTask;

/*
|--------------------------------------------------------------------------
| What a recurring template is CALLED — decision 3-13
|--------------------------------------------------------------------------
|
| A template titles its tasks from a pattern: "Buffalo Modular Monthly SEO —
| {period}" becomes "Buffalo Modular Monthly SEO — October 2026". The task detail
| page prints both, one under the other, and printed the pattern verbatim — so an
| assignee with no template to edit met "{period}" in a sentence about their own
| task, two centimetres under the month it had already been rendered into.
|
| `displayName()` is what that line prints now. The rejected alternative was a
| second stored "display name" column, which is what made this a decision rather
| than a fix: a field somebody fills in by hand is a field that disagrees with the
| pattern the first time either is edited.
|
| No database: a title pattern is a string and this is the function over it.
| Constants and functions are global in Pest, so nothing here declares any.
|
*/

it('takes the placeholders out of a template name', function (string $pattern, string $expected) {
    expect((new RecurringTask(['title_template' => $pattern]))->displayName())->toBe($expected);
})->with([
    // The seeded shape, and the one in the decision.
    'trailing period' => ['Buffalo Modular Monthly SEO — {period}', 'Buffalo Modular Monthly SEO'],
    // A leading placeholder loses its trailing space as well as itself.
    'leading period' => ['{period} retainer report', 'retainer report'],
    // The em dashes were holding the placeholder between them. Two in a row is a visible hole
    // where something used to be, so the run collapses to one.
    'placeholder in the middle' => ['abc.com — {period} — Maintenance', 'abc.com — Maintenance'],
    'hyphens too' => ['abc.com - {project} - Maintenance', 'abc.com - Maintenance'],
    'all three' => ['{project} SEO {period} as at {date}', 'SEO as at'],
    // Nothing to do is nothing done: a pattern with no placeholders is its own name.
    'no placeholder at all' => ['Quarterly board pack', 'Quarterly board pack'],
    // A pattern that is ONLY placeholders has no name to give, so it keeps the pattern — the
    // same choice `RecurringTaskEngine::renderTitle()` makes when every placeholder resolves to
    // nothing. An identifier the author recognises beats an empty space.
    'nothing but placeholders' => ['{project} — {period}', '{project} — {period}'],
    'one placeholder and nothing else' => ['{period}', '{period}'],
]);

it('renders and un-renders the same three placeholders', function () {
    // The engine strtr()s this list and `displayName()` strips it, and they are the same list —
    // so a fourth placeholder is one edit rather than three, and the pair cannot drift into a
    // template whose name keeps a token the engine has learned to replace.
    expect(RecurringTask::PLACEHOLDERS)
        ->toBe(['{period}', '{project}', '{date}']);
});
