<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\FinanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreRecurringExpenseRequest;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Repeating expenses (a VPS every month, a domain every year): set up, changed and removed from
 * the expenses page, recorded on each due date by `hq:generate-recurring-expenses`.
 *
 * Managing one takes the same right as recording an expense (`create` on `Expense`), asked here
 * and again inside `FinanceService`. Every rule lives in the service; this only translates.
 */
class RecurringExpenseController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
    ) {}

    public function store(StoreRecurringExpenseRequest $request): RedirectResponse
    {
        Gate::authorize('create', Expense::class);

        try {
            $this->finance->saveRecurringExpense($request->user(), $request->templateAttributes());
        } catch (FinanceStateException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect('/finance/expenses')->with('success', 'Repeating expense saved.');
    }

    public function update(StoreRecurringExpenseRequest $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        Gate::authorize('create', Expense::class);

        try {
            $this->finance->saveRecurringExpense($request->user(), $request->templateAttributes(), $recurringExpense);
        } catch (FinanceStateException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect('/finance/expenses')->with('success', 'Repeating expense saved.');
    }

    public function destroy(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        Gate::authorize('create', Expense::class);

        try {
            $this->finance->deleteRecurringExpense($request->user(), $recurringExpense);
        } catch (FinanceStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect('/finance/expenses')->with('success', 'Repeating expense deleted.');
    }
}
