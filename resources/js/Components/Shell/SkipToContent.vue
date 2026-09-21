<script setup lang="ts">
/**
 * WCAG 2.4.1 (Level A). Every shell screen put 8–14 tab stops — wordmark, nav groups, rail
 * toggle, search, create, bell, user menu — between a keyboard user and the page body, and every
 * Inertia visit sent them back to the start of that walk. This is the first tabbable element in
 * the document and the only way past it.
 *
 * Two things it deliberately does:
 *
 * - It **moves focus**, not just the scroll position. `<main>` carries `tabindex="-1"` so it can
 *   hold focus; the browser's own fragment handling only scrolls reliably, and under Inertia the
 *   hash would also end up in the history entry, so the click is intercepted instead. `href` stays
 *   on the element because that is what makes it a link to assistive tech.
 * - It is off-screen rather than `display: none`, so it stays in the tab order, and it is `fixed`
 *   with `-top-16`, so it never occupies layout. `focus:top-4` is a higher-specificity rule on the
 *   same property, so the two can't fight. No transition: there is nothing here to animate.
 */
const targetId = 'main-content';

function skip(): void {
    const main = document.getElementById(targetId);

    if (!main) {
        return;
    }

    main.focus();
    main.scrollIntoView();
}
</script>

<template>
    <a
        :href="`#${targetId}`"
        class="fixed -top-16 left-4 z-50 rounded-md border bg-card px-4 py-2 text-sm font-medium text-card-foreground shadow-overlay outline-none focus:top-4 focus:border-ring focus:ring-3 focus:ring-ring/50"
        @click.prevent="skip"
    >
        Skip to main content
    </a>
</template>
