<script setup lang="ts">
import { Settings2 } from '@lucide/vue';
import { computed } from 'vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

/**
 * The column-visibility menu.
 *
 * `DropdownMenuCheckboxItem` is the checkbox primitive in menu form: it carries
 * `role="menuitemcheckbox"` and `aria-checked`, so each entry is reachable and announced
 * without nesting a focusable control inside a menu item.
 */
const props = defineProps<{
    columns: ColumnDef<never>[];
    /** Column keys currently hidden. */
    hidden: string[];
}>();

const emit = defineEmits<{ toggle: [key: string, visible: boolean] }>();

/** A column the screen marked `hideable: false` is structural and never offered. */
const options = computed(() => props.columns.filter((column) => column.hideable !== false));

function isVisible(key: string): boolean {
    return !props.hidden.includes(key);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm" aria-label="Choose columns">
                <Settings2 aria-hidden="true" />
                <span class="hidden sm:inline">Columns</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-48">
            <DropdownMenuLabel>Columns</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuCheckboxItem
                v-for="column in options"
                :key="column.key"
                :model-value="isVisible(column.key)"
                @select.prevent
                @update:model-value="(value) => emit('toggle', column.key, value === true)"
            >
                {{ column.header }}
            </DropdownMenuCheckboxItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
