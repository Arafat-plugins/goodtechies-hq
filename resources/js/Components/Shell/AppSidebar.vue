<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppWordmark from '@/Components/AppWordmark.vue';
import AppSidebarNav from '@/Components/Shell/AppSidebarNav.vue';
import SidebarComingSoon from '@/Components/Shell/SidebarComingSoon.vue';
import SidebarRailToggle from '@/Components/Shell/SidebarRailToggle.vue';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { cn } from '@/lib/utils';
import { setSidebarRail, useSidebarRail } from '@/lib/sidebarState';
import type { NavGroup } from '@/navigation/types';
import { ROLE_LABELS } from '@/navigation/types';

defineProps<{
    groups: NavGroup[];
    homeHref: string;
}>();

const page = usePage();

const user = computed(() => page.props.auth.user);
const roleLabel = computed(() => (user.value?.role ? ROLE_LABELS[user.value.role] : ''));

/**
 * The rail flag is module-level state, so the three layouts pad their content by the same
 * value without the sidebar having to tell them: `lg:pl-64` ↔ `lg:pl-14`.
 */
const rail = useSidebarRail();

const initials = computed(() =>
    (user.value?.name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join(''),
);
</script>

<template>
    <aside
        :class="
            cn(
                'fixed inset-y-0 left-0 z-30 hidden flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground lg:flex',
                'transition-[width] duration-200 motion-reduce:transition-none',
                rail ? 'w-14' : 'w-64',
            )
        "
    >
        <div
            :class="
                cn(
                    'flex h-14 shrink-0 items-center border-b border-sidebar-border',
                    rail ? 'justify-center px-0' : 'px-4',
                )
            "
        >
            <Link :href="homeHref" class="rounded-md focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none">
                <AppWordmark :variant="rail ? 'mark' : 'lockup'" surface="light" />
            </Link>
        </div>
        <ScrollArea class="min-h-0 flex-1">
            <AppSidebarNav :groups="groups" :rail="rail" />
        </ScrollArea>
        <!-- Rail mode drops the disclosure rather than keeping a "more" icon for work
             nobody can open yet: expanding the sidebar is the way back to it. -->
        <SidebarComingSoon v-if="!rail" :groups="groups" />
        <div
            v-if="user"
            :class="cn('shrink-0 border-t border-sidebar-border py-3', rail ? 'flex justify-center px-1' : 'px-4')"
        >
            <template v-if="rail">
                <span
                    :title="`${user.name} — ${roleLabel}`"
                    class="flex size-8 items-center justify-center rounded-full bg-sidebar-border text-xs font-medium"
                >
                    <span aria-hidden="true">{{ initials }}</span>
                    <span class="sr-only">{{ user.name }}, {{ roleLabel }}</span>
                </span>
            </template>
            <template v-else>
                <p class="truncate text-sm font-medium">{{ user.name }}</p>
                <p class="truncate text-xs text-sidebar-foreground-muted">{{ roleLabel }}</p>
            </template>
        </div>
        <SidebarRailToggle :rail="rail" @update:rail="setSidebarRail" />
    </aside>
</template>
