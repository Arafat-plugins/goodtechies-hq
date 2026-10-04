<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import ConversationAvatar from '@/Components/Messages/ConversationAvatar.vue';
import { messagesHref } from '@/Components/Messages/messages';
import { Button } from '@/Components/ui/button';

/**
 * A chat that is opening (2026-10-04): the real header — the name is already known from the
 * list row — over a shimmering log and a composer-shaped bar, shown between the tap and the
 * thread's arrival so the tap is answered at once. It holds nothing interactive except Back.
 *
 * Bubble widths alternate left and right like a conversation, so the screen does not jump
 * when the real messages replace them.
 */
defineProps<{
    label: string;
    avatarUrl?: string | null;
}>();

/** [side, width] — fixed, so every opening looks the same and nothing is random. */
const BUBBLES: Array<['in' | 'out', string]> = [
    ['in', 'w-48'],
    ['in', 'w-64'],
    ['out', 'w-40'],
    ['in', 'w-56'],
    ['out', 'w-60'],
    ['out', 'w-32'],
    ['in', 'w-44'],
];
</script>

<template>
    <div class="flex min-h-0 min-w-0 flex-1 flex-col" aria-busy="true">
        <div class="flex min-w-0 shrink-0 items-center gap-2 border-b p-3">
            <Button as-child type="button" variant="ghost" size="icon-sm" class="shrink-0 lg:hidden">
                <Link :href="messagesHref()" aria-label="Back to conversations">
                    <ArrowLeft aria-hidden="true" />
                </Link>
            </Button>
            <ConversationAvatar class="size-10" :label="label" :avatar-url="avatarUrl ?? null" />
            <h2 class="min-w-0 flex-1 truncate text-base font-semibold">{{ label }}</h2>
        </div>

        <div
            class="chat-wallpaper flex min-h-0 flex-1 flex-col justify-end gap-3 overflow-hidden px-3 py-4 lg:px-6"
        >
            <p class="sr-only" role="status">Opening {{ label }}…</p>
            <div
                v-for="([side, width], index) in BUBBLES"
                :key="index"
                :class="['flex min-w-0 items-end gap-2', side === 'out' ? 'justify-end' : 'justify-start']"
                aria-hidden="true"
            >
                <span v-if="side === 'in'" class="shimmer size-8 shrink-0 rounded-full" />
                <span :class="['shimmer h-11 max-w-[75%] rounded-2xl', width]" />
            </div>
        </div>

        <div class="shrink-0 border-t px-3 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:px-6" aria-hidden="true">
            <div class="shimmer h-12 w-full rounded-full" />
        </div>
    </div>
</template>
