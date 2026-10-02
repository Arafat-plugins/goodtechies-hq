<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import type { ThreadReplyTo } from '@/Components/Messages/messages';
import { replyExcerpt } from '@/Components/Messages/messages';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Brief 013: the quoted original of a reply.
 *
 * - `composer` — above the composer's input row while a reply is being written: who is being
 *   answered, one line of what they said, and an `X` that cancels the reply.
 * - `bubble` — inside a sent reply, above its body. The whole quote is one button: it asks the
 *   thread to scroll to the original (`jump`). A deleted original still shows, as words.
 *
 * Nothing here is colour alone: the bar is decoration; the author's name and the excerpt carry
 * what this is.
 */

const props = withDefaults(
    defineProps<{
        reply: ThreadReplyTo;
        variant?: 'composer' | 'bubble';
        /** Inside the viewer's own `--bubble-own` bubble: take its foreground, not hue. */
        onAccent?: boolean;
        /** The viewer's id, so their own message is quoted as "You". */
        viewerId?: number | null;
    }>(),
    { variant: 'bubble', onAccent: false, viewerId: null },
);

const emit = defineEmits<{
    cancel: [];
    jump: [id: number];
}>();

const author = computed(() => {
    const person = props.reply.author;

    if (person === null) {
        return 'Somebody who has since left';
    }

    if (props.viewerId !== null && person.id === props.viewerId) {
        return 'You';
    }

    return person.name !== '' ? person.name : 'Somebody';
});

const excerpt = computed(() => replyExcerpt(props.reply));
</script>

<template>
    <div
        v-if="variant === 'composer'"
        class="flex min-w-0 items-center gap-2 rounded-md border-l-2 border-primary bg-muted/40 py-1 pr-1 pl-2"
        data-testid="reply-quote-composer"
    >
        <div class="flex min-w-0 flex-1 flex-col">
            <span class="min-w-0 truncate text-xs font-medium">
                <span class="sr-only">Replying to </span>{{ author }}
            </span>
            <span :class="cn('min-w-0 truncate text-xs text-muted-foreground', reply.is_deleted && 'italic')">
                {{ excerpt }}
            </span>
        </div>
        <Button
            type="button"
            size="icon-xs"
            variant="ghost"
            aria-label="Cancel reply"
            @click="emit('cancel')"
        >
            <X aria-hidden="true" />
        </Button>
    </div>

    <button
        v-else
        type="button"
        :class="
            cn(
                'flex max-w-full min-w-0 flex-col rounded-md border-l-2 py-0.5 pr-2 pl-2 text-left focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                onAccent
                    ? 'border-bubble-own-foreground/60 text-bubble-own-foreground/80 hover:bg-bubble-own-foreground/10'
                    : 'border-primary bg-muted/40 text-muted-foreground hover:bg-muted',
            )
        "
        data-testid="reply-quote"
        :data-reply-to="reply.id"
        @click="emit('jump', reply.id)"
    >
        <span :class="cn('min-w-0 truncate text-xs font-medium', !onAccent && 'text-foreground')">
            <span class="sr-only">In reply to </span>{{ author }}
        </span>
        <span :class="cn('min-w-0 truncate text-xs', reply.is_deleted && 'italic')">
            {{ excerpt }}
        </span>
        <span class="sr-only">(show the original message)</span>
    </button>
</template>
