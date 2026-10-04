<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Hash, Megaphone, MessageSquare, Users } from '@lucide/vue';
import { computed } from 'vue';
import ConversationAvatar from '@/Components/Messages/ConversationAvatar.vue';
import { type ConversationSummary, type ConversationTypeKey, formatClockTime, messagesHref } from '@/Components/Messages/messages';
import type { PreviewMessage } from '@/Components/Messages/opening';
import { personTone } from '@/Components/Messages/people';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * A chat that is opening (2026-10-04): what the reader is about to see, drawn from what is
 * already known, between the tap and the thread's arrival.
 *
 * It follows the chat's REAL structure and never invents one (the client: the shimmer "shows
 * everywhere, where there is no message — it should be exactly the message, the file, where it
 * will load, following their structure"):
 *
 * - a chat shown earlier in this tab: its own messages, on their own sides, with their own words;
 *   only a picture, a voice note or a file shimmers, in the shape it will have;
 * - a chat never opened yet: just its last message, from the list row;
 * - a chat with no messages: nothing at all — an empty chat background.
 *
 * The composer is drawn still, not shimmering: it is not loading anything.
 */
const props = defineProps<{
    id: number;
    type: ConversationTypeKey | null;
    label: string;
    avatarUrl?: string | null;
    /** Channels and groups name the author over other people's messages; a DM does not. */
    namesAuthors: boolean;
    /** From this tab's memory (`rememberedThread`), or `null` if this chat was never shown. */
    messages: PreviewMessage[] | null;
    /** The list row's last message, for a chat never shown yet. */
    last: ConversationSummary['last_message'];
}>();

const shown = computed<PreviewMessage[]>(() => {
    if (props.messages !== null) {
        return props.messages;
    }

    if (props.last === null) {
        return [];
    }

    // The row says "Sent an attachment" for a message that is only a file.
    const fileOnly = props.last.excerpt === 'Sent an attachment';

    return [
        {
            id: 0,
            mine: props.last.is_mine,
            author: props.last.author,
            body: fileOnly ? null : props.last.excerpt,
            files: fileOnly ? ['file'] : [],
            at: props.last.created_at,
            deleted: false,
        },
    ];
});

/** The header's face, exactly as the real header draws it: a person or group, or a channel's icon. */
const CHANNEL_ICONS: Partial<Record<ConversationTypeKey, typeof Hash>> = {
    team: Users,
    announcement: Megaphone,
    project: Hash,
    task: MessageSquare,
};
const channelIcon = computed(() =>
    props.type === 'dm' || props.type === 'group' ? null : (CHANNEL_ICONS[props.type ?? 'task'] ?? MessageSquare),
);

/** A photo and nothing else sits without a bubble, as the real row draws it. */
function photoOnly(message: PreviewMessage): boolean {
    return !message.deleted && !message.body && message.files.length > 0 && message.files.every((kind) => kind === 'image');
}
</script>

<template>
    <div class="flex min-h-0 min-w-0 flex-1 flex-col" aria-busy="true">
        <div class="flex min-w-0 shrink-0 items-center gap-2 border-b p-3">
            <Button as-child type="button" variant="ghost" size="icon-sm" class="shrink-0 lg:hidden">
                <Link :href="messagesHref()" aria-label="Back to conversations">
                    <ArrowLeft aria-hidden="true" />
                </Link>
            </Button>
            <span
                v-if="channelIcon"
                :class="cn('flex size-10 shrink-0 items-center justify-center rounded-full', personTone(id).avatar)"
                aria-hidden="true"
            >
                <component :is="channelIcon" class="size-5" />
            </span>
            <ConversationAvatar v-else class="size-10" :label="label" :avatar-url="avatarUrl ?? null" />
            <h2 class="min-w-0 flex-1 truncate text-base font-semibold">{{ label }}</h2>
        </div>

        <div class="chat-wallpaper flex min-h-0 flex-1 flex-col justify-end gap-1.5 overflow-hidden px-3 py-4 lg:px-6">
            <p class="sr-only" role="status">Opening {{ label }}…</p>

            <div
                v-for="message in shown"
                :key="message.id"
                :class="cn('flex min-w-0', message.mine ? 'justify-end' : 'justify-start')"
                aria-hidden="true"
            >
                <!-- A photo alone: just its shimmering place, the size the real one starts at. -->
                <span v-if="photoOnly(message)" class="shimmer block h-44 w-60 max-w-[85%] rounded-2xl" />

                <div
                    v-else
                    :class="
                        cn(
                            'flex max-w-[85%] min-w-0 flex-col gap-1 rounded-2xl border bg-card px-3 py-1.5 text-foreground',
                            message.mine ? 'rounded-br-md' : 'rounded-bl-md',
                        )
                    "
                >
                    <span v-if="namesAuthors && !message.mine && message.author" class="text-xs font-semibold text-muted-foreground">
                        {{ message.author }}
                    </span>

                    <p v-if="message.deleted" class="text-sm text-muted-foreground italic">This message was deleted</p>

                    <p v-else-if="message.body" class="min-w-0 text-sm break-words whitespace-pre-line">{{ message.body }}</p>

                    <template v-for="(kind, index) in message.files" :key="index">
                        <span v-if="kind === 'image'" class="shimmer block h-44 w-60 max-w-full rounded-xl" />
                        <span v-else-if="kind === 'voice'" class="flex w-64 max-w-full items-center gap-3 py-1">
                            <span class="shimmer size-10 shrink-0 rounded-full" />
                            <span class="shimmer h-4 min-w-0 flex-1 rounded-full" />
                        </span>
                        <span v-else class="flex w-56 max-w-full items-center gap-2.5 py-1">
                            <span class="shimmer size-9 shrink-0 rounded-md" />
                            <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <span class="shimmer h-3 w-4/5 rounded-full" />
                                <span class="shimmer h-3 w-1/2 rounded-full" />
                            </span>
                        </span>
                    </template>

                    <span class="self-end text-xs text-muted-foreground tabular-nums">{{ formatClockTime(message.at) }}</span>
                </div>
            </div>
        </div>

        <div class="shrink-0 border-t px-3 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:px-6" aria-hidden="true">
            <div class="flex h-12 w-full items-center rounded-full border px-5 text-sm text-muted-foreground">
                Say something.
            </div>
        </div>
    </div>
</template>
