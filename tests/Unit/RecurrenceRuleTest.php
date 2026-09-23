<?php

use App\Support\RecurrenceFrequency;
use App\Support\RecurrenceRule;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The period-key scheme
|--------------------------------------------------------------------------
|
| One function answers "which period is this" for all three frequencies, and
| the keys it produces have two properties the engine leans on:
|
|   1. a key is unique within one template, which is all the unique index on
|      (recurring_task_id, recurring_period) needs;
|   2. keys of one shape sort lexicographically into chronological order, which
|      is what lets the previous-open-instance lookup be a plain `<` instead of
|      a second date column.
|
| No database: this is arithmetic.
|
*/

it('keys a monthly period as the calendar month', function () {
    $rule = RecurrenceRule::monthly();

    expect($rule->periodKey(Carbon::parse('2026-10-01')))->toBe('2026-10')
        ->and($rule->periodKey(Carbon::parse('2026-10-31')))->toBe('2026-10')
        ->and($rule->periodKey(Carbon::parse('2026-11-01')))->toBe('2026-11');
})->group('phase3');

it('keys a weekly period as the ISO week, using the ISO year', function () {
    $rule = RecurrenceRule::weekly();

    expect($rule->periodKey(Carbon::parse('2026-10-05')))->toBe('2026-W41')
        ->and($rule->periodKey(Carbon::parse('2026-10-11')))->toBe('2026-W41')
        ->and($rule->periodKey(Carbon::parse('2026-10-12')))->toBe('2026-W42');

    // 29 December 2025 is in ISO week 1 of 2026. A key built from the CALENDAR year would read
    // 2025-W01 and sort a whole year out of place, which would quietly break the
    // previous-open-instance lookup every new year.
    expect($rule->periodKey(Carbon::parse('2025-12-29')))->toBe('2026-W01');
})->group('phase3');

it('keys a custom period as the day its cycle started', function () {
    $rule = RecurrenceRule::custom(14, '2026-10-07');

    expect($rule->periodKey(Carbon::parse('2026-10-07')))->toBe('2026-10-07')
        ->and($rule->periodKey(Carbon::parse('2026-10-20')))->toBe('2026-10-07')
        ->and($rule->periodKey(Carbon::parse('2026-10-21')))->toBe('2026-10-21')
        // Before the anchor: floor division, so it lands in the cycle before rather than in the
        // anchor's own.
        ->and($rule->periodKey(Carbon::parse('2026-10-06')))->toBe('2026-09-23');
})->group('phase3');

it('sorts the keys of one shape into chronological order as plain strings', function (array $keys) {
    $sorted = $keys;
    sort($sorted);

    expect($sorted)->toBe($keys);
})->with([
    'monthly' => [['2026-09', '2026-10', '2026-11', '2027-01']],
    'weekly' => [['2026-W01', '2026-W09', '2026-W41', '2027-W01']],
    'custom' => [['2026-09-23', '2026-10-07', '2026-10-21']],
])->group('phase3');

/*
|--------------------------------------------------------------------------
| The dates the engine writes
|--------------------------------------------------------------------------
*/

it('generates a monthly rule on its day of the month and never skips February', function () {
    $first = RecurrenceRule::monthly(1);
    $fifteenth = RecurrenceRule::monthly(15);

    expect($first->generationDate($first->periodStart(Carbon::parse('2026-10-20')))->toDateString())
        ->toBe('2026-10-01')
        ->and($fifteenth->generationDate($fifteenth->periodStart(Carbon::parse('2026-10-20')))->toDateString())
        ->toBe('2026-10-15');

    // Clamped at construction: a rule asking for the 31st would otherwise never fire in
    // February, which is a month of retainer work silently lost every year.
    $late = RecurrenceRule::monthly(31);

    expect($late->dayOfMonth)->toBe(RecurrenceRule::MAX_DAY_OF_MONTH)
        ->and($late->generationDate($late->periodStart(Carbon::parse('2027-02-10')))->toDateString())
        ->toBe('2027-02-28');
})->group('phase3');

