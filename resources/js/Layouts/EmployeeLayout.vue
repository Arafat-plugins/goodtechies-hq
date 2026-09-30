<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FlashMessage from '@/Components/FlashMessage.vue';
import NavigationPending from '@/Components/Shell/NavigationPending.vue';
import AppSidebar from '@/Components/Shell/AppSidebar.vue';
import AppTopBar from '@/Components/Shell/AppTopBar.vue';
import SkipToContent from '@/Components/Shell/SkipToContent.vue';
import ShellLive from '@/Components/Shell/ShellLive.vue';
import TaskTimerPulse from '@/Components/Timer/TaskTimerPulse.vue';
import TimerBar from '@/Components/Timer/TimerBar.vue';
import Toaster from '@/Components/Toaster.vue';
import { cn } from '@/lib/utils';
import { useSidebarRail } from '@/lib/sidebarState';
import { useShellNavigationPending } from '@/lib/useNavigationPending';
import { employeeNav } from '@/navigation/employee';

const page = usePage();

const nav = computed(() => employeeNav(page.props.auth.user?.trackingMode));
const homeHref = '/employee/dashboard';

// The content offset follows the sidebar's own width: 256 px column, 56 px rail.
const rail = useSidebarRail();

// Slow-loading slice 6: a navigation the person started that is still out after 300 ms marks
// `<main>` busy and dims it slightly — the old page stays readable, it just stops looking current.
const navigating = useShellNavigationPending();
</script>

<template>
    <div class="min-h-screen bg-background">
        <!-- First tab stop in the document — it must stay the first child. -->
        <SkipToContent />
        <AppSidebar :groups="nav" :home-href="homeHref" />
        <div
            :class="
                cn(
                    'flex min-h-screen flex-col transition-[padding] duration-200 motion-reduce:transition-none',
                    rail ? 'lg:pl-14' : 'lg:pl-64',
                )
            "
        >
            <AppTopBar :groups="nav" :home-href="homeHref" />
            <NavigationPending :pending="navigating" />
            <main
                id="main-content"
                tabindex="-1"
                :aria-busy="navigating ? 'true' : undefined"
                :class="
                    cn(
                        'mx-auto w-full max-w-screen-2xl flex-1 p-4 outline-none md:p-6',
                        // Opt-in for working surfaces (`PageShell bleed`, the Tasks views): full
                        // width, 16 px gutters, a 12 px top band. Inert on every other page.
                        'has-[[data-page-bleed]]:max-w-none has-[[data-page-bleed]]:pt-3 md:has-[[data-page-bleed]]:px-4',
                        '*:transition-opacity *:duration-200 motion-reduce:*:transition-none',
                        navigating && '*:opacity-70',
                    )
                "
            >
                <ShellLive />
                <!-- Flow F3: keeps an office/Admin task timer's heartbeat going on every page. -->
                <TaskTimerPulse />
                <FlashMessage class="mb-4" />
                <slot />
            </main>

            <!--
                Phase 4's persistent timer bar. It renders only for somebody the SERVER says may
                time (`auth.user.canTrackTime` = `TimeEntryPolicy::track`), so an office
                employee's shell has no timer in it at all — not a disabled one.

                It is the last child of this column and `sticky bottom-0`, so it occupies layout
                space rather than lying on top of the page: it follows the viewport while there
                is page left to scroll, settles at the end, and covers nothing at any width. Last
                in the DOM also puts it last in the tab order, after the page's own controls.
            -->
            <TimerBar />
        </div>
        <Toaster />
    </div>
</template>
