<script setup lang="ts">
import { CircleAlert, Mic, RotateCcw, Square, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import VoicePlayer from '@/Components/Messages/VoicePlayer.vue';
import type { VoiceClip } from '@/Components/Messages/voice';
import {
    VOICE_MAX_LABEL,
    VOICE_MAX_SECONDS,
    formatClock,
    spokenClock,
    useVoiceRecorder,
    voiceUnavailable,
} from '@/Components/Messages/voice';
import { Button } from '@/Components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * The mic control, the waveform, the timer and the preview.
 *
 * It renders **two** roots: a strip that only exists while something is happening, and the mic
 * button itself. The composer drops it straight into its control row; the strip carries
 * `order-first basis-full`, so it takes the line above the controls rather than pushing the
 * paperclip and Send onto three lines of their own. `inheritAttrs` is off because two roots
 * cannot share one `class`.
 *
 * ## Both gestures, and the rule that tells them apart
 *
 * The plan asks for **hold-to-record**, and hold-to-record alone is unusable: it cannot be done
 * with a keyboard at all, and it is painful with a tremor or with one working hand. So the same
 * button is also **press-to-start / press-to-stop**, and the rule that decides which gesture
 * happened is the press's own length:
 *
 * - `pointerdown` starts recording immediately, whichever gesture this turns out to be. Asking
 *   for the microphone takes ~100 ms even after it has been granted, so waiting to find out
 *   would cost the first word of every held note.
 * - Release **after 300 ms or more** is a hold: stop, and show the preview.
 * - Release **under 300 ms** is a click: stay recording, latched. The button is now *Stop*, and
 *   the next press — pointer or `Space`/`Enter` — ends it.
 *
 * 300 ms is where the two stop being confusable: it is roughly twice a fast click and well under
 * a deliberate hold, it is the figure the same gesture uses in WhatsApp and Telegram, and the
 * failure on either side of it is recoverable — a hold misread as a click leaves you recording
 * with a Stop button in front of you, and a click misread as a hold gives you a one-second clip
 * and a Discard next to it. Nothing is sent by either mistake.
 *
 * `Space` and `Enter` never reach the pointer path: a keyboard activation produces only a
 * `click`, so it is always the toggle. `suppressClick` is what stops the synthetic click that
 * *follows* a real `pointerup` from toggling a second time.
 *
 * ## Escape cancels, and it does so in the capture phase
 *
 * While recording, Escape discards. It is a capture-phase listener on the window because the
 * task-detail drawer this composer sometimes lives inside also listens for Escape, and a reader
 * who pressed it to abandon a recording did not ask for the drawer to close on top of it.
 *
 * ## What is not drawn
 *
 * If the browser has no `MediaRecorder`, or will not record any container the endpoint accepts,
 * **nothing here renders** and the composer is exactly what it was. An insecure origin is the
 * one exception: the button is drawn and pressing it says why, because that is a deployment
 * mistake somebody needs to see rather than a missing feature.
 */

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        /** The composer is posting. */
        disabled?: boolean;
        /**
         * Why recording is unavailable right now — a file is attached. The control stays, says
         * so, and does nothing: hiding it would make the mic vanish when you attach a file.
         */
        blocked?: string | null;
    }>(),
    { disabled: false, blocked: null },
);

/** The finished recording, handed to the composer. The composer nulls it after it is sent. */
const clip = defineModel<VoiceClip | null>('clip', { default: null });

/** True from the moment the microphone is asked for until the clip is sent or discarded. */
const active = defineModel<boolean>('active', { default: false });

const uid = useId();
const timerId = `${uid}-voice-timer`;

/**
 * Read once, at setup. `MediaRecorder` and `isSecureContext` do not change under a live page,
 * and re-asking per render would run `isTypeSupported` four times a frame.
 */
const unavailable = voiceUnavailable();
const drawn = unavailable === null || unavailable === 'insecure';

const voice = useVoiceRecorder();

const status = ref('');
const canvasEl = ref<HTMLCanvasElement | null>(null);
const reducedMotion = ref(false);

let media: MediaQueryList | null = null;
let pressAt = 0;
let suppressClick = false;

/** Under this, a press was a click and the recording latches. Over it, it was a hold. */
const HOLD_MS = 300;

/** The timer turns into a warning here — half a minute of runway, not a surprise. */
const WARN_AT = VOICE_MAX_SECONDS - 30;

const recording = computed(() => voice.state.value === 'recording' || voice.state.value === 'starting');
const previewing = computed(() => voice.state.value === 'preview');
const shown = computed(() => recording.value || previewing.value || voice.error.value !== null);

const elapsed = computed(() => formatClock(voice.seconds.value));
const remaining = computed(() => Math.max(0, VOICE_MAX_SECONDS - Math.floor(voice.seconds.value)));
const warning = computed(() => recording.value && voice.seconds.value >= WARN_AT);