it('generates a weekly rule on its weekday inside the ISO week', function () {
    $monday = RecurrenceRule::weekly(Carbon::MONDAY);
    $thursday = RecurrenceRule::weekly(Carbon::THURSDAY);

    $week = Carbon::parse('2026-10-07');

    expect($monday->generationDate($monday->periodStart($week))->toDateString())->toBe('2026-10-05')
        ->and($thursday->generationDate($thursday->periodStart($week))->toDateString())->toBe('2026-10-08');
})->group('phase3');

it('defaults the due date to the end of the period and takes one offset for all three rules', function () {
    $monthly = RecurrenceRule::monthly();
    $weekly = RecurrenceRule::weekly();
    $custom = RecurrenceRule::custom(14, '2026-10-07');

    expect($monthly->dueDate($monthly->periodStart(Carbon::parse('2026-10-10')))->toDateString())
        ->toBe('2026-10-31')
        ->and($weekly->dueDate($weekly->periodStart(Carbon::parse('2026-10-07')))->toDateString())
        ->toBe('2026-10-11')
        ->and($custom->dueDate($custom->periodStart(Carbon::parse('2026-10-10')))->toDateString())
        ->toBe('2026-10-20');

    // One knob, the same meaning on every frequency: days after the period STARTED.
    $tenth = RecurrenceRule::monthly(1, 9);

    expect($tenth->dueDate($tenth->periodStart(Carbon::parse('2026-10-20')))->toDateString())
        ->toBe('2026-10-10');
})->group('phase3');

it('previews the next run strictly after the given day', function () {
    $rule = RecurrenceRule::monthly(1);

    // On the generation day itself the NEXT run is next month's: today's has been dealt with.
    expect($rule->nextRunAt(Carbon::parse('2026-10-01'))->toDateString())->toBe('2026-11-01')
        ->and($rule->nextRunAt(Carbon::parse('2026-10-20'))->toDateString())->toBe('2026-11-01');

    $fifteenth = RecurrenceRule::monthly(15);

    expect($fifteenth->nextRunAt(Carbon::parse('2026-10-01'))->toDateString())->toBe('2026-10-15');
})->group('phase3');

/*
|--------------------------------------------------------------------------
| Round-tripping and labels
|--------------------------------------------------------------------------
*/

it('round-trips through the JSON a template stores', function (RecurrenceRule $rule) {
    $restored = RecurrenceRule::fromArray($rule->toArray());

    expect($restored->frequency)->toBe($rule->frequency)
        ->and($restored->toArray())->toBe($rule->toArray())
        ->and($restored->periodKey(Carbon::parse('2026-10-20')))
        ->toBe($rule->periodKey(Carbon::parse('2026-10-20')));
})->with([
    'monthly' => [fn () => RecurrenceRule::monthly(15, 9)],
    'weekly' => [fn () => RecurrenceRule::weekly(Carbon::THURSDAY)],
    'custom' => [fn () => RecurrenceRule::custom(14, '2026-10-07')],
])->group('phase3');

it('falls back to monthly on the first rather than throwing on a rule it cannot read', function () {
    // A row somebody hand-edited. A console command at five past midnight should generate the
    // obvious thing; the Form Request on the templates screen is where bad input becomes an
    // error a person can see.
    $rule = RecurrenceRule::fromArray(['frequency' => 'fortnightly-ish']);

    expect($rule->frequency)->toBe(RecurrenceFrequency::Monthly)
        ->and($rule->dayOfMonth)->toBe(1);
})->group('phase3');

it('reads a period label back out of the key alone', function (?string $key, ?string $label) {
    // Task detail shows "period <Month YYYY>" and has nothing but the stored string, so the
    // label survives the template being deleted.
    expect(RecurrenceRule::labelForPeriod($key))->toBe($label);
})->with([
    ['2026-10', 'October 2026'],
    ['2026-01', 'January 2026'],
    ['2026-W41', 'Week 41, 2026'],
    ['2026-W01', 'Week 1, 2026'],
    ['2026-10-07', 'From 7 Oct 2026'],
    [null, null],
    ['', null],
    // An unrecognised shape is shown as stored rather than swallowed.
    ['whatever', 'whatever'],
])->group('phase3');
