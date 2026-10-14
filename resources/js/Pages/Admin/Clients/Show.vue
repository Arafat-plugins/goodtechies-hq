<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Box,
    CalendarCheck,
    CalendarDays,
    Clock,
    Code,
    ExternalLink,
    FolderKanban,
    Globe,
    LayoutGrid,
    ListTodo,
    Mail,
    Megaphone,
    MoreHorizontal,
    NotebookPen,
    Pencil,
    Phone,
    Plus,
    Settings,
    ShoppingCart,
    Signal,
    UserX,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import ClientAvatar from '@/Components/Clients/ClientAvatar.vue';
import { personTone } from '@/Components/Messages/people';
import { formatDuration } from '@/Components/Timer/timer';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { formatDate } from '@/Components/Tasks/taskDetail';
import type { Client } from '@/Components/Clients/ClientForm.vue';
import { NO_TASKS, type TaskCounts } from '@/Components/Clients/clientTasks';
import TaskCountChips from '@/Components/Clients/TaskCountChips.vue';
import { type Tone, toneChipClass } from '@/Components/Dashboard/tones';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import EmptyState from '@/Components/EmptyState.vue';
import FilePanel from '@/Components/Files/FilePanel.vue';
import { fileRoutes } from '@/Components/Files/files';
import PageShell from '@/Components/PageShell.vue';
import type { ProjectFinance } from '@/Components/Projects/ProjectForm.vue';
import { NativeSelect } from '@/Components/ui/native-select';
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
import { useUrlTab } from '@/lib/tabState';
import { cn } from '@/lib/utils';

defineOptions({ layout: AdminLayout });

/**
 * A project as `ProjectResource` serializes it. Every finance key is optional: the server
 * omits `finance` entirely for a role that may not see money, so nothing here may assume it.
 */
interface ClientProject {
    id: number;
    name: string;
    domain?: string | null;
    project_type?: string | null;
    project_type_label?: string | null;
    status: string;
    status_label: string;
    priority_label?: string | null;
    deadline?: string | null;
    start_date?: string | null;
    pm?: { id: number; name: string | null } | null;
    members?: { id: number; name: string | null }[];
    is_archived?: boolean;
    finance?: ProjectFinance | null;
}

const props = defineProps<{
    client: { data: Client & { projects?: ClientProject[] } };
    activity: { description: string; actor: string | null; at: string }[];
    /** Polish 049: task counts per project id, and for the whole client. */
    projectTasks?: Record<string, TaskCounts>;
    taskCounts?: TaskCounts;
    /** Polish 066: per project id, seconds spent (approved time) and estimated on its tasks. */
    projectTime?: Record<string, { spent: number; estimate: number }>;
}>();

const client = computed(() => props.client.data);
const projects = computed(() => client.value.projects ?? []);
const contacts = computed(() => client.value.contacts ?? []);

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

const files = computed(() => fileRoutes('admin', 'clients', client.value.id));

/**
 * Attaching takes `ClientPolicy::update` — the same gate `FileService::guardMayAttach()` asks
 * the owner's own policy for. Read off the payload rather than inferred from "only an Admin
 * reaches this surface anyway": that inference is true today and stops being true the moment
 * a role is added, and the screen would go on offering an upload the endpoint refuses.
 */
const canAttach = computed(() => client.value.permissions?.can_update === true);

/**
 * Which tab is open, in the URL — decision 2-51: a reload used to drop back to Overview, and a
 * client's Files could not be linked to. See `lib/tabState.ts`.
 */
const tab = useUrlTab(['overview', 'notes', 'files', 'activity'], 'overview');

/* ------------------------------------------------------- websites & businesses (polish 049/050) */

const clientTasks = computed(() => props.taskCounts ?? NO_TASKS);

/** Polish 050: the contact shown under the client's name (image 4's subline). */
const primary = computed(() => contacts.value.find((contact) => contact.name || contact.email || contact.phone) ?? null);

