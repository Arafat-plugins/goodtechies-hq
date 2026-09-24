<?php

use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| "Upcoming holidays" on both dashboards (Part D §3)
|--------------------------------------------------------------------------
|
| Both cards are the same question with the same answer, so the thing worth
| asserting is that they ARE the same answer: the Admin's Company dashboard
| and an employee's dashboard get identical rows, from one service, through
| one resource. A holiday is a fact about the company and not about the
| person looking at it, so nothing here is scoped and nothing can drift.
|
| The employee dashboard's "Arrives in Phase 5" placeholder is gone, replaced
| by this card. That half cannot be asserted from here — it is a Vue
| constant — so it is verified by rendering the page, not claimed here.
|
| Time is frozen at 2026-09-14 so "today", "tomorrow" and the ordering are
| facts rather than whatever the clock says when the suite runs.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-14 09:00:00');

    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    Holiday::query()->delete();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the same upcoming holidays on the Company and the employee dashboard', function (): void {
    Holiday::factory()->on(Carbon::parse('2026-09-14'))->named('Today the office is shut')->create();
    Holiday::factory()->on(Carbon::parse('2026-09-20'))->named('Next Sunday')->create();
    Holiday::factory()->on(Carbon::parse('2026-09-01'))->named('A fortnight ago')->create();

    $adminRows = null;

    $this->actingAs($this->admin)
        ->get('/admin/dashboard')
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$adminRows) {
            $adminRows = $page->toArray()['props']['upcomingHolidays'];

            expect(collect($adminRows)->pluck('name')->all())
                // Today counts as upcoming — a card that dropped it at midnight would go quiet
                // on the day it is most useful. What is past is not on the list.
                ->toBe(['Today the office is shut', 'Next Sunday']);
        });

    $this->actingAs($this->yaseen)
        ->get('/employee/dashboard')
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$adminRows) {
            $facts = fn (array $rows): array => array_map(
                fn (array $row): array => Arr::except($row, 'permissions'),
                $rows,
            );

            // Every FACT is identical — same rows, same order, same dates, same weekdays, same
            // day counts. Only the `permissions` block differs, and that is the design: what a
            // holiday IS cannot depend on who is looking, and what you may DO to it must.
            expect($facts($page->toArray()['props']['upcomingHolidays']))->toBe($facts($adminRows));
        });
});

it('marks today as today and sends the weekday from the server', function (): void {
    Holiday::factory()->on(Carbon::parse('2026-09-14'))->named('Today')->create();
    Holiday::factory()->on(Carbon::parse('2026-09-15'))->named('Tomorrow')->create();

    $this->actingAs($this->admin)
        ->get('/admin/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcomingHolidays.0.is_today', true)
            ->where('upcomingHolidays.0.days_away', 0)
            // 14 September 2026 is a Monday. The weekday is the server's, not the browser's —
            // a laptop in another timezone must not move a holiday by a day.
            ->where('upcomingHolidays.0.weekday', 'Monday')
            ->where('upcomingHolidays.1.is_today', false)
            ->where('upcomingHolidays.1.days_away', 1)
            ->etc());
});

it('sends an empty list rather than a placeholder when the calendar is clear', function (): void {
    // Zero is an answer. The card says the calendar is clear; it does not say "arrives in
    // Phase 5".
    $this->actingAs($this->admin)
        ->get('/admin/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('upcomingHolidays', 0));

    $this->actingAs($this->tapu)
        ->get('/employee/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('upcomingHolidays', 0));
});

it('tells an employee they may not edit the calendar, per record, from the server', function (): void {
    Holiday::factory()->on(Carbon::parse('2026-09-20'))->named('Next Sunday')->create();

    $this->actingAs($this->yaseen)
        ->get('/employee/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Reading is everybody's — a holiday is not private to anyone — but editing is
            // `settings.manage`, and the answer is the policy's rather than a role compared in
            // Vue (decisions 2-28, 2-31).
            ->where('upcomingHolidays.0.permissions.can_update', false)
            ->where('upcomingHolidays.0.permissions.can_delete', false)
            ->etc());
});

it('shows at most the card\'s own limit, and the nearest ones', function (): void {
    foreach (range(1, 8) as $offset) {
        Holiday::factory()
            ->on(Carbon::parse('2026-09-14')->addDays($offset))
            ->named('Holiday '.$offset)
            ->create();
    }

    $this->actingAs($this->admin)
        ->get('/admin/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('upcomingHolidays', HolidayService::DASHBOARD_LIMIT)
            ->where('upcomingHolidays.0.name', 'Holiday 1')
            ->etc());
});
