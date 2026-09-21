<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import GlobalSearch from '@/Components/Shell/GlobalSearch.vue';
import MobileNavSheet from '@/Components/Shell/MobileNavSheet.vue';
import NotificationBell from '@/Components/Shell/NotificationBell.vue';
import QuickCreate from '@/Components/Shell/QuickCreate.vue';
import UserMenu from '@/Components/Shell/UserMenu.vue';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/Components/ui/breadcrumb';
import { buildBreadcrumbs } from '@/lib/breadcrumb';
import type { NavGroup } from '@/navigation/types';

/**
 * The app's navigation surface: where you are (breadcrumb) on the left, and the four ways
 * to move or act on the right — search, create, notifications, account.
 *
 * At 375 it carries the hamburger, the last breadcrumb crumb, the search icon, the bell
 * and the avatar; quick create and the leading crumbs are the two things that drop.
 */
const props = withDefaults(
    defineProps<{
        groups: NavGroup[];
        homeHref: string;
        /**
         * A last crumb the page supplies (a record's name). Left unset, it is derived from
         * the URL and, for an id segment, from the Inertia resource the page already has.
         */
        breadcrumb?: string | null;
    }>(),
    { breadcrumb: null },
);

const page = usePage();

const crumbs = computed(() =>
    buildBreadcrumbs(props.groups, page.url, {
        trailing: props.breadcrumb,
        pageProps: page.props as unknown as Record<string, unknown>,
    }),
);
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b bg-card px-4 md:px-6"
    >
        <MobileNavSheet :groups="groups" :home-href="homeHref" />
        <Breadcrumb v-if="crumbs.length" class="min-w-0">
            <BreadcrumbList class="flex-nowrap gap-1.5 text-sm">
                <template v-for="(crumb, index) in crumbs" :key="crumb.label">
                    <!-- Below `md` only the current page shows; the trail above it is context. -->
                    <BreadcrumbItem
                        :class="index < crumbs.length - 1 ? 'hidden min-w-0 md:inline-flex' : 'min-w-0'"
                    >
                        <BreadcrumbLink v-if="crumb.href" as-child>
                            <Link :href="crumb.href" class="truncate">{{ crumb.label }}</Link>
                        </BreadcrumbLink>
                        <BreadcrumbPage v-else class="truncate font-medium">
                            {{ crumb.label }}
                        </BreadcrumbPage>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator
                        v-if="index < crumbs.length - 1"
                        class="hidden md:inline-flex"
                    />
                </template>
            </BreadcrumbList>
        </Breadcrumb>
        <div class="ml-auto flex shrink-0 items-center gap-1 md:gap-2">
            <GlobalSearch :groups="groups" />
            <QuickCreate />
            <NotificationBell />
            <UserMenu />
        </div>
    </header>
</template>
