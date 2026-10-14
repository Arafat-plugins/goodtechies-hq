<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, CalendarCheck, CalendarDays, CalendarPlus, CircleCheck, CirclePlay, Clock, ExternalLink, Folder, KanbanSquare, List, ListChecks, Pencil, Plus, Timer, Trash2, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import FilePanel from '@/Components/Files/FilePanel.vue';
import { fileRoutes, internalFileRoutes } from '@/Components/Files/files';
import MessageThread from '@/Components/Messages/MessageThread.vue';
import { conversationRoutes, emptyThread } from '@/Components/Messages/messages';
import PageShell from '@/Components/PageShell.vue';
import DeleteProjectDialog, { canForceDelete } from '@/Components/Projects/DeleteProjectDialog.vue';
import FinanceCard from '@/Components/Projects/FinanceCard.vue';
import MembersCard from '@/Components/Projects/MembersCard.vue';
import ProjectTaskList, { type ProjectTasks } from '@/Components/Projects/ProjectTaskList.vue';
import type { EmployeeOption, Option, Project } from '@/Components/Projects/ProjectForm.vue';
import ProjectTaskBoard from '@/Components/Projects/ProjectTaskBoard.vue';
import { projectTile } from '@/Components/Projects/projectTile';
import StatusActions from '@/Components/Projects/StatusActions.vue';
import { useLiveTaskProps } from '@/Components/Realtime/reload';
import RecurringPanel from '@/Components/Recurring/RecurringPanel.vue';
import { statusToneClass } from '@/Components/StatusBadge.vue';
import StatusPill, { toneForProjectStatus } from '@/Components/StatusPill.vue';
import QuickAddTaskModal from '@/Components/Tasks/QuickAddTaskModal.vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { formatDuration } from '@/Components/Timer/timer';
import { workingNowPing, type WorkingNowRow } from '@/Components/Timer/taskTimer';
import WorkingNowPanel from '@/Components/Timer/WorkingNowPanel.vue';
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
import { ToggleGroup, ToggleGroupItem } from '@/Components/ui/toggle-group';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useUrlTab } from '@/lib/tabState';
import { cn } from '@/lib/utils';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    project: { data: Project };
    activity: { description: string; actor: string | null; at: string }[];
    assignableEmployees: EmployeeOption[];
    /** The project's channel (Phase 6). The Discussion tab fetches the thread itself. */
    discussionConversationId: number;
    /** Open task timers on this project (flow F3) — absent for anybody without `watchLive`. */
    workingNow?: WorkingNowRow[];
    /** Client request 2026-10-06: every task on this project. */
    projectTasks: ProjectTasks;
    /** Polish 080: for the quick-add task modal. */
    taskPriorities: Option[];
}>();

/** A `task.changed` kind `timer` frame re-reads `workingNow` alone (flow F1). */
if (props.workingNow !== undefined) {
    useLiveTaskProps(['workingNow'], { accept: workingNowPing });
}

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
    { value: 'hourly', label: 'Hourly' },
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

/** The internal notes' own attachments — listed only for whoever may see the notes. */
const internalFiles = computed(() => internalFileRoutes(project.value.id));

/**
 * Which tab is open, in the URL — decision 2-51.
 *
 * `ref('overview')` meant a reload dropped back to Overview and a project's Files could not be
 * linked to. Every write on this page redirects `back()`, so uploading a file also bounced the
 * reader out of the tab they uploaded it into.
 *
 * Finance is in the list only when the payload says this reader may see it, so `?tab=finance`
 * from somebody who may not opens Overview rather than selecting a panel that is not rendered.
 */
const tab = useUrlTab(
    [
        'overview',
        'tasks',
        'files',
        'discussion',
        'activity',
        ...(permissions.value.can_view_finance === true ? ['finance'] : []),
        'members',
        'recurring',
    ],
    // Polish 080: the page opens on its tasks, as in the client's reference.
    'tasks',
);

/* ------------------------------------------------------------ polish 080 */

/** Board (status columns) or the plain list. */
const taskView = ref<'board' | 'list'>('board');

function chooseTaskView(value: unknown): void {
    if (value === 'board' || value === 'list') {
        taskView.value = value;
    }
}

const counts = computed(() => props.projectTasks.counts ?? { todo: 0, progress: 0, review: 0, complete: 0 });

function percent(part: number): number {
    return props.projectTasks.total > 0 ? Math.round((part / props.projectTasks.total) * 100) : 0;
}

