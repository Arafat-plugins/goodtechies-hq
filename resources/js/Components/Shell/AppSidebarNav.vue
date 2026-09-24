<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { usePagePoll } from '@/lib/pagePoll';
import { cn } from '@/lib/utils';
import { readGroupOpen, writeGroupOpen } from '@/lib/sidebarState';
import type { NavGroup, NavItem } from '@/navigation/types';
import { activeItem, groupHasActive, isPinnedGroup, liveGroups } from '@/navigation/types';

const props = withDefaults(
    defineProps<{
        groups: NavGroup[];
        /** Icon-only rail. The drawer never rails — it is already a drawer. */
        rail?: boolean;
    }>(),
    { rail: false },
);

const emit = defineEmits<{
    navigate: [];
}>();

const page = usePage();

const role = computed(() => page.props.auth.user?.role ?? 'guest');

/**
 * The one row this URL belongs to, resolved across the whole nav rather than row by row.
 *
 * More than one row can legitimately claim a URL — the Tasks row owns the Calendar through
 * its `activePrefix` and the Calendar row points straight at it; My Tasks owns
 * `/admin/my-tasks` and Overdue owns `?bucket=overdue` on the same page. `activeItem()` picks
 * the most specific claim, so exactly one row is ever lit.
 */
const active = computed(() => activeItem(props.groups, page.url));

function isActive(item: NavItem): boolean {
    return active.value === item;
}

/**
 * Only built rows live in the groups; everything with a `phase` is rendered by
 * `SidebarComingSoon.vue` instead (Decision 0.5-3). The split is done here, at render
 * time — `navigation/{admin,employee,accountant}.ts` is untouched, so a row joins its
 * group again the moment its phase lands and it drops the `phase` key.
 */
const displayGroups = computed(() => liveGroups(props.groups));

/** `My work` sits above the first heading with a separator under it, not in a disclosure. */
function isPlain(group: NavGroup): boolean {
    return props.rail || isPinnedGroup(group);
}

/**
 * Expanded: one separator, under the pinned group. Rail: one between every group, since
 * the headings are gone and the rule is all that is left of them.
 */
function separatorBefore(index: number): boolean {
    if (index === 0) {
        return false;
    }

    return props.rail || isPinnedGroup(displayGroups.value[index - 1]);
}

// Admin opens the two groups its people start the day in; the short navs open whole.
// WORK stays open by default while most rows are still phase-gated: with only a handful
// of live links, a closed group hides half the app. Revisit once Phases 2-12 have landed.
const ADMIN_DEFAULT_OPEN = ['my work', 'company', 'work'];

function defaultOpen(group: NavGroup): boolean {
    if (groupHasActive(group, active.value)) {
        return true;
    }

    return role.value === 'ADMIN' ? ADMIN_DEFAULT_OPEN.includes(group.label.toLowerCase()) : true;
}

// What this viewer chose wins over the default, and survives a reload; the default only
// answers for a group they have never touched.
const openState = ref<Record<string, boolean>>({});

function hydrate(groups: NavGroup[]): void {
    const next = { ...openState.value };

    for (const group of groups) {
        if (next[group.label] === undefined) {
            next[group.label] = readGroupOpen(role.value, group.label) ?? defaultOpen(group);
        }
    }

    openState.value = next;
}

hydrate(displayGroups.value);
watch(displayGroups, hydrate);

function isOpen(group: NavGroup): boolean {
    return openState.value[group.label] ?? defaultOpen(group);
}

function setOpen(group: NavGroup, open: boolean): void {
    openState.value = { ...openState.value, [group.label]: open };
    writeGroupOpen(role.value, group.label, open);
}

// `relative` carries the active row's 3 px rail, drawn as a ::before so the
// label never shifts between the active and inactive state.
const rowClass = 'relative flex h-9 items-center gap-2 rounded-md px-3 text-sm';
const railRowClass = 'relative flex h-9 w-full items-center justify-center rounded-md text-sm';

// The whole active treatment: tint fill + rail + weight. No second active style.
const activeClass =
    'bg-sidebar-accent font-medium text-sidebar-accent-foreground hover:bg-brand-tint-strong ' +
    'before:absolute before:inset-y-1 before:left-0 before:w-[3px] before:rounded-full before:bg-sidebar-rail';

// Hover on a non-active row is the neutral wash, never orange.
const inactiveClass =
    'text-sidebar-foreground/80 hover:bg-sidebar-border hover:text-sidebar-accent-foreground';

