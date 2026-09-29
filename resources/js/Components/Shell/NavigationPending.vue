<script setup lang="ts">
/**
 * The shell's loading state for a slow navigation (slow-loading slice 6).
 *
 * The layout owns the state (`useShellNavigationPending()`), because the other two parts of it
 * live on `<main>` itself: `aria-busy="true"` and the slight dim of the page underneath. This
 * component draws the rest — a thin line pinned just under the top bar, across the content
 * column, and the words a screen reader hears.
 *
 * It is mounted just BEFORE `<main>`, not inside it: several screen readers hold back every
 * live-region change inside an `aria-busy` subtree until it stops being busy, which would say
 * "Loading…" exactly when loading had finished.
 *
 * - The live region is **always mounted** and only its text changes. A region inserted together
 *   with its text is not announced by most screen readers; one that was already there is.
 * - The line takes no height (`h-0` wrapper), so it appearing and going never moves the page. The
 *   wrapper sticks at the top bar's height (`top-14`), so the line hugs the bar's lower edge
 *   whether the page is scrolled or not.
 * - Neutral, not orange: the accent is spent once per screen (DESIGN.md §5.3), and Inertia's own
 *   progress bar at the very top of the window already carries it.
 * - `motion-reduce` keeps the line but stops it pulsing.
 */
defineProps<{
    pending: boolean;
}>();
</script>

<template>
    <div class="pointer-events-none sticky top-14 z-10 h-0">
        <div
            v-show="pending"
            class="absolute inset-x-0 top-0 h-0.5 animate-pulse bg-muted-foreground/40 motion-reduce:animate-none"
            aria-hidden="true"
        />
        <p class="sr-only" role="status" aria-live="polite">{{ pending ? 'Loading…' : '' }}</p>
    </div>
</template>
