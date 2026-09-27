<script setup lang="ts">
import { Pause, Play } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { claimPlayback, formatClock, releasePlayback, spokenClock } from '@/Components/Messages/voice';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * A voice message, played.
 *
 * Play/pause, elapsed of total, a seekable position control and 1× / 1.5× / 2×. It is used
 * twice — inside `AttachmentCard` for a note that has been sent, and inside `VoiceRecorder` as
 * the preview of one that has not — because a preview that played differently from the thing it
 * previews is a preview of something else.
 *
 * ## The total comes from the payload, not from the file
 *
 * `MediaRecorder` writes a WebM stream with no duration in its header, so `audio.duration` for a
 * freshly recorded blob is `Infinity` in Chrome and Firefox until the whole thing has been
 * seeked through. That is why `message_attachments.duration_seconds` exists and why the recorder
 * measures its own elapsed time: the number is known where the recording happened, and it
 * travels with the file. `audio.duration` is used only as a fallback, and only when it is
 * finite. When neither is available the control degrades to elapsed-only and the slider is
 * disabled rather than lying about where in the file you are.
 *
 * ## One at a time
 *
 * Starting a note pauses the one before it, so a thread of five voice messages cannot become
 * five voices at once. The reference to whatever is playing lives in `voice.ts` and **not** at
 * the top of this block: a `<script setup>` top-level binding is re-created per instance, so a
 * `let current` here is one variable per player and pauses nothing. That was measured, in this
 * thread, with two notes on screen.
 *
 * ## The seek control reflects what the browser can actually do
 *
 * Scrubbing needs byte ranges. A `blob:` URL always has them, so the recorder's preview seeks
 * perfectly; a note that has been **sent** is fetched from the signed download route, and if
 * that response carries no `Accept-Ranges`, Chrome reports `audio.seekable` as `[0, 0]` and
 * ignores every write to `currentTime`. Measured, on this build: preview `[[0, 2.4]]` and seeks;
 * a sent note `[[0, 0]]` and does not.
 *
 * So the control **watches whether a seek actually landed** and believes nothing else. A write
 * to `currentTime` that the element silently ignores is the one unambiguous answer, and it comes
 * on the reader's first attempt.
 *
 * `audio.seekable` is deliberately *not* consulted, and that is measured rather than assumed: on
 * this build it answers `[0, Infinity]` for a note it has not worked out yet, and collapses to
 * `[0, 0]` on notes that seek perfectly well. Disabling on that reading took a working control
 * away. One honest signal beats two unreliable ones.
 *
 * When a seek does not land the slider is disabled and its name says why, instead of offering a
 * thumb that slides back to zero — DESIGN.md §5.11, *never render a control the server ignores*.
 * The day the download route serves byte ranges, this stops firing and nothing here changes.
 *
 * ## A native range, on purpose
 *
 * The position control is `<input type="range">`. A div with `role="slider"` would need every
 * key, every ARIA property and a thumb reimplemented, and the three of those that got missed
 * would be found by somebody who cannot use a mouse. The native control already has arrow keys,
 * Home/End, Page Up/Down, a value, and a focus ring; `aria-valuetext` is the only thing added,
 * so it says "3 seconds of 14 seconds" rather than "3".
 *
 * ## It draws no surface, and it takes no `onAccent`
 *
 * Both of those are the same finding, and the finding is `AttachmentCard`'s: that card declares
 * `bg-card text-card-foreground` **on its own root**, precisely so nothing inside it inherits
 * the bubble it was dropped into. A DM's own message is a solid `--primary` fill, and the card
 * on top of it is not. So this player is always on `--card`, in every bubble, on every screen —
 * and giving it a second `border bg-card` of its own would be one surface sitting on another at
 * the same elevation, which DESIGN.md §5.13 forbids. The caller owns the surface; this owns the
 * controls.
 *
 * Which is why there is no accent variant to write. Every pair in here is card-relative and
 * already passes in both modes: `--foreground` / `--card` 13.63:1 light and 16.25:1 dark for the
 * elapsed time, `--muted-foreground` / `--card` 5.51:1 / 6.63:1 for the total and the *Speed*
 * label, `--foreground` / `--secondary` 12.50:1 / 14.25:1 for the play glyph,
 * `--primary-foreground` / `--primary` 4.99:1 / 7.31:1 for the chosen speed, `--primary` /
 * `--card` 5.13:1 / 6.66:1 for the slider's filled track and thumb, and `--ring` / `--card`
 * 3.61:1 / 6.13:1 for every focus ring. Moving any of them to `--primary-foreground` because of
 * the fill *outside* the card would put `#FCFCFC` on `#FFFFFF` at 1.01:1 — which is the exact
 * bug the comment at the top of `AttachmentCard` was written about.
 */

