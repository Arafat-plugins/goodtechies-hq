/**
 * The column contract every list screen writes against.
 *
 * A column def is data, not markup: `DataTable` renders it as a table row at `md` and up
 * and as a stacked card below `md` from the same object, so a screen describes its columns
 * once. A `#cell-<key>` slot overrides the rendering of any one column.
 */

import type { StatusKey } from '@/Components/StatusBadge.vue';

/** How a cell renders when no `#cell-<key>` slot is given. */
export type CellType = 'text' | 'badge' | 'avatar' | 'date' | 'currency' | 'number' | 'actions';

export type ColumnAlign = 'left' | 'center' | 'right';

/** Row height. Persisted per table id under `hq.table.<id>.density`. */
export type Density = 'comfortable' | 'compact';

export type SortDirection = 'asc' | 'desc';

/** The `?sort=&dir=` pair, as the query string carries it. */
export interface SortState {
    key: string;
    dir: SortDirection;
}

/**
 * One collapsible band of rows in a grouped table.
 *
 * The shape is the server's: a list endpoint that groups its result sends
 * `{key, label, tone, count, tasks}` per group and the screen maps `tasks` onto `rows`.
 * Everything here is decided server-side, including the badge tone — a screen that
 * re-derived a tone from a status would be a second copy of a mapping that already exists
 * in PHP, and the two would drift.
 */
export interface TableGroup<T = Record<string, unknown>> {
    /** Unique within one grouping variant. Doubles as the collapsed-state storage key. */
    key: string;
    label: string;
    /**
     * A `StatusBadge` tone, when the grouping is by something that has one. Given, the
     * header prints the label inside a badge instead of as plain text; `null` for a
     * grouping (assignee, project, priority) that carries no status colour.
     */
    tone?: StatusKey | null;
    /**
     * The server's count for this group. Defaults to `rows.length`, and is a separate key
     * because a grouping may legitimately place one row in two groups — the counts then
     * sum to more than the table's total, which is correct and not a bug.
     */
    count?: number;
    rows: T[];
}

export interface ColumnDef<T = Record<string, unknown>> {
    /** Unique per table. Doubles as the `?sort=` value and the `#cell-<key>` slot name. */
    key: string;
    header: string;
    /** Default renderer. Omitted means `text`. */
    cell?: CellType;
    /**
     * Sorting is server-driven: a sortable header pushes `?sort=<key>&dir=`. Leave it off
     * for a column the controller cannot order by, so no ignored parameter is ever sent.
     */
    sortable?: boolean;
    align?: ColumnAlign;
    /** A width utility class (`w-48`, `w-1/4`) — token classes only, never an arbitrary value. */
    width?: string;
    /** False keeps the column out of the column-visibility menu. Defaults to true. */
    hideable?: boolean;
    /** Starts hidden, until the viewer turns it on (their choice is then persisted). */
    defaultHidden?: boolean;
    /** Pulls the raw value out of a row when it is not `row[key]`. */
    value?: (row: T) => unknown;
    /** Renders the header for screen readers only — the actions column uses it. */
    headerHidden?: boolean;
    /** Keeps the cell on one line at `md` and up. Wide text columns leave it off. */
    nowrap?: boolean;
}

export const ALIGN_TEXT: Record<ColumnAlign, string> = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
};

export const ALIGN_JUSTIFY: Record<ColumnAlign, string> = {
    left: 'justify-start',
    center: 'justify-center',
    right: 'justify-end',
};

/** Numbers, money and dates line up only when every one of them is `tabular-nums`. */
const NUMERIC: CellType[] = ['number', 'currency', 'date'];

export function isNumericCell(cell: CellType | undefined): boolean {
    return cell !== undefined && NUMERIC.includes(cell);
}

/** Money and plain numbers read right-aligned; everything else starts at the left. */
export function alignOf(column: ColumnDef<never>): ColumnAlign {
    if (column.align) {
        return column.align;
    }

    if (column.cell === 'currency' || column.cell === 'number') {
        return 'right';
    }

    return column.cell === 'actions' ? 'right' : 'left';
}
