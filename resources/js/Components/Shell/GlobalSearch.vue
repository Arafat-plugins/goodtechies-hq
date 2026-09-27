<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Building2,
    CalendarDays,
    FileText,
    FolderKanban,
    LoaderCircle,
    MessageSquare,
    Search,
    SquareCheckBig,
    Users,
} from '@lucide/vue';
import { ListboxFilter } from 'reka-ui';
import type { Component, ComponentPublicInstance } from 'vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/Components/ui/button';
import { Command, CommandGroup, CommandItem, CommandList } from '@/Components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import type { NavGroup } from '@/navigation/types';
import { liveGroups } from '@/navigation/types';

/**
 * The command palette — how people actually move around 31 nav rows, and, since Phase 10,
 * how they find the records behind them.
 *
 * Phase 0.5 built it navigation-only and left a note saying the entity groups were "the seams
 * Phase 10 fills … so global entity search extends this component instead of becoming a second
 * one". This is that fill. **Nothing about navigation changed**: the nav rows are still every
 * live row of the current role's nav, still matched by the subsequence scorer below, still
 * grouped by nav group, and still the only thing the palette can show without a round trip.
 *
 * ## Two matchers, deliberately, and they are not the same question
 *
 * Nav rows are matched HERE, in the browser, because the whole list is already on the client
 * and "which of these 31 pages did I mean" is answered instantly or not usefully at all.
 * `cdash` → *Company Dashboard* is a typing shortcut, not a search.
 *
 * Entity rows are matched on the SERVER and can only be matched there, because the answer
 * depends on who is asking. `GET /search` scopes every query to the requester's accessible
 * ids before it ranks anything (`SearchService`), which is a rule no client-side filter could
 * implement and none should try — a palette that fetched everything and filtered it here would
 * be the leak the whole slice exists to prevent.
 *
 * So this component never decides what a person may find. It does not know, and the payload it
 * receives does not say. Even the `href` on each row is resolved by the server for this
 * viewer's own shell, because an employee sent to `/admin/projects/12` meets a 403 dressed up
 * as a link.
 *
 * ## The keyboard model is Phase 0.5's and is untouched
 *
 * `⌘K`/`Ctrl K` to open (ignored while the caret is in a field), `↑↓` through every row in one
 * flat listbox, `↵` to go, `esc` to close and return focus to the trigger. Entity rows join
 * that listbox as further groups rather than as a second pane, so there is no new mode to
 * learn and nothing to tab between.
 *
 * What the entity rows DO add is an announcement: results arrive asynchronously, so a list
 * that changed silently under a typing screen-reader user would be unusable. See
 * `liveMessage`.
 */

const props = defineProps<{
    groups: NavGroup[];
}>();

interface PaletteRow {
    label: string;
    href: string;
    group: string;
    icon: Component;
}

interface PaletteGroup {
    label: string;
    rows: PaletteRow[];
}

const open = ref(false);
const query = ref('');
const trigger = ref<ComponentPublicInstance | null>(null);

/** Every navigable row for this role, in nav order. */
const rows = computed<PaletteRow[]>(() =>
    liveGroups(props.groups).flatMap((group) =>
        group.items.map((item) => ({
            label: item.label,
            href: item.href!,
            group: group.label,
            icon: item.icon,
        })),
    ),
);

/**
 * Subsequence matching, so `cdash` finds `Company Dashboard` and `fin rep` finds
 * `Financial Reports`. The score only orders the results: a hit at the start of a word
 * counts double, and consecutive characters count more than scattered ones. `-1` is "no
 * match". `Command`'s own substring filter never runs, because `filterState.search` stays
 * empty — the input below is a bare `ListboxFilter`, not `CommandInput`, so this function
 * is the only filter.
 */
function fuzzyScore(query: string, text: string): number {
    const needle = query.toLowerCase().replace(/\s+/g, '');
    const haystack = text.toLowerCase();

    if (needle === '') {
        return 0;
    }

    let score = 0;
    let at = 0;
    let streak = 0;

    for (const character of needle) {
        const found = haystack.indexOf(character, at);

        if (found === -1) {
            return -1;
        }

        const startsWord = found === 0 || /[\s\-/]/.test(haystack[found - 1] ?? '');
        score += 1 + streak + (startsWord ? 2 : 0);
        streak = found === at ? streak + 1 : 0;
        at = found + 1;
    }

    return score;
}

