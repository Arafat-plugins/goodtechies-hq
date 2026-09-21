<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import type { Component } from 'vue';
import {
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
} from '@/Components/ui/dropdown-menu';
import type { ThemeMode } from '@/lib/theme';
import { isThemeMode, setTheme, useTheme } from '@/lib/theme';

/**
 * Light / Dark / System, as a radio group inside the user menu — where the reference
 * products put it, and out of a top bar that already has four controls.
 *
 * Choosing does not close the menu, so the three states can be tried against the page
 * behind it. The preference is written to `localStorage` by `lib/theme.ts`; the inline
 * script in `app.blade.php` reads it back before the next page's first paint.
 */

const mode = useTheme();

const options: { value: ThemeMode; label: string; icon: Component }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

function choose(value: unknown): void {
    if (isThemeMode(value)) {
        setTheme(value);
    }
}
</script>

<template>
    <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Theme</DropdownMenuLabel>
    <DropdownMenuRadioGroup :model-value="mode" @update:model-value="choose">
        <DropdownMenuRadioItem
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            @select="(event: Event) => event.preventDefault()"
        >
            <component :is="option.icon" aria-hidden="true" />
            {{ option.label }}
        </DropdownMenuRadioItem>
    </DropdownMenuRadioGroup>
</template>