function tasksOf(project: ClientProject): TaskCounts {
    return props.projectTasks?.[String(project.id)] ?? NO_TASKS;
}

/** A domain as a link: "ibctanks.ca" → https://ibctanks.ca; already-full URLs are kept. */
function domainHref(domain: string): string {
    return /^https?:\/\//iu.test(domain) ? domain : `https://${domain}`;
}

function domainKey(domain: string | null | undefined): string | null {
    const key = (domain ?? '').trim().toLowerCase().replace(/^https?:\/\//u, '').replace(/^www\./u, '').replace(/\/.*$/u, '');

    return key === '' ? null : key;
}

/**
 * Polish 050 — image 4: one card per website. Projects on the same domain are one website, and
 * each of them is one of its services; a project with no domain is a website of its own.
 */
interface Website {
    key: string;
    name: string;
    domain: string | null;
    main: ClientProject;
    projects: ClientProject[];
    status: string;
    statusLabel: string;
    tasks: TaskCounts;
}

const websites = computed<Website[]>(() => {
    const groups = new Map<string, ClientProject[]>();

    for (const project of projects.value) {
        const key = domainKey(project.domain) ?? `project-${project.id}`;
        groups.set(key, [...(groups.get(key) ?? []), project]);
    }

    return [...groups.entries()].map(([key, members]) => {
        const main = [...members].sort((a, b) => a.name.length - b.name.length)[0];
        const live = members.find((project) => project.status === 'active') ?? main;
        const tasks = members.reduce<TaskCounts>(
            (sum, project) => {
                const counts = tasksOf(project);

                return {
                    todo: sum.todo + counts.todo,
                    progress: sum.progress + counts.progress,
                    complete: sum.complete + counts.complete,
                    total: sum.total + counts.total,
                };
            },
            { ...NO_TASKS },
        );

        return {
            key,
            name: websiteName(members, main),
            domain: domainKey(main.domain) ?? null,
            main,
            projects: members,
            status: live.status,
            statusLabel: live.status_label,
            tasks,
        };
    });
});

/**
 * The website's own name: what its projects' names have in common ("Buffalo Modular — SEO" +
 * "Buffalo Modular — Website Maintenance" → "Buffalo Modular"), else the shortest name.
 */
function websiteName(members: ClientProject[], main: ClientProject): string {
    if (members.length < 2) {
        // "Northwind Freight — Web Application": the service is already its chip.
        const label = main.project_type_label ?? '';
        const suffix = new RegExp(`\\s*[-–—:|]\\s*${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`, 'iu');

        return label !== '' && suffix.test(main.name) ? main.name.replace(suffix, '') : main.name;
    }

    let prefix = members[0].name;

    for (const project of members.slice(1)) {
        while (prefix !== '' && !project.name.startsWith(prefix)) {
            prefix = prefix.slice(0, -1);
        }
    }

    const cleaned = prefix.replace(/[\s\-–—:|·,]+$/u, '').trim();

    return cleaned.length >= 3 ? cleaned : main.name;
}

/* Square tiles and progress bars take turns through these, like image 4. */
const TILE_TONES: Tone[] = ['progress', 'done', 'waiting', 'changes', 'backlog', 'review'];

const TILE_SOLID: Record<Tone, string> = {
    backlog: 'bg-status-backlog-fg text-background',
    todo: 'bg-status-todo-fg text-background',
    progress: 'bg-status-progress-fg text-background',
    review: 'bg-status-review-fg text-background',
    changes: 'bg-status-changes-fg text-background',
    done: 'bg-status-done-fg text-background',
    waiting: 'bg-status-waiting-fg text-background',
    cancelled: 'bg-status-cancelled-fg text-background',
};

const BAR_SOLID: Record<Tone, string> = {
    backlog: 'bg-status-backlog-fg',
    todo: 'bg-status-todo-fg',
    progress: 'bg-status-progress-fg',
    review: 'bg-status-review-fg',
    changes: 'bg-status-changes-fg',
    done: 'bg-status-done-fg',
    waiting: 'bg-status-waiting-fg',
    cancelled: 'bg-status-cancelled-fg',
};

function tileTone(index: number): Tone {
    return TILE_TONES[index % TILE_TONES.length];
}

/** A service chip per project type, with the icon and colour image 4 uses. */
const SERVICE: Record<string, { icon: Component; tone: Tone }> = {
    website_maintenance: { icon: Settings, tone: 'todo' },
    website_development: { icon: Code, tone: 'changes' },
    web_application: { icon: Code, tone: 'changes' },
    woocommerce: { icon: ShoppingCart, tone: 'progress' },
    seo: { icon: Signal, tone: 'done' },
    marketing: { icon: Megaphone, tone: 'waiting' },
};

function serviceOf(project: ClientProject): { icon: Component; tone: Tone } {
    return SERVICE[project.project_type ?? ''] ?? { icon: Box, tone: 'backlog' };
}

const projectStatus = ref('');

/** Polish 069: one website, picked from this client's websites ('' = all of them). */
const projectWebsite = ref('');

const statusOptions = computed(() => {
    const seen = new Map<string, string>();

    for (const site of websites.value) {
        seen.set(site.status, site.statusLabel);
    }

    return [...seen.entries()].map(([value, label]) => ({ value, label }));
});

const shownWebsites = computed(() =>
    websites.value.filter(
        (site) =>
            (projectStatus.value === '' || site.status === projectStatus.value) &&
            (projectWebsite.value === '' || site.key === projectWebsite.value),
    ),
);

/* Polish 060/061: the details under each project row — start, deadline, assigned people. */
const today = new Date().toISOString().slice(0, 10);

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

/** "26 Aug" for this year, "26 Aug 2027" otherwise — keeps a project's details on one line. */
const THIS_YEAR = new Date().getFullYear();
const SHORT_DATE = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' });

function shortDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const date = new Date(`${value.slice(0, 10)}T00:00:00`);

    return Number.isNaN(date.getTime()) || date.getFullYear() !== THIS_YEAR ? formatDate(value) : SHORT_DATE.format(date);
}

