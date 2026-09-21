<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/Components/ui/breadcrumb';

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

const crumbs = computed<Crumb[]>(() => props.breadcrumb ?? []);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <div class="flex min-w-0 flex-col gap-4">
            <Breadcrumb v-if="crumbs.length > 0">
                <BreadcrumbList>
                    <template v-for="(crumb, index) in crumbs" :key="`${crumb.label}-${index}`">
                        <BreadcrumbItem>
                            <BreadcrumbLink v-if="crumb.href && index < crumbs.length - 1" as-child>
                                <Link :href="crumb.href">{{ crumb.label }}</Link>
                            </BreadcrumbLink>
                            <BreadcrumbPage v-else>{{ crumb.label }}</BreadcrumbPage>
                        </BreadcrumbItem>
                        <BreadcrumbSeparator v-if="index < crumbs.length - 1" />
                    </template>
                </BreadcrumbList>
            </Breadcrumb>

            <!-- Actions sit right of the title from sm up, and wrap under it at 375. -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex min-w-0 flex-col gap-1">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ heading }}</h1>
                    <p v-if="subline" class="text-sm text-muted-foreground">{{ subline }}</p>
                </div>
                <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
                    <slot name="actions" />
                </div>
            </div>

            <div v-if="$slots.tabs" class="min-w-0">
                <slot name="tabs" />
            </div>
        </div>

        <div class="flex min-w-0 flex-col gap-6">
            <slot />
        </div>
    </div>
</template>
