<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { FileText, ListTodo } from '@lucide/vue';
import { computed } from 'vue';
import ProjectFacts from '@/Components/Employee/ProjectFacts.vue';
import type { EmployeeProject } from '@/Components/Employee/ProjectListCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { toneForProjectStatus } from '@/Components/StatusPill.vue';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';

defineOptions({ layout: EmployeeLayout });

const props = defineProps<{
    project: { data: EmployeeProject };
}>();

const project = computed(() => props.project.data);

/** The domain is the operational name of the work; the project name is the subtitle. */
const title = computed(() => project.value.domain ?? project.value.name);
const description = computed(() => (project.value.domain ? project.value.name : undefined));

const page = usePage();

/**
 * Members are identified by their **employee** id, while the shared `auth.user` carries only
 * the user id and name — there is no employee id on the shared props to compare against. So
 * the viewer's own row is matched on the name the same user record supplies on both sides.
 */
const viewerName = computed(() => page.props.auth.user?.name ?? null);

function isViewer(member: EmployeeProject['members'][number]): boolean {
    return viewerName.value !== null && member.name === viewerName.value;
}
</script>

<template>
    <Head :title="title" />

    <PageShell :title="title" :description="description">
        <!-- No action buttons: an employee reads a project, they do not change one. -->
        <template #actions>
            <StatusBadge :status="toneForProjectStatus(project.status)" :label="project.status_label" />
            <Badge v-if="project.is_archived" variant="outline" class="text-muted-foreground">Archived</Badge>
        </template>

        <div class="grid min-w-0 items-start gap-4 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
                <Card class="min-w-0 gap-2 shadow-xs">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Notes from the team</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="project.employee_notes"
                            class="text-sm break-words whitespace-pre-wrap"
                        >{{ project.employee_notes }}</p>
                        <p v-else class="text-sm text-muted-foreground">No notes on this project yet.</p>
                    </CardContent>
                </Card>

                <!--
                    Phase 2 filled the first of these two, so it stopped being a placeholder: it
                    hands the reader to the Tasks screens filtered to this project rather than
                    growing a fourth copy of that query here (DESIGN.md §5.8).
                -->
                <Card class="min-w-0 gap-4 p-6 shadow-xs">
                    <h2 class="text-sm font-medium">Tasks</h2>
                    <EmptyState
                        :icon="ListTodo"
                        title="Your tasks on this project"
                        description="They live on your Tasks screens — List, Board and Calendar all read the same work. This opens them filtered to this project."
                    >
                        <template #action>
                            <Button as-child size="sm">
                                <Link :href="`/employee/tasks?project_id=${project.id}`">
                                    Open this project’s tasks
                                </Link>
                            </Button>
                        </template>
                    </EmptyState>
                </Card>

                <!--
                    The second did not: Phase 2's Files tabs are on the **admin** project and
                    client screens, and this surface has no project-files route to read. What an
                    employee can reach is a task's own attachments, so the panel says that
                    instead of naming a phase that has already shipped.
                -->
                <Card class="min-w-0 gap-4 p-6 shadow-xs">
                    <h2 class="text-sm font-medium">Files</h2>
                    <EmptyState
                        :icon="FileText"
                        title="Files live on the task"
                        description="Anything attached to a task you are on is in that task's Attachments panel. There is no project-wide file list on this surface."
                    />
                </Card>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                <ProjectFacts :project="project" />

                <Card class="min-w-0 gap-4 shadow-xs">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Team</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="project.members.length === 0" class="text-sm text-muted-foreground">
                            No one is assigned to this project yet.
                        </p>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="member in project.members"
                                :key="member.id"
                                class="flex min-w-0 flex-col gap-1"
                            >
                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                    <span class="text-sm break-words">{{ member.name ?? 'Unknown' }}</span>
                                    <Badge v-if="isViewer(member)" variant="secondary">You</Badge>
                                </div>
                                <span
                                    v-if="member.role_on_project"
                                    class="text-xs text-muted-foreground break-words"
                                >
                                    {{ member.role_on_project }}
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>
    </PageShell>
</template>
