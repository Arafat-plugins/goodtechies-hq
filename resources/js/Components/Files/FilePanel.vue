<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleAlert,
    Download,
    History,
    Paperclip,
    RefreshCw,
    Replace,
    Trash2,
    Upload,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type {
    FileHistoryResponse,
    FileIndexResponse,
    FileRoutes,
    FileSummary,
} from '@/Components/Files/files';
import {
    FILE_ACCEPT,
    FILE_EXTENSIONS,
    FILE_MAX_LABEL,
    formatUploadedAt,
    iconFor,
    rejectionFor,
} from '@/Components/Files/files';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';
import { Label } from '@/Components/ui/label';
import { Skeleton } from '@/Components/ui/skeleton';
import { flashSeq, lastFlash } from '@/lib/flashChannel';

/**
 * The attachments on one record: list, upload, replace, delete, download.
 *
 * One component for all three owners because a file on a task, a project and a client is the
 * same object with the same four verbs — the only difference is the URLs, which arrive as a
 * prop. A second panel per owner is how the project Files tab ends up with a different idea of
 * what "delete" means from the task drawer's.
 *
 * **Why it fetches.** The index endpoint answers JSON (`ManagesFiles::fileIndex`), not an
 * Inertia page, so the list is the panel's own to hold. Every *write* is an ordinary Inertia
 * visit that comes back `back()->with('success'|'error')`, which the layout's `FlashMessage`
 * renders — nothing here toasts, because a message said twice is DESIGN.md §5.19's one
 * forbidden thing. After a write the list is re-fetched rather than patched: the server may
 * have promoted a surviving version back to current, and a freshly minted URL is the only kind
 * worth holding.
 *
 * **Why the links go stale.** `url` is signed and expires in fifteen minutes, and the download
 * route re-runs `FilePolicy::view` on top of that. So the panel watches `url_expires_at`, never
 * rebuilds a URL of its own, and re-fetches instead of clicking something it knows is dead.
 *
 * **Why history is a second fetch.** A chain is not part of a file's payload — it cannot be, as
 * `FileResource` explains — so the disclosure asks `routes.history(id)` when somebody opens it,
 * the same way the list asks `routes.index` on mount. Fetched on open rather than with the list
 * because most readers never open it, and each version in it carries a freshly signed URL that
 * would otherwise have been minted and left to expire unread. The endpoint answers 404 for a
 * file the requester may not see, versions and their count included, so nothing here needs to
 * know a rule the server does not already enforce.
 */

const props = withDefaults(
    defineProps<{
        routes: FileRoutes;
        /**
         * Whether this record accepts new attachments — the owner's own server-resolved
         * `permissions.can_update`, which is the ability `FileService::guardMayAttach()` asks
         * for. Never a role, never an id comparison. Per-file delete and replace come off each
         * file's own `permissions`, not this.
         */
        canUpload?: boolean;
        title?: string;
        description?: string;
        emptyDescription?: string;
    }>(),
    {
        canUpload: false,
        title: 'Files',
        description: 'Briefs, deliverables and anything else worth keeping with this record.',
        emptyDescription: 'Anything attached to this record shows up here.',
    },
);

/** A write landed. A mount that holds its own fetched payload re-reads it here. */
const emit = defineEmits<{ changed: [] }>();

const uid = useId();
const pickerId = `${uid}-file`;
const hintId = `${uid}-hint`;
const errorId = `${uid}-error`;
const confirmButtonId = `${uid}-confirm`;

