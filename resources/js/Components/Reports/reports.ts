import { formatMinutes } from '@/Components/Attendance/attendance';
import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The report contract (`docs/report-contract.md`), in TypeScript.
 *
 * Sixteen reports, one screen. Everything here mirrors §1 and §3 of that file and adds
 * nothing to them: a builder that needs a shape this module does not have is a finding to
 * raise against the contract, not a shape to invent in Vue.
 *
 * ## What this module deliberately does not know
 *
 * There is no list of report keys here, no per-report column set, and no `switch` on a key
 * anywhere in `Components/Reports/` or `Pages/{Admin,Employee}/Reports*`. The renderer knows
 * `ReportFormat` and nothing else — that is the whole reason the contract exists, and it is
 * what makes the next eight reports a builder each rather than a screen each.
 *
 * ## Permissions
 *
 * None, here or anywhere below. The server sends the catalogue the viewer may read and the
 * columns the viewer may read; a report that is absent from `reports` is absent because it was
 * never serialised, not because this file filtered it (Part C §1 — absent, not masked).
 *
 * ## The formatters, and why each one is where it is
 *
 * - `formatMinutes` is **imported**, from `Components/Attendance/attendance.ts`. A duration is
 *   read out loud the same way on every screen in this app (`4h 18m`, `45m`, `—`) and a fourth
 *   private copy of that arithmetic is the drift DESIGN.md §5.8 exists to stop.
 * - money, dates, counts and percentages are formatted **here**, because each one has a rule
 *   the existing helpers do not follow:
 *   - money **never becomes a JS number** (contract §3). `Components/Finance/finance.ts`'s
 *     `formatMoney()` parses the decimal string before handing it to `Intl`; that is safe for a
 *     ledger's cents and is still not what this contract says, so the digits below are grouped
 *     as text and only the *symbol* is asked of `Intl`.
 *   - a date is re-ordered as a string, never `new Date('2026-09-03')`, which is parsed as UTC
 *     midnight and prints the day before in any negative offset.
 */

/* ------------------------------------------------------------------- §1 the catalogue */

/** `ReportGroup` — the three bands the Reports index is divided into. */
export type ReportGroupKey = 'work' | 'workforce' | 'money';

/** `ReportFilter` — what a report is willing to be narrowed by. */
export type ReportFilterKey = 'date_range' | 'employee' | 'project' | 'client';

/**
 * How a filter is named on a catalogue card — "Date range · Employee · Project".
 *
 * `ReportFilter::label()` says the same four words in PHP, but `ReportKey::toCard()` sends the
 * enum's *values*, so this is the only place they become English. Four fixed cases; if a fifth
 * ever lands, it is one line here and one in the enum — or `toCard()` starts sending the label
 * and this map goes away, which is the better fix and is raised with the slice.
 */
export const REPORT_FILTER_LABELS: Record<ReportFilterKey, string> = {
    date_range: 'Date range',
    employee: 'Employee',
    project: 'Project',
    client: 'Client',
};

export function reportFilterLabel(filter: ReportFilterKey): string {
    return REPORT_FILTER_LABELS[filter] ?? filter;
}

/** One `ReportKey` as the catalogue sends it: what it is called and what it answers. */
export interface ReportSummary {
    /** The `ReportKey` backed value — `task`, `employee-work`, … It is the URL segment. */
    key: string;
    label: string;
    /** `ReportKey::question()`, printed under the title on both screens. */
    question: string;
    group: ReportGroupKey;
    /** `ReportKey::filters()` — exactly the chips the Show screen offers. */
    filters: ReportFilterKey[];
}

/**
 * Every report URL, spelled once.
 *
 * A report's address is its key, so the front end can build it without the server sending a
 * link per card — the same reason `financeRoutes` exists rather than an `href` on every
 * finance payload.
 */
export const reportRoutes = {
    index: '/admin/reports',
    show: (key: string): string => `/admin/reports/${encodeURIComponent(key)}`,
    employee: '/employee/reports',
} as const;

/* --------------------------------------------------------------------- §3 the answer */

/** The renderer's whole vocabulary. Nothing outside this list is a cell. */
export type ReportFormat = 'text' | 'number' | 'money' | 'minutes' | 'date' | 'percent' | 'status';

