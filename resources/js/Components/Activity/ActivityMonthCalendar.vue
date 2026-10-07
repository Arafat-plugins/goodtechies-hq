<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { formatDuration } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Polish 031: the month as a calendar — each date with the time tracked on it, the chosen day
 * marked, any date one press away. The days of a week run Sunday → Saturday, as on Attendance.
 */
const props = defineProps<{
    month: { label: string; previous: string; next: string; days: { date: string; seconds: number }[] };
    selected: string;
    today: string;
    /** This page's path, so a date becomes `?date=`. */
    path: string;
}>();

const HEADINGS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const blanks = computed(() => {
    const first = props.month.days[0]?.date;

    return first ? new Date(`${first}T00:00:00`).getDay() : 0;
});

const most = computed(() => Math.max(1, ...props.month.days.map((day) => day.seconds)));

function href(date: string): string {
    return `${props.path}?date=${date}`;
}

function dayNumber(date: string): number {
    return Number(date.slice(8, 10));
}

/** Short enough for a calendar cell: "45m", "3.9h". The full figure is in the label. */
function hours(seconds: number): string {
    if (seconds <= 0) {
        return '';
    }

    return seconds < 3600 ? `${Math.max(1, Math.round(seconds / 60))}m` : `${(seconds / 3600).toFixed(1)}h`;
}
</script>

<template>
    <Card class="flex min-w-0 flex-col gap-3 p-4">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-medium">{{ month.label }}</h2>
            <div class="flex gap-1">
                <Button as-child variant="ghost" size="icon-sm">
                    <Link :href="href(month.previous)" preserve-scroll aria-label="Previous month">
                        <ChevronLeft aria-hidden="true" />
                    </Link>
                </Button>
                <Button as-child variant="ghost" size="icon-sm">
                    <Link :href="href(month.next)" preserve-scroll aria-label="Next month">
                        <ChevronRight aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </div>

        <div class="grid grid-cols-7 gap-1 text-center" role="grid" :aria-label="`Tracked time in ${month.label}`">
            <span v-for="heading in HEADINGS" :key="heading" class="text-xs text-muted-foreground" aria-hidden="true">
                {{ heading.slice(0, 2) }}
            </span>
            <span v-for="blank in blanks" :key="`blank-${blank}`" aria-hidden="true" />

            <Link
                v-for="day in month.days"
                :key="day.date"
                :href="href(day.date)"
                preserve-scroll
                :aria-current="day.date === selected ? 'date' : undefined"
                :aria-label="`${day.date}${day.seconds > 0 ? `, ${formatDuration(day.seconds)} tracked` : ', nothing tracked'}`"
                :class="
                    cn(
                        'relative flex aspect-square min-w-0 flex-col items-center justify-center gap-0.5 overflow-hidden rounded-md border text-xs hover:border-ring focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                        day.date === selected ? 'border-primary bg-brand-tint font-semibold' : 'bg-card',
                        day.date > today && 'opacity-50',
                        day.date === today && day.date !== selected && 'ring-1 ring-ring',
                    )
                "
            >
                <!-- How full the day was, as a thin bar along the bottom edge. -->
                <span
                    v-if="day.seconds > 0"
                    class="absolute bottom-0 left-0 h-1 rounded-r-sm bg-chart-2"
                    :style="{ width: `${Math.max(12, Math.round((day.seconds / most) * 100))}%` }"
                    aria-hidden="true"
                />
                <span class="relative tabular-nums">{{ dayNumber(day.date) }}</span>
                <span class="relative text-xs leading-none text-muted-foreground tabular-nums">{{ hours(day.seconds) }}</span>
            </Link>
        </div>
    </Card>
</template>
