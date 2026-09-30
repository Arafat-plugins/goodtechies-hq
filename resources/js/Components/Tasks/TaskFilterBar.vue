<script lang="ts">
import type { TaskFilters } from '@/Components/Tasks/TaskList.vue';

/**
 * The toolbar every Tasks view wears — List, Board, Calendar and Gantt: `#leading` (the page's
 * view switcher) and the scope dropdown on the left; Manage tags, Add filter and `#trailing`
 * (the page's New task) on the right. Show archived and Show subtasks are yes/no entries in
 * *Add filter* (FilterBar's `toggle` kind) and removable chips once set, like every other filter.
 * Due today and Overdue are the scope dropdown's alone (brief 017). There is no search box: the
 * top bar's Ctrl K is the search, and the server still reads `search` from a URL.
 *
 * It exists because there are now three screens reading one `TaskService::filters()`, and a
 * second copy of the filter definitions is a copy that drifts (DESIGN.md §5.8). The bar owns
 * the query string, which is also what makes a view switch keep its filters: the switcher
 * carries the same parameters to the next route and the controller sends them straight back.
 */

/**
 * Is any filter set?
 *
 * The window a Calendar is looking at is deliberately NOT counted. `date_from` / `date_to` are
 * filters to `TaskService`, but on the Calendar they are the month somebody navigated to, and
 * a grid that called its own month "a filter" would offer *Clear filters* for arriving at
 * September.
 */