export type ReportAlign = 'start' | 'end';

export interface ReportColumn {
    /** Unique per report. Doubles as the row key and the totals key. */
    key: string;
    label: string;
    format: ReportFormat;
    align: ReportAlign;
    /**
     * The name of **another key on the row** holding this cell's href, when the cell is a link.
     *
     * Every count of something openable is a link, which is the rule the rest of this app
     * follows. The href is the server's — the builder knows the scope the row came from, so it
     * cannot link somewhere the reader may not go — and nothing here builds a URL from an id.
     * The href key is not a column, so it is carried and never printed as one.
     */
    link_key?: string;
    /**
     * `status` columns only: the tone → **the word the server prints for it**.
     *
     * `StatusBadge` carries a default word per tone and those defaults are not always the
     * app's own vocabulary — `waiting` defaults to *"Waiting"* where `TaskStatus` says
     * *"Waiting / Blocked"*, and `done` defaults to *"Done"* where it says *"Completed"*. The
     * Task report puts a donut built from the enum's labels directly above a table of these
     * cells, so without this map the same status is printed two different ways on one screen.
     *
     * Absent on every column that is not a status, and a tone with no entry still falls back
     * to the badge's default.
     */
    labels?: Record<string, string>;
}

/** A cell is a scalar. There is no markup, no href and no nested object in a row. */
export type ReportCellValue = string | number | boolean | null;

export type ReportRow = Record<string, ReportCellValue>;

/** The footer row, keyed like a row. `null` on the result means no footer at all. */
export type ReportTotals = Record<string, string | number | null>;

/** One mark. `value` is an int or a decimal **string**, exactly as a cell is. */
export interface ReportChartPoint {
    label: string;
    value: number | string;
    /** A `StatusKey`, when the series is a breakdown of something that has statuses. */
    tone?: string | null;
}

export interface ReportChart {
    kind: 'bar' | 'donut' | 'area';
    title: string;
    /**
     * How the chart's own figures are written — in its axis, its tooltip, its legend and its
     * screen-reader table.
     *
     * A chart whose axis reads `432` above a table reading `432h` is a chart the reader has to
     * be told how to read. Defaults to `number` server-side, which is right for a count.
     */
    format: ReportFormat;
    series: ReportChartPoint[];
}

/** What every report answers with — and the only shape either screen renders. */
export interface ReportResult {
    columns: ReportColumn[];
    rows: ReportRow[];
    /** `null` = no footer. */
    totals: ReportTotals | null;
    /** 0..3. The cap is `ReportResult`'s constructor; this side never has to remember it. */
    charts: ReportChart[];
    /** Caveats printed under the table, in the server's words. */
    notes: string[];
    /** The sentence shown when there are no rows. */
    empty: string;
}

/* ------------------------------------------------------------------- §2 what is asked */

/** The validated filter values, echoed back so a chip can show what it is set to. */
export interface ReportFilterValues {
    from: string | null;
    to: string | null;
    employee: number | null;
    project: number | null;
    client: number | null;
}

/** One pickable id. Shaped like `TaskNamedRef`, because it is the same kind of thing. */
export interface ReportOption {
    id: number;
    name: string;
}

/**
 * The option lists for the chips this report offers.
 *
 * Each list is the scoped set the viewer may narrow by — the server's, never filtered here.
 * A report whose `filters` omits `client` gets no `clients` list, and the chip is not offered.
 */
export interface ReportFilterOptions {
    employees?: ReportOption[];
    projects?: ReportOption[];
    clients?: ReportOption[];
}

/* -------------------------------------------------------------------- the formatters */

/**
 * `en-US` and not the browser's locale, so two people reading the same report read the same
 * characters — the same decision `DataTable` and `finance.ts` both record.
 */
const COUNT = new Intl.NumberFormat('en-US');

const SYMBOLS = new Map<string, string>();

/**
 * The currency's symbol, asked of `Intl` **once per code** and then cached.
 *
 * Only the symbol comes from `Intl`. The digits never go near it: `format()` takes a JS number
 * and the whole point of contract §3's money rule is that the decimal string is never turned
 * into one. An unknown ISO code falls back to the code itself, so `settings.currency` set to
 * something exotic prints `XYZ 1,250.00` rather than throwing.
 */
