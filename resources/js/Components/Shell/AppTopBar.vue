<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MobileNavSheet from '@/Components/Shell/MobileNavSheet.vue';
import UserMenu from '@/Components/Shell/UserMenu.vue';
import type { NavGroup } from '@/navigation/types';
import { isActiveHref } from '@/navigation/types';

const props = defineProps<{
    groups: NavGroup[];
    homeHref: string;
}>();

const page = usePage();

// The section name comes from the active nav row; the page itself owns the <h1>.
const sectionTitle = computed(
    () =>
        props.groups.flatMap((group) => group.items).find((item) => isActiveHref(page.url, item.href))
            ?.label ?? (page.url.startsWith('/profile') ? 'Profile' : ''),
);
</script>

<template>
    <header class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b bg-card px-4 md:px-6">
        <MobileNavSheet :groups="groups" :home-href="homeHref" />
        <p class="min-w-0 truncate text-sm font-medium">{{ sectionTitle }}</p>
        <div class="ml-auto shrink-0">
            <UserMenu />
        </div>
    </header>
</template>
