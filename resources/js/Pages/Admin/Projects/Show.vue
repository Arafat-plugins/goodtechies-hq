<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import FilePanel from '@/Components/Files/FilePanel.vue';
import { fileRoutes } from '@/Components/Files/files';
import PageShell from '@/Components/PageShell.vue';
import FinanceCard from '@/Components/Projects/FinanceCard.vue';
import MembersCard from '@/Components/Projects/MembersCard.vue';
import type { EmployeeOption, Option, Project } from '@/Components/Projects/ProjectForm.vue';
import ProjectMetaList from '@/Components/Projects/ProjectMetaList.vue';
import StatusActions from '@/Components/Projects/StatusActions.vue';
import StatusPill, { toneForProjectStatus } from '@/Components/StatusPill.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    project: { data: Project };
    activity: { description: string; actor: string | null; at: string }[];
    assignableEmployees: EmployeeOption[];
}>();

const project = computed(() => props.project.data);
const permissions = computed(() => project.value.permissions ?? {});

/**
 * `GET /admin/projects/{id}` does not carry the option lists, so the two the detail page
 * edits with are spelled out here. They mirror `BillingFrequency` and `ProjectStatus`.
 */
const billingFrequencies: Option[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'yearly', label: 'Yearly' },
    { value: 'custom', label: 'Custom' },
];

/** Archived is not a transition — it has its own endpoint — so it is not offered here. */
const statuses: Option[] = [
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];

const RELATIVE = new Intl.RelativeTimeFormat('en-GB', { numeric: 'auto' });
const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 60 * 60],
    ['month', 30 * 24 * 60 * 60],
    ['day', 24 * 60 * 60],
    ['hour', 60 * 60],
    ['minute', 60],
];

function relativeTime(at: string): string {
    const date = new Date(at);

    if (Number.isNaN(date.getTime())) {
        return at;
    }

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return RELATIVE.format(Math.round(seconds / size), unit);
        }
    }

    return RELATIVE.format(Math.round(seconds), 'second');
}

const recentActivity = computed(() => props.activity.slice(0, 20));

/**
 * The Files tab's endpoints. `can_update` is what `FileService::guardMayAttach()` asks for, so
 * an archived project lists and downloads what it already has and takes nothing new — the same
 * answer the endpoint gives, resolved once by `ProjectPolicy`.
 */
const files = computed(() => fileRoutes('admin', 'projects', project.value.id));

const tab = ref('overview');

const archiveOpen = ref(false);
const archiving = ref(false);

