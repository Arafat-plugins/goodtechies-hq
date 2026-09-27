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
}

/** One payment out. `ExpenseResource`. There is no project — Part D §20 gives an expense none. */
export type ExpenseRecord = LedgerRecordBase;

/** Either side's row, for anything that renders both. */
export type LedgerRecord = IncomeRecord | ExpenseRecord;

/** One line of a month's rollup: a category and what it came to. */
export interface RollupLine {
    category_id: number;
    name: string;
    /** An exact decimal string, like every other amount here. */
    total: string;
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

/** A whole month, both sides and the operating result. */
export interface MonthlyRollup {
    /** `YYYY-MM`. */
    month: string;
    /** "September 2026", formatted by the server. */
    label: string;
    income: RollupSide;
    expenses: RollupSide;
    /** Income less expenses, in exact cents. Negative is a real answer, not an error. */
    net: string;
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
export function ledgerDeletePrompt(
    kind: FinanceCategoryKind,
    record: LedgerRecord,
    currency: string,
): string {
    const category = record.category?.name ?? 'Uncategorised';

    return `Delete the ${category} ${ledgerNoun(kind)} of ${formatMoney(record.amount, currency)} from ${formatLedgerDateLong(record.date)}?`;
}
