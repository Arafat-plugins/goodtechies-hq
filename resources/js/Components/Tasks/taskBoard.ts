import type { StatusKey } from '@/Components/StatusBadge.vue';
import type { Task } from '@/Components/Tasks/TaskList.vue';
import type { TaskDetail, TaskTransition } from '@/Components/Tasks/taskDetail';

/**
 * The Board's payload, and the two pieces of arithmetic a drag needs.
 *
 * Nothing here decides what a person may do. `transitions` is the ROLE half of the drag rule,
 * resolved by `TaskService::transitionsFor()`; the per-task half — am I assigned to this, am I
 * this project's reviewer — is `TaskPolicy`'s and is checked on the drop. So a target this
 * module calls allowed can still come back refused, and the Board has to be able to put the
 * card back. What this half buys is a board that does not offer a column nobody in this role
 * could ever drop into.
 */

/**
 * The project fragment on a board card.
 *
 * It is `ProjectResource`'s output, which decides its own fields per requester: an employee's
 * carries `domain` and no client and no finance. The card prints the domain on the employee
 * surface for exactly that reason — it is what that payload has and what that reader knows a
 * project by.
 */
export interface BoardProject {
    id: number;
    name: string;
    domain?: string | null;
}

/**
 * One card.
 *
 * `TaskResource` sends a board card whole except for the five keys it puts behind `whenLoaded`
 * or the detail attribute — the checklist, the links, both dependency directions and
 * `available_transitions`. Everything the status dialogs read (`work_summary`,
 * `work_summary_by`, `primary_assignee`, `permissions`) is unconditional and is here.
 *
 * There is deliberately **no `comment_count` and no `attachment_count`**: comments and
 * attachments are slice 4, and a card that printed a zero for them would be inventing a
 * number the server never sent.
 */
export interface BoardCard
    extends Omit<
        TaskDetail,
        'checklist' | 'links' | 'dependencies' | 'dependents' | 'available_transitions' | 'project'
    > {
    project?: BoardProject | null;
}

export interface BoardColumn {
    key: string;
    label: string;
    tone: StatusKey | null;
    count: number;
    tasks: BoardCard[];
}

/**
 * Always eight columns, in lifecycle order, empty ones included — `TaskService::byStatus()`
 * walks `TaskStatus::boardOrder()` rather than the rows it happens to have, because a column
 * that vanishes when it is empty is a column you cannot drag anything into.
 */
export interface BoardPayload {
    columns: BoardColumn[];
    total: number;
    overdue_count: number;
}

/** `{from_status: [allowed_to, …]}` — the role half, and only the role half. */
export type TransitionMap = Record<string, string[]>;

/**
 * The moves this role may make from a status, as `TaskStatusActions` wants them.
 *
 * The label and the tone are read off the board's own columns, never re-derived: the server
 * already resolved both, and a second mapping of a status to a colour in Vue is a second
 * mapping that drifts (DESIGN.md §4.5).
 */
export function movesFor(
    status: string,
    transitions: TransitionMap,
    columns: BoardColumn[],
): TaskTransition[] {
    const byKey = new Map(columns.map((column) => [column.key, column]));

    return (transitions[status] ?? []).flatMap((key) => {
        const column = byKey.get(key);

        return column === undefined ? [] : [{ value: column.key, label: column.label, tone: column.tone ?? 'todo' }];
    });
}

/**
 * A board card as the detail components type a task.
 *
 * The five missing keys are filled with empties rather than cast away: `TaskStatusActions`
 * reads none of them, and a lie in the type is worse than an empty array a panel would render
 * as "nothing here". `available_transitions` is the role half — see the module docblock.
 */
export function asDetail(card: BoardCard, moves: TaskTransition[]): TaskDetail {
    const project: Task['project'] = card.project
        ? { id: card.project.id, name: card.project.name }
        : (card.project ?? null);

    return {
        ...card,
        project,
        checklist: [],
        links: [],
        dependencies: [],
        dependents: [],
        available_transitions: moves,
    };
}

/** A copy the Board may move cards around in without writing to its own props. */
export function cloneColumns(columns: BoardColumn[]): BoardColumn[] {
    return columns.map((column) => ({ ...column, tasks: [...column.tasks] }));
}

/**
 * Move a card between (or inside) columns, and say which card it landed under.
 *
 * `after_id` is computed against the target list **with the dragged card already taken out** —
 * otherwise a card moved two places down inside its own column reports the neighbour it is
 * about to displace, which is off by one. `null` is the top of the column, which is what the
 * server reads as "no predecessor".
 *
 * The anchor is simply the card above the drop, whatever project it belongs to. A lane is a
 * status on both sides now — the server scopes `position` to the status too — so the card you
 * dropped under is always a card it can order against.
 */
export function moveCard(
    columns: BoardColumn[],
    cardId: number,
    from: string,
    to: string,
    index: number,
): { columns: BoardColumn[]; afterId: number | null } | null {
    const next = cloneColumns(columns);
    const source = next.find((column) => column.key === from);
    const target = next.find((column) => column.key === to);

    if (source === undefined || target === undefined) {
        return null;
    }

    const at = source.tasks.findIndex((task) => task.id === cardId);

    if (at === -1) {
        return null;
    }

    const [card] = source.tasks.splice(at, 1);
    source.count = source.tasks.length;

    const bounded = Math.max(0, Math.min(index, target.tasks.length));
    const afterId = bounded > 0 ? (target.tasks[bounded - 1]?.id ?? null) : null;

    target.tasks.splice(bounded, 0, { ...card, status: to });
    target.count = target.tasks.length;

    return { columns: next, afterId };
}

/**
 * Where a card sits now, and where *Move up* / *Move down* would put it.
 *
 * `canUp` / `canDown` are separate from the two ids because `null` already means something
 * here — it is the top of the column, which is exactly where *Move up* from second place
 * lands. A menu that read "no id" as "no move" could never offer the top.
 */
export interface CardNeighbours {
    index: number;
    canUp: boolean;
    canDown: boolean;
    /** The insertion index one place up / down, for the optimistic move. */
    upIndex: number;
    downIndex: number;
}

export function neighbours(column: BoardColumn, cardId: number): CardNeighbours {
    const index = column.tasks.findIndex((task) => task.id === cardId);
    const last = column.tasks.length - 1;

    return {
        index,
        canUp: index > 0,
        canDown: index !== -1 && index < last,
        upIndex: Math.max(index - 1, 0),
        downIndex: Math.min(index + 1, Math.max(last, 0)),
    };
}
