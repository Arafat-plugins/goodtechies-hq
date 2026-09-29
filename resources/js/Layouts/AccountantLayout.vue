<script setup lang="ts">
import FlashMessage from '@/Components/FlashMessage.vue';
import NavigationPending from '@/Components/Shell/NavigationPending.vue';
import AppSidebar from '@/Components/Shell/AppSidebar.vue';
import AppTopBar from '@/Components/Shell/AppTopBar.vue';
import SkipToContent from '@/Components/Shell/SkipToContent.vue';
import ShellLive from '@/Components/Shell/ShellLive.vue';
import Toaster from '@/Components/Toaster.vue';
import { cn } from '@/lib/utils';
import { useSidebarRail } from '@/lib/sidebarState';
import { useShellNavigationPending } from '@/lib/useNavigationPending';
import { accountantNav } from '@/navigation/accountant';

const homeHref = '/accountant/dashboard';

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
        <AppSidebar :groups="accountantNav" :home-href="homeHref" />
        <div
            :class="
                cn(
                    'flex min-h-screen flex-col transition-[padding] duration-200 motion-reduce:transition-none',
                    rail ? 'lg:pl-14' : 'lg:pl-64',
                )
            "
        >
            <AppTopBar :groups="accountantNav" :home-href="homeHref" />
            <NavigationPending :pending="navigating" />
            <main
                id="main-content"
                tabindex="-1"
                :aria-busy="navigating ? 'true' : undefined"
                :class="
                    cn(
                        'mx-auto w-full max-w-screen-2xl flex-1 p-4 outline-none md:p-6',
                        '*:transition-opacity *:duration-200 motion-reduce:*:transition-none',
                        navigating && '*:opacity-70',
                    )
                "
            >
                <ShellLive />
                <FlashMessage class="mb-4" />
                <slot />
            </main>
        </div>
        <Toaster />
    </div>
</template>
