<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleAlert, FolderKanban, ListTodo, Users } from '@lucide/vue';
import { computed } from 'vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import type { WorkloadEmployee, WorkloadProject, WorkloadTotals } from '@/Components/Workload/workload';
import { estimateNote, formatDuration, formatMinutes } from '@/Components/Workload/workload';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Admin → Workforce → Workload: who is carrying what, in counts.
 *
 * An Admin opens it when deciding who can take the next job. So it answers four questions and
 * stops: how many open tasks each person has, how many of those are overdue, what those tasks
 * were estimated at against what has actually been tracked on them, and which projects are
 * holding the most pending work.
 *
 * ## Why nothing on this page is sortable, and nobody is ranked
 *
 * Part H forbids productivity scoring. People are listed **by name** and the columns carry no
 * `sortable` flag, so there is no way to re-order the table into a league table — and none to
 * offer, because the controller reads no `sort` parameter and a control the server ignores is a
 * lie (DESIGN.md §5.11).
 *
 * **Estimated and tracked are two columns, never one number.** They are not divided,
 * subtracted, tinted or arrowed. An estimate that has not been met may mean the work was
 * harder, the estimate was optimistic, or the job changed, and this screen does not get to pick
 * one. The count of tasks with no estimate at all travels in the Estimated cell, because a sum
 * over a half-estimated set is an honest number that misleads on its own.
 *
 * Projects ARE ordered by how much is pending — the plan asks for "projects with most pending
 * work" — and that is a different thing: a project is not a person, and no row here attributes
 * a project's backlog to anybody.
 *
 * ## Every number is a link to the list it describes
 *
 * Each count links to `/admin/tasks` under the very filter it was counted with, so a figure
 * that looks wrong can be opened and read. The count and the destination are the same bucket
 * (decision 2-37), which is what stops a card reading "4" over a list of five.
 */

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    as_of: { value: string; label: string };
    employees: WorkloadEmployee[];
    projects: WorkloadProject[];
    totals: WorkloadTotals;
}>();

/** The Tasks list, under the exact filter a number was counted with. */
function tasksHref(params: Record<string, string | number>): string {
    return `/admin/tasks?${new URLSearchParams(
        Object.fromEntries(Object.entries(params).map(([key, value]) => [key, String(value)])),
    ).toString()}`;
}

/**
 * No column is `sortable`: see the note above. The two number columns are `number` cells, which
 * is what gives them `tabular-nums` and right alignment.
 */
const employeeColumns = computed<ColumnDef<WorkloadEmployee>[]>(() => [
    { key: 'name', header: 'Employee' },
    { key: 'open_count', header: 'Open tasks', cell: 'number' },
    { key: 'overdue_count', header: 'Overdue', cell: 'number' },
    { key: 'estimated_minutes', header: 'Estimated', cell: 'number' },
    { key: 'tracked_seconds', header: 'Tracked', cell: 'number' },
]);

const projectColumns = computed<ColumnDef<WorkloadProject>[]>(() => [
    { key: 'name', header: 'Project' },
    { key: 'client', header: 'Client' },
    { key: 'open_count', header: 'Pending tasks', cell: 'number' },
    { key: 'overdue_count', header: 'Overdue', cell: 'number' },
]);
</script>

