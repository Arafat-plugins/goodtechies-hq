<?php

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Project;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\AuditEvent;
use App\Support\FinanceCategoryKind;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Repeating expenses — set up once, recorded on every due date
|--------------------------------------------------------------------------
|
| A VPS every month, a domain every year: `FinanceService::
| generateDueRecurringExpenses()` records an ordinary expense on each due
| date, catches up on missed days, is safe to run twice, never drifts off a
| month-end date, and stops at `ends_on`.
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

it('records one expense per missed due date and moves the next date on', function () {
    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'project_id' => $this->project->id,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-09-05',
        'notes' => 'VPS',
    ]);

    $created = $this->service->generateDueRecurringExpenses(Carbon::create(2026, 11, 20));

    $rows = Expense::where('recurring_expense_id', $template->id)->orderBy('date')->get();

    expect($created)->toBe(3)
        ->and($rows->map(fn (Expense $row) => $row->date->toDateString())->all())
        ->toBe(['2026-09-05', '2026-10-05', '2026-11-05']);

    foreach ($rows as $row) {
        expect((int) $row->category_id)->toBe($this->hosting)
            ->and((string) $row->amount)->toBe('20.00')
            ->and((int) $row->project_id)->toBe((int) $this->project->id)
            ->and((int) $row->recorded_by)->toBe((int) $this->accountant->id);
    }

    $template->refresh();

    expect($template->next_due_date->toDateString())->toBe('2026-12-05')
        ->and($template->occurrences)->toBe(3)
        ->and(AuditLog::where('event', AuditEvent::ExpenseCreated->value)
            ->whereIn('target_id', $rows->pluck('id'))->count())->toBe(3);
})->group('finance');

it('records nothing the second time it runs on the same day', function () {
    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-09-05',
    ]);

    $today = Carbon::create(2026, 11, 20);

    expect($this->service->generateDueRecurringExpenses($today))->toBe(3)
        ->and($this->service->generateDueRecurringExpenses($today))->toBe(0)
        ->and(Expense::where('recurring_expense_id', $template->id)->count())->toBe(3);
})->group('finance');

it('counts every due date from the start, so a 31st never drifts', function () {
    $yearly = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '15.00',
        'frequency' => 'yearly',
        'start_date' => '2026-01-31',
    ]);

    $this->service->generateDueRecurringExpenses(Carbon::create(2026, 1, 31));

    expect($yearly->refresh()->next_due_date->toDateString())->toBe('2027-01-31');

    $monthly = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2026-01-31',
    ]);

    expect($monthly->dueDateFor(1)->toDateString())->toBe('2026-02-28')
        ->and($monthly->dueDateFor(2)->toDateString())->toBe('2026-03-31');

    $this->service->generateDueRecurringExpenses(Carbon::create(2026, 3, 31));

    expect(Expense::where('recurring_expense_id', $monthly->id)->orderBy('date')->get()
        ->map(fn (Expense $row) => $row->date->toDateString())->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31'])
        ->and($monthly->refresh()->next_due_date->toDateString())->toBe('2026-04-30');
})->group('finance');

it('stops at the end date and pauses the template', function () {
    $template = $this->service->saveRecurringExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '20.00',
        'frequency' => 'monthly',
        'start_date' => '2027-03-10',
        'ends_on' => '2027-05-10',
    ]);

    $created = $this->service->generateDueRecurringExpenses(Carbon::create(2027, 9, 1));

    expect($created)->toBe(3)
        ->and(Expense::where('recurring_expense_id', $template->id)->count())->toBe(3)
        ->and($template->refresh()->active)->toBeFalse();

    expect($this->service->generateDueRecurringExpenses(Carbon::create(2027, 12, 1)))->toBe(0);
})->group('finance');

it('refuses the employee and lets the accountant set one up from the expenses page', function () {
    $body = [
        'category_id' => $this->hosting,
        'amount' => '15.00',
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

    $this->service->generateDueRecurringExpenses(Carbon::today());

    expect(Expense::where('recurring_expense_id', $template->id)->orderBy('date')->pluck('date')
        ->map(fn ($date) => Carbon::parse($date)->toDateString())->all())
        ->toBe(['2025-03-10', '2026-03-10']);

    $template = $this->service->saveRecurringExpense($this->accountant, ['frequency' => 'monthly'], $template->refresh());

    expect($template->next_due_date->toDateString())->toBe('2026-11-10');

    expect($this->service->generateDueRecurringExpenses(Carbon::today()))->toBe(0)
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

    expect($this->service->generateDueRecurringExpenses(Carbon::today()))->toBe(1);

    $template = $this->service->saveRecurringExpense($this->accountant, ['active' => false], $template->refresh());

    // Paused for July, August and September.
    $this->travelTo('2026-09-20');

    $template = $this->service->saveRecurringExpense($this->accountant, ['active' => true], $template->refresh());

    expect($template->next_due_date->toDateString())->toBe('2026-10-01')
        ->and($this->service->generateDueRecurringExpenses(Carbon::today()))->toBe(0)
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
