/**
 * The finance payloads, the endpoints that write them, and the one function that renders money.
 *
 * Everything here describes `App\Http\Resources\{Income,Expense,FinanceCategory}Resource` and
 * `App\Services\FinanceService::monthlyRollup()`. It is imported by the two ledgers, the
 * Categories screen, the finance dashboard and the monthly report, so **every export is
 * general**: nothing in this module knows which screen is asking.
 *
 * ## Two rules this module exists to enforce
 *
 *   1. **Money is formatted in exactly one place.** `formatMoney()` below. A `toFixed(2)` in a
 *      template is how two screens come to round differently, and a ledger where the dashboard
 *      and the list disagree by a cent is a ledger nobody trusts (decision 8-4). Amounts arrive
 *      as exact decimal strings from `decimal(12, 2)` and are parsed once, here.
 *   2. **No total is ever computed here.** A month's figure per category and its grand total
 *      come from `FinanceService::monthlyRollup()` — computed, never stored (decision 8-10) —
 *      and a screen that added up its own rows would be a second statement of a fact about
 *      money. There is deliberately no `sum()` in this file.
 *
 * Nothing here parses a date either. `new Date('2026-09-03')` is UTC midnight and prints as
 * 2 September for anybody west of Greenwich; `formatLedgerDate()` re-orders the parts of the
 * string the server already sent, which cannot do that.
 */

/* ------------------------------------------------------------------ the payloads */

/** Which side of the ledger. `App\Support\FinanceCategoryKind`. */
export type FinanceCategoryKind = 'income' | 'expense';

/**
 * A category as `FinanceCategoryResource` sends it.
 *
 * `usage_count` and `in_use` are present **only when the controller counted** — the Categories
 * screen asks for them, the form pickers do not. Absent is not zero: a screen must never read
 * "nothing is filed under this" from a caller that simply did not ask.
 */
export interface FinanceCategory {
    id: number;
    kind: FinanceCategoryKind;
    name: string;
    position: number;
    usage_count?: number;
    in_use?: boolean;
    permissions: {
        can_update: boolean;
        can_delete: boolean;
    };
}

/**
 * A project as every finance screen may know it: id, name, domain, and nothing else.
 *
 * No client, no contact, no description, no status, no count — Part C, and the same three keys
 * `IncomeResource::projectPayload()` sends to the income form's picker and embeds in every
 * income row. The domain is what stands in place of the client's name (Part C §2) and is how a
 * person tells four *Website Maintenance* rows apart.
 */
export interface FinanceProject {
    id: number;
    name: string;
    domain: string | null;
}

/** What both ledgers' rows have in common. */
interface LedgerRecordBase {
    id: number;
    /** `YYYY-MM-DD`. Never parsed here — see the module note. */
    date: string;
    /** The exact decimal string the column holds. Rendered only by `formatMoney()`. */
    amount: string;
    /** The record's own currency (`BDT` or `USD`). Never mixed with another in a sum. */
    currency: string;
    category: { id: number; name: string | null } | null;
    notes: string | null;
    permissions: {
        can_update: boolean;
        can_delete: boolean;
    };
}

/** One receipt of money. `IncomeResource`. */
export interface IncomeRecord extends LedgerRecordBase {
    /** Part D §13's optional project link. Null on most rows. */
    project: FinanceProject | null;
    /** `manual` when typed in (all income now); `auto` only on old rows generated before 2026-11. */
    source: 'manual' | 'auto';
}

/**
 * One payment out. `ExpenseResource`. Like income it may name a project, and it may be billable
 * to the client (decision 8-17b).
 */
export interface ExpenseRecord extends LedgerRecordBase {
    /** Optional project the cost belongs to (hosting, domain, VPS for one site). */
    project: FinanceProject | null;
    /** True when the cost is to be charged on to the client. */
    billable: boolean;
}

/**
 * A repeating expense (VPS monthly, domain yearly), as `ExpenseController::recurring()` sends
 * it. It is never recorded on its own: when it is due (`is_due`), somebody records it by hand.
 */