/** Matching rows, grouped by their nav group; groups keep nav order, rows go best-first. */
const results = computed<PaletteGroup[]>(() => {
    const scored = rows.value
        .map((row) => ({ row, score: fuzzyScore(query.value, `${row.label} ${row.group}`) }))
        .filter((hit) => hit.score >= 0);

    const grouped: PaletteGroup[] = [];

    for (const { row } of scored) {
        const existing = grouped.find((group) => group.label === row.group);

        if (existing) {
            existing.rows.push(row);
        } else {
            grouped.push({ label: row.group, rows: [row] });
        }
    }

    if (query.value.trim() !== '') {
        const rank = new Map(scored.map((hit) => [hit.row.href, hit.score]));

        for (const group of grouped) {
            group.rows.sort((a, b) => (rank.get(b.href) ?? 0) - (rank.get(a.href) ?? 0));
        }
    }

    return grouped;
});

/** Phase 2 fills `Recent` from the viewer's own history. Still empty, still renders nothing. */
const recent = ref<PaletteRow[]>([]);

/*
|--------------------------------------------------------------------------
| Entity results, from GET /search
|--------------------------------------------------------------------------
*/

/** One record the server decided this viewer may find. Exactly the endpoint's five keys. */
interface EntityRow {
    type: string;
    id: number;
    label: string;
    snippet: string | null;
    href: string;
}

interface EntityGroup {
    type: string;
    label: string;
    results: EntityRow[];
}

/**
 * The shortest term the server will run, mirrored here so the palette does not spend a
 * request per keystroke on a term it already knows is too short. It is a mirror, not the
 * rule: the server answers a one-character term with 200 and an empty body either way
 * (decision M-4), so being wrong here costs a wasted request and never a wrong result.
 */
const MINIMUM_TERM = 2;

/** Long enough that a fast typist sends one request per word, short enough to feel live. */
const DEBOUNCE_MS = 180;

const entityGroups = ref<EntityGroup[]>([]);
const loading = ref(false);
const failed = ref(false);

/** Whether the current term has had an answer yet — so "no matches" is never shown too early. */
const answered = ref(false);

let debounce: ReturnType<typeof setTimeout> | undefined;
let inFlight: AbortController | undefined;

/**
 * What kind of thing each result is, in one word.
 *
 * It mirrors `SearchableType::singular()` and is used for the accessible NAME of every row,
 * not for its visible text: a screen-reader user moving through one flat listbox does not hear
 * the group heading again for each row, so "Buffalo Modular — SEO" on its own never says
 * whether it is a project, a file or a meeting.
 */
const SINGULAR: Record<string, string> = {
    project: 'Project',
    task: 'Task',
    message: 'Message',
    meeting: 'Meeting',
    employee: 'Person',
    client: 'Client',
    file: 'File',
    income: 'Income',
    expense: 'Expense',
};

const ENTITY_ICONS: Record<string, Component> = {
    project: FolderKanban,
    task: SquareCheckBig,
    message: MessageSquare,
    meeting: CalendarDays,
    employee: Users,
    client: Building2,
    file: FileText,
    income: ArrowDownLeft,
    expense: ArrowUpRight,
};

function iconFor(type: string): Component {
    return ENTITY_ICONS[type] ?? FileText;
}

function singularFor(type: string): string {
    return SINGULAR[type] ?? 'Result';
}

/**
 * Ask the server what this term finds.
 *
 * Every request aborts the one before it, which is the only thing standing between a fast
 * typist and results from a term they have moved on from: responses are not guaranteed to
 * arrive in the order they were sent, so without the abort a slow `bu` can land after a fast
 * `buffalo` and leave the wrong list on screen under a selection.
 */
