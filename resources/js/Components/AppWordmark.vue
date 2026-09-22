<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { CSSProperties, HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * The only sanctioned use of the GoodTechies mark.
 *
 * The SVG below is `public/brand/mark.svg` inlined (not an <img>) so the lockup text can
 * inherit `currentColor` while the artwork keeps its own three colours. Nothing here
 * recolours or re-rounds the mark: the tile's corner radius is the artwork's own
 * 50/1080 = 4.6 %, never a Tailwind `rounded-*` class.
 *
 * `surface="dark"` drops the tile path (the `public/brand/mark-bare.svg` variant) so a dark
 * surface shows no black square seam; the glyphs stay white either way, which reads on
 * `--brand-ink` and keeps the mark's colours identical on both surfaces.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'lockup' | 'mark';
        surface?: 'light' | 'dark';
        /** Rendered mark size in px. 28 in the shell, 32 on the auth card. */
        size?: number;
        class?: HTMLAttributes['class'];
    }>(),
    {
        variant: 'lockup',
        surface: 'light',
        size: 28,
    },
);

/**
 * Optical match, derived from the artwork rather than guessed:
 * the `G` spans y 292.12 → 787.80 of the 1080 viewBox, so its cap height is 0.459 × the
 * mark. Inter's cap height is 0.727 em, so the text is sized to put the same cap height
 * next to it. The `G` is centred in the tile (its midpoint is y 540), and with
 * `leading-none` a cap box is centred in its line box, so `items-center` lands both on one
 * baseline.
 */
const MARK_CAP_RATIO = 495.68 / 1080;
const INTER_CAP_RATIO = 0.727;

const lockupStyle = computed<CSSProperties>(() => ({
    /* the gap is 0.5 × the mark's width */
    gap: `${props.size / 2}px`,
}));

const textStyle = computed<CSSProperties>(() => ({
    fontSize: `${((props.size * MARK_CAP_RATIO) / INTER_CAP_RATIO).toFixed(2)}px`,
}));

/**
 * The product name, from the shared `app.name` prop — which is `config('app.name')`, which is
 * APP_NAME. One source, so renaming the product is one line in `.env` and never a hunt through
 * components for a literal somebody forgot.
 */
const appName = computed(() => (usePage().props.app?.name as string | undefined) ?? 'goodERP');
</script>

<template>
    <span
        :class="cn('inline-flex items-center text-sidebar-foreground', props.class)"
        :style="variant === 'lockup' ? lockupStyle : undefined"
    >
        <svg
            :width="size"
            :height="size"
            viewBox="0 0 1080 1080"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            class="block shrink-0"
            aria-hidden="true"
        >
            <!-- tile: #0A0D12, corners rounded by the artwork itself (r = 50 on 1080) -->
            <path
                v-if="surface === 'light'"
                d="M1030 0H50C22.3858 0 0 22.3858 0 50V1030C0 1057.61 22.3858 1080 50 1080H1030C1057.61 1080 1080 1057.61 1080 1030V50C1080 22.3858 1057.61 0 1030 0Z"
                fill="#0A0D12"
            />
            <!-- glyph: G -->
            <path
                d="M381.28 589.52C295.51 589.52 228.71 526.95 228.71 440.47C228.71 353.29 295.5 292.12 389.01 292.12H560.56V295.64L528.22 357.51H514.16C535.95 379.3 548.61 408.13 548.61 441.18C548.61 480.55 531.03 515.71 505.02 533.99V536.1C539.47 552.97 561.27 595.16 561.27 642.97C561.27 728.04 490.96 787.8 388.31 787.8C311.67 787.8 246.29 754.75 212.54 699.91L273.71 645.07C299.02 684.44 342.61 709.05 390.42 709.05C438.93 709.05 472.68 683.04 472.68 645.07C472.68 611.32 448.07 588.82 413.62 588.82C403.08 588.81 398.86 589.52 381.28 589.52ZM315.19 440.46C315.19 481.24 345.42 512.17 388.31 512.17C431.2 512.17 462.84 481.24 462.84 440.46C462.84 401.79 431.2 369.45 388.31 369.45C346.13 369.45 315.19 401.09 315.19 440.46Z"
                fill="white"
            />
            <!-- glyph: T -->
            <path
                d="M833.12 367.26H724.05V324.66C724.05 299.85 703.94 279.75 679.14 279.75C654.33 279.75 634.23 299.86 634.23 324.66V758.12C634.23 782.93 654.34 803.03 679.14 803.03C703.95 803.03 724.05 782.92 724.05 758.12V457.09H833.11C857.92 457.09 878.02 436.98 878.02 412.18C878.03 387.37 857.92 367.26 833.12 367.26Z"
                fill="white"
            />
            <!-- the dot — the smallest element, so the mark's legibility floor -->
            <path
                d="M848 801.07C872.555 801.07 892.46 781.165 892.46 756.61C892.46 732.055 872.555 712.15 848 712.15C823.445 712.15 803.54 732.055 803.54 756.61C803.54 781.165 823.445 801.07 848 801.07Z"
                fill="#F04E27"
            />
        </svg>
        <!-- stands in for the wordmark the client has not supplied: Inter Semibold -->
        <span
            v-if="variant === 'lockup'"
            class="font-semibold leading-none tracking-tight whitespace-nowrap"
            :style="textStyle"
        >
            {{ appName }}
        </span>
        <span v-else class="sr-only">{{ appName }}</span>
    </span>
</template>