function itemClass(item: NavItem): string {
    return cn(
        props.rail ? railRowClass : rowClass,
        'transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none',
        isActive(item) ? activeClass : inactiveClass,
    );
}

/**
 * The number on a row, or null for no pill.
 *
 * Read from the page props by the key the nav data names, so this component knows nothing about
 * messages — add a `badgeKey` to a row and the pill follows. Zero is null: a badge that reads 0
 * is telling the reader nothing while asking for their attention.
 */
function badgeFor(item: NavItem): number | null {
    if (!item.badgeKey) {
        return null;
    }

    const value = (page.props as Record<string, unknown>)[item.badgeKey];

    return typeof value === 'number' && value > 0 ? value : null;
}

function badgeLabel(item: NavItem, count: number): string {
    return `${item.label}, ${count} unread`;
}

/**
 * Part 0.5 refresh rule: the badge counts something somebody else does, and the sidebar is on
 * every page — so it is the sidebar that keeps it current, not each page in turn. Mounted twice
 * (rail and drawer) on some viewports; `usePagePoll` is reference-counted, so that is still one
 * interval and one request.
 */
usePagePoll(['messagesUnread']);
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <nav aria-label="Main" :class="cn('flex flex-col', rail ? 'gap-2 p-2' : 'gap-4 p-4')">
            <template v-for="(group, index) in displayGroups" :key="group.label">
                <div
                    v-if="separatorBefore(index)"
                    role="presentation"
                    :class="cn('h-px bg-sidebar-border', rail && 'mx-1')"
                />

                <!-- Pinned group, and every group in rail mode: rows with no disclosure. -->
                <ul v-if="isPlain(group)" class="flex flex-col gap-1">
                    <li v-for="item in group.items" :key="item.label">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Link
                                    :href="item.href!"
                                    :aria-current="isActive(item) ? 'page' : undefined"
                                    :class="itemClass(item)"
                                    @click="emit('navigate')"
                                >
                                    <component :is="item.icon" class="size-4 shrink-0" aria-hidden="true" />
                                    <span :class="rail ? 'sr-only' : 'truncate'">{{ item.label }}</span>
                                    <span
                                        v-if="badgeFor(item) !== null"
                                        :class="
                                            cn(
                                                'shrink-0 rounded-full bg-primary text-xs font-medium tabular-nums text-primary-foreground',
                                                rail
                                                    ? 'absolute top-1 right-1 size-2 p-0'
                                                    : 'ml-auto px-1.5 py-0.5',
                                            )
                                        "
                                    >
                                        <template v-if="!rail">{{ badgeFor(item)! > 99 ? '99+' : badgeFor(item) }}</template>
                                        <span class="sr-only">{{ badgeLabel(item, badgeFor(item)!) }}</span>
                                    </span>
                                </Link>
                            </TooltipTrigger>
                            <TooltipContent v-if="rail" side="right">{{ item.label }}</TooltipContent>
                        </Tooltip>
                    </li>
                </ul>

                <Collapsible
                    v-else
                    :open="isOpen(group)"
                    class="flex flex-col gap-1"
                    @update:open="setOpen(group, $event)"
                >
                    <CollapsibleTrigger
                        class="flex h-7 w-full items-center gap-2 rounded-md px-3 text-xs font-medium tracking-wider text-sidebar-foreground-muted uppercase transition-colors hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none"
                    >
                        <span class="truncate">{{ group.label }}</span>
                        <ChevronDown
                            aria-hidden="true"
                            :class="
                                cn(
                                    'ml-auto size-3.5 shrink-0 transition-transform motion-reduce:transition-none',
                                    !isOpen(group) && '-rotate-90',
                                )
                            "
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <ul class="flex flex-col gap-1">
                            <li v-for="item in group.items" :key="item.label">
                                <Link
                                    :href="item.href!"
                                    :aria-current="isActive(item) ? 'page' : undefined"
                                    :class="itemClass(item)"
                                    @click="emit('navigate')"
                                >
                                    <component :is="item.icon" class="size-4 shrink-0" aria-hidden="true" />
                                    <span class="truncate">{{ item.label }}</span>
                                    <span
                                        v-if="badgeFor(item) !== null"
                                        class="ml-auto shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-xs font-medium tabular-nums text-primary-foreground"
                                    >
                                        {{ badgeFor(item)! > 99 ? '99+' : badgeFor(item) }}
                                        <span class="sr-only">{{ badgeLabel(item, badgeFor(item)!) }}</span>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </CollapsibleContent>
                </Collapsible>
            </template>
        </nav>
    </TooltipProvider>
</template>