function timeOf(project: ClientProject): { spent: number; estimate: number } {
    return props.projectTime?.[String(project.id)] ?? { spent: 0, estimate: 0 };
}

function duration(seconds: number): string {
    return seconds > 0 ? formatDuration(seconds) : '0m';
}

function team(project: ClientProject): { id: number; name: string }[] {
    return (project.members ?? []).filter((member): member is { id: number; name: string } => Boolean(member.name));
}

function overdue(project: ClientProject): boolean {
    return Boolean(project.deadline) && (project.deadline as string) < today && !['completed', 'archived', 'cancelled'].includes(project.status);
}

function progressPercent(counts: TaskCounts): number {
    return counts.total === 0 ? 0 : Math.round((counts.complete / counts.total) * 100);
}

const confirmOpen = ref(false);
const deactivating = ref(false);

function confirmDeactivate(): void {
    if (deactivating.value) {
        return;
    }

    deactivating.value = true;

    router.post(
        `/admin/clients/${client.value.id}/deactivate`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                deactivating.value = false;
                confirmOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="client.name" />

    <PageShell :title="client.name">
        <!-- Polish 050 — image 4's header: avatar, name + status, and the contact under the name. -->
        <div class="flex min-w-0 flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-center gap-4">
                <ClientAvatar :id="client.id" :name="client.name" size="lg" />
                <div class="flex min-w-0 flex-col gap-1.5">
                    <div class="flex min-w-0 flex-wrap items-center gap-3">
                        <p class="text-2xl font-bold tracking-tight break-words sm:text-3xl" aria-hidden="true">{{ client.name }}</p>
                        <StatusPill :label="client.status_label" :tone="client.status === 'active' ? 'done' : 'todo'" />
                    </div>
                    <div
                        v-if="primary"
                        class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground"
                        data-testid="client-contact"
                    >
                        <span class="font-medium text-foreground">
                            {{ primary.name }}<span v-if="primary.role" class="font-normal text-muted-foreground"> · {{ primary.role }}</span>
                        </span>
                        <a
                            v-if="primary.email"
                            :href="`mailto:${primary.email}`"
                            class="flex min-w-0 items-center gap-1.5 break-all hover:text-foreground hover:underline"
                        >
                            <Mail class="size-4 shrink-0" aria-hidden="true" />
                            {{ primary.email }}
                        </a>
                        <a
                            v-if="primary.phone"
                            :href="`tel:${primary.phone}`"
                            class="flex items-center gap-1.5 hover:text-foreground hover:underline"
                        >
                            <Phone class="size-4 shrink-0" aria-hidden="true" />
                            {{ primary.phone }}
                        </a>
                        <span v-if="contacts.length > 1" class="text-xs">+{{ contacts.length - 1 }} more contacts</span>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">No contact recorded</p>
                    <!-- Polish 073: the overview numbers as one quiet line (the two big stat cards
                         and the "Websites & Businesses" heading are gone). -->
                    <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-muted-foreground" data-testid="client-summary">
                        <span class="inline-flex items-center gap-1.5">
                            <FolderKanban class="size-4 shrink-0" aria-hidden="true" />
                            <span class="font-semibold text-foreground tabular-nums">{{ websites.length }}</span>
                            {{ websites.length === 1 ? 'website' : 'websites' }}
                        </span>
                        <span class="inline-flex min-w-0 flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5">
                                <ListTodo class="size-4 shrink-0" aria-hidden="true" />
                                <span class="font-semibold text-foreground tabular-nums">{{ clientTasks.total }}</span>
                                {{ clientTasks.total === 1 ? 'task' : 'tasks' }}
                            </span>
                            <TaskCountChips :counts="clientTasks" stacked />
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="client.permissions?.can_update" as-child variant="outline">
                    <Link :href="`/admin/clients/${client.id}/edit`">
                        <Pencil aria-hidden="true" />
                        Edit
                    </Link>
                </Button>
                <Button
                    v-if="client.status === 'active' && client.permissions?.can_delete"
                    type="button"
                    variant="destructive"
                    @click="confirmOpen = true"
                >
                    <UserX aria-hidden="true" />
                    Deactivate
                </Button>
            </div>
        </div>

        <Tabs v-model="tab" class="min-w-0 gap-4">
            <!-- Image 4's underline tabs. Polish 068: no scrollbar beside them (the tabs still
                 scroll sideways at 375 without one); the Overview's status filter and Add Website
                 sit at the end of this row. -->
            <div class="flex min-w-0 max-w-full flex-wrap items-center gap-x-2 gap-y-2 border-b 2xl:flex-nowrap">
                <div class="min-w-0 flex-1 basis-full overflow-x-auto overflow-y-hidden [scrollbar-width:none] 2xl:basis-auto [&::-webkit-scrollbar]:hidden">
                <TabsList class="h-auto gap-2 rounded-none bg-transparent p-0">
                    <TabsTrigger
                        v-for="item in [
                            { value: 'overview', label: 'Overview' },
                            { value: 'notes', label: 'Internal notes' },
                            { value: 'files', label: 'Files' },
                            { value: 'activity', label: 'Activity' },
                        ]"
                        :key="item.value"
                        :value="item.value"
                        class="-mb-px h-10 flex-none rounded-none border-0 border-b-2 border-transparent bg-transparent px-3 text-muted-foreground shadow-flat data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-foreground dark:data-[state=active]:border-primary dark:data-[state=active]:bg-transparent"
                    >
                        {{ item.label }}
                    </TabsTrigger>
                </TabsList>
                </div>
                <div v-if="tab === 'overview'" class="flex max-w-full shrink-0 flex-wrap items-center gap-2 pb-2 2xl:pb-1">
                    <NativeSelect
                        v-if="websites.length > 1"
                        v-model="projectWebsite"
                        aria-label="Show one website"
                        class="h-9 max-w-60"
                    >
                        <option value="">All websites</option>
                        <option v-for="site in websites" :key="site.key" :value="site.key">
                            {{ site.name }}
                        </option>
                    </NativeSelect>
                    <NativeSelect v-model="projectStatus" aria-label="Filter websites by status" class="h-9">
                        <option value="">All statuses</option>
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                    <!-- Polish 070/071: black (the text colour; light in dark mode), not a second red
                         button beside Deactivate. -->
                    <Button as-child class="h-9 bg-foreground text-background hover:bg-foreground/85">
                        <Link :href="`/admin/projects/create?client=${client.id}`">
                            <Plus aria-hidden="true" />
                            Add Website
                        </Link>
                    </Button>
                </div>
            </div>

            <!--
                Every panel is a tab stop: reka gives it `tabindex="0"` so a keyboard can reach
                what the strip controls, and the primitive's base class is `outline-none`, so
                the stop painted nothing. The ring goes on here rather than in the generated
                component, exactly as `Pages/Shared/Notifications.vue` does it.
            -->
            <TabsContent value="overview" class="flex min-w-0 flex-col gap-6 rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <section aria-label="Websites &amp; Businesses" class="flex min-w-0 flex-col gap-4">

                    <Card v-if="websites.length === 0" class="min-w-0 p-4">
                        <EmptyState
                            :icon="FolderKanban"
                            tone="progress"
                            title="No websites yet"
                            description="Add the first website or business for this client."
                        />
                    </Card>

                    <p v-else-if="shownWebsites.length === 0" class="text-sm text-muted-foreground">
                        No website matches these filters.
                    </p>

                    <ul v-else class="grid min-w-0 items-start gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        <li v-for="(site, index) in shownWebsites" :key="site.key" class="min-w-0">
                            <Card class="min-w-0 gap-4 p-5" :data-website-card="site.key">
                                <div class="flex min-w-0 flex-wrap items-start gap-4">
                                    <span
                                        :class="cn('flex size-14 shrink-0 items-center justify-center rounded-xl', TILE_SOLID[tileTone(index)])"
                                        aria-hidden="true"
                                    >
                                        <Globe class="size-7" />
                                    </span>
                                    <div class="flex min-w-0 flex-1 basis-40 flex-col gap-1">
                                        <Link
                                            :href="`/admin/projects/${site.main.id}`"
                                            class="text-lg font-semibold break-words hover:underline"
                                        >
                                            {{ site.name }}
                                        </Link>
                                        <a
                                            v-if="site.domain"
                                            :href="domainHref(site.domain)"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="flex min-w-0 items-center gap-1.5 text-sm break-all text-status-progress-fg hover:underline"
                                        >
                                            {{ site.domain }}
                                            <ExternalLink class="size-3.5 shrink-0" aria-hidden="true" />
                                            <span class="sr-only">(opens in a new tab)</span>
                                        </a>
                                        <span v-else class="text-sm text-muted-foreground">Website not added</span>
                                    </div>
                                    <div class="flex basis-full flex-wrap items-center gap-2 sm:basis-auto sm:justify-end">
                                        <StatusPill :label="site.statusLabel" :tone="toneForProjectStatus(site.status)" />
                                        <span class="flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs text-muted-foreground tabular-nums">
                                            <!-- Polish 058: the website's project count (its tasks are in the rows below). -->
                                            <FolderKanban class="size-3.5" aria-hidden="true" />
                                            {{ site.projects.length }} {{ site.projects.length === 1 ? 'project' : 'projects' }}
                                        </span>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    class="text-muted-foreground"
                                                    :aria-label="`More for ${site.name}`"
                                                >
                                                    <MoreHorizontal aria-hidden="true" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuLabel>Open</DropdownMenuLabel>
                                                <DropdownMenuItem v-for="project in site.projects" :key="project.id" as-child>
                                                    <Link :href="`/admin/projects/${project.id}`" class="w-full">
                                                        <component :is="serviceOf(project).icon" aria-hidden="true" />
                                                        {{ project.name }}
                                                    </Link>
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </div>

                                <!-- Polish 057/059: the website's projects as a plain list — name and
                                     status only, no task counts, no dropdown. -->
                                <div class="flex min-w-0 flex-col gap-2 border-t pt-4">
                                    <span class="text-sm font-medium text-muted-foreground">Projects</span>
                                    <ul class="flex min-w-0 flex-col gap-2" :aria-label="`Projects of ${site.name}`">
                                        <li
                                            v-for="project in site.projects"
                                            :key="project.id"
                                            class="flex min-w-0 flex-col gap-2 rounded-md border bg-card p-2.5"
                                            :data-website-project="project.id"
                                        >
                                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1.5">
                                                <Link
                                                    :href="`/admin/projects/${project.id}`"
                                                    :title="project.name"
                                                    class="flex min-w-0 flex-1 basis-40 items-center gap-2 rounded-md text-sm font-medium outline-none hover:underline focus-visible:ring-3 focus-visible:ring-ring"
                                                >
                                                    <span
                                                        :class="cn('flex size-7 shrink-0 items-center justify-center rounded-md border', toneChipClass(serviceOf(project).tone))"
                                                        aria-hidden="true"
                                                    >
                                                        <component :is="serviceOf(project).icon" class="size-3.5" />
                                                    </span>
                                                    <span class="min-w-0 break-words">{{ project.project_type_label ?? project.name }}</span>
                                                </Link>
                                                <!-- Polish 066: time spent on the project / the estimate on its tasks. -->
                                                <span
                                                    class="ml-auto inline-flex shrink-0 items-center gap-1 text-sm font-medium tabular-nums"
                                                    :title="`Time spent on ${project.name} / estimated on its tasks`"
                                                >
                                                    <Clock class="size-3.5 text-muted-foreground" aria-hidden="true" />
                                                    <span class="sr-only">Time spent</span>
                                                    {{ duration(timeOf(project).spent) }}
                                                    <span class="text-muted-foreground">/ {{ timeOf(project).estimate > 0 ? duration(timeOf(project).estimate) : '—' }}</span>
                                                    <span class="sr-only">estimated</span>
                                                </span>
                                            </div>
                                            <!-- Polish 060/066: status, start date, deadline, assigned faces. -->
                                            <!-- Polish 067: one line on a computer — dates drop the year when it is this
                                                 year, nothing wraps, the faces stay at the right. -->
                                            <div class="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1.5 pl-9 text-xs whitespace-nowrap text-muted-foreground sm:flex-nowrap">
                                                <!-- Polish 061: the people assigned to the project (not its manager). -->
                                                <!-- Polish 066: as initials faces (name on hover), not full names. -->
                                                <!-- Polish 079: a little smaller (size-6) and first on the line, before the status. -->
                                                <span
                                                    v-if="team(project).length"
                                                    class="flex shrink-0 items-center -space-x-0.5"
                                                    role="img"
                                                    :aria-label="`Assigned: ${team(project).map((member) => member.name).join(', ')}`"
                                                >
                                                    <TooltipProvider>
                                                        <Tooltip v-for="member in team(project).slice(0, 4)" :key="member.id">
                                                            <TooltipTrigger as-child>
                                                                <Avatar class="size-6 ring-2 ring-card">
                                                                    <AvatarFallback :class="cn('text-xs tracking-tighter', personTone(member.id).avatar)" aria-hidden="true">
                                                                        {{ initials(member.name) }}
                                                                    </AvatarFallback>
                                                                </Avatar>
                                                            </TooltipTrigger>
                                                            <TooltipContent>{{ member.name }}</TooltipContent>
                                                        </Tooltip>
                                                        <Tooltip v-if="team(project).length > 4">
                                                            <TooltipTrigger as-child>
                                                                <span class="inline-flex size-6 items-center justify-center rounded-full bg-muted text-xs ring-2 ring-card" aria-hidden="true">
                                                                    +{{ team(project).length - 4 }}
                                                                </span>
                                                            </TooltipTrigger>
                                                            <TooltipContent>{{ team(project).slice(4).map((member) => member.name).join(', ') }}</TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                </span>
                                                <StatusPill :label="project.status_label" :tone="toneForProjectStatus(project.status)" />
                                                <span v-if="project.start_date" :title="`Start date: ${formatDate(project.start_date)}`" class="inline-flex items-center gap-1 tabular-nums">
                                                    <CalendarDays class="size-3.5" aria-hidden="true" />
                                                    <span>Start</span>
                                                    {{ shortDate(project.start_date) }}
                                                </span>
                                                <span
                                                    v-if="project.deadline"
                                                    :class="cn('inline-flex items-center gap-1 tabular-nums', overdue(project) && 'font-medium text-destructive')"
                                                    :title="`Deadline: ${formatDate(project.deadline)}`"
                                                >
                                                    <CalendarCheck class="size-3.5" aria-hidden="true" />
                                                    <span>Due</span>
                                                    {{ shortDate(project.deadline) }}
                                                </span>
                                            </div>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Polish 063: View workspace removed; cards keep their own height (no gap). -->
                                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                                    <span class="text-xs text-muted-foreground">Task progress</span>
                                    <span
                                        class="h-2 min-w-20 flex-1 overflow-hidden rounded-full bg-muted"
                                        role="progressbar"
                                        :aria-valuenow="progressPercent(site.tasks)"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        :aria-label="`${site.name} task progress`"
                                    >
                                        <span
                                            :class="cn('block h-full rounded-full', BAR_SOLID[tileTone(index)])"
                                            :style="{ width: `${progressPercent(site.tasks)}%` }"
                                        />
                                    </span>
                                    <span class="text-xs tabular-nums">{{ site.tasks.complete }} / {{ site.tasks.total }}</span>
                                </div>
                            </Card>
                        </li>
                    </ul>
                </section>
            </TabsContent>

            <TabsContent value="notes" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <Card class="min-w-0 gap-2">
                    <CardHeader class="flex flex-row items-center justify-between gap-2">
                        <CardTitle class="flex items-center gap-2 text-sm font-medium">
                            <NotebookPen class="size-4 text-muted-foreground" aria-hidden="true" />
                            Internal notes
                        </CardTitle>
                        <Button v-if="client.permissions?.can_update" as-child variant="outline" size="sm">
                            <Link :href="`/admin/clients/${client.id}/edit`">
                                <Pencil aria-hidden="true" />
                                Edit notes
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="client.internal_notes"
                            class="text-sm break-words whitespace-pre-wrap"
                        >{{ client.internal_notes }}</p>
                        <p v-else class="text-sm text-muted-foreground">No internal notes yet.</p>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="files" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <FilePanel
                    :routes="files"
                    :can-upload="canAttach"
                    description="Contracts, brand assets and anything else that belongs to this client rather than one project."
                    empty-description="Attach a contract or a brand pack and it will be here next week."
                />
            </TabsContent>

            <TabsContent value="activity" class="rounded-lg focus-visible:ring-3 focus-visible:ring-ring">
                <Card class="min-w-0 gap-2">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Activity</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="recentActivity.length === 0" class="text-sm text-muted-foreground">
                            Nothing has happened on this client yet.
                        </p>
                        <ol v-else class="flex flex-col gap-0">
                            <li v-for="(entry, index) in recentActivity" :key="index" class="flex gap-3">
                                <div class="flex flex-col items-center">
                                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-status-progress" aria-hidden="true" />
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

    <Dialog v-model:open="confirmOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Deactivate {{ client.name }}?</DialogTitle>
                <DialogDescription>
                    The client stays on record with their projects — they just stop counting as active.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="deactivating" @click="confirmOpen = false">
                    Cancel
                </Button>
                <Button type="button" variant="destructive" :disabled="deactivating" @click="confirmDeactivate">
                    {{ deactivating ? 'Deactivating…' : 'Deactivate' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