function currencySymbol(currency: string): string {
    const cached = SYMBOLS.get(currency);

    if (cached !== undefined) {
        return cached;
    }

    let symbol = `${currency} `;

    try {
        const parts = new Intl.NumberFormat('en-US', { style: 'currency', currency }).formatToParts(0);

        symbol = parts.find((part) => part.type === 'currency')?.value ?? symbol;
    } catch {
        // An ISO code `Intl` does not know. The fallback above already reads correctly.
    }

    SYMBOLS.set(currency, symbol);

    return symbol;
}

const DECIMAL = /^-?\d+(\.\d+)?$/;

/**
 * **Money, as text.** The one place a report's money becomes something a person reads.
 *
 * The value arrives from PostgreSQL as `numeric(12,2)::text` and stays a string the whole way:
 * the groups are inserted between digits and the two decimals are the server's own, padded but
 * never rounded here. Nothing in this file adds two amounts together — a total is `SUM()` in
 * the query that produced the rows (contract §3).
 *
 * Anything that is not a decimal string comes back unchanged: a report that prints its raw
 * value has at least said what it was given, where `$NaN` would have said nothing.
 */
export function formatReportMoney(value: ReportCellValue, currency: string): string {
    const text = typeof value === 'number' ? String(value) : String(value ?? '').trim();

    if (!DECIMAL.test(text)) {
        return String(value ?? '');
    }

    const negative = text.startsWith('-');
    const [whole = '0', fraction = ''] = text.replace('-', '').split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const cents = `${fraction}00`.slice(0, 2);

    // A hyphen-minus, which is what `Intl` puts in front of a negative amount on every finance
    // screen in this app. A typographic minus here would make the same figure look different
    // depending on which screen it was read on.
    return `${negative ? '-' : ''}${currencySymbol(currency)}${grouped}.${cents}`;
}

/** A count, with its thousands separated. */
export function formatReportNumber(value: ReportCellValue): string {
    const amount = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(amount) ? COUNT.format(amount) : String(value ?? '');
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * `2026-09-03` → `3 Sep 2026`. A re-ordering of the server's own string, never a parse.
 *
 * `new Date('2026-09-03')` is UTC midnight, which prints as the 2nd for anybody west of
 * Greenwich. A report whose dates are off by one in half the world is worse than useless, so
 * the string is split rather than parsed — the same treatment `formatLedgerDate` gives a
 * ledger row.
 */
export function formatReportDate(value: ReportCellValue): string {
    const text = String(value ?? '');
    const [year, month, day] = text.split('-');
    const name = MONTHS[Number(month) - 1];

    if (!name || !day || !year) {
        return text;
    }

    return `${Number(day)} ${name} ${year}`;
}

/** `62` → `62%`. The server sends 0..100; nothing here multiplies by a hundred. */
export function formatReportPercent(value: ReportCellValue): string {
    return `${formatReportNumber(value)}%`;
}

const STATUS_KEYS: readonly string[] = [
    'backlog',
    'todo',
    'progress',
    'review',
    'changes',
    'done',
    'waiting',
    'cancelled',
];

/**
 * The tone in a `status` cell, when it really is one of the eight.
 *
 * A value that is not a `StatusKey` is not forced into a badge: it is printed as the word it
 * is. A badge built from an unknown key would be an untinted pill with `undefined` in it, and
 * a wrong word is worse than a plain one.
 */
export function reportStatusKey(value: ReportCellValue): StatusKey | null {
    const text = String(value ?? '');

    return STATUS_KEYS.includes(text) ? (text as StatusKey) : null;
}

/**
 * A cell as text — every format except `status`, which is a badge and not a string.
 *
 * Used by the cells, by the totals footer and by anything that needs the same value in a
 * sentence, so a figure reads identically wherever it appears on the page.
 */
export function formatReportValue(value: ReportCellValue, format: ReportFormat, currency: string): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    switch (format) {
        case 'money':
            return formatReportMoney(value, currency);
        case 'number':
            return formatReportNumber(value);
        case 'minutes':
            return formatMinutes(typeof value === 'number' ? value : Number(value));
        case 'date':
            return formatReportDate(value);
        case 'percent':
            return formatReportPercent(value);
        case 'status':
            return String(value);
        default:
            return String(value);
    }
}