function confirmArchiveToggle(): void {
    if (archiving.value) {
        return;
    }

    archiving.value = true;

    router.post(
        `/admin/projects/${project.value.id}/${project.value.is_archived ? 'unarchive' : 'archive'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                archiving.value = false;
                archiveOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="project.name" />

    <PageShell :title="project.name" description="Money, members and what has happened lately.">
        <template #actions>
            <StatusPill :label="project.status_label" :tone="toneForProjectStatus(project.status)" />
            <span
                v-if="project.is_archived"
                class="rounded-full border px-2 py-0.5 text-xs font-medium text-muted-foreground"
            >
                Archived
            </span>
            <Button v-if="permissions.can_update" as-child variant="outline" size="sm">
                <Link :href="`/admin/projects/${project.id}/edit`">
                    <Pencil aria-hidden="true" />
                    Edit
                </Link>
            </Button>
            <StatusActions v-if="permissions.can_update" :project="project" :statuses="statuses" />
            <Button
                v-if="permissions.can_archive"
                type="button"
                :variant="project.is_archived ? 'outline' : 'destructive'"
                size="sm"
                @click="archiveOpen = true"
            >
                <component :is="project.is_archived ? ArchiveRestore : Archive" aria-hidden="true" />
                {{ project.is_archived ? 'Unarchive' : 'Archive' }}
            </Button>
        </template>

        <Tabs v-model="tab" class="min-w-0 gap-4">
            <!-- The trigger row scrolls on its own at 375 so the page itself never does. -->
            <div class="min-w-0 max-w-full overflow-x-auto">
                <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger v-if="permissions.can_view_finance" value="finance">Finance</TabsTrigger>
                    <TabsTrigger value="members">Members</TabsTrigger>
                    <TabsTrigger value="activity">Activity</TabsTrigger>
                    <TabsTrigger value="tasks" disabled title="Arrives in Phase 2">Tasks</TabsTrigger>
                    <TabsTrigger value="files">Files</TabsTrigger>
                </TabsList>
            </div>

            <TabsContent value="overview" class="flex flex-col gap-4">
                <ProjectMetaList :project="project" />

                <div class="grid items-start gap-4 lg:grid-cols-2">
                    <Card class="min-w-0 gap-2 shadow-xs">
                        <CardHeader>
                            <CardTitle class="text-sm font-medium">Employee notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p
                                v-if="project.employee_notes"
                                class="text-sm break-words whitespace-pre-wrap"
                            >{{ project.employee_notes }}</p>
                            <p v-else class="text-sm text-muted-foreground">No employee notes yet.</p>
                        </CardContent>
                    </Card>

                    <!-- The key is absent (not null) for a role that may not see it. -->
                    <Card v-if="project.internal_notes !== undefined" class="min-w-0 gap-2 shadow-xs">
                        <CardHeader>
                            <CardTitle class="text-sm font-medium">Internal notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p
                                v-if="project.internal_notes"
                                class="text-sm break-words whitespace-pre-wrap"
                            >{{ project.internal_notes }}</p>
                            <p v-else class="text-sm text-muted-foreground">No internal notes yet.</p>
                        </CardContent>
                    </Card>
                </div>
            </TabsContent>

            <TabsContent v-if="permissions.can_view_finance" value="finance">
                <FinanceCard
                    :project="project"
                    :billing-frequencies="billingFrequencies"
                    :can-edit="permissions.can_update === true"
                />
            </TabsContent>

            <TabsContent value="members">
                <MembersCard
                    :project="project"
                    :assignable-employees="assignableEmployees"
                    :can-manage="permissions.can_manage_members === true"
                />
            </TabsContent>

            <TabsContent value="files">
                <FilePanel
                    :routes="files"
                    :can-upload="permissions.can_update === true"
                    description="Briefs, deliverables and anything else worth keeping with this project."
                    empty-description="Attach a brief, a deliverable or a signed scope and it will be here next week."
                />
            </TabsContent>

            <TabsContent value="activity">
                <Card class="min-w-0 gap-2 shadow-xs">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Activity</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="recentActivity.length === 0" class="text-sm text-muted-foreground">
                            Nothing has happened on this project yet.
                        </p>
                        <ol v-else class="flex flex-col gap-0">
                            <li v-for="(entry, index) in recentActivity" :key="index" class="flex gap-3">
                                <div class="flex flex-col items-center">
                                    <span
                                        class="mt-1.5 size-1.5 shrink-0 rounded-full bg-status-progress"
                                        aria-hidden="true"
                                    />
                                    <span
                                        v-if="index < recentActivity.length - 1"
                                        class="w-px grow bg-border"
                                        aria-hidden="true"
                                    />
                                </div>
                                <div class="flex min-w-0 flex-col gap-1 pb-4">
                                    <p class="text-sm break-words">{{ entry.description }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        <span v-if="entry.actor">{{ entry.actor }} · </span>{{ relativeTime(entry.at) }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </CardContent>
                </Card>
            </TabsContent>
        </Tabs>
    </PageShell>

    <Dialog v-model:open="archiveOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{ project.is_archived ? 'Unarchive' : 'Archive' }} {{ project.name }}?
                </DialogTitle>
                <DialogDescription>
                    {{
                        project.is_archived
                            ? 'The project goes back on the active list and can be worked on again.'
                            : 'The project stays on record but drops off the active list and stops accepting changes.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="archiving" @click="archiveOpen = false">
                    Cancel
                </Button>
                <Button
                    type="button"
                    :variant="project.is_archived ? 'default' : 'destructive'"
                    :disabled="archiving"
                    @click="confirmArchiveToggle"
                >
                    {{ project.is_archived ? 'Unarchive' : 'Archive' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