export interface RecurringExpense {
    id: number;
    category: { id: number; name: string } | null;
    project: FinanceProject | null;
    billable: boolean;
    /** The exact decimal string. Rendered only by `formatMoney()`. */
    amount: string;
    /** The template's own currency; every expense it records carries it. */
    currency: string;
    frequency: 'monthly' | 'quarterly' | 'yearly';
    frequency_label: string;
    /** `YYYY-MM-DD`, like every date here. */
    start_date: string;
    ends_on: string | null;
    next_due_date: string;
    notes: string | null;
    active: boolean;
    /** Active, `next_due_date` reached (agency timezone) and not past `ends_on`. */
    is_due: boolean;
}

/** The currencies a record may be kept in. `App\Support\Currency`. There is no exchange rate. */
export type CurrencyCode = 'BDT' | 'USD';

/** One entry of the currency picker, as `Currency::options()` sends it. */
export interface CurrencyOption {
    value: CurrencyCode;
    label: string;
}

/** The list filters, as the ledger controllers read them back from the query string. */
export interface LedgerFilters {
    category: number | null;
    currency: string | null;
    project: number | 'none' | null;
    q: string;
}

/** Either side's row, for anything that renders both. */
export type LedgerRecord = IncomeRecord | ExpenseRecord;

/** One line of a month's rollup: a category and what it came to. */
export interface RollupLine {
    category_id: number;
    name: string;
    /** An exact decimal string, like every other amount here. */
    total: string;
    /** `ledger` for a category's rows; `payroll` for the closed month's salaries line (`category_id` 0). */
    source?: 'ledger' | 'payroll';
}

/** The month's payroll as the rollup sees it. Counted as an expense only once locked or paid. */
export interface RollupPayroll {
    /** The period's status value, or null when the month has no payroll period. */
    status: string | null;
    /** SUM of net salary for the period. Exact decimal string. */
    total: string;
    /** True when the period is locked or paid, so the salaries are in the expenses. */
    counted: boolean;
    /** Expenses entered by hand this month under a category named "Payroll". */
    manual_payroll_entries: string;
    /** Counted payroll AND a manual "Payroll" expense in the same month. */
    double_count_warning: boolean;
    /** The currency payroll is paid in (the default currency). */
    currency: string;
}

/**
 * One side of a month, from `FinanceService::monthlyRollup()`.
 *
 * **A category with no rows this month is absent from `categories`, not present as zero.** The
 * rollup reports what happened; a screen that wants every category asks for the categories.
 */
export interface RollupSide {
    categories: RollupLine[];
    total: string;
}

/** One side of a month in one currency. Totals in different currencies are never added. */
export interface RollupCurrencySide extends RollupSide {
    currency: string;
}

/**
 * A whole month **in BDT** (D7): both sides and the operating result, as the finance dashboard
 * and report receive it (`FinanceService::monthlyRollup()`'s `bdt` entry plus the month, label
 * and payroll). Every USD figure is converted at the stored rate (`ExchangeRate`).
 */
export interface MonthlyRollup {
    /** `YYYY-MM`. */
    month: string;
    /** "September 2026", formatted by the server. */
    label: string;
    /** The month's income, in BDT. */
    income: RollupSide;
    /** The month's expenses, in BDT (payroll counted once the period is locked or paid). */
    expenses: RollupSide;
    /** Income less expenses, in exact cents. Negative is a real answer. */
    net: string;
    /** The month's payroll: counted into `expenses` only when the period is locked or paid. */
    payroll: RollupPayroll;
}

/** The USD→BDT rate the reports convert at, and whether the viewer may change it (D7). */
export interface ExchangeRate {
    /** BDT for 1 USD, two decimals. */
    usd_bdt: string;
    /** `auto` follows the live market rate; `manual` is the Admin's own figure. */
    mode: 'auto' | 'manual';
    /** ISO time the rate was last fetched or set; null when never. */
    updated_at: string | null;
    can_edit: boolean;
}

/**
 * One project's month, from `App\Support\ProjectEarnings::forMonth()`: what it should have
 * earned and what it did, in the project's own currency and in BDT (`*_bdt`). Every amount is
 * an exact decimal string.
 */
