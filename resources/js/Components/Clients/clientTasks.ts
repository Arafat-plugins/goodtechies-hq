/**
 * Polish 049: a client's (or a project's) tasks in three counts, as ClientTaskCounts sends them.
 * To do = backlog + to do; In progress = in progress + in review + changes requested + waiting;
 * Complete = completed. `total` is the three added up.
 */
export interface TaskCounts {
    todo: number;
    progress: number;
    complete: number;
    total: number;
}

export const NO_TASKS: TaskCounts = { todo: 0, progress: 0, complete: 0, total: 0 };

/** Two initials for an avatar: "Brian Lataille" → "BL", "IBC" → "IB". */
export function initials(name: string): string {
    const words = name.trim().split(/\s+/u).filter(Boolean);

    if (words.length === 0) {
        return '?';
    }

    if (words.length === 1) {
        return words[0].slice(0, 2).toUpperCase();
    }

    return (words[0][0] + words[words.length - 1][0]).toUpperCase();
}
