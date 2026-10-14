<script setup lang="ts">
/**
 * Polish 046: a dashboard card's icon in a tinted circle (see tones.ts). Decorative — the
 * heading or label beside it says what the card is.
 */
import type { Component } from 'vue';
import { cn } from '@/lib/utils';
import { type Tone, toneIconClass } from '@/Components/Dashboard/tones';

withDefaults(defineProps<{ icon: Component; tone: Tone; size?: 'sm' | 'md' }>(), { size: 'sm' });
</script>

<template>
    <span
        :class="
            cn(
                // No circle behind the icon any more (client request 2026-10-14): the box keeps its
                // size so the label beside it does not move, the icon carries the tone's colour.
                'flex shrink-0 items-center justify-center',
                size === 'md' ? 'size-8 sm:size-10' : 'size-8',
                toneIconClass(tone),
            )
        "
        aria-hidden="true"
    >
        <component :is="icon" :class="size === 'md' ? 'size-4 sm:size-5' : 'size-4'" />
    </span>
</template>
