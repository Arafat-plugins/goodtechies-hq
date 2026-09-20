<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppWordmark from '@/Components/AppWordmark.vue';
import AppSidebarNav from '@/Components/Shell/AppSidebarNav.vue';
import { ScrollArea } from '@/Components/ui/scroll-area';
import type { NavGroup } from '@/navigation/types';
import { ROLE_LABELS } from '@/navigation/types';

defineProps<{
    groups: NavGroup[];
    homeHref: string;
}>();

const page = usePage();

const user = computed(() => page.props.auth.user);
const roleLabel = computed(() => (user.value?.role ? ROLE_LABELS[user.value.role] : ''));
</script>

<template>
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-sidebar text-sidebar-foreground lg:flex">
        <div class="flex h-14 shrink-0 items-center border-b border-sidebar-border px-4">
            <Link :href="homeHref" class="rounded-md focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none">
                <AppWordmark inverted />
            </Link>
        </div>
        <ScrollArea class="min-h-0 flex-1">
            <AppSidebarNav :groups="groups" />
        </ScrollArea>
        <div v-if="user" class="shrink-0 border-t border-sidebar-border px-4 py-3">
            <p class="truncate text-sm font-medium">{{ user.name }}</p>
            <p class="truncate text-xs text-sidebar-foreground/60">{{ roleLabel }}</p>
        </div>
    </aside>
</template>