export function taskFiltersActive(filters: TaskFilters): boolean {
    return (
        Boolean(filters.search) ||
        filters.status !== null ||
        filters.priority !== null ||
        filters.project_id !== null ||
        filters.tag_id !== null ||
        filters.assignee_id !== null ||
        // A bucket arrived from a dashboard card. It counts: a list narrowed to four overdue
        // tasks must say it was narrowed, or the empty case reads as "there is no work" rather
        // than "nothing is late", and there is no way back to the whole list.
        filters.bucket !== null ||
        // A scope narrows too: an empty "Overdue" must offer the way back to All tasks.
        filters.scope !== null ||
        filters.overdue ||
        filters.archived ||
        // "Show subtasks" widens rather than narrows, but it is still a setting *Clear filters*
        // must undo. Under a personal scope the server turns it on by itself, which is not a
        // chip anybody set.
        (Boolean(filters.subtasks) && !filters.mine)
    );
}
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Tags } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { FilterDef } from '@/Components/FilterBar.vue';
import FilterBar from '@/Components/FilterBar.vue';
import TagManagerDialog from '@/Components/Tags/TagManagerDialog.vue';
import type { TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { pushQuery, resetQuery } from '@/lib/tableState';

const props = withDefaults(
    defineProps<{
        filters: TaskFilters;
        statuses: TaskOption[];
        /**
         * The bucket chip's options, labelled by `TaskBucket::label()`.
         *
         * Unset, no bucket chip is offered — and because `FilterBar` draws a chip only for a
         * filter that has a value, offering it costs the three Tasks views nothing visually
         * until somebody arrives from a dashboard card or picks one from *Add filter*.
         */
        buckets?: TaskOption[];
        priorities: TaskOption[];
        projects: TaskNamedRef[];
        tags: TaskTag[];
        /**
         * Whether to offer the tag manager beside the chips. Resolved by the server from
         * `TagPolicy::create` (`canManageTags` on every Tasks payload) — never inferred here
         * from a role, which would be a second copy of the policy. Defaults to false, so a
         * screen that forgets to pass it hides a door rather than showing a refused one.
         */
        canManageTags?: boolean;
        /**
         * The assignee chip's options — the Admin surface only. The employee views are
         * already scoped to one person, so the filter would have exactly one value.
         */
        employees?: TaskNamedRef[];
        placeholder: string;
        /** Unique per screen: two bars on one page must not share a `for`. */
        idPrefix: string;
        /**
         * Query parameters the *empty state's* Clear filters leaves alone — the List keeps
         * `group_by`, which is a way of reading the list rather than a filter on it.
         *
         * The bar's own *Clear all* is `FilterBar`'s and keeps nothing; that is its existing
         * behaviour on the List and is not restated here, because two components both
         * answering "what does clear mean" is the drift this component exists to avoid.
         */
        clearKeeps?: string[];
    }>(),
    { clearKeeps: () => [], canManageTags: false },
);

/** Bucket values the scope dropdown already offers, so *Add filter* does not repeat them. */
const RETIRED_BUCKETS = ['due_today', 'overdue'];

const filterDefs = computed<FilterDef[]>(() => {
    const defs: FilterDef[] = [
        { key: 'status', label: 'Status', kind: 'select', options: props.statuses },
        { key: 'priority', label: 'Priority', kind: 'select', options: props.priorities },
        {
            key: 'project_id',
            label: 'Project',
            kind: 'select',
            options: props.projects.map((project) => ({ value: String(project.id), label: project.name })),
            searchPlaceholder: 'Search projects…',
        },
        {
            key: 'tag_id',
            label: 'Tag',
            kind: 'select',
            options: props.tags.map((tag) => ({ value: String(tag.id), label: tag.name })),
            searchPlaceholder: 'Search tags…',
        },
    ];

    if (props.employees?.length) {
        defs.splice(2, 0, {
            key: 'assignee_id',
            label: 'Assignee',
            kind: 'select',
            options: props.employees.map((employee) => ({
                value: String(employee.id),
                label: employee.name,
            })),
            searchPlaceholder: 'Search people…',
        });
    }

    /*
     * Last, and after the splice above, so adding it cannot move the assignee chip: it is the
     * broadest of the filters and the one a reader arrives with rather than reaches for.
     */
    if (props.buckets?.length) {
        defs.push({
            key: 'bucket',
            label: 'Bucket',
            kind: 'select',
            // Due today / Overdue live in the scope dropdown only (brief 017). Hidden rather than
            // dropped, so an old `?bucket=overdue` link still gets a labelled, removable chip.
            options: props.buckets.map((option) =>
                RETIRED_BUCKETS.includes(option.value) ? { ...option, hidden: true } : option,
            ),
        });
    }

    /*
     * The yes/no filters close the list. `overdue=1` is no longer offered — the scope dropdown's
     * Overdue says it — but the server still reads it, so a link carrying it keeps its chip.
     */
    defs.push(
        { key: 'overdue', label: 'Overdue only', kind: 'toggle', hidden: true },
        { key: 'archived', label: 'Show archived', kind: 'toggle' },
        // Flow F2 (decision 12-71): subtasks are off a general view unless this is on.
        { key: 'subtasks', label: 'Show subtasks', kind: 'toggle' },
    );

    return defs;
});

/* ------------------------------------------------------------------ scope */

/**
 * The scope dropdown, `?scope=`. What each one means is `TaskService::SCOPES`, mapped on the
 * server onto the `mine` and `bucket` filters; this side only writes the key. "All tasks" is
 * the absence of the parameter.
 */
const SCOPES: { value: string; label: string }[] = [
    { value: 'all', label: 'All tasks' },
    { value: 'mine', label: 'My Tasks' },
    { value: 'due-today', label: 'Due Today' },
    { value: 'overdue', label: 'Overdue' },
];

/**
 * The bucket chip reads what the server APPLIED (`filters.bucket`), never the raw `?bucket=`.
 * Under a date scope the applied bucket is the scope's own, which the dropdown already says —
 * so no chip then, rather than a duplicate "Due today" or a stale `?bucket=` the server ignored.
 */
const appliedFilters = computed<Record<string, string | null>>(() => ({
    bucket: props.filters.scope === 'due-today' || props.filters.scope === 'overdue' ? null : props.filters.bucket,
    // What the server applied, so a stray `?overdue=0` never draws a chip.
    overdue: props.filters.overdue ? '1' : null,
    archived: props.filters.archived ? '1' : null,
    // Only the one somebody set: a personal scope includes subtasks on the server's own say-so.
    subtasks: props.filters.subtasks && !props.filters.mine ? '1' : null,
}));

const scopeValue = computed(() => SCOPES.find((option) => option.value === props.filters.scope)?.value ?? 'all');

/**
 * A date scope brings its own bucket and the server lets it win over `?bucket=`, so picking one
 * drops a bucket chip that would otherwise sit there naming a narrowing that is not applied.
 */
function chooseScope(value: unknown): void {
    const scope = typeof value === 'string' && value !== 'all' ? value : null;
    const dated = scope === 'due-today' || scope === 'overdue';

    pushQuery(dated ? { scope, bucket: null } : { scope });
}

function clearFilters(): void {
    resetQuery(props.clearKeeps);
}

defineExpose({ clearFilters });

/* ------------------------------------------------------------- managing tags */

/**
 * Where the tag manager hangs.
 *
 * Here, and not on each of the three Tasks pages, because all three already wear this bar:
 * one affordance beside the Tag chip is the same affordance on the List, the Board and the
 * Calendar, and it is where somebody is when they notice a label is missing. The chip itself
 * is untouched — this sits next to it.
 */
const tagManagerOpen = ref(false);

const page = usePage();

/**
 * Which shell's tag routes to write to.
 *
 * The four endpoints exist on both, because an Admin manages tags from the Admin shell and a
 * Manager from the Employee one. `EnsureSurface` lets each role reach only its own shell, so
 * the surface on the shared auth prop is the surface whose routes this person is on — there
 * is no case where the page and the user disagree.
 */
const tagBase = computed(() => `/${page.props.auth.user?.surface ?? 'admin'}/tags`);
</script>

<template>
    <FilterBar
        :search="filters.search"
        :filters="filterDefs"
        :placeholder="placeholder"
        :input-id="`${idPrefix}-search`"
        :searchable="false"
        :applied="appliedFilters"
        layout="toolbar"
    >
        <template #leading>
            <slot name="leading" />

            <Select :model-value="scopeValue" @update:model-value="chooseScope">
                <SelectTrigger :id="`${idPrefix}-scope`" aria-label="Task scope" class="w-36">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="option in SCOPES" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </template>

        <template #extra>
            <template v-if="canManageTags">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-8"
                    @click="tagManagerOpen = true"
                >
                    <Tags aria-hidden="true" />
                    Manage tags
                </Button>

                <!--
                    Inside the slot rather than beside the bar so this component keeps one root
                    element; the dialog renders into a portal at the end of the body either way.
                -->
                <TagManagerDialog
                    v-model:open="tagManagerOpen"
                    :base="tagBase"
                    :projects="projects"
                />
            </template>
        </template>

        <template #trailing>
            <slot name="trailing" />
        </template>
    </FilterBar>
</template>
