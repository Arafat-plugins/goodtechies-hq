<script lang="ts">
/**
 * Brief 009, no flashing: the first url an image was drawn with, by file id. A re-read that
 * re-signs the link does not change what the `<img>` loads, so the picture never blinks. An
 * image that errors drops its entry, so the next render takes the fresh url.
 */
const imageUrls = new Map<number, string>();

/**
 * Pictures this tab has already drawn, by file id: reopening a chat shows them at once instead
 * of shimmering again for a picture the browser already has.
 */
const drawnImages = new Set<number>();
</script>

<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import { iconFor } from '@/Components/Files/files';
import type { ThreadAttachment } from '@/Components/Messages/messages';
import { formatDuration } from '@/Components/Messages/messages';
import ImageLightbox from '@/Components/Messages/ImageLightbox.vue';
import VoicePlayer from '@/Components/Messages/VoicePlayer.vue';
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
 *
 * A voice note is the exception since 12-77: the card draws no surface for it at all and the
 * `VoicePlayer` sits directly on the bubble, so it is passed `onAccent` and recolours itself to
 * `--bubble-own-foreground` on the viewer's own bubble (~9:1, bubble-own, 12-77).
 */

const props = withDefaults(
    defineProps<{
        file: ThreadAttachment;
        /** The signed URLs on this payload have lapsed; draw the card without its link. */
        stale?: boolean;
        /** Render an image or a voice note in place, above the card's own line. */
        inline?: boolean;
        /** This card sits inside the own `--bubble-own` bubble: drop the hairline, keep the surface. */
        onAccent?: boolean;
        /**
         * A picture in a chat bubble, Telegram-style (2026-10-04): only the picture, rounded —
         * no card frame around it and no name/size line under it. The download is in the
         * lightbox the picture opens. Files that are not pictures keep their card.
         */
        bare?: boolean;
    }>(),
    { stale: false, inline: false, onAccent: false, bare: false },
);

const rendersImage = computed(() => props.inline && !props.stale && props.file.kind === 'image');
const rendersVoice = computed(() => props.inline && !props.stale && props.file.kind === 'voice');
const bareImage = computed(() => props.bare && rendersImage.value);

/** Brief 013: the image's lightbox, and the thumbnail focus returns to when it closes. */
const lightboxOpen = ref(false);
const thumbEl = ref<HTMLButtonElement | null>(null);

/** Bumped when an image errors, so `imageUrl` reads the map again. */
const imageEpoch = ref(0);

const imageUrl = computed(() => {
    void imageEpoch.value;

    const held = imageUrls.get(props.file.id);

    if (held !== undefined) {
        return held;
    }

    imageUrls.set(props.file.id, props.file.url);

    return props.file.url;
});

/**
 * Until the picture has arrived its place shimmers (2026-10-04, the client: "photo voice, before
 * they completely load, give there shimmer effect"). The `<img>` is in the page the whole time —
 * invisible and laid over the placeholder — so the browser fetches it as usual; when it lands,
 * the placeholder goes and the picture takes its own size.
 */
const imageReady = ref(drawnImages.has(props.file.id));

function onImageLoad(): void {
    drawnImages.add(props.file.id);
    imageReady.value = true;
}

