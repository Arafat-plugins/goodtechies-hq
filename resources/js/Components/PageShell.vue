<script setup lang="ts">
import { computed, provide, shallowRef } from 'vue';
import { PAGE_ACTIONS, PAGE_TABLE_TOOLS } from '@/lib/pageActions';
import { cn } from '@/lib/utils';

export interface Crumb {
    label: string;
    href?: string;
}

const props = defineProps<{
    /** Page title. Ignored when `greeting` is set. */
    title: string;
    description?: string;
    breadcrumb?: Crumb[];
    /**
     * Renders "Good {morning|afternoon|evening}, {name}" over the date instead of
     * the title and description. `today` is 'YYYY-MM-DD'.
     */
    greeting?: { name: string; today: string };
    /**
     * Keep the `<h1>` for screen readers and draw no visible head: no title, no description,
     * no `actions` slot. For a screen whose own toolbar is its head (the Tasks views).
     */
    titleHidden?: boolean;
    /**
     * A working surface rather than a document: the shell's `<main>` drops its max width and
     * narrows its gutters to 16 px with a 12 px top band (the layouts read `data-page-bleed`
     * with `has-[…]`), and the page's own rhythm tightens to `gap-3`. Opt-in, so every other
     * page keeps the standard padding. The four Tasks views use it (brief 008).
     */
    bleed?: boolean;
}>();

/* The greeting reads the browser clock; `today` comes from the server as 'YYYY-MM-DD'. */
function partOfDay(hour: number): string {
    if (hour < 12) {
        return 'morning';
    }

    return hour < 18 ? 'afternoon' : 'evening';
}

function formatDay(isoDate: string): string {
    const [year, month, day] = isoDate.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    const part = (options: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('en-GB', options).format(date);

    return `${part({ weekday: 'long' })}, ${day} ${part({ month: 'long' })} ${year}`;
}

const heading = computed(() =>
    props.greeting ? `Good ${partOfDay(new Date().getHours())}, ${props.greeting.name}` : props.title,
);

const subline = computed(() => (props.greeting ? formatDay(props.greeting.today) : props.description));

/** Polish 007: the in-page breadcrumb is gone; `breadcrumb` is still accepted and ignored. */
void props.breadcrumb;

/** Where the actions render: a claimed toolbar host, else this shell's own row. */
const actionsHost = shallowRef<HTMLElement | null>(null);
const actionsHome = shallowRef<HTMLElement | null>(null);

/** Polish 013: the first table's view options go to the same toolbar row. */
const toolsHost = shallowRef<HTMLElement | null>(null);
const toolsOwner = shallowRef<symbol | null>(null);

provide(PAGE_TABLE_TOOLS, {
    host: toolsHost,
    owner: toolsOwner,
    claimHost: (el) => {
        if (toolsHost.value !== null) {
            return false;
        }

        toolsHost.value = el;

        return true;
    },
    releaseHost: (el) => {
        if (toolsHost.value === el) {
            toolsHost.value = null;
        }
    },
});

provide(PAGE_ACTIONS, {
    host: actionsHost,
    claim: (el) => {
        if (actionsHost.value !== null) {
            return false;
        }

        actionsHost.value = el;

        return true;
    },
    release: (el) => {
        if (actionsHost.value === el) {
            actionsHost.value = null;
        }
    },
});
</script>

<template>
    <div :class="cn('flex min-w-0 flex-col', bleed ? 'gap-3' : 'gap-6')" :data-page-bleed="bleed || undefined">
        <!--
            Polish 002: no visible page title or description; the heading stays for screen
            readers (absolutely positioned by `sr-only`, so it adds no gap).
            Polish 007: no in-page breadcrumb either — the top bar already shows the same trail.
            The actions go to the end of the page's first toolbar row when that row carries a
            `PageActionsHost`; otherwise they sit in this row, beside the tabs when there are any.
        -->
        <h1 class="sr-only">{{ heading }}</h1>
        <p v-if="subline && !titleHidden" class="sr-only">{{ subline }}</p>

        <div
            v-if="$slots.tabs || ($slots.actions && !actionsHost)"
            class="flex min-w-0 flex-wrap items-center gap-3"
        >
            <div v-if="$slots.tabs" class="min-w-0">
                <slot name="tabs" />
            </div>
            <div
                v-if="$slots.actions && !actionsHost"
                ref="actionsHome"
                class="ml-auto flex shrink-0 flex-wrap items-center gap-2"
            />
        </div>

        <Teleport v-if="$slots.actions && (actionsHost || actionsHome)" :to="actionsHost ?? actionsHome">
            <slot name="actions" />
        </Teleport>

        <div :class="cn('flex min-w-0 flex-col', bleed ? 'gap-3' : 'gap-6')">
            <slot />
        </div>
    </div>
</template>