const props = withDefaults(
    defineProps<{
        src: string;
        /** Whole seconds, from the payload. The only trustworthy total for a recorded blob. */
        durationSeconds?: number | null;
        /** What this note is, for the controls' names: "voice message", "your recording". */
        label?: string;
    }>(),
    { durationSeconds: null, label: 'voice message' },
);

const SPEEDS = [1, 1.5, 2] as const;

type Speed = (typeof SPEEDS)[number];

const uid = useId();
const statusId = `${uid}-voice-status`;

const audioEl = ref<HTMLAudioElement | null>(null);
const playing = ref(false);
const position = ref(0);
const measured = ref<number | null>(null);
const speed = ref<Speed>(1);
const failed = ref(false);
/** True until a seek is demonstrably ignored by the element. Reset for every new `src`. */
const canSeek = ref(true);

const total = computed(() => {
    const given = props.durationSeconds;

    if (given !== null && Number.isFinite(given) && given > 0) {
        return given;
    }

    return measured.value ?? 0;
});

const seekable = computed(() => total.value > 0 && !failed.value && canSeek.value);

/**
 * How far one arrow key moves.
 *
 * A fixed `0.1` is unusable on anything long: a five-minute note would be three thousand key
 * presses end to end. So a step is one hundredth of the clip, floored at a whole second once
 * the clip is long enough for that to be finer than the spoken value — a screen-reader user who
 * presses an arrow and hears the same number back has been given a control that does nothing as
 * far as they can tell.
 */
const step = computed(() => {
    if (total.value < 3) {
        return 0.5;
    }

    return Math.max(1, Math.round(total.value / 100));
});

const elapsedLabel = computed(() => formatClock(position.value));
const totalLabel = computed(() => (total.value > 0 ? formatClock(total.value) : '--:--'));

const valueText = computed(() =>
    total.value > 0
        ? `${spokenClock(position.value)} of ${spokenClock(total.value)}`
        : spokenClock(position.value),
);

/** Pending "did that seek land?" check. One at a time; a newer drag supersedes an older one. */
let seekProbe: ReturnType<typeof setTimeout> | undefined;

function element(): HTMLAudioElement | null {
    return audioEl.value;
}

function toggle(): void {
    const audio = element();

    if (audio === null || failed.value) {
        return;
    }

    if (playing.value) {
        audio.pause();

        return;
    }

    claimPlayback(audio);
    audio.playbackRate = speed.value;

    void audio.play().catch(() => {
        // An autoplay refusal or a URL that has lapsed since the thread was drawn. Either way
        // the reader is told rather than left with a button that does nothing.
        failed.value = true;
        playing.value = false;
    });
}

function choose(next: Speed): void {
    speed.value = next;

    const audio = element();

    if (audio !== null) {
        audio.playbackRate = next;
    }
}

function seek(event: Event): void {
    const audio = element();
    const next = Number((event.target as HTMLInputElement).value);

    position.value = next;

    if (audio === null || !seekable.value) {
        return;
    }

    audio.currentTime = next;

    // Did it land? A response with no byte ranges swallows the write silently, and the only
    // honest way to find that out is to look afterwards.
    clearTimeout(seekProbe);
    seekProbe = setTimeout(() => {
        const settled = element();

        if (settled === null) {
            return;
        }

        // Half a step, floored: a landed seek is within milliseconds of where it was sent, and
        // a whole step is exactly the distance one arrow key moves — the threshold has to sit
        // below that or a single ignored press reads as a success.
        if (Math.abs(settled.currentTime - next) > Math.max(0.35, step.value / 2)) {
            canSeek.value = false;
            position.value = settled.currentTime;
        }
    }, 300);
}

