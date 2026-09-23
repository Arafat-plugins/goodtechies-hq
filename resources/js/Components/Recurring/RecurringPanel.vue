<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { History, Pencil, Play, Plus, Repeat, ShieldAlert, TriangleAlert } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import DataTable from '@/Components/DataTable/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import RecurringLogDialog from '@/Components/Recurring/RecurringLogDialog.vue';
import RecurringTemplateDialog from '@/Components/Recurring/RecurringTemplateDialog.vue';
import type { RecurringTemplate } from '@/Components/Recurring/recurring';
import { formatDate, loadTemplates, recurringRoutes, stateOf } from '@/Components/Recurring/recurring';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { DropdownMenuItem } from '@/Components/ui/dropdown-menu';
import { Skeleton } from '@/Components/ui/skeleton';
import { useFlashAsToast } from '@/lib/flashChannel';

/**
 * The Recurring tab on admin project detail: this project's retainers.
 *
 * The audience is one admin, once a month at most, setting up a retainer that then runs itself.
 * They come back only when something looks wrong — which is why every row carries its next run,
 * its last outcome and, when it has one, the reason it is not going to run at all.
 *
 * ## Read is JSON, write is Inertia
 *
 * The same shape every Phase 2 panel has. `GET …/recurring` answers `{ templates }`, fetched on
 * mount and re-fetched after every write; the writes are ordinary Inertia visits coming back
 * `back()->with(…)`. `preserveState` on those is what keeps this panel — and any dialog open
 * over it — mounted across the redirect.
 *
 * ## Why it claims the flash channel
 *
 * The interesting flashes here are refusals in the server's own words: *"An instance for October
 * 2026 already exists (task #41)."* A flash rendered by the layout's alert strip lands at the top
 * of a long project page, above a tab strip somebody has scrolled past, and under a dialog when
 * one is open. So this panel calls `useFlashAsToast()` for as long as it is mounted, which is as
 * long as the Recurring tab is the open one — the message is MOVED to the toaster, never
 * duplicated (DESIGN.md §5.19).
 *
 * ## Three states, not two
 *
 * A template can be switched on and still produce nothing, because the project it belongs to was
 * cancelled, completed or archived. The engine derives that on every run and never writes it to
 * the row (decision 3-4), so the row itself looks perfectly healthy — which is why `stop_reason`
 * arrives on the payload, asked of the same `RecurringTaskEngine::stopReason()` the 00:05 run
 * asks, and is printed rather than inferred.
 */

const props = defineProps<{
    projectId: number;
    /** `ProjectPolicy`'s answer, from the page's own payload. It decides nothing on its own — */
    canManage: boolean;
    /** the endpoints ask `RecurringTaskPolicy` again, per record. */
    employees: { id: number; name: string | null }[];
}>();

// Claimed while this tab is mounted. See the docblock.
useFlashAsToast();

const routes = computed(() => recurringRoutes(props.projectId));

const templates = ref<RecurringTemplate[]>([]);
const loading = ref(true);
const failure = ref<'forbidden' | 'failed' | null>(null);
/** Bumped per load, so a slow answer for a list that has moved on cannot land in it. */
const token = ref(0);

async function load(): Promise<void> {
    const mine = ++token.value;
    // A re-read after a write keeps the rows on screen: swapping them for a skeleton makes an
    // edit look like the list reloading from nothing.
    loading.value = templates.value.length === 0;

    const result = await loadTemplates(routes.value);

    if (mine !== token.value) {
        return;
    }

    if (result.ok) {
        templates.value = result.payload.templates;
        failure.value = null;
    } else {
        templates.value = [];
        failure.value = result.reason;
    }

    loading.value = false;
}

onMounted(() => void load());

/* ------------------------------------------------------------------ the table */

const columns = computed<ColumnDef<RecurringTemplate>[]>(() => [
    { key: 'title_template', header: 'Template', hideable: false },
    { key: 'recurrence_summary', header: 'Recurrence' },
    { key: 'default_assignee', header: 'Lands on', nowrap: true },
    { key: 'state', header: 'State', cell: 'badge', nowrap: true },
    { key: 'next_run', header: 'Next run', nowrap: true },
    { key: 'last_run', header: 'Last outcome' },
]);

