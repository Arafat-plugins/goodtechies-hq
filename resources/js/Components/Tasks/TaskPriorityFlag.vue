<script setup lang="ts">
import { Flag } from '@lucide/vue';
import { computed } from 'vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * A task's priority as a filled flag: Urgent red, High orange, Medium blue, Low grey — the
 * `--priority-*` tokens (DESIGN.md §1.4b), each ≥ 3:1 on `--card` in both themes.
 *
 * The colour is never the only carrier (DESIGN.md §5.6): the word rides in `aria-label` and the
 * tooltip. The four keys are `App\Support\TaskPriority`'s values; anything else, or none,
 * renders nothing. It must sit inside a `TooltipProvider` (the card supplies one).
 */

const props = defineProps<{
    priority: string | null | undefined;
}>();

const FLAGS: Record<string, { label: string; tone: string }> = {
    urgent: { label: 'Urgent priority', tone: 'text-priority-urgent fill-priority-urgent' },
    high: { label: 'High priority', tone: 'text-priority-high fill-priority-high' },
    medium: { label: 'Medium priority', tone: 'text-priority-medium fill-priority-medium' },
    low: { label: 'Low priority', tone: 'text-priority-low fill-priority-low' },
};

const flag = computed(() => (props.priority ? (FLAGS[props.priority] ?? null) : null));
</script>

<template>
    <Tooltip v-if="flag">
        <TooltipTrigger as-child>
            <span role="img" :aria-label="flag.label" data-priority-flag :data-priority="priority" class="inline-flex shrink-0">
                <Flag :class="cn('size-4', flag.tone)" aria-hidden="true" />
            </span>
        </TooltipTrigger>
        <TooltipContent>{{ flag.label }}</TooltipContent>
    </Tooltip>
</template>
