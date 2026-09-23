import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The tag-management payload, the four endpoints that write it, and the two sentences the
 * panel has to get right.
 *
 * Everything here describes `TagResource` and the `ManagesTags` trait, which are the same on
 * both shells: `admin/tags` and `employee/tags` are an identical four routes, because the
 * people the plan lets manage tags are an Admin and a Manager and those two live on different
 * surfaces. The only difference is the prefix — so `tagRoutes()` takes a base and nothing in
 * this module knows which surface it is on.
 *
 * ASSIGNING a tag is not here and never will be: that is `tag_ids` on the task update, it is
 * `TaskFieldsPanel`'s, and it takes the ability an edit of the task takes.
 */

/** A project as a management row names one: an id and a name, never a `ProjectResource`. */
export interface TagProjectRef {
    id: number;
    name: string;
}

/**
 * One tag, exactly as `TagResource` sends it.
 *
 * `colour` is typed as a `StatusKey` rather than a `string` because it cannot be anything
 * else. The column is NOT NULL under `tags_colour_is_a_status_token`, whose value list is
 * generated from `App\Support\TagColour`, and tests/Unit/TagColourTest.php fails the day that
 * enum and the `StatusKey` union stop being the same eight names. A defensive fallback here
 * would be a second opinion about a value PostgreSQL will not let through.
 */
export interface ManagedTag {
    id: number;
    name: string;
    colour: StatusKey;
    /** What the colour is CALLED — "Amber", not "In review". A tag coloured `review` is amber. */
    colour_label: string;
    is_global: boolean;
    /** Null for a global tag. */
    project: TagProjectRef | null;
    /**
     * How many tasks lose this label if it goes. `TagService::delete()` takes it off every one
     * of them, and none of those tasks are on the screen of the person clicking — so this is
     * the blast radius, and it belongs in the question rather than in the flash afterwards.
     */
    task_count: number;
    /**
     * `TagPolicy`'s answer for THIS person, per row. The panel renders a control only where
     * this says so, and derives nothing from a role or an id: the endpoint asks the same
     * policy, which is where it is actually enforced.
     */
    permissions: {
        can_update: boolean;
        can_delete: boolean;
    };
}

/**
 * One entry of the colour picker.
 *
 * It arrives from the server for the same reason the CHECK constraint is generated from the
 * enum rather than typed out: a hand-written array of the eight tones in Vue would be a third
 * copy of a list only `TagColour` is allowed to define, and the copy is the one that drifts.
 */
export interface TagColourOption {
    value: StatusKey;
    label: string;
}

export interface TagIndexPayload {
    tags: ManagedTag[];
    colours: TagColourOption[];
}

/** Every tag endpoint for one surface, spelled once. `base` is `/admin/tags` or `/employee/tags`. */
export function tagRoutes(base: string) {
    return {
        index: base,
        store: base,
        update: (id: number) => `${base}/${id}`,
        destroy: (id: number) => `${base}/${id}`,
    };
}

export type TagIndexResult =
    | { ok: true; payload: TagIndexPayload }
    | { ok: false; reason: 'forbidden' | 'failed' };

/**
 * Read the list.
 *
 * A plain `fetch`, because the index answers JSON and not an Inertia page — the tag list is
 * not a screen, it is this panel's data, and `ManagesTags` says so in as many words. It is the
 * same shape of call `TaskDetailDrawer` makes, minus the Inertia headers this endpoint would
 * have no use for.
 *
 * A 403 is kept apart from every other failure because it is the one answer with a sentence
 * worth printing: it means `TagPolicy::viewAny` refused this person, not that the request
 * broke. Everything else — offline, 500, a body that is not JSON — reads the same to whoever
 * is looking at it, so it is one state with a retry.
 */
export async function loadTags(base: string): Promise<TagIndexResult> {
    try {
        const response = await fetch(tagRoutes(base).index, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return { ok: false, reason: response.status === 403 ? 'forbidden' : 'failed' };
        }

        return { ok: true, payload: (await response.json()) as TagIndexPayload };
    } catch {
        return { ok: false, reason: 'failed' };
    }
}

export interface TagGroup {
    key: string;
    label: string;
    description: string;
    tags: ManagedTag[];
}

/**
 * The list, split by scope.
 *
 * A global tag and a project tag are not one object wearing a different flag. A global label
 * belongs to everybody and a scoped one names one client's work, they can carry the same word
 * without colliding (the unique indexes are per scope), and deleting the wrong one takes a
 * label off a different set of tasks. A row that says which only when you hover is a row that
 * gets deleted by mistake, so the split is a heading.
 *
 * The server already ordered the rows by name, and that order is kept inside each group.
 */
export function groupTags(tags: ManagedTag[]): TagGroup[] {
    const groups: TagGroup[] = [];
    const globals = tags.filter((tag) => tag.is_global);

    if (globals.length > 0) {
        groups.push({
            key: 'global',
            label: 'Global',
            description: 'Usable on every project.',
            tags: globals,
        });
    }

    const scoped = new Map<number, TagGroup>();

    for (const tag of tags) {
        if (tag.is_global || tag.project === null) {
            continue;
        }

        const existing = scoped.get(tag.project.id);

        if (existing) {
            existing.tags.push(tag);

            continue;
        }

        scoped.set(tag.project.id, {
            key: `project-${tag.project.id}`,
            label: tag.project.name,
            description: 'Only on this project.',
            tags: [tag],
        });
    }

    return [
        ...groups,
        ...[...scoped.values()].sort((a, b) => a.label.localeCompare(b.label)),
    ];
}

/** The row's usage line. It is printed before the delete is offered, not after it. */
export function tagUsage(tag: ManagedTag): string {
    if (tag.task_count === 0) {
        return 'Not on any task';
    }

    return tag.task_count === 1 ? 'On 1 task' : `On ${tag.task_count} tasks`;
}

/**
 * The question the confirm step asks.
 *
 * It states the count inside the sentence rather than under it, because the tasks that lose
 * the label are somebody else's and are not on this screen. `task_count` is what makes the
 * question honest, and it is the whole reason `TagResource` carries it.
 */
export function tagDeletePrompt(tag: ManagedTag): string {
    if (tag.task_count === 0) {
        return `Delete “${tag.name}”? It is not on any task.`;
    }

    const tasks = tag.task_count === 1 ? '1 task' : `${tag.task_count} tasks`;

    return `Remove “${tag.name}” from ${tasks} and delete it?`;
}
