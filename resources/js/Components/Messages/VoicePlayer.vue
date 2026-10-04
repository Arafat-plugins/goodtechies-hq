<script lang="ts">
/**
 * Module scope, shared by every player on the page. A `<script setup>` top-level binding is
 * re-created per instance, so the peak cache and the decoding context have to live here.
 */

/** How many bars the waveform draws, whatever the length of the note. */
const BARS = 40;

/**
 * Peaks already worked out, and the note's bytes, by `cacheKey ?? src` (brief 009: the file id,
 * so a re-signed url for the same note is the same note — no refetch, no spinner).
 */
const peakCache = new Map<string, number[]>();
const blobCache = new Map<string, Blob>();
/** Enough notes for a long thread; the oldest goes first. */
const BLOB_CACHE_MAX = 60;

function rememberBlob(key: string, blob: Blob): void {
    blobCache.delete(key);
    blobCache.set(key, blob);

    while (blobCache.size > BLOB_CACHE_MAX) {
        const oldest = blobCache.keys().next().value;

        if (oldest === undefined) {
            break;
        }

        blobCache.delete(oldest);
    }
}

/** Brief 009: the playback speed is the reader's, for every note, until they change it. */
const SPEED_KEY = 'hq.voice.speed';

function storedSpeed(): 1 | 1.5 | 2 {
    try {
        const value = Number(window.localStorage.getItem(SPEED_KEY));

        return value === 1.5 || value === 2 ? value : 1;
    } catch {
        return 1;
    }
}

function storeSpeed(value: number): void {
    try {
        window.localStorage.setItem(SPEED_KEY, String(value));
    } catch {
        // Blocked storage: the speed lasts for this player only.
    }
}

let sharedContext: AudioContext | null = null;

