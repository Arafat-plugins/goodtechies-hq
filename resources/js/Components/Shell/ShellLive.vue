<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AnnouncementBanner from '@/Components/Shell/AnnouncementBanner.vue';
import ConnectivityStrip from '@/Components/Shell/ConnectivityStrip.vue';
import NewVersionStrip from '@/Components/Shell/NewVersionStrip.vue';
import NotificationsPrompt from '@/Components/Shell/NotificationsPrompt.vue';
import SessionEndedDialog from '@/Components/Shell/SessionEndedDialog.vue';
import { useShellLive } from '@/Components/Realtime/shell';

/**
 * The shell's live chrome: one mount per layout, and the announcement banner it draws.
 *
 * Two things hang off `useShellLive()` and only one of them is visible here. The banner is this
 * component's own output; the Messages nav row's unread pill is `messagesBadge`, read straight
 * from the same module by `AppSidebarNav.vue`, because a badge inside a nav row cannot be a
 * sibling component of the nav. Starting the transport is this component's job either way, so
 * every layout mounts it exactly once and the sidebar never has to.
 *
 * ## One screen draws the banner itself, and this is how that is decided
 *
 * `Pages/Shared/Messages.vue` puts the same banner inside its own fixed-height workspace, where it
 * has to come out of the THREAD's height rather than out of the viewport's — that page is one
 * `calc(100svh - …)` row from `lg` up (M-12), and a strip added above it would push the composer
 * off the bottom of the screen. So there are two placements of one banner, and exactly one of them
 * draws at a time.
 *
 * It is decided from `page.component`, which is Inertia's own name for "which screen is this", and
 * not from a prop threaded through the layout or a flag the page sets on mount. Both of those were
 * tried: a layout prop cannot be passed at all, because `defineOptions({ layout })` returns a
 * component and not an element; and a flag set in the page's `setup` is set AFTER this component
 * has already rendered — the layout renders its own children before it evaluates the slot the page
 * is in — so the first frame draws two banners and the second draws one. `page.component` is known
 * before the first render and cannot flicker.
 */

const page = usePage();

const { announcement } = useShellLive();

/** The one screen that owns its own placement. */
const drawsItsOwn = computed(() => page.component === 'Shared/Messages');
</script>

<template>
    <!-- Reliability slice 1: the one mount of the session dialog, and its signed-out strip. -->
    <SessionEndedDialog />
    <!-- Reliability slice 2a: offline / server unreachable, one line for every poller. -->
    <ConnectivityStrip />
    <!-- Reliability slice 3: a deploy while this tab was open; the person chooses when to reload. -->
    <NewVersionStrip />
    <!-- Polish 016: the one-time "get a popup" card, top-right, until this device is on. -->
    <NotificationsPrompt />
    <AnnouncementBanner v-if="!drawsItsOwn" :announcement="announcement" class="mb-4" />
</template>