function onLoaded(): void {
    const audio = element();

    if (audio === null) {
        return;
    }

    // `Infinity` is the MediaRecorder case; `NaN` is a stream that has not decoded.
    measured.value = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : null;
    audio.playbackRate = speed.value;
}

function onTime(): void {
    const audio = element();

    if (audio !== null) {
        position.value = total.value > 0 ? Math.min(audio.currentTime, total.value) : audio.currentTime;
    }
}

function onEnded(): void {
    playing.value = false;
    position.value = 0;

    const audio = element();

    if (audio !== null) {
        audio.currentTime = 0;
        releasePlayback(audio);
    }
}

/** A new src is a new note: stop the old one rather than playing its bytes under a new label. */
watch(
    () => props.src,
    () => {
        const audio = element();

        if (audio !== null && !audio.paused) {
            audio.pause();
        }

        playing.value = false;
        position.value = 0;
        measured.value = null;
        failed.value = false;
        canSeek.value = true;
        clearTimeout(seekProbe);
    },
);

onBeforeUnmount(() => {
    clearTimeout(seekProbe);

    const audio = element();

    if (audio !== null) {
        audio.pause();
        releasePlayback(audio);
    }
});
</script>

<template>
    <!-- No surface of its own: the caller is already one. See the note above §5.13. -->
    <div class="flex min-w-0 flex-col gap-2">
        <audio
            ref="audioEl"
            :src="src"
            preload="metadata"
            class="sr-only"
            @loadedmetadata="onLoaded"
            @durationchange="onLoaded"
            @timeupdate="onTime"
            @play="playing = true"
            @pause="playing = false"
            @ended="onEnded"
            @error="failed = true"
        ></audio>

        <div class="flex min-w-0 items-center gap-2">
            <Button
                type="button"
                variant="secondary"
                size="icon-sm"
                class="shrink-0"
                :disabled="failed"
                :aria-label="playing ? `Pause ${label}` : `Play ${label}`"
                :aria-describedby="statusId"
                @click="toggle"
            >
                <component :is="playing ? Pause : Play" aria-hidden="true" />
            </Button>

            <!--
                The position. A real range with a real name and a real value: `aria-valuetext`
                turns "3" into "3 seconds of 14 seconds", and `accent-primary` is the filled
                track and thumb at 5.13:1 / 6.66:1 over `--card`.
            -->
            <input
                type="range"
                min="0"
                :max="total > 0 ? total : 1"
                :step="step"
                :value="position"
                :disabled="!seekable"
                :aria-label="
                    seekable || total === 0
                        ? `Position in this ${label}`
                        : `Position in this ${label} (this one cannot be moved through)`
                "
                :title="seekable || total === 0 ? undefined : 'This recording cannot be moved through'"
                :aria-valuetext="valueText"
                class="h-1.5 min-w-0 flex-1 cursor-pointer accent-primary disabled:cursor-not-allowed disabled:opacity-50 focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                @input="seek"
            >

            <!--
                Elapsed / total. `tabular-nums` so the row does not twitch as the digits change,
                and one `sr-only` sentence rather than two numbers read as "0 14".
            -->
            <span class="shrink-0 text-xs tabular-nums" aria-hidden="true">
                {{ elapsedLabel }}<span class="text-muted-foreground"> / {{ totalLabel }}</span>
            </span>
            <span :id="statusId" class="sr-only">{{ valueText }}</span>
        </div>

        <div class="flex min-w-0 flex-wrap items-center gap-1">
            <span class="text-xs text-muted-foreground">Speed</span>

            <!--
                Three buttons rather than a menu: the whole control is three keystrokes wide, and
                `aria-pressed` says which one is on. The chosen one is a fill **and** a heavier
                weight, so it is never the colour alone (DESIGN.md §5.6).
            -->
            <Button
                v-for="option in SPEEDS"
                :key="option"
                type="button"
                size="sm"
                :variant="speed === option ? 'default' : 'ghost'"
                :aria-pressed="speed === option"
                :aria-label="`Play at ${option} times speed`"
                :class="cn('h-7 px-2 text-xs tabular-nums', speed === option && 'font-semibold')"
                @click="choose(option)"
            >
                {{ option }}×
            </Button>
        </div>

        <p v-if="failed" class="text-xs text-muted-foreground">
            This voice message could not be played. Its link may have expired — refresh the
            conversation.
        </p>
    </div>
</template>
