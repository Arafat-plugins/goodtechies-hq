<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';
import type { NavGroup } from '@/navigation/types';
import { isActiveHref } from '@/navigation/types';

defineProps<{
    groups: NavGroup[];
}>();

const emit = defineEmits<{
    navigate: [];
}>();

const page = usePage();

const rowClass = 'flex h-9 items-center gap-2 rounded-md px-3 text-sm';
</script>

<template>
    <nav aria-label="Main" class="flex flex-col gap-6 p-4">
        <div v-for="group in groups" :key="group.label" class="flex flex-col gap-1">
            <p class="px-3 pb-1 text-xs font-medium tracking-wider text-sidebar-foreground/60 uppercase">
                {{ group.label }}
            </p>
            <ul class="flex flex-col gap-1">
                <li v-for="item in group.items" :key="item.label">
                    <Link
                        v-if="item.href && !item.phase"
                        :href="item.href"
                        :aria-current="isActiveHref(page.url, item.href) ? 'page' : undefined"
                        :class="
                            cn(
                                rowClass,
                                'transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none',
                                isActiveHref(page.url, item.href)
                                    ? 'bg-sidebar-primary font-medium text-sidebar-primary-foreground'
                                    : 'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                            )
                        "
                        @click="emit('navigate')"
                    >
                        <component :is="item.icon" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="truncate">{{ item.label }}</span>
                    </Link>
                    <span
                        v-else
                        aria-disabled="true"
                        :title="`Arrives in Phase ${item.phase}`"
                        :class="cn(rowClass, 'cursor-not-allowed text-sidebar-foreground/80 opacity-50')"
                    >
                        <component :is="item.icon" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="truncate">{{ item.label }}</span>
                        <span class="sr-only">, arrives in Phase {{ item.phase }}</span>
                        <Badge
                            variant="outline"
                            aria-hidden="true"
                            class="ml-auto border-sidebar-border px-1.5 text-sidebar-foreground"
                        >
                            P{{ item.phase }}
                        </Badge>
                    </span>
                </li>
            </ul>
        </div>
    </nav>
</template>
