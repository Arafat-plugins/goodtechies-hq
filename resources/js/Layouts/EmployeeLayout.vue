<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FlashMessage from '@/Components/FlashMessage.vue';
import AppSidebar from '@/Components/Shell/AppSidebar.vue';
import AppTopBar from '@/Components/Shell/AppTopBar.vue';
import SkipToContent from '@/Components/Shell/SkipToContent.vue';
import Toaster from '@/Components/Toaster.vue';
import { cn } from '@/lib/utils';
import { useSidebarRail } from '@/lib/sidebarState';
import { employeeNav } from '@/navigation/employee';

const page = usePage();

const nav = computed(() => employeeNav(page.props.auth.user?.trackingMode));
const homeHref = '/employee/dashboard';

// The content offset follows the sidebar's own width: 256 px column, 56 px rail.
const rail = useSidebarRail();
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
            <main
                id="main-content"
                tabindex="-1"
                class="mx-auto w-full max-w-screen-2xl flex-1 p-4 outline-none md:p-6"
            >
                <FlashMessage class="mb-4" />
                <slot />
            </main>
        </div>
        <Toaster />
    </div>
</template>
