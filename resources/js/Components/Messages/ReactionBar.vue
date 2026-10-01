<script setup lang="ts">
import type { MessageReaction } from '@/Components/Messages/messages';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * Brief 010: the reactions under a message — one chip per emoji with its count.
 *
 * The viewer's own reaction is a ring AND a heavier weight, and `aria-pressed` says it to a
 * screen reader, so it is never carried by colour alone. The tooltip names who reacted. A click
 * toggles it; the row does the optimistic update and the request. Nothing is drawn when empty.
 */

defineProps<{ reactions: MessageReaction[]; disabled?: boolean }>();

const emit = defineEmits<{ toggle: [emoji: string] }>();

function label(reaction: MessageReaction): string {
    const who = reaction.names.join(', ');

    return `${reaction.emoji} ${reaction.count}${who !== '' ? `: ${who}` : ''}${reaction.mine ? ' (you reacted)' : ''}`;
}
</script>

<template>
    <ul v-if="reactions.length > 0" class="flex min-w-0 flex-wrap gap-1" aria-label="Reactions">
        <TooltipProvider :delay-duration="200">
            <li v-for="reaction in reactions" :key="reaction.emoji">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            :disabled="disabled"
                            :aria-pressed="reaction.mine"
                            :aria-label="label(reaction)"
                            :class="
                                cn(
                                    'inline-flex h-6 items-center gap-1 rounded-full border bg-card px-2 text-xs tabular-nums hover:bg-accent hover:text-accent-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50',
                                    reaction.mine
                                        ? 'border-primary font-semibold ring-1 ring-primary'
                                        : 'font-normal text-muted-foreground',
                                )
                            "
                            @click="emit('toggle', reaction.emoji)"
                        >
                            <span aria-hidden="true">{{ reaction.emoji }}</span>
                            <span aria-hidden="true">{{ reaction.count }}</span>
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>{{ reaction.names.join(', ') || reaction.emoji }}</TooltipContent>
                </Tooltip>
            </li>
        </TooltipProvider>
    </ul>
</template>
