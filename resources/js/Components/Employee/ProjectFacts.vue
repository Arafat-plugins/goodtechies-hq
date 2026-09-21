<script setup lang="ts">
import { computed } from 'vue';
import type { EmployeeProject } from '@/Components/Employee/ProjectListCard.vue';
import { formatProjectDate, isProjectOverdue } from '@/Components/Employee/ProjectListCard.vue';
import StatusPill, { toneForProjectStatus } from '@/Components/StatusPill.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

const props = defineProps<{
    project: EmployeeProject;
}>();

/** Domains are stored bare, so the link has to put the scheme back on. */
const domainHref = computed(() => (props.project.domain ? `https://${props.project.domain}` : null));

const overdue = computed(() => isProjectOverdue(props.project));

/**
 * The operational facts only. There is no commercial row here by construction: the keys that
 * would carry one are absent from `EmployeeProject`.
 */
const rows = computed(() => [
    { label: 'Type', value: props.project.project_type_label ?? '—' },
    { label: 'Priority', value: props.project.priority_label ?? '—' },
    { label: 'Start date', value: formatProjectDate(props.project.start_date) ?? '—' },
    { label: 'Project manager', value: props.project.pm?.name ?? 'Unassigned' },
]);
</script>

<template>
    <Card class="min-w-0 gap-4 shadow-xs">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Project</CardTitle>
        </CardHeader>
        <CardContent>
            <dl class="grid min-w-0 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-1">
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">Domain</dt>
                    <dd class="text-sm break-all">
                        <a
                            v-if="domainHref"
                            :href="domainHref"
                            target="_blank"
                            rel="noopener"
                            class="hover:underline"
                        >{{ project.domain }}</a>
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">Status</dt>
                    <dd class="text-sm">
                        <StatusPill :label="project.status_label" :tone="toneForProjectStatus(project.status)" />
                    </dd>
                </div>
                <div v-for="row in rows" :key="row.label" class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">{{ row.label }}</dt>
                    <dd class="text-sm break-words">{{ row.value }}</dd>
                </div>
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">Deadline</dt>
                    <!-- The word, not just the colour: red alone is silent to a screen reader. -->
                    <dd
                        :class="
                            cn(
                                'flex flex-wrap items-baseline gap-1.5 text-sm',
                                overdue && 'font-medium text-destructive',
                            )
                        "
                    >
                        <span class="min-w-0 break-words">
                            {{ formatProjectDate(project.deadline) ?? 'No deadline' }}
                        </span>
                        <span v-if="overdue">Overdue</span>
                    </dd>
                </div>
            </dl>
        </CardContent>
    </Card>
</template>
