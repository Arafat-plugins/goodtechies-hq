<?php

use App\Exceptions\FinanceStateException;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\AuditEvent;
use App\Support\FinanceCategoryKind;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Repeating expenses — set up once, recorded only when an Admin says so
|--------------------------------------------------------------------------
|
| A VPS every month, a domain every year. Nothing records them on their own:
| when one is due, `FinanceService::recordDueRecurringExpense()` (the "Record
| now" button) records ONE ordinary expense and moves the template on, never
| twice for the same date, never into a closed month; `skipDueRecurringExpense()`
| moves it on without recording. No scheduled job generates expenses or income.
|
| Helpers are prefixed recExp*, because Pest declares them globally across
| the whole suite (AGENTS.md).
|
*/

/** @return int the id of a seeded expense category, by name */
function recExpCategoryId(string $name): int
{
    return (int) FinanceCategory::query()->ofKind(FinanceCategoryKind::Expense)->where('name', $name)->firstOrFail()->getKey();
}

beforeEach(function () {
    $this->seed();

    $this->service = app(FinanceService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->hosting = recExpCategoryId('Hosting');
    $this->project = Project::query()->orderBy('id')->firstOrFail();
});

/** Lock the payroll month that `$month` (YYYY-MM-01) falls in, the way CurrencyLedgerTest does. */
function recExpLockMonth(string $month): void
{
    $period = PayrollPeriod::factory()->forMonth($month)->create();
    PayrollItem::factory()->in($period)->of('1000.00', '0.00')->create();

    DB::table('payroll_periods')->where('id', $period->getKey())->update([
        'status' => 'locked',
        'locked_at' => Carbon::now(),
    ]);
}

it('records exactly one expense from a due template, with its amount, currency, category and project', function () {
    $this->travelTo('2026-11-20');

    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'project_id' => $this->project->id,
        'amount' => '20.00',
        'currency' => 'USD',
        'frequency' => 'monthly',
        'start_date' => '2026-11-05',
        'notes' => 'VPS',
    ]);

    // Nothing is recorded just because it is due.
    expect(Expense::where('recurring_expense_id', $template->id)->count())->toBe(0);

    $expense = $this->service->recordDueRecurringExpense($this->admin, $template);

    $rows = Expense::where('recurring_expense_id', $template->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($expense->id)->toBe($rows->first()->id)
        ->and($expense->date->toDateString())->toBe('2026-11-05')
        ->and((int) $expense->category_id)->toBe($this->hosting)
        ->and((string) $expense->amount)->toBe('20.00')
        ->and((string) $expense->currency)->toBe('USD')
        ->and((int) $expense->project_id)->toBe((int) $this->project->id)
        ->and((int) $expense->recorded_by)->toBe((int) $this->admin->id)
        ->and(AuditLog::where('event', AuditEvent::ExpenseCreated->value)
            ->where('target_id', $expense->id)->count())->toBe(1);

    $template->refresh();

    expect($template->next_due_date->toDateString())->toBe('2026-12-05')
        ->and($template->occurrences)->toBe(1);
})->group('finance');

it('records one occurrence per click and never a duplicate for the same due date', function () {
    $this->travelTo('2026-11-20');

    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-11-05',
    ]);

    $first = $this->service->recordDueRecurringExpense($this->admin, $template);

    // The next one (Dec 5) is not due yet: a second click is refused, not duplicated.
    expect(fn () => $this->service->recordDueRecurringExpense($this->admin, $template->refresh()))
        ->toThrow(FinanceStateException::class, 'This repeating expense is not due yet. It is next due on 2026-12-05.');

    // An expense already on the due date (a double submit that raced) is reused, not duplicated.
    $template->refresh();
    $template->occurrences = 0;
    $template->next_due_date = Carbon::parse('2026-11-05');
    $template->save();

    $again = $this->service->recordDueRecurringExpense($this->admin, $template);

    expect($again->id)->toBe($first->id)
        ->and(Expense::where('recurring_expense_id', $template->id)->count())->toBe(1)
        ->and($template->refresh()->next_due_date->toDateString())->toBe('2026-12-05');
})->group('finance');

it('refuses a template that is not due yet, a paused one and a closed month', function () {
    $this->travelTo('2026-11-20');

    $future = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-12-01',
    ]);

    expect(fn () => $this->service->recordDueRecurringExpense($this->admin, $future))
        ->toThrow(FinanceStateException::class, 'not due yet');

    $paused = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-11-01',
        'active' => false,
    ]);

    expect(fn () => $this->service->recordDueRecurringExpense($this->admin, $paused))
        ->toThrow(FinanceStateException::class, 'This repeating expense is paused or finished.')
        ->and(fn () => $this->service->skipDueRecurringExpense($this->admin, $paused))
        ->toThrow(FinanceStateException::class, 'This repeating expense is paused or finished.');

    $locked = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-11-03',
    ]);

    recExpLockMonth('2026-11-01');

    expect(fn () => $this->service->recordDueRecurringExpense($this->admin, $locked))
        ->toThrow(FinanceStateException::class);

    expect(Expense::whereIn('recurring_expense_id', [$future->id, $paused->id, $locked->id])->count())->toBe(0)
        ->and($locked->refresh()->next_due_date->toDateString())->toBe('2026-11-03');
})->group('finance');

