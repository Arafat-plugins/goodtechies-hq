<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { formatDate } from "@/Components/Tasks/taskDetail";
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from "@/Components/ui/tooltip";
import { dueCountdown } from "@/lib/dueCountdown";
import { useMinuteTicker } from "@/lib/minuteTicker";
import { cn } from "@/lib/utils";

/**
 * `5 days left` / `3 hours overdue` — one unit, from `lib/dueCountdown.ts`.
 *
 * **The only reader of the minute ticker.** Its render reads `now`; the card that mounts it
 * does not, so a tick re-renders this span and nothing around it. The zone is the app's
 * (`page.props.app.timezone`), never the browser's, because the server's overdue rule is.
 *
 * It must sit inside a `TooltipProvider` (the card supplies one).
 *
 * `truncate` (the Board card's one-row footer, brief 026): the label is the first thing in the row
 * to give way — `min-w-0` + `truncate` with a high `flex-shrink` — and its tooltip then carries the
 * full label as well as the date, since the visible text may end in `…`. Without it (the drawer's
 * subtask rows), it never shrinks, as before.
 */

const props = defineProps<{
    dueDate: string | null;
    status: string;
    truncate?: boolean;
}>();

const page = usePage();
const now = useMinuteTicker();

const countdown = computed(() =>
    dueCountdown(
        props.dueDate,
        props.status,
        now.value,
        page.props.app.timezone,
    ),
);
const due = computed(() => `Due ${formatDate(props.dueDate)}`);
const tip = computed(() =>
    props.truncate && countdown.value
        ? `${countdown.value.label} · ${due.value}`
        : due.value,
);
</script>

<template>
    <Tooltip v-if="countdown">
        <TooltipTrigger as-child>
            <time
                :datetime="dueDate ?? undefined"
                data-due-countdown
                :class="
                    cn(
                        'relative text-xs tabular-nums',
                        truncate ? 'min-w-6 truncate' : 'shrink-0',
                        countdown.overdue
                            ? 'font-medium text-destructive'
                            : 'text-muted-foreground',
                    )
                "
            >
                {{ countdown.label }}<span class="sr-only"> ({{ due }})</span>
            </time>
        </TooltipTrigger>
        <TooltipContent>{{ tip }}</TooltipContent>
    </Tooltip>
</template>