/** One lazily-created `AudioContext` for decoding. Never closed; reused by every player. */
function decodingContext(): AudioContext | null {
    if (sharedContext !== null) {
        return sharedContext;
    }

    const Context =
        window.AudioContext ??
        (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

    if (Context === undefined) {
        return null;
    }

    sharedContext = new Context();

    return sharedContext;
}

/** A flat line, for a note that could not be decoded (or is still loading). */
function flatPeaks(): number[] {
    return Array.from({ length: BARS }, () => 0.3);
}

/**
 * `BARS` peaks of absolute amplitude, normalised to 0.15–1.0 so the quietest bar is still a
 * visible stub and the loudest fills the row.
 */
async function computePeaks(buffer: ArrayBuffer): Promise<number[]> {
    const context = decodingContext();

    if (context === null) {
        return flatPeaks();
    }

    const decoded = await context.decodeAudioData(buffer);
    const channels = Array.from({ length: decoded.numberOfChannels }, (_, index) => decoded.getChannelData(index));
    const length = decoded.length;

    if (length === 0 || channels.length === 0) {
        return flatPeaks();
    }

    const raw: number[] = [];

    for (let bar = 0; bar < BARS; bar += 1) {
        const from = Math.floor((bar * length) / BARS);
        const to = Math.max(from + 1, Math.floor(((bar + 1) * length) / BARS));
        let peak = 0;

        for (const data of channels) {
            for (let index = from; index < to && index < length; index += 1) {
                const value = Math.abs(data[index] ?? 0);

                if (value > peak) {
                    peak = value;
                }
            }
        }

        raw.push(peak);
    }

    const loudest = Math.max(...raw);

    if (loudest === 0) {
        return raw.map(() => 0.15);
    }

    return raw.map((peak) => 0.15 + 0.85 * (peak / loudest));
}
</script>

<script setup lang="ts">
import { Pause, Play } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { claimPlayback, formatClock, releasePlayback, spokenClock } from '@/Components/Messages/voice';
import { cn } from '@/lib/utils';

/**
 * A voice message, played — Telegram-style (12-77).
 *
 * A round play button, a 40-bar waveform that is also the seek control, and the duration under
 * it. A speed chip (1× → 1.5× → 2×) appears only while playing, or when the speed is not 1×. No
 * file name, size or download row: `AttachmentCard` renders only this for a voice note.
 *
 * Used twice — inside `AttachmentCard` for a sent note, and inside `VoiceRecorder` as the
 * preview — because a preview that played differently from the thing it previews would be a
 * preview of something else.
 *
 * ## The bytes are fetched, so seeking always works
 *
 * The signed download route does not serve byte ranges, and without them Chrome ignores every
 * write to `currentTime`. So the note is fetched once into a `Blob` and played from an object URL
 * (a `blob:` URL always seeks). The same bytes are decoded for the waveform's peaks. A `src` that
 * is already a `blob:` URL — the recorder's preview — is played as is.
 *
 * ## The total comes from the payload, not from the file
 *
 * `MediaRecorder` writes WebM with no duration in its header, so `audio.duration` is `Infinity`
 * for a freshly recorded note. `durationSeconds` travels with the file and wins; the element's
 * own duration is used only when it is finite.
 *
 * ## One at a time
 *
 * Starting a note pauses the one before it, through `claimPlayback` in `voice.ts`.
 *
 * ## `onAccent`
 *
 * On the viewer's own DM bubble (`--bubble-own`) the button and the bars swap to
 * `--bubble-own-foreground`, ~9:1 (bubble-own, 12-77), and so does the focus ring.
 */

const props = withDefaults(
    defineProps<{
        src: string;
        /** Whole seconds, from the payload. The only trustworthy total for a recorded blob. */
        durationSeconds?: number | null;
        /** What this note is, for the controls' names: "voice message", "your recording". */
        label?: string;
        /** Sitting directly on the viewer's own DM bubble. */
        onAccent?: boolean;
        /** What identifies the note across re-signed urls — `AttachmentCard` passes the file id. */
        cacheKey?: string | number;
    }>(),
    { durationSeconds: null, label: 'voice message', onAccent: false, cacheKey: undefined },
);

const SPEEDS = [1, 1.5, 2] as const;

type Speed = (typeof SPEEDS)[number];

/** How far one arrow key moves, in seconds. */
const KEY_STEP = 5;

const uid = useId();
const statusId = `${uid}-voice-status`;

const audioEl = ref<HTMLAudioElement | null>(null);
const audioSrc = ref<string | null>(null);
const loading = ref(true);
const failed = ref(false);
const playing = ref(false);
const position = ref(0);
const measured = ref<number | null>(null);
const speed = ref<Speed>(storedSpeed());
const peaks = ref<number[]>(flatPeaks());

/** The object URL this player made, and must revoke. Never the caller's own `blob:` URL. */
let ownedUrl: string | null = null;
/** Bumped per load, so a slow fetch for an old `src` cannot land on a new one. */
let loadToken = 0;
let dragging = false;

const total = computed(() => {
    const given = props.durationSeconds;

    if (given !== null && Number.isFinite(given) && given > 0) {
        return given;
    }

    return measured.value ?? 0;
});

const fraction = computed(() => (total.value > 0 ? Math.min(1, position.value / total.value) : 0));

const clockLabel = computed(() => {
    if (playing.value || position.value > 0) {
        return formatClock(position.value);
    }

    return total.value > 0 ? formatClock(total.value) : '0:00';
});

const valueText = computed(() =>
    total.value > 0
        ? `${spokenClock(position.value)} of ${spokenClock(total.value)}`
        : spokenClock(position.value),
);

const showSpeed = computed(() => playing.value || speed.value !== 1);

function revoke(): void {
    if (ownedUrl !== null) {
        URL.revokeObjectURL(ownedUrl);
        ownedUrl = null;
    }
}

function keyFor(src: string): string {
    return props.cacheKey === undefined ? src : `key:${props.cacheKey}`;
}

async function load(src: string): Promise<void> {
    const token = ++loadToken;
    const key = keyFor(src);
    const cached = src.startsWith('blob:') ? undefined : blobCache.get(key);

    revoke();
    audioSrc.value = null;
    loading.value = cached === undefined;
    failed.value = false;
    peaks.value = peakCache.get(key) ?? flatPeaks();

    try {
        let blob: Blob;

        if (cached !== undefined) {
            blob = cached;
        } else {
            const response = await fetch(src, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            blob = await response.blob();

            if (!src.startsWith('blob:')) {
                rememberBlob(key, blob);
            }
        }

        if (token !== loadToken) {
            return;
        }

        if (src.startsWith('blob:')) {
            audioSrc.value = src;
        } else {
            ownedUrl = URL.createObjectURL(blob);
            audioSrc.value = ownedUrl;
        }

        loading.value = false;

        if (!peakCache.has(key)) {
            let next: number[];

            try {
                next = await computePeaks(await blob.arrayBuffer());
            } catch {
                next = flatPeaks();
            }

            peakCache.set(key, next);

            if (token === loadToken) {
                peaks.value = next;
            }
        }
    } catch {
        if (token === loadToken) {
            loading.value = false;
            failed.value = true;
        }
    }
}

function toggle(): void {
    const audio = audioEl.value;

    if (audio === null || failed.value || loading.value) {
        return;
    }

    if (playing.value) {
        audio.pause();

        return;
    }

    claimPlayback(audio);
    audio.playbackRate = speed.value;

    void audio.play().catch(() => {
        failed.value = true;
        playing.value = false;
    });
}

function cycleSpeed(): void {
    const index = SPEEDS.indexOf(speed.value);
    const next = SPEEDS[(index + 1) % SPEEDS.length] ?? 1;

    speed.value = next;
    storeSpeed(next);

    if (audioEl.value !== null) {
        audioEl.value.playbackRate = next;
    }
}

function seekTo(seconds: number): void {
    if (total.value <= 0) {
        return;
    }

    const next = Math.min(total.value, Math.max(0, seconds));

    position.value = next;

    if (audioEl.value !== null && audioSrc.value !== null) {
        audioEl.value.currentTime = next;
    }
}

function seekToPointer(event: PointerEvent): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();

    if (rect.width === 0) {
        return;
    }

    seekTo(((event.clientX - rect.left) / rect.width) * total.value);
}

function onPointerDown(event: PointerEvent): void {
    if (event.button !== 0 || total.value <= 0 || failed.value) {
        return;
    }

    dragging = true;
    (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
    seekToPointer(event);
}

function onPointerMove(event: PointerEvent): void {
    if (dragging) {
        seekToPointer(event);
    }
}

function onPointerUp(): void {
    dragging = false;
}

function onKey(event: KeyboardEvent): void {
    if (total.value <= 0 || failed.value) {
        return;
    }

    const moves: Record<string, () => number> = {
        ArrowLeft: () => position.value - KEY_STEP,
        ArrowRight: () => position.value + KEY_STEP,
        Home: () => 0,
        End: () => total.value,
    };

    const move = moves[event.key];

    if (move === undefined) {
        return;
    }

    event.preventDefault();
    seekTo(move());
}

function onLoaded(): void {
    const audio = audioEl.value;

    if (audio === null) {
        return;
    }

    // `Infinity` is the MediaRecorder case; `NaN` is a stream that has not decoded.
    measured.value = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : null;
    audio.playbackRate = speed.value;
}

function onTime(): void {
    const audio = audioEl.value;

    if (audio !== null && !dragging) {
        position.value = total.value > 0 ? Math.min(audio.currentTime, total.value) : audio.currentTime;
    }
}

function onPause(): void {
    playing.value = false;

    if (audioEl.value !== null) {
        releasePlayback(audioEl.value);
    }
}

function onError(): void {
    // Only once there is something to play: an empty `src` is the loading state, not a fault.
    if (audioSrc.value !== null) {
        failed.value = true;
    }
}

function onEnded(): void {
    playing.value = false;
    position.value = 0;

    const audio = audioEl.value;

    if (audio !== null) {
        audio.currentTime = 0;
        releasePlayback(audio);
    }
}

/** A new src is a new note: stop the old one rather than playing its bytes under a new label. */
watch(
    () => [props.src, props.cacheKey] as const,
    ([src, key], [, previousKey]) => {
        // Brief 009: the same note under a freshly signed url is not a new note.
        if (key !== undefined && key === previousKey) {
            return;
        }

        const audio = audioEl.value;

        if (audio !== null && !audio.paused) {
            audio.pause();
        }

        playing.value = false;
        position.value = 0;
        measured.value = null;
        void load(src);
    },
);

onMounted(() => {
    void load(props.src);
});

onBeforeUnmount(() => {
    loadToken += 1;

    const audio = audioEl.value;

    if (audio !== null) {
        audio.pause();
        releasePlayback(audio);
    }

    revoke();
});
</script>

<template>
    <!-- No surface of its own: the caller (or the bubble) is already one. -->
    <div
        :class="
            cn(
                'flex min-w-0 flex-col gap-1',
                // The shimmer on the own blue bubble is drawn from the bubble's own foreground.
                onAccent && '[--shimmer-base:color-mix(in_oklch,var(--bubble-own-foreground)_22%,transparent)]',
            )
        "
    >
        <audio
            ref="audioEl"
            :src="audioSrc ?? undefined"
            preload="metadata"
            class="sr-only"
            @loadedmetadata="onLoaded"
            @durationchange="onLoaded"
            @timeupdate="onTime"
            @play="playing = true"
            @pause="onPause"
            @ended="onEnded"
            @error="onError"
        ></audio>

        <div class="flex min-w-0 items-center gap-3 py-1">
            <button
                type="button"
                :class="
                    cn(
                        'inline-flex size-10 shrink-0 items-center justify-center rounded-full transition-colors outline-none focus-visible:ring-3 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4',
                        // Not ready yet: a shimmering disc where the button will be (2026-10-04).
                        loading
                            ? 'shimmer disabled:opacity-100'
                            : onAccent
                              ? 'bg-bubble-own-foreground text-bubble-own hover:bg-bubble-own-foreground/90 focus-visible:ring-bubble-own-foreground'
                              : 'bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring',
                    )
                "
                :disabled="failed || loading"
                :aria-label="playing ? `Pause ${label}` : `Play ${label}`"
                :aria-describedby="statusId"
                @click="toggle"
            >
                <component :is="playing ? Pause : Play" v-if="!loading" aria-hidden="true" />
            </button>

            <div class="flex min-w-0 flex-1 flex-col">
                <!--
                    The waveform is the seek control. A slider with a real name and a spoken value,
                    driven by pointer (click or drag) and by ArrowLeft/ArrowRight (±5 s), Home, End.
                -->
                <div
                    role="slider"
                    tabindex="0"
                    :aria-label="`Position in this ${label}`"
                    :aria-valuemin="0"
                    :aria-valuemax="Math.round(total)"
                    :aria-valuenow="Math.round(position)"
                    :aria-valuetext="valueText"
                    :aria-disabled="failed || total <= 0 || undefined"
                    :class="
                        cn(
                            'flex h-7 cursor-pointer touch-none items-center gap-0.5 rounded-sm focus-visible:ring-3 focus-visible:outline-none',
                            onAccent
                                ? 'text-bubble-own-foreground focus-visible:ring-bubble-own-foreground'
                                : 'text-primary focus-visible:ring-ring',
                        )
                    "
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="onPointerUp"
                    @pointercancel="onPointerUp"
                    @keydown="onKey"
                >
                    <!-- Still loading: one shimmering strip where the waveform will be drawn. -->
                    <span v-if="loading" class="shimmer h-4 w-full rounded-full" aria-hidden="true" />
                    <template v-else>
                        <span
                            v-for="(peak, index) in peaks"
                            :key="index"
                            aria-hidden="true"
                            :class="
                                cn(
                                    'w-0.5 shrink-0 rounded-full bg-current',
                                    index / peaks.length < fraction ? 'opacity-100' : 'opacity-40',
                                )
                            "
                            :style="{ height: Math.round(peak * 100) + '%' }"
                        />
                    </template>
                </div>

                <div class="flex min-w-0 items-center justify-between gap-2">
                    <span class="text-xs tabular-nums" aria-hidden="true">{{ clockLabel }}</span>

                    <button
                        v-if="showSpeed"
                        type="button"
                        :class="
                            cn(
                                'h-5 shrink-0 rounded-sm px-1 text-xs font-medium tabular-nums outline-none focus-visible:ring-3',
                                onAccent ? 'focus-visible:ring-bubble-own-foreground' : 'focus-visible:ring-ring',
                            )
                        "
                        :aria-label="`Playback speed, currently ${speed}×`"
                        @click="cycleSpeed"
                    >
                        {{ speed }}×
                    </button>
                </div>
            </div>
        </div>

        <span :id="statusId" class="sr-only">{{ valueText }}</span>

        <p v-if="failed" class="text-xs">
            This voice message could not be played. Its link may have expired — refresh the
            conversation.
        </p>
    </div>
</template>
