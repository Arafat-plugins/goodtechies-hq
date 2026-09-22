<script lang="ts">
import type { TaskFilters } from '@/Components/Tasks/TaskList.vue';

/**
 * The chip bar every Tasks view wears — List, Board and Calendar.
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
        filters.overdue ||
        filters.archived
    );
}
</script>

<script setup lang="ts">
import { computed } from 'vue';
import type { FilterDef } from '@/Components/FilterBar.vue';
import FilterBar from '@/Components/FilterBar.vue';
import type { TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import { Checkbox } from '@/Components/ui/checkbox';
import { Label } from '@/Components/ui/label';
import { pushQuery, resetQuery } from '@/lib/tableState';

const props = withDefaults(
    defineProps<{
        filters: TaskFilters;
        statuses: TaskOption[];
        priorities: TaskOption[];
        projects: TaskNamedRef[];
        tags: TaskTag[];
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
    { clearKeeps: () => [] },
);

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

    return defs;
});

function clearFilters(): void {
    resetQuery(props.clearKeeps);
}

defineExpose({ clearFilters });
</script>

<template>
    <FilterBar
        :search="filters.search"
        :filters="filterDefs"
        :extra-active="filters.overdue || filters.archived"
        :placeholder="placeholder"
        :input-id="`${idPrefix}-search`"
    >
        <template #extra>
            <div class="flex h-9 items-center gap-2">
                <Checkbox
                    :id="`${idPrefix}-overdue`"
                    :model-value="filters.overdue"
                    @update:model-value="(checked) => pushQuery({ overdue: checked === true })"
                />
                <Label :for="`${idPrefix}-overdue`" class="font-normal whitespace-nowrap">Overdue only</Label>
            </div>
            <div class="flex h-9 items-center gap-2">
                <Checkbox
                    :id="`${idPrefix}-archived`"
                    :model-value="filters.archived"
                    @update:model-value="(checked) => pushQuery({ archived: checked === true })"
                />
                <Label :for="`${idPrefix}-archived`" class="font-normal whitespace-nowrap">Show archived</Label>
            </div>
        </template>
    </FilterBar>
</template>