const files = ref<FileSummary[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
/** Bumped per fetch so a slow answer for a list that has moved on cannot land in it. */
const token = ref(0);

/** `'new'` attaches to the record; a number uploads a replacement for that file. */
const target = ref<number | 'new'>('new');
const picked = ref<File | null>(null);
const pickedError = ref<string | null>(null);
/** Whatever the server said about the field, rendered under it unchanged. */
const serverError = ref<string | null>(null);
const uploading = ref(false);
const pickerEl = ref<HTMLInputElement | null>(null);

const confirming = ref<number | null>(null);
const busy = ref<number | null>(null);

const replacingName = computed(
    () => files.value.find((file) => file.id === target.value)?.name ?? null,
);

const fieldError = computed(() => pickedError.value ?? serverError.value);

/* ------------------------------------------------------------------------ reading */

async function load(): Promise<void> {
    const mine = ++token.value;

    try {
        const response = await fetch(props.routes.index, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (mine !== token.value) {
            return;
        }

        if (!response.ok) {
            // 404 is "you may not see the record these hang off", said the way the backend
            // says it everywhere. There is no 403 to distinguish it from.
            loadError.value = response.status === 404
                ? 'These files are not available.'
                : 'The files could not be loaded.';

            return;
        }

        const body = (await response.json()) as Partial<FileIndexResponse>;

        if (mine !== token.value) {
            return;
        }

        files.value = body.files ?? [];
        loadError.value = null;
    } catch {
        if (mine === token.value) {
            loadError.value = 'The files could not be loaded.';
        }
    } finally {
        if (mine === token.value) {
            loading.value = false;
        }
    }
}

function retry(): void {
    loading.value = true;
    loadError.value = null;
    void load();
}

/* ------------------------------------------------------------- version history */

/**
 * One file's chain, as the disclosure holds it: oldest first, the current version among them.
 *
 * Keyed by the CURRENT row's id, which is the id the disclosure hangs off and the id
 * `routes.history()` is asked about. A replacement gives the chain a new head with a new id, so
 * a write drops the whole cache rather than trying to patch a key that no longer names a row.
 */
interface Chain {
    loading: boolean;
    error: string | null;
    rows: FileSummary[];
}

const openChain = ref<Record<number, boolean>>({});
const chains = ref<Record<number, Chain>>({});
/** Per file, the same guard `token` is for the list: a slow answer cannot land in a newer one. */
const chainToken = ref<Record<number, number>>({});

function toggleChain(file: FileSummary, open: boolean): void {
    openChain.value[file.id] = open;

    if (open) {
        // Re-fetched on every open rather than cached forever: the links in it expire, and the
        // chain may have grown since the last time somebody looked.
        void loadChain(file.id);
    }
}

async function loadChain(id: number): Promise<void> {
    const mine = (chainToken.value[id] ?? 0) + 1;
    chainToken.value[id] = mine;

    chains.value[id] = {
        loading: true,
        error: null,
        // Keep whatever is on screen while the refresh is in flight, so a ticker-driven
        // re-mint does not blank an open disclosure.
        rows: chains.value[id]?.rows ?? [],
    };

    try {
        const response = await fetch(props.routes.history(id), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (chainToken.value[id] !== mine) {
            return;
        }

        if (!response.ok) {
            // 404 is "you may not see this file", said the way the backend says it everywhere.
            // There is no 403 to distinguish it from, and no count leaks out of the difference.
            chains.value[id] = {
                loading: false,
                error: response.status === 404
                    ? 'This history is not available.'
                    : 'The version history could not be loaded.',
                rows: [],
            };

            return;
        }

        const body = (await response.json()) as Partial<FileHistoryResponse>;

        if (chainToken.value[id] !== mine) {
            return;
        }

        chains.value[id] = { loading: false, error: null, rows: body.versions ?? [] };
    } catch {
        if (chainToken.value[id] === mine) {
            chains.value[id] = {
                loading: false,
                error: 'The version history could not be loaded.',
                rows: [],
            };
        }
    }
}

/** The rows on screen for this file, or nothing while it has never been opened. */
function chainOf(id: number): Chain | undefined {
    return chains.value[id];
}

function chainStale(id: number): boolean {
    return rowsStale(chains.value[id]?.rows ?? []);
}

function refreshStaleChains(): void {
    for (const [key, open] of Object.entries(openChain.value)) {
        const id = Number(key);

        if (open && chainStale(id) && !chains.value[id]?.loading) {
            void loadChain(id);
        }
    }
}

/**
 * Forget every chain. Called when the panel is pointed at a different record, and after any
 * write — a replacement, a delete and a promotion all change what a chain contains, and one of
 * them changes which id it is keyed by.
 */
function resetChains(): void {
    openChain.value = {};
    chains.value = {};
    chainToken.value = {};
}

/* --------------------------------------------------------------- expiring links */

/**
 * How long before the stamped expiry a link is treated as dead. A click that leaves here and
 * arrives after the signature lapsed is a 403 the reader cannot act on, so the margin buys the
 * round trip.
 */
const EXPIRY_MARGIN_MS = 30_000;
const TICK_MS = 15_000;

const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;

/** A batch of rows is minted in one request, so the earliest expiry is the batch's expiry. */
function earliestExpiry(rows: FileSummary[]): number | null {
    let earliest: number | null = null;

    for (const file of rows) {
        const at = Date.parse(file.url_expires_at);

        if (!Number.isNaN(at) && (earliest === null || at < earliest)) {
            earliest = at;
        }
    }

    return earliest;
}

/** Reads `now`, so anything calling it in the template re-renders when the ticker moves. */
function rowsStale(rows: FileSummary[]): boolean {
    const at = earliestExpiry(rows);

    return at !== null && now.value >= at - EXPIRY_MARGIN_MS;
}

const expiresAt = computed(() => earliestExpiry(files.value));

const linksStale = computed(
    () => expiresAt.value !== null && now.value >= expiresAt.value - EXPIRY_MARGIN_MS,
);

/**
 * Refresh only while somebody is looking. A page left open in a background tab should not be
 * asking the server for fresh signatures all afternoon; one on screen should never show a link
 * it knows is dead.
 *
 * An open history was minted by its own request and expires on its own clock, so it is
 * refreshed alongside the list rather than being left holding dead links behind an open
 * disclosure.
 */
function refreshIfStale(): void {
    now.value = Date.now();

    if (uploading.value || document.visibilityState !== 'visible') {
        return;
    }

    if (linksStale.value) {
        void load();
    }

    refreshStaleChains();
}

onMounted(() => {
    void load();
    ticker = setInterval(refreshIfStale, TICK_MS);
    document.addEventListener('visibilitychange', refreshIfStale);
});

onBeforeUnmount(() => {
    clearInterval(ticker);
    document.removeEventListener('visibilitychange', refreshIfStale);
});

/** A drawer reused for a second record points the same panel at different URLs. */
watch(
    () => props.routes.index,
    () => {
        files.value = [];
        loading.value = true;
        resetPicker();
        resetChains();
        confirming.value = null;
        void load();
    },
);

/* ------------------------------------------------------------------------ writing */

function resetPicker(): void {
    target.value = 'new';
    picked.value = null;
    pickedError.value = null;
    serverError.value = null;

    if (pickerEl.value) {
        // A file input's value is not bound, so clearing the model is not clearing the field.
        pickerEl.value.value = '';
    }
}

function choose(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    picked.value = file;
    serverError.value = null;
    pickedError.value = file === null ? null : rejectionFor(file);
}

function replace(file: FileSummary): void {
    confirming.value = null;
    target.value = file.id;
    picked.value = null;
    pickedError.value = null;
    serverError.value = null;

    if (pickerEl.value) {
        pickerEl.value.value = '';
    }

    void nextTick(() => pickerEl.value?.focus());
}

function upload(): void {
    const file = picked.value;

    if (file === null || pickedError.value !== null || uploading.value) {
        return;
    }

    const to = target.value;
    const url = to === 'new' ? props.routes.store : props.routes.version(to);

    uploading.value = true;
    serverError.value = null;

    // What the server says about THIS write. Read from the flash channel rather than from
    // `page.props.flash`, because a screen that has called `useFlashAsToast()` — the task
    // detail and the task list's drawer both do — consumes the bag synchronously as the page
    // lands, strictly before any `onSuccess` runs. Reading the props there finds it already
    // empty, so a refusal would read as an acceptance and the panel would clear the picker on
    // a file it never stored. `flashSeq` moving is what says a flash arrived at all.
    const mark = flashSeq.value;

    router.post(
        url,
        { file },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                // A `FileStateException` comes back 200 with a flashed sentence, so a 2xx is
                // not on its own an acceptance. The flash itself is announced elsewhere —
                // by the toaster on a screen that claimed it, by `FlashMessage` otherwise,
                // which is why both channels are asked and neither is spoken here.
                const refused = flashSeq.value !== mark
                    ? lastFlash.error !== null
                    : Boolean(page.props.flash?.error);

                if (!refused) {
                    resetPicker();
                }

                resetChains();
                void load();
                emit('changed');
            },
            onError: (errors) => {
                serverError.value = errors.file ?? 'That upload was refused.';
            },
            onFinish: () => {
                uploading.value = false;
            },
        },
    );
}

function confirmDelete(file: FileSummary): void {
    confirming.value = file.id;

    void nextTick(() => document.getElementById(confirmButtonId)?.focus());
}

function destroy(file: FileSummary): void {
    if (busy.value !== null) {
        return;
    }

    busy.value = file.id;

    router.delete(props.routes.destroy(file.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirming.value = null;
            // A delete can promote the newest survivor back to current, which gives the chain a
            // different head — so nothing cached about it is still true.
            resetChains();
            void load();
            emit('changed');
        },
        onFinish: () => {
            busy.value = null;
        },
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">{{ title }}</CardTitle>
            <CardDescription>{{ description }}</CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-4">
            <!-- Loading -->
            <div v-if="loading" class="flex flex-col gap-3" aria-busy="true">
                <span class="sr-only">Loading files</span>
                <div v-for="row in 3" :key="row" class="flex items-center gap-3">
                    <Skeleton class="size-8 shrink-0 rounded-md" />
                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <Skeleton class="h-4 w-1/2" />
                        <Skeleton class="h-3 w-1/3" />
                    </div>
                </div>
            </div>

            <!-- The list could not be read at all -->
            <EmptyState
                v-else-if="loadError"
                :icon="CircleAlert"
                variant="error"
                title="Files could not be loaded"
                :description="loadError"
            >
                <template #action>
                    <Button type="button" variant="outline" size="sm" @click="retry">
                        <RefreshCw aria-hidden="true" />
                        Try again
                    </Button>
                </template>
            </EmptyState>

            <template v-else>
                <!--
                    Every link in the list expired together. Nothing here is offered as a
                    download while that is true — a dead link is worse than an honest refusal.
                -->
                <div
                    v-if="linksStale && files.length > 0"
                    class="flex flex-col gap-2 rounded-md border bg-muted/40 p-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-muted-foreground">
                        These download links have expired.
                    </p>
                    <!-- `load()`, not `retry()`: the list is fine, only its signatures are old. -->
                    <Button type="button" variant="outline" size="sm" class="shrink-0" @click="load">
                        <RefreshCw aria-hidden="true" />
                        Refresh links
                    </Button>
                </div>

                <EmptyState
                    v-if="files.length === 0"
                    :icon="Paperclip"
                    title="No files yet"
                    :description="emptyDescription"
                />

                <ul v-else class="divide-y">
                    <li v-for="file in files" :key="file.id" class="flex min-w-0 flex-col gap-2 py-3">
                        <div
                            class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <component
                                    :is="iconFor(file)"
                                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col gap-1">
                                    <a
                                        v-if="!linksStale"
                                        :href="file.url"
                                        :target="file.is_previewable ? '_blank' : undefined"
                                        rel="noopener noreferrer"
                                        class="rounded-sm text-sm font-medium break-all hover:underline focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    >
                                        {{ file.name }}
                                        <span v-if="file.is_previewable" class="sr-only">
                                            (opens in a new tab)
                                        </span>
                                    </a>
                                    <span v-else class="text-sm font-medium break-all">{{ file.name }}</span>

                                    <p class="text-xs text-muted-foreground">
                                        {{ file.size_label }} ·
                                        {{ file.uploaded_by?.name ?? 'Unknown uploader' }} ·
                                        {{ formatUploadedAt(file.uploaded_at) }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-1">
                                <!-- The number, not a tint: a version is a fact, not a status. -->
                                <Badge v-if="file.version > 1" variant="outline" class="mr-1">
                                    Version {{ file.version }}
                                </Badge>

                                <Button
                                    v-if="!linksStale"
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                >
                                    <a
                                        :href="file.url"
                                        :target="file.is_previewable ? '_blank' : undefined"
                                        rel="noopener noreferrer"
                                        :aria-label="`Download ${file.name}`"
                                    >
                                        <Download aria-hidden="true" />
                                    </a>
                                </Button>

                                <Button
                                    v-if="file.permissions.can_replace"
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    :disabled="uploading"
                                    :aria-label="`Upload a new version of ${file.name}`"
                                    @click="replace(file)"
                                >
                                    <Replace aria-hidden="true" />
                                </Button>

                                <Button
                                    v-if="file.permissions.can_delete"
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    :disabled="busy === file.id"
                                    :aria-label="`Delete ${file.name}`"
                                    @click="confirmDelete(file)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </div>
                        </div>

                        <!--
                            Inline, named, and in the row it belongs to. Deleting is not
                            superseding: this removes the bytes, and the sentence says so.
                        -->
                        <div
                            v-if="confirming === file.id"
                            class="flex flex-col gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                            @keydown.esc="confirming = null"
                        >
                            <p class="text-sm">
                                Delete <span class="font-medium break-all">{{ file.name }}</span>? The
                                file is removed for everyone and cannot be recovered.
                            </p>
                            <div class="flex shrink-0 gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="busy === file.id"
                                    @click="confirming = null"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    :id="confirmButtonId"
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    :disabled="busy === file.id"
                                    @click="destroy(file)"
                                >
                                    {{ busy === file.id ? 'Deleting…' : 'Delete' }}
                                </Button>
                            </div>
                        </div>

                        <!--
                            History, out of the way and fetched when it is opened.

                            Shown when the server says this row is past version 1, which is the
                            only honest signal available before the fetch: the chain itself is
                            never in the list payload. A chain whose earlier rows have all been
                            deleted still opens and says so, because "version 3" with nothing
                            behind it is a fact worth stating rather than a disclosure that
                            refuses to open.

                            `Collapsible` is reka-ui's, so the trigger is a real button with
                            aria-expanded and aria-controls and works from the keyboard.
                        -->
                        <Collapsible
                            v-if="file.version > 1"
                            :open="openChain[file.id] === true"
                            class="min-w-0"
                            @update:open="toggleChain(file, $event)"
                        >
                            <CollapsibleTrigger
                                class="group flex items-center gap-2 rounded-sm text-xs text-muted-foreground hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <History class="size-3 shrink-0" aria-hidden="true" />
                                <span>Version history of {{ file.name }}</span>
                                <ChevronDown
                                    class="size-3 shrink-0 transition-transform group-data-[state=open]:rotate-180"
                                    aria-hidden="true"
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <div class="mt-2 min-w-0 border-l pl-3">
                                    <p
                                        v-if="chainOf(file.id)?.loading && (chainOf(file.id)?.rows.length ?? 0) === 0"
                                        class="text-xs text-muted-foreground"
                                        aria-busy="true"
                                    >
                                        Loading version history…
                                    </p>

                                    <p
                                        v-else-if="chainOf(file.id)?.error"
                                        class="flex items-start gap-2 text-xs text-destructive"
                                    >
                                        <CircleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                                        {{ chainOf(file.id)?.error }}
                                    </p>

                                    <!--
                                        The whole chain, oldest first, the current version
                                        among them — so "which one am I looking at" is answered
                                        in the list rather than inferred from its absence. The
                                        current row is named in words as well as tinted: no
                                        state here is carried by colour alone.
                                    -->
                                    <ul
                                        v-else-if="(chainOf(file.id)?.rows.length ?? 0) > 0"
                                        class="flex flex-col gap-2"
                                    >
                                        <li
                                            v-for="version in chainOf(file.id)?.rows"
                                            :key="version.id"
                                            class="flex min-w-0 flex-col gap-0.5"
                                        >
                                            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                                <a
                                                    v-if="!chainStale(file.id)"
                                                    :href="version.url"
                                                    rel="noopener noreferrer"
                                                    class="rounded-sm text-xs break-all hover:underline focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                >
                                                    Version {{ version.version }} — {{ version.name }}
                                                </a>
                                                <span v-else class="text-xs break-all">
                                                    Version {{ version.version }} — {{ version.name }}
                                                </span>

                                                <Badge
                                                    v-if="version.is_current"
                                                    variant="secondary"
                                                    class="shrink-0"
                                                >
                                                    Current
                                                </Badge>
                                            </div>
                                            <span class="text-xs text-muted-foreground">
                                                {{ version.size_label }} ·
                                                {{ version.uploaded_by?.name ?? 'Unknown uploader' }} ·
                                                {{ formatUploadedAt(version.uploaded_at) }}
                                            </span>
                                        </li>
                                    </ul>

                                    <p v-else class="text-xs text-muted-foreground">
                                        No earlier versions are still stored.
                                    </p>

                                    <p
                                        v-if="chainStale(file.id) && (chainOf(file.id)?.rows.length ?? 0) > 0"
                                        class="mt-2 text-xs text-muted-foreground"
                                    >
                                        These download links have expired. Refreshing…
                                    </p>
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    </li>
                </ul>

                <!--
                    One picker for both writes. Which one it is, is the label's job: pointing it
                    at a file turns the same control into that file's next version, and Cancel
                    points it back at the record.
                -->
                <form
                    v-if="canUpload"
                    class="flex min-w-0 flex-col gap-2 border-t pt-4"
                    novalidate
                    @submit.prevent="upload"
                >
                    <Label :for="pickerId" class="text-xs text-muted-foreground">
                        <template v-if="replacingName">
                            New version of <span class="font-medium break-all">{{ replacingName }}</span>
                        </template>
                        <template v-else>Add a file</template>
                    </Label>

                    <input
                        :id="pickerId"
                        ref="pickerEl"
                        type="file"
                        :accept="FILE_ACCEPT"
                        :disabled="uploading"
                        :aria-describedby="fieldError ? `${errorId} ${hintId}` : hintId"
                        :aria-invalid="fieldError ? true : undefined"
                        class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-xs transition-[color,box-shadow] outline-none file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-2 file:py-1 file:text-sm file:font-medium file:text-secondary-foreground disabled:cursor-not-allowed disabled:opacity-50 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 dark:bg-input/30"
                        @change="choose"
                    >

                    <p :id="hintId" class="text-xs text-muted-foreground">
                        Up to {{ FILE_MAX_LABEL }}. {{ FILE_EXTENSIONS.join(', ') }}.
                    </p>

                    <p
                        v-if="fieldError"
                        :id="errorId"
                        class="flex items-start gap-2 text-xs text-destructive"
                    >
                        <CircleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                        {{ fieldError }}
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            :disabled="uploading || picked === null || pickedError !== null"
                        >
                            <Upload aria-hidden="true" />
                            {{ uploading ? 'Uploading…' : replacingName ? 'Upload new version' : 'Upload' }}
                        </Button>
                        <Button
                            v-if="replacingName"
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="uploading"
                            @click="resetPicker"
                        >
                            <X aria-hidden="true" />
                            Cancel
                        </Button>
                    </div>
                </form>
            </template>
        </CardContent>
    </Card>
</template>
