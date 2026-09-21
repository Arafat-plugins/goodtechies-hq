<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/Components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';
import { cn } from '@/lib/utils';
import { readGroupOpen, writeGroupOpen } from '@/lib/sidebarState';
import type { NavGroup } from '@/navigation/types';
import { comingSoonItems } from '@/navigation/types';

/**
 * Every nav row that is not built yet, in one disclosure pinned above the user block and
 * closed by default (Decision 0.5-3). The main rail stops advertising work nobody can do
 * today, and the rows keep the accessibility they had in the groups: no link, `aria-disabled`,
 * the `title`, the `P<n>` badge and the sr-only "arrives in Phase N".
 *
 * The order is the nav data's own order, group by group, so a row lands where the person
 * who reads the nav file would expect it.
 */
const props = defineProps<{
    groups: NavGroup[];
}>();

const GROUP_LABEL = 'Coming soon';

const page = usePage();

const role = computed(() => page.props.auth.user?.role ?? 'guest');
const items = computed(() => comingSoonItems(props.groups));

const open = ref(readGroupOpen(role.value, GROUP_LABEL) ?? false);

function setOpen(next: boolean): void {
    open.value = next;
    writeGroupOpen(role.value, GROUP_LABEL, next);
}

const rowClass = 'flex h-9 items-center gap-2 rounded-md px-3 text-sm';
</script>

<template>
    <Collapsible
        v-if="items.length > 0"
        :open="open"
        class="shrink-0 border-t border-sidebar-border p-2"
        @update:open="setOpen"
    >
        <CollapsibleTrigger
            class="flex h-8 w-full items-center gap-2 rounded-md px-3 text-xs font-medium tracking-wider text-sidebar-foreground-muted uppercase transition-colors hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none"
        >
            <span class="truncate">{{ GROUP_LABEL }}</span>
            <span aria-hidden="true" class="tabular-nums">{{ items.length }}</span>
            <ChevronDown
                aria-hidden="true"
                :class="
                    cn('ml-auto size-3.5 shrink-0 transition-transform motion-reduce:transition-none', !open && '-rotate-90')
                "
            />
        </CollapsibleTrigger>
        <CollapsibleContent>
            <ul class="mt-1 max-h-64 overflow-y-auto">
                <li v-for="(item, index) in items" :key="`${item.label}-${index}`">
                    <span
                        aria-disabled="true"
                        :title="`Arrives in Phase ${item.phase}`"
                        :class="cn(rowClass, 'cursor-not-allowed text-sidebar-foreground-muted opacity-50')"
                    >
                        <component :is="item.icon" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="truncate">{{ item.label }}</span>
                        <span class="sr-only">, arrives in Phase {{ item.phase }}</span>
                        <Badge
                            variant="outline"
                            aria-hidden="true"
                            class="ml-auto border-sidebar-border px-1.5 text-sidebar-foreground-muted"
                        >
                            P{{ item.phase }}
                        </Badge>
                    </span>
                </li>
            </ul>
        </CollapsibleContent>
    </Collapsible>
</template>