it('skips a due occurrence without recording anything', function () {
    $this->travelTo('2026-11-20');

    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-11-05',
    ]);

    $audits = AuditLog::count();

    $this->service->skipDueRecurringExpense($this->admin, $template);

    expect(Expense::where('recurring_expense_id', $template->id)->count())->toBe(0)
        ->and($template->refresh()->next_due_date->toDateString())->toBe('2026-12-05')
        ->and($template->occurrences)->toBe(1)
        ->and(AuditLog::count())->toBe($audits);
})->group('finance');

it('counts every due date from the start, so a 31st never drifts', function () {
    $this->travelTo('2026-01-31');

    $monthly = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-01-31',
    ]);

    expect($monthly->dueDateFor(1)->toDateString())->toBe('2026-02-28')
        ->and($monthly->dueDateFor(2)->toDateString())->toBe('2026-03-31');

    $this->service->recordDueRecurringExpense($this->admin, $monthly);
    $this->travelTo('2026-02-28');
    $this->service->recordDueRecurringExpense($this->admin, $monthly);
    $this->travelTo('2026-03-31');
    $this->service->recordDueRecurringExpense($this->admin, $monthly);

    expect(Expense::where('recurring_expense_id', $monthly->id)->orderBy('date')->get()
        ->map(fn (Expense $row) => $row->date->toDateString())->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31'])
        ->and($monthly->refresh()->next_due_date->toDateString())->toBe('2026-04-30');
})->group('finance');

it('finishes the template once it has passed its end date', function () {
    $this->travelTo('2027-05-20');

    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2027-05-10',
        'ends_on' => '2027-05-31',
    ]);

    $this->service->recordDueRecurringExpense($this->admin, $template);

    expect($template->refresh()->active)->toBeFalse()
        ->and(Expense::where('recurring_expense_id', $template->id)->count())->toBe(1);
})->group('finance');

it('records and skips from the expenses page for an Admin, and refuses an employee', function () {
    $this->travelTo('2026-11-20');

    $record = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'currency' => 'USD',
        'frequency' => 'monthly',
        'start_date' => '2026-11-05',
    ]);

    $skip = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '9.00',
        'frequency' => 'monthly',
        'start_date' => '2026-11-06',
    ]);

    $this->actingAs($this->admin)
        ->get('/finance/expenses')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dueRecurring', 2)
            ->where('recurring.0.is_due', true));

    $this->actingAs($this->employee)
        ->post('/finance/recurring-expenses/'.$record->id.'/record')
        ->assertForbidden();

    $this->actingAs($this->employee)
        ->post('/finance/recurring-expenses/'.$skip->id.'/skip')
        ->assertForbidden();

    expect(Expense::whereNotNull('recurring_expense_id')->count())->toBe(0);

    $this->actingAs($this->admin)
        ->post('/finance/recurring-expenses/'.$record->id.'/record')
        ->assertRedirect('/finance/expenses?month=2026-11')
        ->assertSessionHas('success', 'Recorded Hosting of USD 20.00.');

    $this->actingAs($this->admin)
        ->from('/finance/expenses')
        ->post('/finance/recurring-expenses/'.$skip->id.'/skip')
        ->assertRedirect('/finance/expenses')
        ->assertSessionHas('success', 'Skipped this time. Next due on 6 Dec 2026.');

    expect(Expense::where('recurring_expense_id', $record->id)->count())->toBe(1)
        ->and(Expense::where('recurring_expense_id', $skip->id)->count())->toBe(0);

    // A second click on the same due date is refused with a message, not a duplicate.
    $this->actingAs($this->admin)
        ->from('/finance/expenses')
        ->post('/finance/recurring-expenses/'.$record->id.'/record')
        ->assertRedirect('/finance/expenses')
        ->assertSessionHas('error');

    expect(Expense::where('recurring_expense_id', $record->id)->count())->toBe(1);

    $this->actingAs($this->admin)
        ->get('/finance/expenses')
        ->assertInertia(fn (Assert $page) => $page->where('dueRecurring', 0));
})->group('finance');

it('schedules no job that records repeating expenses or project income', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (Event $event) => (string) $event->command);

    expect($commands->filter(fn (string $command) => str_contains($command, 'generate-recurring-expenses')
        || str_contains($command, 'generate-project-income')))->toBeEmpty();
})->group('finance');

