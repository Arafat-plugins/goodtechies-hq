<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, BellOff, CheckCheck, PlugZap, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import NotificationRow from '@/Components/Notifications/NotificationRow.vue';
import { centerHref, markAllRead, useNotificationBell } from '@/Components/Notifications/notifications';
import { Button } from '@/Components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Skeleton } from '@/Components/ui/skeleton';

/**
 * The bell: the unread badge, and the newest ten behind it.
 *
 * It subscribes to `private-notifications.{user}` and falls back to reading
 * `GET /notifications/recent` every 15 seconds — on a polling build, and while the socket is
 * down on a socket build. Both come through `useNotificationBell()`, which owns the single
 * interval and the single subscription and stops the interval whenever nobody is looking.
 * Nothing about either transport lives in this file, so a second mount of the bell cannot
 * start a second one.
 *
 * **The count is the server's**, on both transports. Marking read does not subtract one here;
 * it writes, and the write re-reads. The badge caps at `9+` because anything wider stretches
 * past the icon.
 *
 * ## When the socket drops
 *
 * The bell says so, in the popover, in words — *"Reconnecting — checking every 15 seconds"* —
 * and keeps polling until it is back. It does not go quiet and it does not pretend: a bell
 * that has silently stopped being right looks exactly like a bell with nothing to say, which
 * is the one thing it must never look like.
 *
 * It is not a banner and not a toast. The state is worth knowing when you open the bell and is
 * not worth interrupting anybody for, so it lives behind the click that asks about it.
 *
 * ## What a screen reader hears
 *
 * One polite live region, always in the DOM, holding at most one sentence every 30 seconds and
 * only when the unread count has RISEN (`notifications.ts`). Arrivals never move anything on
 * the page — the badge changes a number inside a fixed-width pill, the list behind the popover
 * is only redrawn while the popover is shut — and nothing here takes focus.
 */

const { unreadCount, recent, status, transport, announcement } = useNotificationBell();

const open = ref(false);
const page = usePage();

/** Anything past 9 would stretch the badge past the icon, so it caps. */
const badgeLabel = computed(() => (unreadCount.value > 9 ? '9+' : String(unreadCount.value)));

/**
 * Close on a real navigation, and only on one.
 *
 * Marking a row read is a write that answers `back()` — the same URL, re-rendered — and the
 * panel holding the other nine rows must survive that. Opening a notification goes somewhere
 * else, and a popover still hanging over the page it just left is a popover nobody closed.
 */
watch(
    () => page.url,
    () => {
        open.value = false;
    },
);

const loading = computed(() => recent.value.length === 0 && (status.value === 'idle' || status.value === 'loading'));
</script>

<template>
    <Popover v-if="status !== 'denied'" v-model:open="open" modal>
        <PopoverTrigger as-child>
            <Button variant="ghost" size="icon" class="relative size-9">
                <Bell class="size-5" aria-hidden="true" />
                <span
                    v-if="unreadCount > 0"
                    class="absolute -top-0.5 -right-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-xs font-medium tabular-nums text-primary-foreground"
                    aria-hidden="true"
                >
                    {{ badgeLabel }}
                </span>
                <span class="sr-only">
                    Notifications{{ unreadCount > 0 ? `, ${unreadCount} unread` : '' }}
                </span>
            </Button>
        </PopoverTrigger>

        <!-- The one live region. It is always in the DOM — a region created at the moment it
             has something to say is a region a screen reader never announces — and `polite`
             means it waits for a pause rather than cutting across whatever is being read.
             What goes in it, and how rarely, is `notifications.ts`'s decision. -->
        <span class="sr-only" role="status" aria-live="polite">{{ announcement }}</span>
        <!-- `PopoverContent` already caps itself at the available width, so 320 px is a
             preference and not an overflow at 360. -->
        <PopoverContent align="end" class="w-80 p-0">
            <div class="flex h-11 items-center gap-2 border-b px-3">
                <p class="text-sm font-medium">Notifications</p>
                <Button
                    v-if="unreadCount > 0"
                    variant="ghost"
                    size="sm"
                    class="ml-auto h-7"
                    @click="markAllRead()"
                >
                    <CheckCheck aria-hidden="true" />
                    Mark all read
                </Button>
            </div>

            <div v-if="loading" class="flex flex-col gap-3 p-4" aria-hidden="true">
                <Skeleton v-for="line in 3" :key="line" class="h-8 w-full" />
            </div>

            <EmptyState
                v-else-if="status === 'failed' && recent.length === 0"
                :icon="TriangleAlert"
                variant="error"
                title="Notifications are not loading"
                description="They will try again on their own in a moment."
            />

            <EmptyState
                v-else-if="recent.length === 0"
                :icon="BellOff"
                title="Nothing yet"
                description="Assignments, reviews and comments land here."
            />

            <!-- The newest ten, read ones included: a bell that empties itself as you glance at
                 it gives you no way back to what you just dismissed. -->
            <ul v-else class="max-h-96 overflow-y-auto">
                <NotificationRow v-for="row in recent" :key="row.id" :row="row" compact />
            </ul>

            <!-- Only when the socket is a socket that is down. A polling build says nothing:
                 it is working exactly as it was built to, and an apology would be a bug report
                 about a supported mode. The icon never carries this alone (DESIGN.md §5.6). -->
            <p
                v-if="transport === 'reconnecting'"
                class="flex items-start gap-2 border-t px-3 py-2 text-xs text-muted-foreground"
            >
                <PlugZap class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                <span>Live updates are reconnecting. Checking every 15 seconds meanwhile.</span>
            </p>

            <div class="border-t p-2">
                <Button as-child variant="ghost" size="sm" class="w-full">
                    <Link :href="centerHref()">Open the Notification Center</Link>
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
