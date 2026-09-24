<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import { iconFor } from '@/Components/Files/files';
import type { ThreadAttachment } from '@/Components/Messages/messages';
import { formatDuration } from '@/Components/Messages/messages';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * One attachment, as a file card: glyph, name, size, who uploaded it, and a download.
 *
 * Shared by the thread and the context panel's *Shared files* section, because the two are the
 * same payload — `FileSummary` plus the `kind` the messaging resource adds — and a card drawn
 * twice is a card that grows a field on one screen and not the other.
 *
 * ## The links expire, and that is a state this card has
 *
 * `url` is a signed, expiring link and **not** a bearer token: `FilePolicy::view` runs on every
 * fetch. When the thread above knows its signatures have lapsed it passes `stale`, and the card
 * stops offering a link it knows is dead — the name stays, as text, because "this file exists
 * and its link needs refreshing" is a truer thing to show than a button that 404s.
 *
 * ## The card owns its foreground, and that is a bug fix
 *
 * The file name was `text-xs font-medium` with no colour, so it INHERITED. Inside a DM's own
 * bubble the inherited colour is `--primary-foreground` — `#FCFCFC` on this card's `#FFFFFF`,
 * a file name at 1.01:1. A card that declares its own surface has to declare its own foreground
 * with it, so `text-card-foreground` is now on the root and nothing in here inherits from
 * whatever the card was dropped into. Every pair inside is then measured against `--card`:
 * 13.63:1 / 16.25:1 for the name, 5.51:1 / 6.63:1 for the meta line.
 *
 * `onAccent` only drops the hairline. `--border` on a `--primary` fill is a grey line on coral
 * that measures 1.31:1 and reads as grime; the card's own fill against the bubble is the edge
 * (5.13:1 light / 6.66:1 dark), which is a stronger boundary than the border ever was.
 */

const props = withDefaults(
    defineProps<{
        file: ThreadAttachment;
        /** The signed URLs on this payload have lapsed; draw the card without its link. */
        stale?: boolean;
        /** Render an image or a voice note in place, above the card's own line. */
        inline?: boolean;
        /** This card sits inside a `--primary` bubble: drop the hairline, keep the surface. */
        onAccent?: boolean;
    }>(),
    { stale: false, inline: false, onAccent: false },
);

const rendersImage = computed(() => props.inline && !props.stale && props.file.kind === 'image');
const rendersVoice = computed(() => props.inline && !props.stale && props.file.kind === 'voice');

const meta = computed(() => {
    const parts = [props.file.size_label];
    const duration = formatDuration(props.file.duration_seconds);

    if (duration !== '') {
        parts.push(duration);
    }

    if (props.file.uploaded_by?.name) {
        parts.push(props.file.uploaded_by.name);
    }

    return parts.join(' · ');
});
</script>

<template>
    <div
        :class="
            cn(
                'flex min-w-0 flex-col gap-2 rounded-md border bg-card p-2 text-card-foreground shadow-flat',
                onAccent && 'border-transparent',
            )
        "
    >
        <a
            v-if="rendersImage"
            :href="file.url"
            target="_blank"
            rel="noopener noreferrer"
            class="min-w-0 rounded-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <img
                :src="file.url"
                :alt="file.name"
                loading="lazy"
                class="max-h-64 w-auto max-w-full rounded-sm"
            >
            <span class="sr-only">(opens in a new tab)</span>
        </a>

        <!--
            Nothing records audio yet. This is here so a voice note written by the later slice
            plays, instead of arriving as a download link nobody expected.
        -->
        <audio v-else-if="rendersVoice" :src="file.url" controls class="w-full min-w-0"></audio>

        <div class="flex min-w-0 items-center gap-2">
            <span
                :class="
                    cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-md bg-muted',
                        'text-muted-foreground',
                    )
                "
            >
                <component :is="iconFor(file)" class="size-4" aria-hidden="true" />
            </span>

            <span class="flex min-w-0 flex-1 flex-col">
                <span class="min-w-0 truncate text-xs font-medium" :title="file.name">
                    {{ file.name }}
                </span>
                <span class="min-w-0 truncate text-xs text-muted-foreground">{{ meta }}</span>
            </span>

            <TooltipProvider v-if="!stale" :delay-duration="150">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <a
                            :href="file.url"
                            :target="file.is_previewable ? '_blank' : undefined"
                            rel="noopener noreferrer"
                            :aria-label="`Download ${file.name}`"
                            class="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <Download class="size-4" aria-hidden="true" />
                            <span v-if="file.is_previewable" class="sr-only">
                                (opens in a new tab)
                            </span>
                        </a>
                    </TooltipTrigger>
                    <TooltipContent>Download</TooltipContent>
                </Tooltip>
            </TooltipProvider>

            <span v-if="stale" class="shrink-0 text-xs text-muted-foreground">Link expired</span>
        </div>
    </div>
</template>
