<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, BellOff, CheckCheck, TriangleAlert } from '@lucide/vue';
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
 * It reads `GET /notifications/recent` every 15 seconds — §11 is in-app only until Reverb
 * arrives in Phase 6 — through `useNotificationBell()`, which owns the single interval and
 * stops it whenever nobody is looking. Nothing about the poll lives in this file, so a second
 * mount of the bell cannot start a second one.
 *
 * **The count is the server's.** Marking read does not subtract one here; it writes, and the
 * write re-reads. The badge caps at `9+` because anything wider stretches past the icon.
 *
 * The bell removes itself for somebody with no mailbox rather than greying out (DESIGN.md
 * §5.12), and it finds that out the way the backend states it — by being refused once. See
 * `notifications.ts`.
 */

const { unreadCount, recent, status } = useNotificationBell();

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

            <div class="border-t p-2">
                <Button as-child variant="ghost" size="sm" class="w-full">
                    <Link :href="centerHref()">Open the Notification Center</Link>
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
