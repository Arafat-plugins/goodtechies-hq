<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Pin, PinOff } from '@lucide/vue';
import { ref } from 'vue';
import type { AnnouncementBanner } from '@/Components/Messages/messages';
import { formatMessageTime, messageRequest, messagesHref, messageUrl } from '@/Components/Messages/messages';
import { liveReload } from '@/Components/Realtime/reload';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * Polish 044: the PINNED announcement (one at a time), as a strip at the top of whatever screen
 * somebody is on. An Admin pins it from the message's menu in the announcements channel and
 * unpins it there or with the button on the strip. Nothing pinned — or the pinned message
 * deleted — means no strip. The notes below predate the pin and still describe the rest.
 *
 * The newest announcement, as a strip at the top of whatever screen somebody is on.
 *
 * ## App-wide from Phase 12, and it was Messages-only for six phases
 *
 * The plan says *"banner + notification"*; what shipped in Phase 6 was a banner on the Messages
 * page, where the people who most need to see an announcement are the least likely to be. That is
 * decision 6-18's follow-up and POLISH-BACKLOG §C.1, and the reason it waited is that a global
 * banner needs a shared prop, which changes every page's prop shape and the tests that assert it
 * (2-42's warning). It now reads `Components/Realtime/shell.ts`, which holds the server's answer
 * at module scope and keeps it current on a timer and — for an announcement specifically — on the
 * socket, because the announcements channel already exists.
 *
 * The markup is lifted unchanged from `Pages/Shared/Messages.vue`, where it lived; that page now
 * mounts this component instead, so there is one banner in the application rather than two that
 * drift.
 *
 * ## It is not dismissed by a button
 *
 * It goes quiet when the announcements channel is read, which is one state and not two. Clicking
 * it is navigation into that channel — which is also what marks it read — so the dismissal and
 * the reading are the same act. `Megaphone` plus the word "Announcement" carry it; the border is
 * third (DESIGN.md §5.6).
 *
 * ## It says nothing to a screen reader, deliberately
 *
 * An announcement also writes a notification, and the bell already has a polite live region that
 * speaks when the unread count rises. A second region here would announce one message twice,
 * which DESIGN.md §5.19 forbids in as many words.
 *
 * ## The one live arrival in this application that is allowed to move the page
 *
 * `LiveUpdateNotice` exists because content appearing above what somebody is reading pushes it
 * down under their cursor, and it solves that by speaking from the bottom of the document and
 * never acting by itself. This does the opposite, on purpose: an announcement is company-wide
 * news whose entire point is to be seen, it arrives at most a few times a week, and a banner that
 * appeared somewhere other than the top of the page would not be the thing the plan asked for.
 * Everything else Phase 12 wired refreshes in place or speaks from the bottom.
 */

const props = defineProps<{
    /** The banner, or `null` — there is nothing to draw and nothing reserving space for it. */
    announcement: AnnouncementBanner | null;
}>();

const unpinning = ref(false);
const hidden = ref<number | null>(null);

/** Polish 044: take the pin off. The strip goes at once; the shell's next read confirms it. */
async function unpin(): Promise<void> {
    const banner = props.announcement;

    if (!banner?.message_id || unpinning.value) {
        return;
    }

    unpinning.value = true;

    try {
        const answer = await messageRequest('DELETE', `${messageUrl(banner.conversation_id, banner.message_id)}/pin`, null);

        if (answer.status >= 200 && answer.status < 300) {
            hidden.value = banner.message_id;
        }
    } catch {
        // Offline: the strip stays; the connectivity strip says why.
    } finally {
        unpinning.value = false;
        liveReload(['shell', 'announcement']);
    }
}
</script>

<template>
    <div
        v-if="announcement && hidden !== announcement.message_id"
        :class="cn('flex min-w-0 shrink-0 items-start gap-1 rounded-lg border bg-card shadow-raised', announcement.is_unread && 'border-primary')"
        data-testid="announcement-banner"
    >
        <Link
            :href="messagesHref(announcement.conversation_id)"
            preserve-scroll
            class="flex min-w-0 flex-1 items-start gap-3 rounded-lg p-3 hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
        >
            <Pin class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="flex min-w-0 flex-wrap items-baseline gap-x-2 text-xs text-muted-foreground">
                    <span class="font-medium text-foreground">
                        Pinned announcement{{ announcement.is_unread ? ' — unread' : '' }}
                    </span>
                    <span>{{ announcement.author ?? 'Somebody' }}</span>
                    <span>{{ formatMessageTime(announcement.created_at) }}</span>
                </span>
                <span class="line-clamp-2 min-w-0 text-sm break-words whitespace-pre-line">
                    {{ announcement.body }}
                </span>
            </span>
        </Link>

        <TooltipProvider v-if="announcement.can_unpin">
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="m-2 flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                        :disabled="unpinning"
                        aria-label="Unpin this announcement"
                        data-testid="announcement-unpin"
                        @click="unpin"
                    >
                        <PinOff class="size-4" aria-hidden="true" />
                    </button>
                </TooltipTrigger>
                <TooltipContent>Unpin</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </div>
</template>