export interface ProjectEarningsRow {
    project: FinanceProject;
    /** The project's pricing currency — the one `expected`, `received` and `outstanding` are in. */
    currency: string;
    type: 'recurring' | 'hourly' | 'one_time' | 'none';
    type_label: string;
    /** How `expected` was worked out, in words. */
    basis: string;
    /** Null when nothing is expected this month. */
    expected: string | null;
    expected_bdt: string | null;
    /** Hourly only: approved hours this month, two decimals. */
    hours: string | null;
    /** Hourly only. */
    rate: string | null;
    /** Income in the project's own currency. */
    received: string;
    /** All income linked to the project, any currency, in BDT. */
    received_bdt: string;
    /** max(expected − received, 0). */
    outstanding: string;
    outstanding_bdt: string;
}

/** A one-time project's price against everything ever received for it. */
export interface ProjectContract {
    project: FinanceProject;
    /** The project's pricing currency — the one `price`, `received_to_date` and `balance` are in. */
    currency: string;
    price: string;
    price_bdt: string;
    received_to_date: string;
    received_to_date_bdt: string;
    /** price − received_to_date. Negative means overpaid. */
    balance: string;
    balance_bdt: string;
}

/** The `earnings` prop on the finance dashboard and report. */
export interface ProjectEarnings {
    /** `YYYY-MM`. */
    month: string;
    /** Always `BDT`: the totals and every `*_bdt` figure are in it. */
    currency: string;
    rows: ProjectEarningsRow[];
    contracts: ProjectContract[];
    /** In BDT. */
    totals: {
        expected: string;
        received: string;
        outstanding: string;
    };
}

/** The month a screen is on, and the two it can step to. All `YYYY-MM` but `label`. */
export interface FinanceMonth {
    value: string;
    label: string;
    previous: string;
    next: string;
}

/* --------------------------------------------------------------------- the money */

/**
 * The agency's currency when a payload does not name one.
 *
 * Every finance controller sends `currency` from `settings.currency`, which is the only place
 * it is configured; this constant is the same value the seeder writes, so a screen that has not
 * been given the prop yet renders the right symbol rather than crashing. Pass the prop.
 */
export const FINANCE_DEFAULT_CURRENCY = 'USD';

const formatters = new Map<string, Intl.NumberFormat>();

/**
 * `en-US` and not the browser's locale, deliberately: the grouping and the decimal separator
 * are then the same on every machine that opens the ledger, and two people reading the same
 * figure read the same characters. `DataTable`'s own cells do the same.
 *
 * **Always two decimals**, unlike `DataTable`'s `currency` cell, which drops them when they are
 * zero. A column of money that is sometimes `$860` and sometimes `$1,250.50` does not line up
 * on its decimal point, and lining up is most of what a ledger column is for. That is also why
 * these amounts are never rendered through `cell: 'currency'`.
 */
function formatterFor(currency: string): Intl.NumberFormat | null {
    const cached = formatters.get(currency);

    if (cached) {
        return cached;
    }

    try {
        const formatter = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

        formatters.set(currency, formatter);

        return formatter;
    } catch {
        // An unknown ISO code. The number still has to render, with the code beside it.
        return null;
    }
}

const PLAIN = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * **The one place money becomes text in this application.**
 *
 * Pass the exact decimal string the server sent. A `toFixed()` anywhere else is the defect this
 * function exists to prevent — see the module note.
 *
 * A value that is not a number comes back unchanged rather than as `NaN`: a ledger that prints
 * `$NaN` has told you nothing, and a ledger that prints the raw value has at least told you
 * what it was given.
 */
export function formatMoney(amount: string | number, currency: string = FINANCE_DEFAULT_CURRENCY): string {
    const value = typeof amount === 'number' ? amount : Number.parseFloat(amount);

    if (!Number.isFinite(value)) {
        return String(amount);
    }

    const formatter = formatterFor(currency);

    return formatter ? formatter.format(value) : `${currency} ${PLAIN.format(value)}`;
}

