<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, LoaderCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/navigation/types';

/**
 * Polish 031: the Projects row in the Admin sidebar opens like ClickUp's space list —
 * every client (by nickname when it has one) with its task count, and under each client its
 * projects with theirs. A project opens its tasks on the Projects page.
 *
 * The data is fetched the first time the row is opened (`GET /admin/projects/tree`), kept for
 * the tab, and quietly re-read on every later open — the sidebar is on every page and must
 * not make every page pay for it.
 */
interface TreeProject {
    id: number;
    name: string;
    open: number;
    total: number;
}

interface TreeClient {
    key: string;
    label: string;
    name: string | null;
    open: number;
    total: number;
    projects: TreeProject[];
}

const props = defineProps<{
    item: NavItem;
    active: boolean;
    itemClass: string;
}>();

const emit = defineEmits<{ navigate: [] }>();

const page = usePage();

const OPEN_KEY = 'hq.sidebar.projects-open';
const CLIENTS_KEY = 'hq.sidebar.projects-clients';

function read<T>(key: string, fallback: T): T {
    try {
        const raw = window.localStorage.getItem(key);

        return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
        return fallback;
    }
}

function write(key: string, value: unknown): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage off: the dropdown simply forgets.
    }
}

const open = ref<boolean>(read(OPEN_KEY, false));
const openClients = ref<string[]>(read(CLIENTS_KEY, []));

const clients = ref<TreeClient[] | null>(null);
const loading = ref(false);
const failed = ref(false);

async function load(): Promise<void> {
    loading.value = clients.value === null;
    failed.value = false;

    try {
        const response = await fetch('/admin/projects/tree', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        clients.value = ((await response.json()) as { clients: TreeClient[] }).clients;
    } catch {
        failed.value = clients.value === null;
    } finally {
        loading.value = false;
    }
}

watch(
    open,
    (isOpen) => {
        write(OPEN_KEY, isOpen);

        if (isOpen) {
            void load();
        }
    },
    { immediate: true },
);

function toggle(): void {
    open.value = !open.value;
}

function toggleClient(key: string): void {
    openClients.value = openClients.value.includes(key)
        ? openClients.value.filter((value) => value !== key)
        : [...openClients.value, key];
    write(CLIENTS_KEY, openClients.value);
}

/** The project open on the Projects page right now, from `?project=`. */
const currentProject = computed(() => {
    const match = page.url.match(/^\/admin\/projects\?(?:.*&)?project=(\d+)/);

    return match ? Number(match[1]) : null;
});

/** The client of the open project stays open. */
watch(
    [currentProject, clients],
    ([id, list]) => {
        const owner = list?.find((client) => client.projects.some((project) => project.id === id));

        if (owner && !openClients.value.includes(owner.key)) {
            openClients.value = [...openClients.value, owner.key];
            write(CLIENTS_KEY, openClients.value);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="flex flex-col">
        <div class="relative flex items-center">
            <Link
                :href="item.href!"
                :aria-current="active ? 'page' : undefined"
                :class="cn(itemClass, 'flex-1 pr-9')"
                @click="emit('navigate')"
            >
                <component :is="item.icon" class="size-4 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ item.label }}</span>
            </Link>
            <button
                type="button"
                class="absolute right-1 flex size-7 items-center justify-center rounded-md text-sidebar-foreground/70 hover:bg-sidebar-border hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none"
                :aria-expanded="open"
                aria-controls="sidebar-projects-tree"
                :aria-label="open ? 'Hide clients and projects' : 'Show clients and projects'"
                @click="toggle"
            >
                <ChevronRight :class="cn('size-4 transition-transform motion-reduce:transition-none', open && 'rotate-90')" aria-hidden="true" />
            </button>
        </div>

        <div v-if="open" id="sidebar-projects-tree" class="mt-1 ml-4 flex flex-col border-l border-sidebar-border pl-2">
            <p v-if="loading" class="flex items-center gap-2 px-2 py-1.5 text-xs text-sidebar-foreground-muted">
                <LoaderCircle class="size-3.5 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                Loading…
            </p>
            <p v-else-if="failed" class="px-2 py-1.5 text-xs text-sidebar-foreground-muted">
                Could not load.
                <button type="button" class="underline underline-offset-2" @click="load">Try again</button>
            </p>
            <p v-else-if="clients && !clients.length" class="px-2 py-1.5 text-xs text-sidebar-foreground-muted">No projects yet.</p>

            <ul v-else-if="clients" class="flex flex-col gap-0.5">
                <li v-for="client in clients" :key="client.key">
                    <button
                        type="button"
                        class="flex h-8 w-full min-w-0 items-center gap-1.5 rounded-md px-2 text-left text-sm text-sidebar-foreground/80 hover:bg-sidebar-border hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none"
                        :aria-expanded="openClients.includes(client.key)"
                        :title="client.name && client.name !== client.label ? client.name : undefined"
                        @click="toggleClient(client.key)"
                    >
                        <ChevronRight
                            :class="cn('size-3.5 shrink-0 transition-transform motion-reduce:transition-none', openClients.includes(client.key) && 'rotate-90')"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ client.label }}</span>
                        <span class="shrink-0 text-xs text-sidebar-foreground-muted tabular-nums" :title="`${client.total} tasks, ${client.open} open`">
                            {{ client.total }}
                        </span>
                    </button>

                    <ul v-if="openClients.includes(client.key)" class="flex flex-col gap-0.5 pl-5">
                        <li v-for="project in client.projects" :key="project.id">
                            <Link
                                :href="`/admin/projects?project=${project.id}`"
                                :aria-current="currentProject === project.id ? 'page' : undefined"
                                :class="
                                    cn(
                                        'flex h-8 min-w-0 items-center gap-2 rounded-md px-2 text-sm focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none',
                                        currentProject === project.id
                                            ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                            : 'text-sidebar-foreground/80 hover:bg-sidebar-border hover:text-sidebar-accent-foreground',
                                    )
                                "
                                @click="emit('navigate')"
                            >
                                <span class="min-w-0 flex-1 truncate">{{ project.name }}</span>
                                <span class="shrink-0 text-xs text-sidebar-foreground-muted tabular-nums" :title="`${project.total} tasks, ${project.open} open`">
                                    {{ project.total }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</template>