const micLabel = computed(() => {
    if (props.blocked !== null) {
        return `Record a voice message (unavailable: ${props.blocked})`;
    }

    if (previewing.value) {
        return 'Record again';
    }

    return recording.value ? 'Stop recording' : 'Record a voice message';
});

const micTooltip = computed(() => {
    if (props.blocked !== null) {
        return props.blocked;
    }

    if (unavailable === 'insecure') {
        return 'Recording needs HTTPS or localhost';
    }

    if (previewing.value) {
        return 'Record again';
    }

    return recording.value ? 'Stop recording' : 'Hold to record, or press to start';
});

watch(active, (value) => {
    if (!value && (recording.value || previewing.value)) {
        // The composer reset itself — a thread change, a send. Throw the recording away.
        voice.cancel();
    }
});

watch([recording, previewing], () => {
    active.value = recording.value || previewing.value;
});

watch(voice.clip, (value) => {
    clip.value = value;
});

watch(clip, (value) => {
    if (value === null && previewing.value) {
        voice.discard();
    }
});

watch(voice.state, (to, from) => {
    if (to === 'recording' && from !== 'recording') {
        status.value = `Recording. Up to ${VOICE_MAX_LABEL}.`;

        return;
    }

    if (to === 'preview') {
        status.value = voice.cutoff.value
            ? `Recording stopped at the limit of ${VOICE_MAX_LABEL}. ${spokenClock(voice.seconds.value)} ready to send.`
            : `Recording stopped. ${spokenClock(voice.seconds.value)} ready to send.`;

        return;
    }

    if (to === 'idle' && from !== 'idle') {
        status.value = voice.error.value === null ? 'Recording discarded.' : voice.error.value;
    }
});

watch(voice.error, (value) => {
    if (value !== null) {
        status.value = value;
    }
});

/* ------------------------------------------------------------------ the gesture */

function onPointerDown(event: PointerEvent): void {
    if (props.disabled || props.blocked !== null || event.button !== 0) {
        return;
    }

    // Capture, so a release that drifts off the button still lands here.
    (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);

    suppressClick = true;

    if (previewing.value) {
        // A press on *Record again* is a fresh recording, not a hold gesture on a clip.
        pressAt = 0;
        void voice.start();

        return;
    }

    if (recording.value) {
        // Latched, and pressed again: this press ends it, on release.
        pressAt = 0;

        return;
    }

    pressAt = performance.now();
    void voice.start();
}

function onPointerUp(): void {
    if (props.disabled || props.blocked !== null) {
        return;
    }

    if (pressAt === 0) {
        // A press that began while already recording, or on the preview's *Record again*.
        if (recording.value && voice.seconds.value > 0) {
            voice.stop();
        }

        return;
    }

    const held = performance.now() - pressAt;

    pressAt = 0;

    if (held >= HOLD_MS) {
        voice.stop();
    }

    // Under HOLD_MS it was a click: stay recording. The button is Stop now.
}

function onClick(): void {
    if (suppressClick) {
        // The synthetic click that follows every real pointerup. Already handled.
        suppressClick = false;

        return;
    }

    if (props.disabled || props.blocked !== null) {
        return;
    }

    // Keyboard only: Space and Enter produce a click and no pointer events at all.
    if (recording.value) {
        voice.stop();

        return;
    }

    void voice.start();
}

function onWindowKey(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || !recording.value) {
        return;
    }

    event.preventDefault();
    // Capture phase: the drawer this composer sometimes sits in must not also close.
    event.stopPropagation();
    voice.cancel();
}

/* ------------------------------------------------------------------ the waveform */

/**
 * Real amplitude, drawn. `levels` is peak-per-frame off an `AnalyserNode` reading the live
 * stream — there is no animation in here pretending to be a microphone.
 *
 * The colour is read from `currentColor` rather than written down, so `text-primary` on the
 * canvas is what picks it and the theme still applies. A hex here would be a light-mode bug
 * shipped into dark (DESIGN.md §5.1).
 */
function paint(): void {
    const canvas = canvasEl.value;

    if (canvas === null || reducedMotion.value) {
        return;
    }

    const context = canvas.getContext('2d');
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;

    if (context === null || width === 0 || height === 0) {
        return;
    }

    const ratio = window.devicePixelRatio || 1;

    if (canvas.width !== Math.round(width * ratio) || canvas.height !== Math.round(height * ratio)) {
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
    }

    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);
    context.fillStyle = getComputedStyle(canvas).color;

    const bars = voice.levels.value;
    const step = width / bars.length;
    const barWidth = Math.max(1, step - 1);
    const middle = height / 2;

    for (let index = 0; index < bars.length; index += 1) {
        const value = bars[index] ?? 0;
        // A floor of 2px, so silence is a baseline rather than an empty box.
        const tall = Math.max(2, value * height);

        context.fillRect(index * step, middle - tall / 2, barWidth, tall);
    }
}

watch(voice.levels, paint, { flush: 'post' });
watch(recording, () => void Promise.resolve().then(paint), { flush: 'post' });