/* ------------------------------------------------------------------ the dialogs */

const editorOpen = ref(false);
const editing = ref<RecurringTemplate | null>(null);

function create(): void {
    editing.value = null;
    editorOpen.value = true;
}

function edit(template: RecurringTemplate): void {
    editing.value = template;
    editorOpen.value = true;
}

const logOpen = ref(false);
const logging = ref<RecurringTemplate | null>(null);

function openLog(template: RecurringTemplate): void {
    logging.value = template;
    logOpen.value = true;
}

/* ---------------------------------------------------------------- generate now */

const generating = ref<number | null>(null);

/**
 * "Generate now" — `RecurringTaskEngine::generate(force: true)` on the server.
 *
 * Nothing about what happens is decided here. `force` skips the due-today check and nothing
 * else: the project's state, the existing-instance check and the unique index all still apply,
 * and the task is still created through `TaskService::create()`. Pressing it twice is therefore
 * a duplicate WARNING in the server's own words, not a second task — which is exactly what the
 * flash says and what the log then shows.
 */
function generate(template: RecurringTemplate): void {
    if (generating.value !== null) {
        return;
    }

    generating.value = template.id;

    router.post(routes.value.generate(template.id), {}, {
        preserveState: true,
        preserveScroll: true,
        // Whatever the outcome — generated, duplicate, stopped — the list has a new last
        // outcome to print, so it is re-read either way.
        onSuccess: () => void load(),
        onFinish: () => {
            generating.value = null;
        },
    });
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <Card class="min-w-0 gap-4 p-4 md:p-6">
            <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <h2 class="text-sm font-medium">Recurring tasks</h2>
                    <p class="text-sm text-muted-foreground">
                        Retainer work that makes itself. One task per period, on one person’s
                        plate, with its checklist already on it.
                    </p>
                </div>
                <Button v-if="canManage" type="button" size="sm" @click="create">
                    <Plus aria-hidden="true" />
                    New template
                </Button>
            </div>

            <div v-if="loading" class="flex flex-col gap-3">
                <Skeleton v-for="line in 3" :key="line" class="h-12 w-full" />
            </div>

            <EmptyState
                v-else-if="failure !== null"
                :icon="ShieldAlert"
                variant="error"
                :title="failure === 'forbidden' ? 'Not yours to set up' : 'The templates did not load'"
                :description="
                    failure === 'forbidden'
                        ? 'Recurring tasks are set up by an Admin.'
                        : 'Something went wrong fetching them. Reload the page to try again.'
                "
            />

            <EmptyState
                v-else-if="templates.length === 0"
                :icon="Repeat"
                title="No recurring tasks on this project"
                description="A retainer that repeats every month — maintenance, an SEO cycle, a report — is set up once here and then generates itself."
            >
                <template #action>
                    <Button v-if="canManage" type="button" size="sm" @click="create">
                        <Plus aria-hidden="true" />
                        Set one up
                    </Button>
                </template>
            </EmptyState>

            <DataTable
                v-else
                id="admin-project-recurring"
                :columns="columns"
                :rows="templates"
                :row-label="(template) => template.title_template"
                noun="template"
                :view-options="false"
            >
                <template #cell-title_template="{ row }">
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="font-medium break-words">{{ row.title_template }}</span>
                        <span v-if="row.checklist_template.length" class="text-xs text-muted-foreground">
                            {{ row.checklist_template.length }}-item checklist
                        </span>
                    </div>
                </template>

                <template #cell-recurrence_summary="{ row }">
                    <span class="text-sm break-words">{{ row.recurrence_summary }}</span>
                </template>

                <template #cell-default_assignee="{ row }">
                    <span v-if="row.default_assignee">{{ row.default_assignee.name ?? 'Unnamed' }}</span>
                    <span v-else class="text-muted-foreground">Unassigned</span>
                </template>

                <!--
                    State, always with its label: DESIGN.md §5.6. "Stopped" is the case a row
                    with only an on/off flag could not show — switched on, and refused by the
                    project it lives on.
                -->
                <template #cell-state="{ row }">
                    <div class="flex min-w-0 flex-col gap-1">
                        <StatusBadge size="sm" v-bind="stateOf(row)" />
                        <span
                            v-if="row.stop_reason"
                            class="flex min-w-0 items-start gap-1 text-xs break-words text-muted-foreground"
                        >
                            <TriangleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                            {{ row.stop_reason }}
                        </span>
                    </div>
                </template>

                <!-- Both dates were computed by RecurrenceRule on the server. -->
                <template #cell-next_run="{ row }">
                    <div class="flex min-w-0 flex-col">
                        <span class="tabular-nums">{{ formatDate(row.next_run.at) }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ row.next_run.period_label ?? row.next_run.period }}
                        </span>
                    </div>
                </template>

                <template #cell-last_run="{ row }">
                    <button
                        v-if="row.last_run"
                        type="button"
                        class="flex min-w-0 flex-col items-start gap-1 rounded-sm text-left focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        @click="openLog(row)"
                    >
                        <StatusBadge
                            size="sm"
                            :status="row.last_run.is_warning ? 'waiting' : 'done'"
                            :label="row.last_run.outcome_label ?? 'Attempted'"
                        />
                        <span class="text-xs break-words text-muted-foreground">{{ row.last_run.sentence }}</span>
                    </button>
                    <span v-else class="text-sm text-muted-foreground">Never run</span>
                </template>

                <template #row-actions="{ row }">
                    <DropdownMenuItem
                        v-if="row.permissions.can_generate"
                        :disabled="generating !== null"
                        @select="generate(row)"
                    >
                        <Play aria-hidden="true" />
                        Generate now
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="openLog(row)">
                        <History aria-hidden="true" />
                        Generation log
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="row.permissions.can_update" @select="edit(row)">
                        <Pencil aria-hidden="true" />
                        Edit
                    </DropdownMenuItem>
                </template>

                <!--
                    The stacked card below `md`. Every control a row has, because the ⋯ menu is
                    the table's and a phone gets the card list instead.
                -->
                <template #card="{ row }">
                    <div class="flex min-w-0 flex-col gap-2">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <StatusBadge size="sm" v-bind="stateOf(row)" />
                            <span class="text-xs text-muted-foreground">{{ row.frequency_label }}</span>
                        </div>
                        <p class="text-sm font-medium break-words">{{ row.title_template }}</p>
                        <p class="text-xs break-words text-muted-foreground">{{ row.recurrence_summary }}</p>
                        <p class="text-xs text-muted-foreground">
                            Next run {{ formatDate(row.next_run.at) }} ·
                            {{ row.next_run.period_label ?? row.next_run.period }} ·
                            {{ row.default_assignee?.name ?? 'Unassigned' }}
                        </p>
                        <p
                            v-if="row.stop_reason"
                            class="flex min-w-0 items-start gap-1 text-xs break-words text-muted-foreground"
                        >
                            <TriangleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                            {{ row.stop_reason }}
                        </p>
                        <p v-if="row.last_run" class="text-xs break-words text-muted-foreground">
                            Last: {{ row.last_run.sentence }}
                        </p>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <Button
                                v-if="row.permissions.can_generate"
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="generating !== null"
                                @click="generate(row)"
                            >
                                <Play aria-hidden="true" />
                                Generate now
                            </Button>
                            <Button type="button" size="sm" variant="outline" @click="openLog(row)">
                                <History aria-hidden="true" />
                                Log
                            </Button>
                            <Button
                                v-if="row.permissions.can_update"
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="edit(row)"
                            >
                                <Pencil aria-hidden="true" />
                                Edit
                            </Button>
                        </div>
                    </div>
                </template>
            </DataTable>
        </Card>

        <RecurringTemplateDialog
            v-model:open="editorOpen"
            :routes="routes"
            :template="editing"
            :employees="employees"
            @saved="load"
        />

        <RecurringLogDialog v-model:open="logOpen" :routes="routes" :template="logging" />
    </div>
</template>