it('refuses the employee and lets the accountant set one up from the expenses page', function () {
    $body = [
        'category_id' => $this->hosting,
        'amount' => '15.00',
        'currency' => 'USD',
        'frequency' => 'yearly',
        'start_date' => '2027-06-01',
        'notes' => 'Domain renewal',
    ];

    $this->actingAs($this->employee)
        ->post('/finance/recurring-expenses', $body)
        ->assertForbidden();

    expect(RecurringExpense::count())->toBe(0);

    $this->actingAs($this->accountant)
        ->post('/finance/recurring-expenses', $body)
        ->assertRedirect('/finance/expenses');

    $template = RecurringExpense::firstOrFail();

    expect($template->next_due_date->toDateString())->toBe('2027-06-01')
        ->and(AuditLog::where('event', AuditEvent::RecurringExpenseSaved->value)->count())->toBe(1);

    $this->actingAs($this->accountant)
        ->get('/finance/expenses')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Finance/Expenses')
            ->has('recurring', 1)
            ->where('recurring.0.id', $template->id)
            ->where('recurring.0.amount', '15.00')
            ->where('recurring.0.frequency_label', 'Yearly')
            ->where('recurring.0.next_due_date', '2027-06-01'));
})->group('finance');

it('refuses the employee, the manager and the remote employee on editing or deleting a template', function (string $email) {
    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2027-06-01',
    ]);

    $user = User::where('email', $email)->firstOrFail();

    $this->actingAs($user)
        ->put('/finance/recurring-expenses/'.$template->id, [
            'category_id' => $this->hosting,
            'amount' => '99.00',
            'currency' => 'USD',
            'frequency' => 'monthly',
            'start_date' => '2027-06-01',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete('/finance/recurring-expenses/'.$template->id)
        ->assertForbidden();

    expect((string) $template->refresh()->amount)->toBe('20.00');
})->with([
    'employee' => 'yaseen@goodtechies.test',
    'manager' => 'faruk@goodtechies.test',
    'remote employee' => 'tapu@goodtechies.test',
])->group('finance');

it('does not back-fill when a yearly template with recorded dates is switched to monthly', function () {
    $this->travelTo('2026-10-14');

    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '15.00',
        'frequency' => 'yearly',
        'start_date' => '2025-03-10',
    ]);

    // Record the two yearly dates that have come due, by hand.
    $this->service->recordDueRecurringExpense($this->admin, $template);
    $this->service->recordDueRecurringExpense($this->admin, $template->refresh());

    expect(Expense::where('recurring_expense_id', $template->id)->orderBy('date')->pluck('date')
        ->map(fn ($date) => Carbon::parse($date)->toDateString())->all())
        ->toBe(['2025-03-10', '2026-03-10']);

    $template = $this->service->saveRecurringExpense($this->accountant, ['frequency' => 'monthly'], $template->refresh());

    expect($template->next_due_date->toDateString())->toBe('2026-11-10');

    expect($this->service->dueRecurringExpenses(Carbon::today())->pluck('id')->all())->not->toContain($template->id)
        ->and(Expense::where('recurring_expense_id', $template->id)->count())->toBe(2)
        ->and(Expense::where('recurring_expense_id', $template->id)
            ->whereDate('date', '>', '2026-03-10')
            ->whereDate('date', '<', Carbon::today()->startOfMonth()->toDateString())
            ->exists())->toBeFalse();
})->group('finance');

it('records nothing for the months a template was paused', function () {
    $this->travelTo('2026-06-14');

    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-06-01',
    ]);

    $this->service->recordDueRecurringExpense($this->admin, $template);

    $template = $this->service->saveRecurringExpense($this->accountant, ['active' => false], $template->refresh());

    // Paused for July, August and September.
    $this->travelTo('2026-09-20');

    $template = $this->service->saveRecurringExpense($this->accountant, ['active' => true], $template->refresh());

    expect($template->next_due_date->toDateString())->toBe('2026-10-01')
        ->and($this->service->dueRecurringExpenses(Carbon::today())->pluck('id')->all())->not->toContain($template->id)
        ->and(Expense::where('recurring_expense_id', $template->id)->orderBy('date')->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())->all())
        ->toBe(['2026-06-01']);
})->group('finance');

it('refuses a new template that starts before this month', function () {
    $this->travelTo('2026-10-14');

    $this->actingAs($this->accountant)
        ->post('/finance/recurring-expenses', [
            'category_id' => $this->hosting,
            'amount' => '20.00',
            'frequency' => 'monthly',
            'start_date' => '2026-09-15',
        ])
        ->assertSessionHasErrors([
            'start_date' => 'A repeating expense can start this month or later. Record earlier months as normal expenses.',
        ]);

    expect(RecurringExpense::count())->toBe(0);
})->group('finance');