/**
 * How a chart writes its own figures — one formatter for its axis, its tooltip, its legend and
 * its screen-reader table, so all four say the same thing.
 *
 * It is `formatReportValue` with the chart's format bound, which is the point: a chart and the
 * table under it cannot render the same quantity two different ways, because there is one
 * function and they both call it.
 */
export function reportChartFormatter(
    format: ReportFormat,
    currency: string,
): (value: number) => string {
    return (value: number): string => formatReportValue(value, format, currency);
}

/**
 * A mark's **length**, for a chart's geometry only.
 *
 * Charts measure lengths and a length is a number; every figure a person reads is still the
 * server's own string, rendered by the formatters above. Nothing here adds two of these
 * together — the same rule, and the same reason, as `moneyValue()` on the finance report.
 */
export function reportChartValue(value: number | string): number {
    const amount = typeof value === 'number' ? value : Number.parseFloat(value);

    return Number.isFinite(amount) ? amount : 0;
}

/* ------------------------------------------------------- the two screens' page props */

/** One band of the catalogue — `ReportGroup` with the reports the viewer may read in it. */
export interface ReportGroupSection {
    key: ReportGroupKey;
    label: string;
    reports: ReportSummary[];
}

/**
 * `GET /admin/reports` — the catalogue.
 *
 * Grouped by the **server**, which is also what decides the order and the three words over the
 * bands (`ReportGroup::inDisplayOrder()`, `ReportGroup::label()`). A band the viewer has no
 * report in is absent rather than empty — an empty heading is a list of what somebody else can
 * see (Part C §1).
 */
export interface ReportIndexPayload {
    groups: ReportGroupSection[];
}

/**
 * `GET /admin/reports/{report}` — one report, whichever it is.
 *
 * `report` is `ReportKey::toCard()`, `result` is contract §3 verbatim, `filters` is what
 * `ReportRequest` validated and `options` is what the chips may be set to.
 *
 * **`currency` is optional because the controller does not send it yet.** Every `money` cell
 * needs `settings.currency` — contract §3 says so in as many words — and there is no shared
 * Inertia prop carrying it, which is why every finance screen is handed the key explicitly.
 * Until this one is, the screen falls back to `FINANCE_DEFAULT_CURRENCY`, which is the value
 * `SettingsSeeder` writes: right on the seeded install, wrong the day somebody changes it.
 * Raised with the slice; it is one line in `ReportController`.
 */
export interface ReportShowPayload {
    report: ReportSummary;
    result: ReportResult;
    filters: ReportFilterValues;
    options: ReportFilterOptions;
    currency?: string;
}

/** One of the Employee screen's counts. The same shape as `MyTaskBucket`, deliberately. */
export interface ReportBucket {
    key: string;
    label: string;
    count: number;
    /** Every count leads somewhere, carrying the filters it was counted with. */
    href: string;
}

/**
 * `GET /employee/reports` — self-scoped, and a different screen from the Admin one.
 *
 * **Two sections are absent rather than zero**, and the absence is the statement (contract §5,
 * Part C §1):
 *
 * - `time` is sent only for somebody the timer tracks (`TimeEntryPolicy::viewAny`),
 * - `clocked` only for somebody the office clock tracks (`AttendanceService::clocks()`).
 *
 * An office employee's `0h 0m tracked` would be a fact about their tracking mode dressed up as
 * a fact about their work, so the screen renders nothing at all for a key it was not sent —
 * no empty card, and no `v-else`. It never asks what mode anybody is in.
 */
export interface EmployeeReportsPayload {
    /** `?period=week|month`, echoed back. */
    period: 'week' | 'month';
    /** The two the server offers, in its order. */
    periods: ('week' | 'month')[];
    /** The window, and the server's own words for it — "21 Sep – 27 Sep 2026". */
    range: { from: string; to: string; label: string };
    buckets: ReportBucket[];
    /** Counts over the window. Not linked: see the note on the screen. */
    summary: { completed: number; due: number; overdue: number };
    /** Timer roles only. */
    time?: { tracked_minutes: number; pending_minutes: number; entries: number; href: string };
    /** Office-clock roles only. */
    clocked?: { minutes: number; days: number };
}