/* --------------------------------------------------------------------- the dates */

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/** `2026-09-03` → `3 Sep 2026`. A re-ordering of the server's own string, never a parse. */
export function formatLedgerDate(date: string): string {
    const [year, month, day] = date.split('-');
    const name = MONTHS[Number(month) - 1];

    if (!name || !day || !year) {
        return date;
    }

    return `${Number(day)} ${name.slice(0, 3)} ${year}`;
}

/** `2026-09-03` → `3 September 2026`. The long form, for a sentence rather than a column. */
export function formatLedgerDateLong(date: string): string {
    const [year, month, day] = date.split('-');
    const name = MONTHS[Number(month) - 1];

    if (!name || !day || !year) {
        return date;
    }

    return `${Number(day)} ${name} ${year}`;
}

/**
 * `2026-09` → `September 2026`.
 *
 * The month a screen is ON arrives with its label already formatted by the server; this is for
 * the two it can step to, which travel as bare values. Same re-ordering, same reason.
 */
export function formatMonthLabel(month: string): string {
    const [year, index] = month.split('-');
    const name = MONTHS[Number(index) - 1];

    return name && year ? `${name} ${year}` : month;
}

/* -------------------------------------------------------------------- the routes */

/**
 * Every finance endpoint, written once.
 *
 * The month travels as a query parameter on each list, so a link to September is a link
 * somebody can send (DESIGN.md §5 rule 10). The dashboard and the report belong to the same
 * group and are here so that no screen spells `/finance` twice.
 */
export const financeRoutes = {
    dashboard: (month?: string) => withMonth('/finance', month),
    report: (month?: string) => withMonth('/finance/report', month),

    income: {
        index: (month?: string) => withMonth('/finance/income', month),
        create: (month?: string) => withMonth('/finance/income/create', month),
        store: () => '/finance/income',
        edit: (id: number, month?: string) => withMonth(`/finance/income/${id}/edit`, month),
        update: (id: number) => `/finance/income/${id}`,
        destroy: (id: number) => `/finance/income/${id}`,
    },

    expenses: {
        index: (month?: string) => withMonth('/finance/expenses', month),
        create: (month?: string) => withMonth('/finance/expenses/create', month),
        store: () => '/finance/expenses',
        edit: (id: number, month?: string) => withMonth(`/finance/expenses/${id}/edit`, month),
        update: (id: number) => `/finance/expenses/${id}`,
        destroy: (id: number) => `/finance/expenses/${id}`,
    },

    recurringExpenses: {
        store: () => '/finance/recurring-expenses',
        update: (id: number) => `/finance/recurring-expenses/${id}`,
        destroy: (id: number) => `/finance/recurring-expenses/${id}`,
        record: (id: number) => `/finance/recurring-expenses/${id}/record`,
        skip: (id: number) => `/finance/recurring-expenses/${id}/skip`,
    },

    categories: {
        index: () => '/finance/categories',
        store: () => '/finance/categories',
        update: (id: number) => `/finance/categories/${id}`,
        destroy: (id: number) => `/finance/categories/${id}`,
    },
};

function withMonth(path: string, month?: string): string {
    return month ? `${path}?month=${month}` : path;
}

/* --------------------------------------------------------------------- the words */

/** "income" / "expense", for a sentence about one row. */
export function ledgerNoun(kind: FinanceCategoryKind): string {
    return kind === 'income' ? 'income' : 'expense';
}

/**
 * The question the delete confirmation asks.
 *
 * **It names the record and its amount**, because a month of a dozen rows several of which are
 * called *Maintenance* is a list where "Are you sure?" is not a question anybody can answer.
 * The word is **delete**: this is a hard delete (decision 8-5) and nothing about it is archived
 * or hidden, so nothing here may say either.
 */
export function ledgerDeletePrompt(kind: FinanceCategoryKind, record: LedgerRecord): string {
    const category = record.category?.name ?? 'Uncategorised';

    return `Delete the ${category} ${ledgerNoun(kind)} of ${formatMoney(record.amount, record.currency)} from ${formatLedgerDateLong(record.date)}?`;
}