/** The four cards under the header: total, in progress (+ review), to do, completed. */
const stats = computed(() => [
    { key: 'total', label: 'Total tasks', value: props.projectTasks.total, icon: ListChecks, tone: 'changes' as const, note: `${counts.value.complete} completed` },
    { key: 'progress', label: 'In progress', value: counts.value.progress + counts.value.review, icon: CirclePlay, tone: 'progress' as const, note: `${percent(counts.value.progress + counts.value.review)}%` },
    { key: 'todo', label: 'To do', value: counts.value.todo, icon: Clock, tone: 'waiting' as const, note: `${percent(counts.value.todo)}%` },
    { key: 'complete', label: 'Completed', value: counts.value.complete, icon: CircleCheck, tone: 'done' as const, note: `${percent(counts.value.complete)}%` },
]);

const canAddTask = computed(() => permissions.value.can_update === true && !project.value.is_archived);
const quickAddOpen = ref(false);
const quickAddStatus = ref<string | null>(null);

function addTask(status: string | null = null): void {
    quickAddStatus.value = status;
    quickAddOpen.value = true;
}

const thisProject = computed(() => [{ id: project.value.id, name: project.value.name }]);
const employeeRefs = computed(() => props.assignableEmployees.map((employee) => ({ id: employee.id, name: employee.name })));

function siteHref(domain: string): string {
    return /^https?:\/\//i.test(domain) ? domain : `https://${domain}`;
}

