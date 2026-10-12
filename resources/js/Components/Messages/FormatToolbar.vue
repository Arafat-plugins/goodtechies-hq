<script lang="ts">
/** Polish 047: what the composer's formatting toolbar and shortcuts can apply. */
export type FormatKind = 'bold' | 'italic' | 'underline' | 'strike' | 'code' | 'spoiler' | 'quote' | 'codeblock';
</script>

<script setup lang="ts">
/**
 * Polish 047: a row of formatting buttons that appears above the composer while text in it is
 * selected (Discord-style). Every button keeps the selection — `mousedown.prevent` stops the
 * click taking focus from the field — and has a name and a tooltip with its shortcut.
 */
import { Bold, Code, EyeOff, Italic, SquareCode, Strikethrough, TextQuote, Underline } from '@lucide/vue';
import type { Component } from 'vue';
import { Button } from '@/Components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';

const emit = defineEmits<{ format: [kind: FormatKind] }>();

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/u.test(navigator.platform);
const mod = isMac ? '⌘' : 'Ctrl+';

const TOOLS: { kind: FormatKind; label: string; icon: Component; shortcut?: string }[] = [
    { kind: 'bold', label: 'Bold', icon: Bold, shortcut: `${mod}B` },
    { kind: 'italic', label: 'Italic', icon: Italic, shortcut: `${mod}I` },
    { kind: 'underline', label: 'Underline', icon: Underline, shortcut: `${mod}U` },
    { kind: 'strike', label: 'Strikethrough', icon: Strikethrough, shortcut: `${mod}Shift+X` },
    { kind: 'code', label: 'Code', icon: Code, shortcut: `${mod}E` },
    { kind: 'spoiler', label: 'Spoiler', icon: EyeOff },
    { kind: 'quote', label: 'Quote', icon: TextQuote },
    { kind: 'codeblock', label: 'Code block', icon: SquareCode },
];
</script>

<template>
    <div
        role="toolbar"
        aria-label="Format the selected text"
        class="flex w-fit max-w-full flex-wrap items-center gap-0.5 self-start rounded-lg border bg-popover p-0.5 shadow-raised"
        data-testid="format-toolbar"
    >
        <TooltipProvider :delay-duration="150">
            <Tooltip v-for="tool in TOOLS" :key="tool.kind">
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground hover:text-foreground"
                        :aria-label="tool.label"
                        :data-format="tool.kind"
                        @mousedown.prevent
                        @click="emit('format', tool.kind)"
                    >
                        <component :is="tool.icon" aria-hidden="true" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ tool.shortcut ? `${tool.label} (${tool.shortcut})` : tool.label }}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </div>
</template>
