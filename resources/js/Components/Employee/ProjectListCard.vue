<script lang="ts">
/**
 * The project payload an **employee** receives, written out key by key.
 *
 * This type is the privacy contract in code: `ProjectResource` omits `client`,
 * `internal_notes`, `billing_type` and `finance` for a role without the commercial or finance
 * gate, so those keys are not declared here either. Nothing on the employee surface may read
 * them — not even defensively — and a missing key is a type error rather than a silent leak.
 */
export interface EmployeeProject {
    id: number;
    name: string;
    domain: string | null;
    project_type: string | null;
    project_type_label: string | null;
    status: string;
    status_label: string;
    priority: string | null;
    priority_label: string | null;
    start_date: string | null;
    deadline: string | null;
    archived_at: string | null;
    is_archived: boolean;
    employee_notes: string | null;
    pm: { id: number; name: string | null } | null;
    members: { id: number; name: string | null; role_on_project: string | null }[];
    permissions: Record<string, boolean>;
}

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });

/** 'YYYY-MM-DD' → "12 Mar 2026". Anything unparseable is shown as it arrived. */
export function formatProjectDate(value: string | null): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE.format(date);
}

/** Only a project that is still open can be late; a finished one just has a past date. */
export function isProjectOverdue(project: EmployeeProject): boolean {
    if (!project.deadline || (project.status !== 'active' && project.status !== 'on_hold')) {
        return false;
    }

    const date = new Date(project.deadline);

    return !Number.isNaN(date.getTime()) && date.getTime() < Date.now();
}
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarClock, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { toneForProjectStatus } from '@/Components/StatusPill.vue';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

const props = defineProps<{
    project: EmployeeProject;
}>();

/** The domain is what an employee recognises the work by; the name is the fallback. */
const title = computed(() => props.project.domain ?? props.project.name);

/** Only worth a second line when it is not already the title. */
const subtitle = computed(() => (props.project.domain ? props.project.name : null));

const deadline = computed(() => {
    const formatted = formatProjectDate(props.project.deadline);

    return formatted === null ? 'No deadline' : `Due ${formatted}`;
});

const overdue = computed(() => isProjectOverdue(props.project));

/** The first written line of the notes; the card clamps it to two rendered lines. */
const notesLine = computed(() => {
    const first = (props.project.employee_notes ?? '')
        .split('\n')
        .map((line) => line.trim())
        .find((line) => line !== '');

    return first ?? null;
});
</script>

<template>
    <Link :href="`/employee/projects/${project.id}`" class="block min-w-0 rounded-xl">
        <Card class="h-full min-w-0 gap-3 p-4 shadow-xs transition-colors hover:bg-accent/40">
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <h2 class="min-w-0 text-sm font-medium break-all">{{ title }}</h2>
                    <Badge v-if="project.is_archived" variant="outline" class="text-muted-foreground">
                        Archived
                    </Badge>
                </div>
                <p v-if="subtitle" class="text-xs text-muted-foreground break-words">{{ subtitle }}</p>
            </div>

            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <StatusBadge :status="toneForProjectStatus(project.status)" :label="project.status_label" />
                <span v-if="project.project_type_label" class="text-xs text-muted-foreground">
                    {{ project.project_type_label }}
                </span>
            </div>

            <dl class="flex min-w-0 flex-col gap-1.5 text-xs text-muted-foreground">
                <div class="flex min-w-0 items-center gap-2">
                    <dt class="flex shrink-0 items-center gap-1.5">
                        <CalendarClock class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">Deadline</span>
                    </dt>
                    <!-- The word, not just the colour: red alone is silent to a screen reader. -->
                    <dd
                        :class="
                            cn(
                                'flex min-w-0 flex-wrap items-baseline gap-1.5',
                                overdue && 'font-medium text-destructive',
                            )
                        "
                    >
                        <span class="min-w-0 break-words">{{ deadline }}</span>
                        <span v-if="overdue">Overdue</span>
                    </dd>
                </div>
                <div class="flex min-w-0 items-center gap-2">
                    <dt class="flex shrink-0 items-center gap-1.5">
                        <UserRound class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">Project manager</span>
                    </dt>
                    <dd class="min-w-0 break-words">{{ project.pm?.name ?? 'No project manager' }}</dd>
                </div>
            </dl>

            <p v-if="notesLine" class="line-clamp-2 text-xs break-words text-muted-foreground">
                {{ notesLine }}
            </p>
        </Card>
    </Link>
</template>