function bareDomain(domain: string): string {
    return domain.replace(/^https?:\/\//i, '').replace(/\/$/, '');
}

const TAB_CLASS =
    '-mb-px h-10 flex-none rounded-none border-0 border-b-2 border-transparent bg-transparent px-3 text-muted-foreground shadow-flat data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-foreground dark:data-[state=active]:border-primary dark:data-[state=active]:bg-transparent';

const archiveOpen = ref(false);
const archiving = ref(false);

/** The project handed to the delete dialog while it is open; null closes it. */
const deleteTarget = ref<Project | null>(null);

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
        <!-- Polish 080: the client's reference header — folder tile, name, website, the project's
             details on one line, its status and the page's buttons. -->
        <header class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-start lg:justify-between" data-testid="project-header">
            <div class="flex min-w-0 items-start gap-4">
                <span :class="cn('flex size-16 shrink-0 items-center justify-center rounded-xl border', projectTile(project.id))" aria-hidden="true">
                    <Folder class="size-8" />
                </span>
                <div class="flex min-w-0 flex-col gap-1.5">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <h2 class="min-w-0 text-2xl font-bold break-words">{{ project.name }}</h2>
                        <StatusPill :label="project.status_label" :tone="toneForProjectStatus(project.status)" />
                        <span v-if="project.is_archived" class="rounded-full border px-2 py-0.5 text-xs font-medium text-muted-foreground">
                            Archived
                        </span>
                    </div>
                    <a
                        v-if="project.domain"
                        :href="siteHref(project.domain)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex w-fit min-w-0 items-center gap-1.5 text-base text-status-progress-fg hover:underline"
                    >
                        <span class="truncate">{{ bareDomain(project.domain) }}</span>
                        <ExternalLink class="size-4 shrink-0" aria-hidden="true" />
                        <span class="sr-only">(opens in a new tab)</span>
                    </a>
                    <dl class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1.5 pt-1 text-sm">
                        <div class="inline-flex items-center gap-1.5">
                            <UserRound class="size-4 text-muted-foreground" aria-hidden="true" />
                            <dt class="text-muted-foreground">Client</dt>
                            <dd class="font-medium">
                                <Link v-if="project.client" :href="`/admin/clients/${project.client.id}`" class="hover:underline">{{ project.client.name }}</Link>
                                <span v-else>Internal</span>
                            </dd>
                        </div>
                        <div v-if="project.created_at" class="inline-flex items-center gap-1.5">
                            <CalendarPlus class="size-4 text-muted-foreground" aria-hidden="true" />
                            <dt class="text-muted-foreground">Created</dt>
                            <dd class="font-medium tabular-nums">{{ formatDate(project.created_at.slice(0, 10)) }}</dd>
                        </div>
                        <div class="inline-flex items-center gap-1.5">
                            <dt class="text-muted-foreground">Type</dt>
                            <dd class="font-medium">{{ project.project_type_label }}</dd>
                        </div>
                        <div v-if="project.pm" class="inline-flex items-center gap-1.5">
                            <dt class="text-muted-foreground">Manager</dt>
                            <dd class="font-medium">{{ project.pm.name }}</dd>
                        </div>
                        <div v-if="project.start_date" class="inline-flex items-center gap-1.5">
                            <CalendarDays class="size-4 text-muted-foreground" aria-hidden="true" />
                            <dt class="text-muted-foreground">Start</dt>
                            <dd class="font-medium tabular-nums">{{ formatDate(project.start_date) }}</dd>
                        </div>
                        <div v-if="project.deadline" class="inline-flex items-center gap-1.5">
                            <CalendarCheck class="size-4 text-muted-foreground" aria-hidden="true" />
                            <dt class="text-muted-foreground">Due</dt>
                            <dd class="font-medium tabular-nums">{{ formatDate(project.deadline) }}</dd>
                        </div>
                        <div class="inline-flex items-center gap-1.5">
                            <Timer class="size-4 text-muted-foreground" aria-hidden="true" />
                            <dt class="text-muted-foreground">Time spent</dt>
                            <dd class="font-medium tabular-nums">{{ formatDuration(projectTasks.tracked_seconds) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Button v-if="permissions.can_update" as-child variant="outline">
                    <Link :href="`/admin/projects/${project.id}/edit`">
                        <Pencil aria-hidden="true" />
                        Edit Project
                    </Link>
                </Button>
                <StatusActions v-if="permissions.can_update" :project="project" :statuses="statuses" />
                <Button v-if="canAddTask" type="button" @click="addTask()">
                    <Plus aria-hidden="true" />
                    Add Task
                </Button>
                <Button
                    v-if="permissions.can_archive"
                    type="button"
                    :variant="project.is_archived ? 'outline' : 'destructive'"
                    @click="archiveOpen = true"
                >
                    <component :is="project.is_archived ? ArchiveRestore : Archive" aria-hidden="true" />
                    {{ project.is_archived ? 'Unarchive' : 'Archive' }}
                </Button>
                <Button
                    v-if="project.is_archived && canForceDelete(project)"
                    type="button"
                    variant="destructive"
                    @click="deleteTarget = project"
                >
                    <Trash2 aria-hidden="true" />
                    Delete permanently…
                </Button>
            </div>
        </header>

        <!-- Polish 080: the task numbers, as four cards. -->
        <ul class="grid min-w-0 grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Task numbers">
            <li v-for="stat in stats" :key="stat.key">
                <Card class="flex min-w-0 flex-col gap-3 p-4 sm:flex-row sm:items-center sm:gap-4">
                    <span :class="cn('flex size-12 shrink-0 items-center justify-center rounded-lg border', statusToneClass(stat.tone))" aria-hidden="true">
                        <component :is="stat.icon" class="size-6" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="text-sm text-muted-foreground">{{ stat.label }}</span>
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-2xl font-semibold tabular-nums">{{ stat.value }}</span>
                            <span :class="cn('rounded-full border px-2 py-0.5 text-xs font-medium tabular-nums', statusToneClass(stat.tone))">
                                {{ stat.note }}
                            </span>
                        </span>
                    </div>
                </Card>
            </li>
        </ul>

        <section v-if="workingNow?.length" aria-labelledby="project-working-now" class="min-w-0">
            <WorkingNowPanel :rows="workingNow" heading-id="project-working-now" />
        </section>

        <Tabs v-model="tab" class="min-w-0 gap-4">
            <!-- The trigger row scrolls on its own at 375 so the page itself never does. -->
            <div class="flex min-w-0 flex-wrap items-center gap-2 border-b">
                <div class="min-w-0 flex-1 basis-full overflow-x-auto [scrollbar-width:none] md:basis-auto [&::-webkit-scrollbar]:hidden">
                    <TabsList class="h-auto w-max gap-1 rounded-none bg-transparent p-0">
                        <TabsTrigger value="overview" :class="TAB_CLASS">Overview</TabsTrigger>
                        <TabsTrigger value="tasks" :class="TAB_CLASS">Tasks</TabsTrigger>
                        <TabsTrigger value="files" :class="TAB_CLASS">Files</TabsTrigger>
                        <TabsTrigger value="discussion" :class="TAB_CLASS">Discussions</TabsTrigger>
                        <TabsTrigger value="activity" :class="TAB_CLASS">Activity</TabsTrigger>
                        <TabsTrigger v-if="permissions.can_view_finance" value="finance" :class="TAB_CLASS">Finance</TabsTrigger>
                        <TabsTrigger value="members" :class="TAB_CLASS">Members</TabsTrigger>
                        <TabsTrigger value="recurring" :class="TAB_CLASS">Recurring</TabsTrigger>
                    </TabsList>
                </div>
                <ToggleGroup
                    v-if="tab === 'tasks'"
                    type="single"
                    variant="outline"
                    class="mb-2 shrink-0"
                    :model-value="taskView"
                    aria-label="How to show tasks"
                    @update:model-value="chooseTaskView"
                >
                    <ToggleGroupItem value="board" class="px-3">
                        <KanbanSquare aria-hidden="true" />
                        Board
                    </ToggleGroupItem>
                    <ToggleGroupItem value="list" class="px-3">
                        <List aria-hidden="true" />
                        List
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <!--
                Every panel is a tab stop: reka gives it `tabindex="0"` so a keyboard can reach
                what the strip controls, and the primitive's base class is `outline-none`, so
                the stop painted nothing. The ring goes on here rather than in the generated
                component, exactly as `Pages/Shared/Notifications.vue` does it.
            -->
            <TabsContent value="overview" class="flex flex-col gap-4 rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <!-- Polish 080: the details moved up into the header; Overview keeps the notes. -->
                <div class="grid items-start gap-4 lg:grid-cols-2">
                    <Card class="min-w-0 gap-2">
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
                    <Card v-if="project.internal_notes !== undefined" class="min-w-0 gap-2">
                        <CardHeader>
                            <CardTitle class="text-sm font-medium">Internal notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p
                                v-if="project.internal_notes"
                                class="text-sm break-words whitespace-pre-wrap"
                            >{{ project.internal_notes }}</p>
                            <p v-else class="text-sm text-muted-foreground">No internal notes yet.</p>
                            <FilePanel
                                class="mt-4"
                                compact
                                title="Attachments"
                                :routes="internalFiles"
                                :can-upload="permissions.can_update === true"
                            />
                        </CardContent>
                    </Card>
                </div>
            </TabsContent>

            <TabsContent v-if="permissions.can_view_finance" value="finance" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <FinanceCard
                    :project="project"
                    :billing-frequencies="billingFrequencies"
                    :can-edit="permissions.can_update === true"
                />
            </TabsContent>

            <TabsContent value="members" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <MembersCard
                    :project="project"
                    :assignable-employees="assignableEmployees"
                    :can-manage="permissions.can_manage_members === true"
                />
            </TabsContent>

            <!--
                Phase 2 built the tasks screens, so this tab stopped being a placeholder. It is
                not a second Tasks list: the three views already answer "the tasks on this
                project" through `project_id`, and a fourth copy of that query here would be the
                bespoke table DESIGN.md §5.8 forbids. So the tab hands the reader over to the
                real one, pre-filtered, and says so.
            -->
            <TabsContent value="tasks" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <!-- Polish 080: a grid of status columns (Board) or the plain list. -->
                <ProjectTaskBoard v-if="taskView === 'board'" :tasks="projectTasks" :can-add="canAddTask" @add="addTask" />
                <ProjectTaskList
                    v-else
                    :tasks="projectTasks"
                    scope-label="All tasks"
                    :all-href="`/admin/tasks?project_id=${project.id}`"
                    show-assignees
                />
            </TabsContent>

            <!--
                Phase 3's Recurring tab: this project's retainer templates. Like the Files tab,
                the panel fetches its own list as JSON and writes with ordinary Inertia visits —
                so the tab costs the project payload nothing until somebody opens it.
            -->
            <TabsContent value="recurring" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <RecurringPanel
                    :project-id="project.id"
                    :can-manage="permissions.can_update === true"
                    :employees="assignableEmployees"
                />
            </TabsContent>

            <!--
                The project's channel — the same conversation the Messages page lists under
                Projects, and the same thread component. Mounted only while the tab is open, so
                the page carries an id rather than a thread and the payload it shows is the one
                the policy built (see `emptyThread`). Who may post is `ConversationPolicy`,
                which asks `ProjectPolicy::view` about this project: a member speaks in it
                because they are a member, with no list synced anywhere.
            -->
            <TabsContent value="discussion" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <Card class="min-w-0">
                    <CardContent class="min-w-0">
                        <MessageThread
                            v-if="tab === 'discussion'"
                            :thread="emptyThread(discussionConversationId, project.name)"
                            :routes="conversationRoutes(discussionConversationId)"
                            heading="Discussion"
                            description="The project channel. Everybody who can see this project can read and post here."
                            scroll
                        />
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="files" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <FilePanel
                    :routes="files"
                    :can-upload="permissions.can_update === true"
                    description="Briefs, deliverables and anything else worth keeping with this project."
                    empty-description="Attach a brief, a deliverable or a signed scope and it will be here next week."
                />
            </TabsContent>

            <TabsContent value="activity" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <Card class="min-w-0 gap-2">
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

    <DeleteProjectDialog :project="deleteTarget" @close="deleteTarget = null" />

    <QuickAddTaskModal
        v-if="canAddTask"
        v-model:open="quickAddOpen"
        :projects="thisProject"
        :priorities="taskPriorities"
        :employees="employeeRefs"
        :project-id="project.id"
        :status="quickAddStatus"
    />
</template>