function onImageError(): void {
    if (imageUrls.get(props.file.id) !== props.file.url) {
        imageUrls.delete(props.file.id);
        imageEpoch.value += 1;

        return;
    }

    // Nothing fresher to try: stop shimmering and let the picture's name stand in for it.
    imageReady.value = true;
}

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
    <!--
        Messaging polish: an attachment is an object of its own size, not a strip across the
        thread. The card is `w-fit` and capped — a file card at `max-w-xs` (20rem), an image at
        its natural aspect ratio inside `max-w-xs` × `max-h-64` — so a PDF name and a photo sit
        in the conversation the way a file sits in any chat, and the author line above stays
        the author line.

        Only the card is a link. The image opens a lightbox (brief 013); a file card is ONE link (glyph, name,
        size, the download arrow) that opens or downloads the file exactly as the old arrow did;
        a voice note is only its player (12-77: Telegram-style, no name, size or download row,
        no card around it). Nothing outside the card —
        the message row, its author, the bubble — is clickable.
    -->
    <div
        :class="
            cn(
                'flex max-w-full min-w-0 flex-col gap-1.5',
                // 12-77: a voice note is only its player, sitting straight on the bubble and
                // taking the bubble's own foreground — no card, no border, no padding.
                rendersVoice || bareImage
                    ? cn('border-0 bg-transparent p-0', rendersVoice ? 'w-64' : 'w-fit')
                    : 'w-fit rounded-lg border bg-card p-1.5 text-card-foreground shadow-flat',
                !rendersImage && !rendersVoice && 'w-full sm:max-w-xs',
                onAccent && !rendersVoice && 'border-transparent',
            )
        "
    >
        <!-- Brief 013: the image opens in a lightbox on this page, never in a new tab. -->
        <button
            v-if="rendersImage"
            ref="thumbEl"
            type="button"
            :aria-label="`Open ${file.name}`"
            aria-haspopup="dialog"
            :class="
                cn(
                    'relative block w-fit max-w-full min-w-0 cursor-zoom-in overflow-hidden bg-muted focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                    bareImage ? 'rounded-2xl' : 'rounded-md',
                    !imageReady && 'shimmer h-44 w-60',
                )
            "
            :aria-busy="!imageReady || undefined"
            data-testid="attachment-image"
            @click="lightboxOpen = true"
        >
            <img
                :src="imageUrl"
                :alt="file.name"
                loading="lazy"
                :class="
                    cn(
                        'block h-auto w-auto max-w-full object-contain',
                        bareImage ? 'max-h-80 sm:max-w-sm' : 'max-h-64 sm:max-w-xs',
                        !imageReady && 'absolute inset-0 size-full opacity-0',
                    )
                "
                @load="onImageLoad"
                @error="onImageError"
            >
        </button>

        <ImageLightbox
            v-if="rendersImage"
            v-model:open="lightboxOpen"
            :src="imageUrl"
            :name="file.name"
            :href="file.url"
            @closed="thumbEl?.focus()"
        />

        <!--
            A voice note plays here rather than arriving as a download link nobody expected.
            The total comes from `duration_seconds` on the payload and not from the file: a
            WebM stream written by `MediaRecorder` carries no duration in its header, so
            `audio.duration` for one is `Infinity` until the whole thing has been seeked.
        -->
        <VoicePlayer
            v-else-if="rendersVoice"
            :src="file.url"
            :cache-key="file.id"
            :duration-seconds="file.duration_seconds"
            label="voice message"
            :on-accent="onAccent"
        />

        <!-- An image's caption line: its name and a download, kept to the image's width. -->
        <div v-if="rendersImage && !bareImage" class="flex w-0 min-w-full items-center gap-2 px-1">
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="min-w-0 truncate text-xs font-medium" :title="file.name">
                    {{ file.name }}
                </span>
                <span class="min-w-0 truncate text-xs text-muted-foreground">{{ meta }}</span>
            </span>

            <TooltipProvider :delay-duration="150">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <a
                            :href="file.url"
                            :target="file.is_previewable ? '_blank' : undefined"
                            rel="noopener noreferrer"
                            :aria-label="`Download ${file.name}`"
                            class="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
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
        </div>

        <!-- A file: the whole compact card is the one link. -->
        <a
            v-else-if="!stale && !rendersVoice && !rendersImage"
            :href="file.url"
            :target="file.is_previewable ? '_blank' : undefined"
            :download="file.is_previewable ? undefined : file.name"
            rel="noopener noreferrer"
            :title="file.name"
            class="group/file flex min-w-0 items-center gap-2.5 rounded-md p-1 hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
        >
            <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <component :is="iconFor(file)" class="size-4" aria-hidden="true" />
            </span>

            <span class="flex min-w-0 flex-1 flex-col">
                <span class="min-w-0 truncate text-xs font-medium">
                    <span class="sr-only">{{ file.is_previewable ? 'Open' : 'Download' }} </span>{{ file.name }}
                </span>
                <span class="min-w-0 truncate text-xs text-muted-foreground">{{ meta }}</span>
            </span>

            <Download
                class="size-4 shrink-0 text-muted-foreground group-hover/file:text-accent-foreground"
                aria-hidden="true"
            />
            <span v-if="file.is_previewable" class="sr-only">(opens in a new tab)</span>
        </a>

        <!-- The links have lapsed: the name stays, as text, and says why it is not a link. -->
        <div v-else-if="stale" class="flex min-w-0 items-center gap-2.5 p-1">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <component :is="iconFor(file)" class="size-4" aria-hidden="true" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="min-w-0 truncate text-xs font-medium" :title="file.name">{{ file.name }}</span>
                <span class="min-w-0 truncate text-xs text-muted-foreground">{{ meta }}</span>
            </span>
            <span class="shrink-0 text-xs text-muted-foreground">Link expired</span>
        </div>
    </div>
</template>
