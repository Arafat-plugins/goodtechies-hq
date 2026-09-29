<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MessageCircle } from '@lucide/vue';
import { computed } from 'vue';
import { messagesBadge } from '@/Components/Realtime/shell';
import { Button } from '@/Components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';

/**
 * The top bar's Messages icon — messaging polish.
 *
 * Messages have their own door, separate from the bell: the bell is about work (tasks, leave,
 * meetings, payroll) and this is about talk. It counts unread messages across the reader's
 * whole inbox from the conversations' own read state (`shell.ts`), less the conversation they
 * are looking at right now, so a reply in the thread on screen never lights it up.
 *
 * It is a link, not a popover: `/messages?unread=1` opens the unread conversation with the
 * newest message in it (`MessageController::requestedConversation()`), and with nothing unread
 * it is simply the Messages page. The count is a word for a screen reader and a pill for the
 * eye, exactly like the bell beside it, so the two read as one family.
 *
 * Mounted only on a shell whose navigation has a Messages row — the Accountant has none, and
 * holds no `messages.use`, so the icon would lead to a 403.
 */

const badge = computed(() => messagesBadge.value);

const href = computed(() => (badge.value === null ? '/messages' : '/messages?unread=1'));

const label = computed(() => badge.value?.label ?? 'Messages');
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <Button as-child variant="ghost" size="icon" class="relative size-9">
                    <Link :href="href" :aria-label="label">
                        <MessageCircle class="size-5" aria-hidden="true" />
                        <span
                            v-if="badge"
                            class="absolute -top-0.5 -right-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-xs font-medium tabular-nums text-primary-foreground"
                            aria-hidden="true"
                        >
                            {{ badge.text }}
                        </span>
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {{ badge ? (badge.count === 1 ? '1 unread message' : `${badge.count} unread messages`) : 'Messages' }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
