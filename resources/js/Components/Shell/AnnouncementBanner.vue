<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Megaphone } from '@lucide/vue';
import type { AnnouncementBanner } from '@/Components/Messages/messages';
import { formatMessageTime, messagesHref } from '@/Components/Messages/messages';
import { cn } from '@/lib/utils';

/**
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

defineProps<{
    /** The banner, or `null` — there is nothing to draw and nothing reserving space for it. */
    announcement: AnnouncementBanner | null;
}>();
</script>

<template>
    <Link
        v-if="announcement"
        :href="messagesHref(announcement.conversation_id)"
        preserve-scroll
        :class="
            cn(
                'flex min-w-0 shrink-0 items-start gap-3 rounded-lg border bg-card p-3 shadow-raised',
                'hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                announcement.is_unread && 'border-primary',
            )
        "
    >
        <Megaphone class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <span class="flex min-w-0 flex-col gap-0.5">
            <span class="flex min-w-0 flex-wrap items-baseline gap-x-2 text-xs text-muted-foreground">
                <span class="font-medium text-foreground">
                    Announcement{{ announcement.is_unread ? ' — unread' : '' }}
                </span>
                <span>{{ announcement.author ?? 'Somebody' }}</span>
                <span>{{ formatMessageTime(announcement.created_at) }}</span>
            </span>
            <span class="line-clamp-2 min-w-0 text-sm break-words">
                {{ announcement.body }}
            </span>
        </span>
    </Link>
</template>
