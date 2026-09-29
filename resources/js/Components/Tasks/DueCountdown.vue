<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import { dueCountdown } from '@/lib/dueCountdown';
import { useMinuteTicker } from '@/lib/minuteTicker';
import { cn } from '@/lib/utils';

/**
 * `5 days left` / `3 hours overdue` — one unit, from `lib/dueCountdown.ts`.
 *
 * **The only reader of the minute ticker.** Its render reads `now`; the card that mounts it
 * does not, so a tick re-renders this span and nothing around it. The zone is the app's
 * (`page.props.app.timezone`), never the browser's, because the server's overdue rule is.
 *
 * It must sit inside a `TooltipProvider` (the card supplies one).
 */

const props = defineProps<{
    dueDate: string | null;
    status: string;
}>();

const page = usePage();
const now = useMinuteTicker();

const countdown = computed(() => dueCountdown(props.dueDate, props.status, now.value, page.props.app.timezone));
const due = computed(() => `Due ${formatDate(props.dueDate)}`);
</script>

<template>
    <Tooltip v-if="countdown">
        <TooltipTrigger as-child>
            <time
                :datetime="dueDate ?? undefined"
                data-due-countdown
                :class="cn('relative shrink-0 text-xs tabular-nums', countdown.overdue ? 'font-medium text-destructive' : 'text-muted-foreground')"
            >
                {{ countdown.label }}<span class="sr-only"> ({{ due }})</span>
            </time>
        </TooltipTrigger>
        <TooltipContent>{{ due }}</TooltipContent>
    </Tooltip>
</template>
