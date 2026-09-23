<?php

use App\Support\RecurrenceRule;
use App\Support\RecurrenceSummary;

/*
|--------------------------------------------------------------------------
| RecurrenceSummary — a rule as a sentence
|--------------------------------------------------------------------------
|
| It is a presenter and nothing else: it reads a rule's parameters and spells
| them. Every date on the templates screen comes from RecurrenceRule, which is
| why there is no date in this file's expectations except an anchor that was
| handed in.
|
*/

it('spells a monthly rule', function () {
    expect(RecurrenceSummary::for(RecurrenceRule::monthly(1)))
        ->toBe('On the 1st of every month, due at the end of the period.')
        ->and(RecurrenceSummary::for(RecurrenceRule::monthly(22)))
        ->toBe('On the 22nd of every month, due at the end of the period.')
        // The rule clamps to 28 so a month never gets skipped; the sentence says what was
        // actually stored rather than what was asked for.
        ->and(RecurrenceSummary::for(RecurrenceRule::monthly(31)))
        ->toBe('On the 28th of every month, due at the end of the period.');
})->group('phase3');

it('spells a weekly rule by ISO weekday', function () {
    expect(RecurrenceSummary::for(RecurrenceRule::weekly(1)))
        ->toBe('Every Monday, due at the end of the period.')
        ->and(RecurrenceSummary::for(RecurrenceRule::weekly(7)))
        ->toBe('Every Sunday, due at the end of the period.');
})->group('phase3');

it('spells a custom cycle with its anchor', function () {
    expect(RecurrenceSummary::for(RecurrenceRule::custom(14, '2026-10-07')))
        ->toBe('Every 14 days from 7 Oct 2026, due at the end of the period.')
        ->and(RecurrenceSummary::for(RecurrenceRule::custom(1, '2026-10-07')))
        ->toBe('Every day from 7 Oct 2026, due at the end of the period.');
})->group('phase3');

it('spells the due offset the same way on all three frequencies', function () {
    // One knob, not three: nine days means the same nine days whichever rule it is on, which is
    // what stops "due" meaning day-of-month on one and days-from-start on another.
    expect(RecurrenceSummary::due(RecurrenceRule::monthly(1, 9)))
        ->toBe('due 9 days after the period starts')
        ->and(RecurrenceSummary::due(RecurrenceRule::weekly(1, 9)))
        ->toBe('due 9 days after the period starts')
        ->and(RecurrenceSummary::due(RecurrenceRule::custom(14, '2026-10-07', 9)))
        ->toBe('due 9 days after the period starts')
        ->and(RecurrenceSummary::due(RecurrenceRule::monthly(1, 1)))
        ->toBe('due 1 day after the period starts')
        ->and(RecurrenceSummary::due(RecurrenceRule::monthly(1, 0)))
        ->toBe('due the day it is created');
})->group('phase3');

it('gets the ordinals right where a last-digit rule would not', function () {
    expect(RecurrenceSummary::ordinal(11))->toBe('11th')
        ->and(RecurrenceSummary::ordinal(12))->toBe('12th')
        ->and(RecurrenceSummary::ordinal(13))->toBe('13th')
        ->and(RecurrenceSummary::ordinal(21))->toBe('21st')
        ->and(RecurrenceSummary::ordinal(22))->toBe('22nd');
})->group('phase3');
