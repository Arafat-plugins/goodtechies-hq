<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
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
 * The command palette — how people actually move around 31 nav rows.
 *
 * Phase 0.5 is navigation-only: every *live* row of the current role's nav, fuzzy matched
 * and grouped by its nav group. The `Recent` and entity groups below are the seams Phase 10
 * fills; they render nothing while they are empty, but the structure stays here so global
 * entity search extends this component instead of becoming a second one.
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

/**
 * Phase 2 fills `Recent` from the viewer's own history and Phase 10 fills the entity
 * groups (Clients, Projects, Tasks, People) from the search endpoint. Both render only
 * when they have rows, so today the palette shows pages and nothing else.
 */
const recent = ref<PaletteRow[]>([]);
const entityGroups = ref<PaletteGroup[]>([]);

const resultCount = computed(
    () =>
        recent.value.length +
        results.value.reduce((total, group) => total + group.rows.length, 0) +
        entityGroups.value.reduce((total, group) => total + group.rows.length, 0),
);

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

onUnmounted(() => window.removeEventListener('keydown', onKeydown));

// Every opening starts from an empty query, never from the last search.
watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
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
                        class="h-9 w-full rounded-md bg-transparent text-sm outline-none transition-[color,box-shadow] placeholder:text-muted-foreground focus-visible:ring-3 focus-visible:ring-ring/50"
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

                    <!-- Phase 10: Clients / Projects / Tasks / People hits from the search endpoint. -->
                    <CommandGroup
                        v-for="group in entityGroups"
                        :key="`entity-${group.label}`"
                        :heading="group.label"
                    >
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

                    <p v-if="resultCount === 0" class="px-3 py-6 text-center text-sm text-muted-foreground">
                        No pages match “{{ query }}”.
                    </p>
                </CommandList>
                <div
                    class="flex shrink-0 items-center gap-3 border-t px-3 py-2 text-xs text-muted-foreground"
                >
                    <span>↑↓ navigate</span>
                    <span>↵ select</span>
                    <span>esc close</span>
                </div>
            </Command>
        </DialogContent>
    </Dialog>
</template>