/** Ten segments, lit by the throttled peak. This is what replaces the waveform's motion. */
const SEGMENTS = 10;
const meter = computed(() => Math.round(voice.level.value * SEGMENTS));

function onMotionChange(event: MediaQueryListEvent): void {
    reducedMotion.value = event.matches;
}

onMounted(() => {
    if (!drawn) {
        return;
    }

    media = window.matchMedia('(prefers-reduced-motion: reduce)');
    reducedMotion.value = media.matches;
    media.addEventListener('change', onMotionChange);
    window.addEventListener('keydown', onWindowKey, true);
});

onBeforeUnmount(() => {
    media?.removeEventListener('change', onMotionChange);
    window.removeEventListener('keydown', onWindowKey, true);
    // The fourth exit path. Nothing else runs between here and the page being gone.
    voice.dispose();
});
</script>

<template>
    <!--
        The strip. `order-first basis-full` puts it on the line above the composer's controls
        without moving any of them, and it exists only while something is happening.
    -->
    <div
        v-if="drawn && shown"
        class="order-first flex w-full min-w-0 basis-full flex-col gap-2 rounded-md border bg-card p-2 text-card-foreground shadow-flat"
    >
        <div v-if="recording" class="flex min-w-0 items-center gap-2">
            <!--
                "Recording" is a word, and the timer is a number. Neither is a red dot: the state
                has to read the same to somebody who cannot tell two hues apart (DESIGN.md §5.6).
            -->
            <span class="shrink-0 text-xs font-medium">Recording</span>

            <!-- Decoration. The timer beside it is what carries the information. -->
            <canvas
                v-if="!reducedMotion"
                ref="canvasEl"
                aria-hidden="true"
                class="h-8 min-w-0 flex-1 text-primary"
            ></canvas>

            <!--
                `prefers-reduced-motion`: a level meter that steps five times a second instead of
                a waveform that scrolls sixty. Still real amplitude, still `aria-hidden`.
            -->
            <span v-else aria-hidden="true" class="flex min-w-0 flex-1 items-center gap-0.5">
                <span
                    v-for="index in SEGMENTS"
                    :key="index"
                    :class="cn('h-4 flex-1 rounded-xs', index <= meter ? 'bg-primary' : 'bg-muted')"
                />
            </span>

            <span
                :id="timerId"
                :class="cn('shrink-0 text-xs tabular-nums', warning && 'font-medium text-destructive')"
            >
                {{ elapsed }}<span class="text-muted-foreground"> / {{ formatClock(VOICE_MAX_SECONDS) }}</span>
            </span>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="shrink-0"
                aria-label="Cancel recording"
                @click="voice.cancel()"
            >
                <Trash2 aria-hidden="true" />
                <span class="hidden sm:inline">Cancel</span>
            </Button>
        </div>

        <!--
            The warning is a sentence as well as a colour, and it only appears in the last thirty
            seconds. Nothing announces it: the live region would then speak once a second.
        -->
        <p v-if="recording && warning" class="text-xs text-destructive">
            <span class="tabular-nums">{{ remaining }}</span> seconds left — recording stops at
            {{ VOICE_MAX_LABEL }}.
        </p>

        <template v-if="previewing && clip !== null">
            <VoicePlayer
                :src="clip.url"
                :duration-seconds="clip.seconds"
                label="recording"
            />

            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <p class="min-w-0 flex-1 text-xs text-muted-foreground">
                    <span v-if="voice.cutoff.value">
                        Stopped at the limit of {{ VOICE_MAX_LABEL }}.
                    </span>
                    Not sent yet — press Send.
                </p>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    :disabled="disabled"
                    @click="voice.start()"
                >
                    <RotateCcw aria-hidden="true" />
                    Re-record
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    :disabled="disabled"
                    @click="voice.discard()"
                >
                    <Trash2 aria-hidden="true" />
                    Discard
                </Button>
            </div>
        </template>

        <p
            v-if="voice.error.value"
            class="flex items-start gap-2 text-xs text-destructive"
        >
            <CircleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
            {{ voice.error.value }}
        </p>
    </div>

    <!--
        Started, stopped, discarded, cut off. Never the timer: a region that spoke every second
        would make the composer unusable with a screen reader.
    -->
    <p v-if="drawn" class="sr-only" aria-live="polite" aria-atomic="true">{{ status }}</p>

    <TooltipProvider v-if="drawn" :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    type="button"
                    :variant="recording ? 'default' : 'ghost'"
                    size="icon-sm"
                    :disabled="disabled"
                    :aria-disabled="blocked !== null || undefined"
                    :aria-label="micLabel"
                    :aria-describedby="recording ? timerId : undefined"
                    :class="blocked !== null && 'opacity-50'"
                    @pointerdown="onPointerDown"
                    @pointerup="onPointerUp"
                    @pointercancel="voice.cancel()"
                    @click="onClick"
                >
                    <component :is="recording ? Square : Mic" aria-hidden="true" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{ micTooltip }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
