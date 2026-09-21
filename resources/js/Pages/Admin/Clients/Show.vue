<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FolderKanban, Mail, Pencil, Phone, UserX } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Client } from '@/Components/Clients/ClientForm.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
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
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * A project as `ProjectResource` serializes it. Every finance key is optional: the server
 * omits `finance` entirely for a role that may not see money, so nothing here may assume it.
 */
interface ClientProject {
    id: number;
    name: string;
    project_type_label?: string | null;
    status: string;
    status_label: string;
    priority_label?: string | null;
    deadline?: string | null;
    is_archived?: boolean;
    finance?: {
        price?: string | number | null;
        recurring_amount?: string | number | null;
    };
}

const props = defineProps<{
    client: { data: Client & { projects?: ClientProject[] } };
    activity: { description: string; actor: string | null; at: string }[];
}>();

const client = computed(() => props.client.data);
const projects = computed(() => client.value.projects ?? []);
const contacts = computed(() => client.value.contacts ?? []);

/**
 * There is no currency prop on this payload yet, so USD is hard-coded here — one helper,
 * on the one page that shows money. Move it to a shared place when a currency arrives.
 */
const MONEY = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

function formatMoney(amount: string | number | null | undefined): string | null {
    if (amount === null || amount === undefined || amount === '') {
        return null;
    }

    const value = typeof amount === 'number' ? amount : Number(amount);

    return Number.isFinite(value) ? MONEY.format(value) : null;
}

/** Monthly retainer wins over a one-off price; absent finance renders nothing at all. */
function moneyLine(project: ClientProject): string | null {
    const recurring = formatMoney(project.finance?.recurring_amount);

    if (recurring) {
        return `${recurring}/mo`;
    }

    return formatMoney(project.finance?.price);
}

function formatDeadline(deadline: string | null | undefined): string {
    if (!deadline) {
        return 'No deadline';
    }

    const date = new Date(deadline);

    return Number.isNaN(date.getTime())
        ? deadline
        : `Due ${new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(date)}`;
}

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

    <PageShell :title="client.name" description="Projects, contacts and what has happened lately.">
        <template #actions>
            <StatusPill :label="client.status_label" :tone="client.status === 'active' ? 'done' : 'todo'" />
            <Button as-child variant="outline" size="sm">
                <Link :href="`/admin/clients/${client.id}/edit`">
                    <Pencil aria-hidden="true" />
                    Edit
                </Link>
            </Button>
            <Button
                v-if="client.status === 'active'"
                type="button"
                variant="destructive"
                size="sm"
                @click="confirmOpen = true"
            >
                <UserX aria-hidden="true" />
                Deactivate
            </Button>
        </template>

        <div class="grid min-w-0 items-start gap-4 lg:grid-cols-3">
            <Card class="min-w-0 gap-2 shadow-xs lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Projects</CardTitle>
                </CardHeader>
                <CardContent>
                    <EmptyState
                        v-if="projects.length === 0"
                        :icon="FolderKanban"
                        title="No projects yet"
                        description="Work booked for this client will show up here."
                    />
                    <ul v-else class="divide-y">
                        <li
                            v-for="project in projects"
                            :key="project.id"
                            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <Link
                                    :href="`/admin/projects/${project.id}`"
                                    class="text-sm font-medium break-words hover:underline"
                                >
                                    {{ project.name }}
                                </Link>
                                <p class="text-xs text-muted-foreground">
                                    <span v-if="project.project_type_label">
                                        {{ project.project_type_label }} ·
                                    </span>
                                    {{ formatDeadline(project.deadline) }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                <StatusPill
                                    :label="project.status_label"
                                    :tone="toneForProjectStatus(project.status)"
                                />
                                <span
                                    v-if="moneyLine(project)"
                                    class="text-sm font-medium whitespace-nowrap tabular-nums"
                                >
                                    {{ moneyLine(project) }}
                                </span>
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <div class="flex min-w-0 flex-col gap-4">
                <Card class="min-w-0 gap-2 shadow-xs">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Contacts</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="contacts.length === 0" class="text-sm text-muted-foreground">
                            No contacts recorded for this client.
                        </p>
                        <ul v-else class="divide-y">
                            <li v-for="(contact, index) in contacts" :key="index" class="flex flex-col gap-1 py-3">
                                <p class="text-sm font-medium break-words">{{ contact.name }}</p>
                                <p v-if="contact.role" class="text-xs text-muted-foreground">{{ contact.role }}</p>
                                <a
                                    v-if="contact.email"
                                    :href="`mailto:${contact.email}`"
                                    class="flex items-center gap-2 text-xs break-all text-muted-foreground hover:text-foreground hover:underline"
                                >
                                    <Mail class="size-3 shrink-0" aria-hidden="true" />
                                    {{ contact.email }}
                                </a>
                                <a
                                    v-if="contact.phone"
                                    :href="`tel:${contact.phone}`"
                                    class="flex items-center gap-2 text-xs text-muted-foreground hover:text-foreground hover:underline"
                                >
                                    <Phone class="size-3 shrink-0" aria-hidden="true" />
                                    {{ contact.phone }}
                                </a>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card class="min-w-0 gap-2 shadow-xs">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Internal notes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="client.internal_notes"
                            class="text-sm break-words whitespace-pre-wrap"
                        >{{ client.internal_notes }}</p>
                        <p v-else class="text-sm text-muted-foreground">No internal notes yet.</p>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Card class="min-w-0 gap-2 shadow-xs">
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
