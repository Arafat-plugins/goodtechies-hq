<script setup lang="ts">
import { computed } from 'vue';
import type { Project } from '@/Components/Projects/ProjectForm.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

const props = defineProps<{
    project: Project;
}>();

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });

function formatDate(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE.format(date);
}

/** Domains are stored bare, so the link has to put the scheme back on. */
const domainHref = computed(() => (props.project.domain ? `https://${props.project.domain}` : null));

/**
 * Plain label/value rows. `client`, `billing_type` and the rest may be absent for roles that
 * may not see them, so each one is read with optional chaining and falls back to an em dash.
 */
const rows = computed(() => [
    { label: 'Type', value: props.project.project_type_label ?? '—' },
    { label: 'Billing type', value: props.project.billing_type_label ?? '—' },
    { label: 'Priority', value: props.project.priority_label ?? '—' },
    { label: 'Project manager', value: props.project.pm?.name ?? 'Unassigned' },
    { label: 'Start date', value: formatDate(props.project.start_date) ?? '—' },
    { label: 'Deadline', value: formatDate(props.project.deadline) ?? '—' },
    ...(props.project.archived_at
        ? [{ label: 'Archived', value: formatDate(props.project.archived_at) ?? '—' }]
        : []),
]);
</script>

<template>
    <Card class="min-w-0 gap-4 shadow-xs">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Details</CardTitle>
        </CardHeader>
        <CardContent>
            <dl class="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">Client</dt>
                    <dd class="text-sm break-words">
                        <span v-if="project.client">{{ project.client.name }}</span>
                        <span v-else class="text-muted-foreground">Internal</span>
                    </dd>
                </div>
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
                <div v-for="row in rows" :key="row.label" class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">{{ row.label }}</dt>
                    <dd class="text-sm break-words">{{ row.value }}</dd>
                </div>
            </dl>
        </CardContent>
    </Card>
</template>
