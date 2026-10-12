/**
 * Polish 046: the dashboards' colour. Every dashboard card stays white; its icon sits in a
 * circle tinted with one of DESIGN.md's eight status colours, and a thin bar in that colour runs
 * along the card's foot. The colour is decoration on top of the words — the label, the number
 * and the sub-line still say everything (DESIGN.md §5.6).
 *
 * The chip reuses StatusBadge's own triplet, so there is one mapping from a status to its
 * classes. The bar paints the status's text-safe step (`-fg`) as a fill, which is the one class
 * per status written out below so Tailwind keeps it in the build.
 */
import type { StatusKey } from '@/Components/StatusBadge.vue';
import { statusToneClass } from '@/Components/StatusBadge.vue';

export type Tone = StatusKey;

const TONE_BAR: Record<Tone, string> = {
    backlog: 'bg-status-backlog-fg/70',
    todo: 'bg-status-todo-fg/70',
    progress: 'bg-status-progress-fg/70',
    review: 'bg-status-review-fg/70',
    changes: 'bg-status-changes-fg/70',
    done: 'bg-status-done-fg/70',
    waiting: 'bg-status-waiting-fg/70',
    cancelled: 'bg-status-cancelled-fg/70',
};

/** The icon circle: the status's tinted fill, its text colour, and its border. */
export function toneChipClass(tone: Tone): string {
    return statusToneClass(tone);
}

/** The thin bar along a card's foot. */
export function toneBarClass(tone: Tone): string {
    return TONE_BAR[tone];
}

/**
 * Task buckets and the Company dashboard's work cards, by their server key. The colour follows
 * the meaning where there is one (Overdue red, Completed green, In progress blue) and the
 * reference picture where there is not.
 */
const WORK_TONE: Record<string, Tone> = {
    open: 'changes',
    due_today: 'backlog',
    overdue: 'cancelled',
    in_progress: 'review',
    waiting: 'waiting',
    in_review: 'review',
    completed: 'progress',
    completed_today: 'done',
    active_projects: 'progress',
};

export function workTone(key: string): Tone {
    return WORK_TONE[key] ?? 'todo';
}

/** "This month" — the finance cards on the Company and Accountant dashboards. */
const FINANCE_TONE: Record<string, Tone> = {
    income: 'done',
    expense: 'cancelled',
    payroll: 'progress',
    operating_result: 'review',
};

export function financeTone(key: string): Tone {
    return FINANCE_TONE[key] ?? 'todo';
}

/** Holiday date tiles take turns through these, so a list of three reads as three dates. */
const LIST_TONES: Tone[] = ['backlog', 'cancelled', 'review', 'progress', 'done', 'changes'];

export function listTone(index: number): Tone {
    return LIST_TONES[index % LIST_TONES.length];
}
