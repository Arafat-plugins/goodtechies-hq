<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, FolderKanban, ListChecks, Plus } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

/**
 * The `+` menu. It lists only what exists today, which is why Employee and Accountant get
 * no button at all rather than a disabled one — a control that never does anything is worse
 * than no control. Each phase that adds a create route adds its row here.
 */

interface CreateAction {
    label: string;
    href: string;
    icon: Component;
}

const page = usePage();

const actions = computed<CreateAction[]>(() => {
    if (page.props.auth.user?.role !== 'ADMIN') {
        return [];
    }

    return [
        { label: 'New client', href: '/admin/clients/create', icon: Building2 },
        { label: 'New project', href: '/admin/projects/create', icon: FolderKanban },
        /*
         * A task has no create PAGE — it is a modal, and the modal needs the project and
         * employee lists that only the Tasks List carries. So this row goes to the list with
         * `?new=1`, which is what opens it there. That keeps the shareable state in the URL
         * (DESIGN.md §5.10) and means the menu has no modal of its own to keep in step with
         * the one on the list.
         */
        { label: 'New task', href: '/admin/tasks?new=1', icon: ListChecks },
    ];
});
</script>

<template>
    <!-- Hidden below `md`: at 375 the bar keeps navigation, not authoring. -->
    <DropdownMenu v-if="actions.length">
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="icon" class="hidden size-9 md:inline-flex">
                <Plus class="size-4" aria-hidden="true" />
                <span class="sr-only">Create</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-48">
            <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Create</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem v-for="action in actions" :key="action.href" as-child>
                <Link :href="action.href" class="w-full">
                    <component :is="action.icon" aria-hidden="true" />
                    {{ action.label }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
