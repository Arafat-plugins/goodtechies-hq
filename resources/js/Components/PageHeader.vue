<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    /** Page title. Ignored when `greetingName` is set. */
    title?: string;
    description?: string;
    /** Renders "Good {morning|afternoon|evening}, {name}" from the browser clock. */
    greetingName?: string;
    /** 'YYYY-MM-DD'; rendered as e.g. "Thursday, 17 September 2026" in place of `description`. */
    today?: string;
}>();

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
    props.greetingName ? `Good ${partOfDay(new Date().getHours())}, ${props.greetingName}` : (props.title ?? ''),
);

const subline = computed(() => (props.today ? formatDay(props.today) : props.description));
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex min-w-0 flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">{{ heading }}</h1>
            <p v-if="subline" class="text-sm text-muted-foreground">{{ subline }}</p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