async function fetchEntities(term: string): Promise<void> {
    inFlight?.abort();

    if (term.length < MINIMUM_TERM) {
        entityGroups.value = [];
        loading.value = false;
        failed.value = false;
        answered.value = false;

        return;
    }

    const controller = new AbortController();
    inFlight = controller;

    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(`/search?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const body = (await response.json()) as { groups: EntityGroup[] };

        entityGroups.value = body.groups ?? [];
        answered.value = true;
    } catch (error) {
        // An abort is this component's own doing and is not a failure to report.
        if ((error as Error)?.name === 'AbortError') {
            return;
        }

        // An honest failure state: the nav rows above still work, and the palette says that
        // the record search is what broke rather than silently showing zero results — which
        // would read as "there is nothing", a different and wrong statement.
        entityGroups.value = [];
        failed.value = true;
        answered.value = true;
    } finally {
        if (inFlight === controller) {
            loading.value = false;
            inFlight = undefined;
        }
    }
}

watch(query, (value) => {
    const term = value.trim();

    clearTimeout(debounce);

    // Clear immediately when the term gets too short, so stale rows never sit under a query
    // that could not have produced them.
    if (term.length < MINIMUM_TERM) {
        void fetchEntities(term);

        return;
    }

    debounce = setTimeout(() => void fetchEntities(term), DEBOUNCE_MS);
});

const entityCount = computed(() =>
    entityGroups.value.reduce((total, group) => total + group.results.length, 0),
);

const navCount = computed(() =>
    results.value.reduce((total, group) => total + group.rows.length, 0),
);

const resultCount = computed(() => recent.value.length + navCount.value + entityCount.value);

/**
 * What a screen reader is told, and the reason this component has a live region at all.
 *
 * The nav rows filter synchronously as you type; the entity rows arrive a moment later. A
 * sighted user sees the list grow. Without this, a screen-reader user gets no signal that
 * anything changed — the listbox they are arrowing through silently gains rows underneath
 * them, which is worse than a list that never updates.
 *
 * It is deliberately a COUNT and not the rows themselves: reading out eight results on every
 * keystroke would bury the one thing the user needs to know, which is whether it is worth
 * arrowing down yet. `aria-live="polite"` means it waits for a pause in speech rather than
 * interrupting the letter being typed.
 */
const liveMessage = computed(() => {
    if (query.value.trim() === '') {
        return '';
    }

    if (loading.value) {
        return 'Searching…';
    }

    if (failed.value) {
        return 'Record search is unavailable. Page results are still listed.';
    }

    if (resultCount.value === 0) {
        return 'No results.';
    }

    // Only the halves that exist. "0 pages and 2 records found" is a true sentence and a
    // useless one to hear on every keystroke — the zero is not news, and a live region earns
    // its interruption only by saying something the listener can act on.
    const parts: string[] = [];

    if (navCount.value > 0) {
        parts.push(navCount.value === 1 ? '1 page' : `${navCount.value} pages`);
    }

    if (entityCount.value > 0) {
        parts.push(entityCount.value === 1 ? '1 record' : `${entityCount.value} records`);
    }

    return `${parts.join(' and ')} found.`;
});

/** `⌘K` on a Mac, `Ctrl K` everywhere else; read after mount, never during render. */
const isMac = ref(false);
const shortcutHint = computed(() => (isMac.value ? '⌘K' : 'Ctrl K'));

/**
 * The one window listener. It ignores the shortcut while the caret is in a text field, a
 * textarea or a contenteditable region, so typing `⌘K`/`Ctrl K` inside a form — including
 * the palette's own input — never steals the keystroke.
 */
function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.defaultPrevented || event.altKey || event.key.toLowerCase() !== 'k') {
        return;
    }

    if (!event.metaKey && !event.ctrlKey) {
        return;
    }

    if (isTypingTarget(event.target)) {
        return;
    }

    event.preventDefault();
    open.value = true;
}

onMounted(() => {
    isMac.value = /mac|iphone|ipad|ipod/i.test(navigator.userAgent);
    window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(debounce);
    inFlight?.abort();
});

// Every opening starts from an empty query, never from the last search — and now also from an
// empty result list, so a palette reopened a week later never flashes last week's records
// before the new request lands.
watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
    } else {
        clearTimeout(debounce);
        inFlight?.abort();
        entityGroups.value = [];
        loading.value = false;
        failed.value = false;
        answered.value = false;
    }
});

function go(href: string): void {
    open.value = false;
    router.visit(href);
}

/** Esc and selection both land back on the trigger, not on the body. */
function focusTrigger(): void {
    (trigger.value?.$el as HTMLElement | undefined)?.focus();
}
</script>

<template>
    <Button
        ref="trigger"
        variant="outline"
        :class="
            cn(
                'h-9 gap-2 px-2 font-normal text-muted-foreground',
                'md:w-56 md:justify-start md:px-3',
            )
        "
        @click="open = true"
    >
        <Search class="size-4 shrink-0" aria-hidden="true" />
        <span class="hidden md:inline">Search</span>
        <span class="sr-only md:hidden">Search</span>
        <kbd
            class="ml-auto hidden select-none rounded border bg-muted px-1.5 py-0.5 font-mono text-xs font-medium md:inline-block"
        >
            {{ shortcutHint }}
        </kbd>
    </Button>

    <Dialog v-model:open="open">
        <DialogContent
            class="overflow-hidden p-0 sm:max-w-xl"
            :show-close-button="false"
            @close-auto-focus="
                (event: Event) => {
                    event.preventDefault();
                    focusTrigger();
                }
            "
        >
            <DialogHeader class="sr-only">
                <DialogTitle>Search</DialogTitle>
                <DialogDescription>Jump to any page you can open.</DialogDescription>
            </DialogHeader>
            <Command class="rounded-none">
                <div class="flex h-12 shrink-0 items-center gap-2 border-b px-3">
                    <Search class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <ListboxFilter
                        v-model="query"
                        auto-focus
                        aria-label="Search pages"
                        placeholder="Search pages…"
                        class="h-9 w-full rounded-md bg-transparent text-sm outline-none transition-[color,box-shadow] placeholder:text-muted-foreground focus-visible:ring-3 focus-visible:ring-ring"
                    />
                </div>
                <CommandList class="max-h-80">
                    <!-- Phase 2: the viewer's recent pages. -->
                    <CommandGroup v-if="recent.length" heading="Recent">
                        <CommandItem
                            v-for="row in recent"
                            :key="`recent-${row.href}`"
                            :value="`recent-${row.href}`"
                            class="gap-2"
                            @select="go(row.href)"
                        >
                            <component :is="row.icon" class="size-4" aria-hidden="true" />
                            <span class="truncate">{{ row.label }}</span>
                        </CommandItem>
                    </CommandGroup>

                    <CommandGroup v-for="group in results" :key="group.label" :heading="group.label">
                        <CommandItem
                            v-for="row in group.rows"
                            :key="row.href"
                            :value="row.href"
                            class="gap-2"
                            @select="go(row.href)"
                        >
                            <component :is="row.icon" class="size-4" aria-hidden="true" />
                            <span class="truncate">{{ row.label }}</span>
                        </CommandItem>
                    </CommandGroup>

                    <!--
                        Phase 10: records the SERVER decided this viewer may find. One group
                        per entity type, in the order `SearchableType::inDisplayOrder()` sends
                        them — the palette does not sort, merge or re-rank them, because
                        `ts_rank` scores are only comparable within one type.

                        `:value` and `:key` are `type-id`, never `href`: two finance rows in
                        the same month legitimately share a ledger link, and two rows with the
                        same listbox value would collapse into one selectable item.
                    -->
                    <CommandGroup
                        v-for="group in entityGroups"
                        :key="`entity-${group.type}`"
                        :heading="group.label"
                    >
                        <CommandItem
                            v-for="row in group.results"
                            :key="`${row.type}-${row.id}`"
                            :value="`${row.type}-${row.id}`"
                            class="items-start gap-2"
                            :aria-label="`${singularFor(row.type)}: ${row.label}`"
                            @select="go(row.href)"
                        >
                            <component
                                :is="iconFor(row.type)"
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <!--
                                `min-w-0` is what keeps a long project name or a 120-character
                                snippet from pushing the dialog wider than the viewport at
                                360px: without it a flex child refuses to shrink below its
                                content and `truncate` never gets the chance to fire.
                            -->
                            <span class="flex min-w-0 flex-col">
                                <span class="truncate">{{ row.label }}</span>
                                <!--
                                    Cut on the server from a column SearchableType declares
                                    snippet-safe, never from one the viewer may not read.
                                    `aria-hidden` because the row's accessible name above
                                    already carries the kind and the label, and reading an
                                    ellipsised fragment after it is noise.
                                -->
                                <span
                                    v-if="row.snippet"
                                    class="truncate text-xs text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    {{ row.snippet }}
                                </span>
                            </span>
                        </CommandItem>
                    </CommandGroup>

                    <!--
                        Honest states, in the order they can occur. Each one says what is
                        actually true rather than falling back on "no results", which would be
                        a claim about the database that none of them is entitled to make.
                    -->
                    <p
                        v-if="loading && entityCount === 0"
                        class="flex items-center justify-center gap-2 px-3 py-6 text-sm text-muted-foreground"
                    >
                        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
                        Searching records…
                    </p>

                    <p v-else-if="failed" class="px-3 py-6 text-center text-sm text-muted-foreground">
                        Record search is unavailable right now. The pages above still work.
                    </p>

                    <p
                        v-else-if="resultCount === 0 && query.trim() !== ''"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        <!--
                            "Nothing you can open matches" and not "nothing matches": a search
                            is scoped to the person asking, so this palette is never in a
                            position to say the term appears nowhere.
                        -->
                        Nothing you can open matches “{{ query }}”.
                    </p>

                    <p
                        v-else-if="resultCount === 0"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        Type to search pages and records.
                    </p>
                </CommandList>
                <!--
                    How many results there are, for a screen reader only.

                    The nav rows filter synchronously as you type and the record rows arrive a
                    moment later, so without this the listbox silently gains rows underneath
                    somebody arrowing through it. `polite` waits for a pause rather than
                    interrupting the keystroke; a count rather than the rows themselves,
                    because reading eight labels on every letter buries the one fact that
                    decides whether it is worth arrowing down yet.
                -->
                <div class="sr-only" role="status" aria-live="polite" aria-atomic="true">
                    {{ liveMessage }}
                </div>

                <div
                    class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-t px-3 py-2 text-xs text-muted-foreground"
                >
                    <span>↑↓ navigate</span>
                    <span>↵ select</span>
                    <span>esc close</span>
                </div>
            </Command>
        </DialogContent>
    </Dialog>
</template>
