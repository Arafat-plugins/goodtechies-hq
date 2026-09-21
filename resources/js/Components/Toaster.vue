<script setup lang="ts">
import 'vue-sonner/style.css';
import { Toaster } from '@/Components/ui/sonner';

/**
 * The app's one toaster. Mounted once per layout; `lib/toast.ts` is how anything
 * pushes to it. Server flash messages stay with FlashMessage.vue — nothing routes
 * a flash through here, so no message is shown twice.
 *
 * Sonner ships its own unlayered CSS, which outranks a Tailwind utility class, so
 * the elevation and the status tints are handed over as CSS variables and inline
 * styles. Every value is a token; there is no literal colour here.
 */
const typeTokens = {
    '--success-bg': 'var(--status-done-bg)',
    '--success-border': 'var(--status-done-border)',
    '--success-text': 'var(--status-done-fg)',
    /* --status-cancelled-* IS the destructive hue, at the tint/foreground pair that
     * clears contrast on both canvases; plain --destructive does not on its own tint. */
    '--error-bg': 'var(--status-cancelled-bg)',
    '--error-border': 'var(--status-cancelled-border)',
    '--error-text': 'var(--status-cancelled-fg)',
    '--info-bg': 'var(--status-progress-bg)',
    '--info-border': 'var(--status-progress-border)',
    '--info-text': 'var(--status-progress-fg)',
    '--warning-bg': 'var(--status-review-bg)',
    '--warning-border': 'var(--status-review-border)',
    '--warning-text': 'var(--status-review-fg)',
};

/* Loading stays neutral: it inherits --normal-bg (var(--popover)) from ui/sonner. */
const toastOptions = {
    style: { boxShadow: 'var(--elevation-overlay)' },
    classes: {
        toast: 'font-sans',
        title: 'text-sm font-medium',
        description: 'text-xs',
    },
};
</script>

<template>
    <Toaster
        position="bottom-right"
        rich-colors
        :offset="16"
        :duration="5000"
        :style="typeTokens"
        :toast-options="toastOptions"
    />
</template>