<template>
    <Head title="Workload" />

    <PageShell
        title="Workload"
        :description="`Who is carrying what, as of ${props.as_of.label}. Counts only — nothing here rates anybody.`"
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Workload' }]"
    >
        <div class="flex min-w-0 flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard
                    label="Open tasks"
                    :value="totals.open_count"
                    sub="Everything still owed across the agency"
                    :icon="ListTodo"
                    :href="tasksHref({ bucket: 'open' })"
                />
                <StatCard
                    label="Overdue"
                    :value="totals.overdue_count"
                    sub="Past its due date and still owed"
                    :icon="CircleAlert"
                    :href="tasksHref({ bucket: 'overdue' })"
                />
                <StatCard
                    label="People"
                    :value="totals.employee_count"
                    sub="Employees whose work you can see"
                    :icon="Users"
                />
            </div>

            <section class="flex min-w-0 flex-col gap-2">
                <h2 class="text-sm font-medium">Per employee</h2>
                <p class="text-xs text-muted-foreground">
                    In alphabetical order. Estimated and tracked are two separate figures about the same tasks — the
                    estimate is a field on the task, so the hours beside it are everybody's hours on that task, not
                    only this person's.
                </p>

                <DataTable
                    id="admin-workload-employees"
                    :columns="employeeColumns"
                    :rows="employees"
                    :row-label="(row) => row.name"
                    noun="employee"
                    :empty-icon="Users"
                    empty-title="Nobody is tracked yet"
                    empty-description="Employees appear here once their tracking mode is the office clock or the remote timer."
                >
                    <template #cell-name="{ row }">
                        <div class="flex min-w-0 flex-col">
                            <span class="font-medium break-words">{{ row.name }}</span>
                            <span v-if="row.role" class="text-xs text-muted-foreground">{{ row.role }}</span>
                        </div>
                    </template>

                    <template #cell-open_count="{ row }">
                        <Link
                            :href="tasksHref({ assignee_id: row.id, bucket: 'open' })"
                            class="tabular-nums underline-offset-4 hover:underline"
                            :aria-label="`${row.open_count} open tasks assigned to ${row.name}`"
                        >
                            {{ row.open_count }}
                        </Link>
                    </template>

                    <template #cell-overdue_count="{ row }">
                        <Link
                            :href="tasksHref({ assignee_id: row.id, bucket: 'overdue' })"
                            class="tabular-nums underline-offset-4 hover:underline"
                            :aria-label="`${row.overdue_count} overdue tasks assigned to ${row.name}`"
                        >
                            {{ row.overdue_count }}
                        </Link>
                    </template>

                    <template #cell-estimated_minutes="{ row }">
                        <span class="tabular-nums">{{ formatMinutes(row.estimated_minutes) }}</span>
                        <span v-if="estimateNote(row)" class="block text-xs leading-tight text-muted-foreground">
                            {{ estimateNote(row) }}
                        </span>
                    </template>

                    <template #cell-tracked_seconds="{ row }">
                        <span class="tabular-nums">{{ formatDuration(row.tracked_seconds) }}</span>
                    </template>
                </DataTable>
            </section>

            <section class="flex min-w-0 flex-col gap-2">
                <h2 class="text-sm font-medium">Projects with the most pending work</h2>
                <p class="text-xs text-muted-foreground">
                    Open tasks per project, most first. Projects with nothing pending are not listed.
                </p>

                <DataTable
                    id="admin-workload-projects"
                    :columns="projectColumns"
                    :rows="projects"
                    :row-label="(row) => row.name"
                    noun="project"
                    :empty-icon="FolderKanban"
                    empty-title="Nothing is pending"
                    empty-description="No project you can see is holding an open task right now."
                >
                    <template #cell-name="{ row }">
                        <Link :href="`/admin/projects/${row.id}`" class="font-medium break-words hover:underline">
                            {{ row.name }}
                        </Link>
                    </template>

                    <template #cell-open_count="{ row }">
                        <Link
                            :href="tasksHref({ project_id: row.id, bucket: 'open' })"
                            class="tabular-nums underline-offset-4 hover:underline"
                            :aria-label="`${row.open_count} open tasks on ${row.name}`"
                        >
                            {{ row.open_count }}
                        </Link>
                    </template>

                    <template #cell-overdue_count="{ row }">
                        <Link
                            :href="tasksHref({ project_id: row.id, bucket: 'overdue' })"
                            class="tabular-nums underline-offset-4 hover:underline"
                            :aria-label="`${row.overdue_count} overdue tasks on ${row.name}`"
                        >
                            {{ row.overdue_count }}
                        </Link>
                    </template>
                </DataTable>
            </section>

            <p class="text-xs text-muted-foreground">
                Every figure links to the Tasks list under the same filter it was counted with, so a number that looks
                wrong can be opened and read. Tracked hours are the approved ones — time added by hand and not yet
                signed off is on Workforce → Time, and is not in these figures.
            </p>
        </div>
    </PageShell>
</template>
