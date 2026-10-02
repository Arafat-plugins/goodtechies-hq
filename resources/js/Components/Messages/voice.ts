import { ref, type Ref } from 'vue';

/**
 * Recording a voice message: the browser APIs, kept out of the component.
 *
 * `VoiceRecorder.vue` is a mic button, a waveform, a timer and a preview. Everything below is
 * `MediaRecorder`, `getUserMedia` and an `AnalyserNode`, which is a different kind of code with
 * a different way of going wrong — four named failures, a stream that must be handed back, and
 * a container negotiation the server's filename depends on. Splitting it is what makes the
 * component readable and this half testable on its own.
 *
 * ## The contract with the endpoint
 *
 * A voice message is posted to the same `store` route as everything else, multipart, with three
 * extra fields: `file` (the blob, under a **filename whose extension matches its MIME type**),
 * `kind: 'voice'`, and `duration` in whole seconds. The filename is not cosmetic — the server
 * validates the extension against the type, so `voice.webm` holding `audio/mp4` is a 422.
 *
 * ## Why the container is negotiated rather than chosen
 *
 * There is no one audio container every browser records. Chrome and Firefox record WebM/Opus
 * and cannot record `audio/mp4`; Safari records `audio/mp4` (AAC) and, before 18.4, supported
 * nothing else. So the preference chain is walked with `MediaRecorder.isTypeSupported()` and
 * the **first** yes wins, carrying its filename with it. A pair, never two lists that drift.
 *
 * If nothing in the chain is supported the mic control is not drawn at all — see
 * `voiceUnavailable()`. A control that cannot work is worse than no control.
 */

/** The server's ceiling, restated so nobody records six minutes and is then refused. */
export const VOICE_MAX_SECONDS = 300;

export const VOICE_MAX_LABEL = '5 minutes';

export interface VoiceContainer {
    /** What `MediaRecorder` is asked for. */
    mimeType: string;
    /** The filename the blob is posted under. Its extension must match `mimeType`. */
    filename: string;
}

/**
 * In preference order. Opus first because it is a third the size of AAC at speech bitrates, and
 * `audio/mp4` before `audio/ogg` because the browser that needs mp4 (Safari) will never say yes
 * to ogg, and the browser that says yes to ogg has already said yes to webm above it.
 */
export const VOICE_CONTAINERS: readonly VoiceContainer[] = [
    { mimeType: 'audio/webm;codecs=opus', filename: 'voice.webm' },
    { mimeType: 'audio/webm', filename: 'voice.webm' },
    { mimeType: 'audio/mp4', filename: 'voice.m4a' },
    { mimeType: 'audio/ogg;codecs=opus', filename: 'voice.ogg' },
];

/** The first container this browser will actually record, or `null` if it will record none. */
export function pickContainer(): VoiceContainer | null {
    if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
        return null;
    }

    for (const container of VOICE_CONTAINERS) {
        try {
            if (MediaRecorder.isTypeSupported(container.mimeType)) {
                return container;
            }
        } catch {
            // A browser that throws on a type it does not know has said no.
        }
    }

    return null;
}

export type VoiceUnavailable = 'insecure' | 'no-recorder' | 'no-container';

/**
 * Why the mic cannot be offered here, or `null` if it can.
 *
 * **`insecure` is asked first and asked of the browser, not of the hostname.** `getUserMedia`
 * and `MediaRecorder` are gated on a *secure context*, and the rule for what counts is the
 * browser's, not ours: `https:` anywhere, plus the loopback range — which in Chrome is
 * `localhost`, `127.0.0.1` and `[::1]` alike, and in older Safari is `localhost` only. A LAN
 * address like `http://192.168.1.20:8000` is **not** secure anywhere, and that is the case this
 * catches: the dev server answers, the page loads, and `navigator.mediaDevices` is simply
 * `undefined`. Reading `window.isSecureContext` gets every one of those right for free; a
 * hostname list we maintain ourselves would be wrong on whichever browser moved last.
 */
export function voiceUnavailable(): VoiceUnavailable | null {
    if (typeof window === 'undefined') {
        return 'no-recorder';
    }

    if (!window.isSecureContext) {
        return 'insecure';
    }

    if (typeof navigator === 'undefined' || typeof navigator.mediaDevices?.getUserMedia !== 'function') {
        return 'no-recorder';
    }

    if (typeof MediaRecorder === 'undefined') {
        return 'no-recorder';
    }

    return pickContainer() === null ? 'no-container' : null;
}

/** mm:ss, counting up from `0:00`. `formatDuration()` answers `''` at zero, which a timer cannot. */
export function formatClock(seconds: number): string {
    const whole = Math.max(0, Math.floor(seconds));

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

/** The same value spoken rather than drawn: "1 minute 4 seconds", not "1:04". */
export function spokenClock(seconds: number): string {
    const whole = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(whole / 60);
    const rest = whole % 60;
    const said: string[] = [];

    if (minutes > 0) {
        said.push(`${minutes} minute${minutes === 1 ? '' : 's'}`);
    }

    if (rest > 0 || minutes === 0) {
        said.push(`${rest} second${rest === 1 ? '' : 's'}`);
    }

    return said.join(' ');
}

export interface VoiceClip {
    blob: Blob;
    /** An object URL for the preview. The recorder revokes it; nobody else may. */
    url: string;
    filename: string;
    mimeType: string;
    /** Whole seconds, 1…300 — what the `duration` field carries. */
    seconds: number;
    size: number;
}

/** The blob as the multipart field the endpoint expects, under its matching filename. */
export function clipFile(clip: VoiceClip): File {
    return new File([clip.blob], clip.filename, { type: clip.mimeType });
}

/* ------------------------------------------------------------------ one at a time */

/**
 * Whatever voice note is playing on this screen.
 *
 * It lives **here**, in a module, and not in `VoicePlayer.vue`, because a `<script setup>` block
 * is not module scope: every top-level binding in one is re-created per component instance. A
 * `let current` written up there looks shared and is not, which is exactly how five voice notes
 * became five voices at once — measured, then moved.
 */
let nowPlaying: HTMLAudioElement | null = null;

/** Take the screen: whatever was playing is paused first. */
export function claimPlayback(audio: HTMLAudioElement): void {
    if (nowPlaying !== null && nowPlaying !== audio) {
        nowPlaying.pause();
    }

    nowPlaying = audio;
}

/** Give it back, on pause, on ended and on unmount. */
export function releasePlayback(audio: HTMLAudioElement): void {
    if (nowPlaying === audio) {
        nowPlaying = null;
    }
}

export type VoiceState = 'idle' | 'starting' | 'recording' | 'preview';

/**
 * Every way starting the microphone fails, in the words the user reads.
 *
 * Each one says what happened and what to do about it. `NotAllowedError` in particular never
 * re-prompts: once a browser has been told no for an origin it will not ask again, so a control
 * that retried would spin silently. The sentence sends the reader to the site settings, which is
 * the only place that decision can be changed.
 */
export function startFailure(reason: unknown): string {
    const name = reason instanceof Error ? reason.name : '';

    switch (name) {
        case 'NotAllowedError':
        case 'PermissionDeniedError':
            return 'Your browser blocked the microphone for this site. Allow it in the site settings — the icon at the left of the address bar — then try again.';
        case 'NotFoundError':
        case 'DevicesNotFoundError':
            return 'No microphone was found on this device.';
        case 'NotReadableError':
        case 'TrackStartError':
            return 'The microphone is being used by another app. Close that app, then try again.';
        case 'OverconstrainedError':
            return 'This microphone could not be started with the settings a voice message needs.';
        case 'SecurityError':
            return 'Recording needs a secure page. Open this app over HTTPS, or on localhost.';
        default:
            return 'The microphone could not be started.';
    }
}

/** The same sentences for the three reasons the control is not drawn at all. */
export const VOICE_UNAVAILABLE_TEXT: Record<VoiceUnavailable, string> = {
    insecure: 'Recording needs a secure page. Open this app over HTTPS, or on localhost.',
    'no-recorder': 'This browser cannot record audio.',
    'no-container': 'This browser cannot record audio in a format this app accepts.',
};

/** How many samples the waveform keeps. One bar each, oldest on the left. */
const LEVEL_BARS = 56;

/** The level meter used under `prefers-reduced-motion` updates five times a second, not sixty. */
const LEVEL_THROTTLE_MS = 200;

export interface VoiceRecorderHandle {
    state: Ref<VoiceState>;
    /** Elapsed recording time in seconds, fractional. The timer floors it. */
    seconds: Ref<number>;
    /** The last `LEVEL_BARS` peak amplitudes, 0…1. Oldest first. Decoration — `aria-hidden`. */
    levels: Ref<number[]>;
    /** The current peak, throttled, for a meter that must not animate. */
    level: Ref<number>;
    error: Ref<string | null>;
    clip: Ref<VoiceClip | null>;
    /** The last stop was the 300-second ceiling, not the user. Cleared by the next start. */
    cutoff: Ref<boolean>;
    start: () => Promise<void>;
    /** Stop and keep it: the clip appears in `clip` and the state becomes `preview`. */
    stop: () => void;
    /** Stop and throw it away. Also the Escape key. */
    cancel: () => void;
    /** Throw away a clip that is already in preview. */
    discard: () => void;
    /** Release everything. `onBeforeUnmount`. */
    dispose: () => void;
}

/**
 * The recorder, as state a component can render.
 *
 * ## The microphone is handed back on every exit, and there are four
 *
 * `stop()`, `cancel()`, a failure, and unmount all reach `release()`, which calls `track.stop()`
 * on every track, tears the audio graph down and closes the `AudioContext`. It is idempotent, so
 * a path that reaches it twice is harmless and a path that might not reach it is not relied on.
 *
 * The mic is released the moment recording ends — **before** the preview, not after sending.
 * Holding an open stream through a preview the reader might abandon would leave the recording
 * light on for as long as they sat there, and a light that is on when nothing is recording is
 * the kind of thing that ends trust in an app. Re-recording simply asks again, which after the
 * first grant is instant and silent.
 */
export function useVoiceRecorder(): VoiceRecorderHandle {
    const state = ref<VoiceState>('idle');
    const seconds = ref(0);
    const levels = ref<number[]>(new Array<number>(LEVEL_BARS).fill(0));
    const level = ref(0);
    const error = ref<string | null>(null);
    const clip = ref<VoiceClip | null>(null);
    const cutoff = ref(false);

    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let context: AudioContext | null = null;
    let analyser: AnalyserNode | null = null;
    let source: MediaStreamAudioSourceNode | null = null;
    let samples: Uint8Array<ArrayBuffer> | null = null;

    let frame = 0;
    let ticker: ReturnType<typeof setInterval> | undefined;
    let safety: ReturnType<typeof setTimeout> | undefined;
    let startedAt = 0;
    let levelAt = 0;
    let chunks: Blob[] = [];
    let container: VoiceContainer | null = null;
    /** The stop in flight is a cancel: bin the bytes when they arrive. */
    let binning = false;
    /** Bumped by every start and every teardown, so a slow `getUserMedia` cannot land late. */
    let generation = 0;

    /**
     * Hand the microphone back, and take the audio graph down with it.
     *
     * Idempotent by construction: every handle is nulled as it is released, so a second call
     * finds nothing to do. This is the only function that calls `track.stop()`.
     */
    function release(): void {
        if (frame !== 0) {
            cancelAnimationFrame(frame);
            frame = 0;
        }

        if (ticker !== undefined) {
            clearInterval(ticker);
            ticker = undefined;
        }

        if (safety !== undefined) {
            clearTimeout(safety);
            safety = undefined;
        }

        if (source !== null) {
            try {
                source.disconnect();
            } catch {
                // A context already closed disconnects by itself.
            }

            source = null;
        }

        analyser = null;
        samples = null;

        if (context !== null) {
            const closing = context;

            context = null;
            void closing.close().catch(() => {
                // Closing twice is not an error worth showing anybody.
            });
        }

        if (stream !== null) {
            for (const track of stream.getTracks()) {
                track.stop();
            }

            stream = null;
        }
    }

    function resetLevels(): void {
        levels.value = new Array<number>(LEVEL_BARS).fill(0);
        level.value = 0;
    }

    function revoke(): void {
        if (clip.value !== null) {
            URL.revokeObjectURL(clip.value.url);
            clip.value = null;
        }
    }

    /** One rAF loop feeds both the waveform and the throttled meter. */
    function sample(): void {
        frame = requestAnimationFrame(sample);

        if (analyser === null || samples === null) {
            return;
        }

        analyser.getByteTimeDomainData(samples);

        let peak = 0;

        for (const value of samples) {
            const amplitude = Math.abs(value - 128) / 128;

            if (amplitude > peak) {
                peak = amplitude;
            }
        }

        // A little headroom so ordinary speech is a visible bar rather than a flat line.
        const scaled = Math.min(1, peak * 1.6);
        const next = levels.value.slice(1);

        next.push(scaled);
        levels.value = next;

        const at = performance.now();

        if (at - levelAt >= LEVEL_THROTTLE_MS) {
            levelAt = at;
            level.value = scaled;
        }
    }

    /**
     * Finish: stop the recorder and let `onstop` decide what the bytes become.
     *
     * The stream is released in `onstop` rather than here, because stopping the tracks first can
     * leave the final `dataavailable` unflushed on some builds. `safety` is the promise that the
     * microphone is handed back even if `onstop` never arrives.
     */
    function finish(keep: boolean): void {
        if (state.value !== 'recording' && state.value !== 'starting') {
            return;
        }

        binning = !keep;

        if (recorder === null || recorder.state === 'inactive') {
            // Nothing was ever running — a failure between `getUserMedia` and `start()`.
            release();
            resetLevels();
            state.value = 'idle';
            seconds.value = 0;

            return;
        }

        safety = setTimeout(release, 2000);

        try {
            recorder.stop();
        } catch {
            release();
            resetLevels();
            state.value = 'idle';
            seconds.value = 0;
        }
    }

    async function start(): Promise<void> {
        if (state.value === 'starting' || state.value === 'recording') {
            return;
        }

        const unavailable = voiceUnavailable();

        if (unavailable !== null) {
            error.value = VOICE_UNAVAILABLE_TEXT[unavailable];

            return;
        }

        container = pickContainer();

        if (container === null) {
            error.value = VOICE_UNAVAILABLE_TEXT['no-container'];

            return;
        }

        const mine = ++generation;

        revoke();
        resetLevels();
        error.value = null;
        cutoff.value = false;
        seconds.value = 0;
        chunks = [];
        binning = false;
        state.value = 'starting';

        let opened: MediaStream;

        try {
            opened = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (reason) {
            if (mine === generation) {
                error.value = startFailure(reason);
                state.value = 'idle';
                release();
            }

            return;
        }

        if (mine !== generation) {
            // Cancelled or unmounted while the permission prompt was up. The stream still has
            // to be handed back: nobody else holds a reference to it.
            for (const track of opened.getTracks()) {
                track.stop();
            }

            return;
        }

        stream = opened;

        try {
            recorder = new MediaRecorder(stream, { mimeType: container.mimeType });
        } catch (reason) {
            error.value = startFailure(reason);
            state.value = 'idle';
            release();

            return;
        }

        const chosen = container;

        recorder.ondataavailable = (event: BlobEvent) => {
            if (event.data.size > 0) {
                chunks.push(event.data);
            }
        };

        recorder.onerror = () => {
            error.value = 'Recording stopped unexpectedly.';
            binning = true;
        };

        recorder.onstop = () => {
            const kept = !binning;
            const elapsed = (performance.now() - startedAt) / 1000;

            release();
            resetLevels();
            recorder = null;

            if (!kept) {
                chunks = [];
                state.value = 'idle';
                seconds.value = 0;

                return;
            }

            // `type` comes from the recorder when it has one — a browser may answer a request
            // for `audio/webm;codecs=opus` with plain `audio/webm`, and the blob should say
            // what it is. The filename stays the one paired with what we asked for, which is
            // the same extension either way.
            const blob = new Blob(chunks, { type: chunks[0]?.type || chosen.mimeType });

            chunks = [];

            const whole = Math.min(VOICE_MAX_SECONDS, Math.max(1, Math.round(elapsed)));

            if (blob.size === 0) {
                error.value = 'Nothing was recorded.';
                state.value = 'idle';
                seconds.value = 0;

                return;
            }

            seconds.value = whole;
            clip.value = {
                blob,
                url: URL.createObjectURL(blob),
                filename: chosen.filename,
                mimeType: chosen.mimeType,
                seconds: whole,
                size: blob.size,
            };
            state.value = 'preview';
        };

        try {
            // A timeslice, so a long recording arrives in pieces and a crashed tab is the only
            // way to lose all of it.
            recorder.start(1000);
        } catch (reason) {
            error.value = startFailure(reason);
            state.value = 'idle';
            release();

            return;
        }

        startedAt = performance.now();
        state.value = 'recording';

        // The waveform. `AnalyserNode` reads the real stream — nothing here animates on a timer
        // pretending to be amplitude.
        try {
            const Context = window.AudioContext ?? (window as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

            if (Context !== undefined) {
                context = new Context();
                analyser = context.createAnalyser();
                analyser.fftSize = 1024;
                samples = new Uint8Array(new ArrayBuffer(analyser.fftSize));
                source = context.createMediaStreamSource(stream);
                source.connect(analyser);
                // Deliberately not connected to the destination: connecting the microphone to
                // the speakers is a feedback loop, not a monitor.
                frame = requestAnimationFrame(sample);
            }
        } catch {
            // No waveform on a browser whose audio graph refused. The timer still carries the
            // information, which is why the waveform is `aria-hidden` in the first place.
            analyser = null;
        }

        // The ceiling, and the clock. An interval rather than the rAF loop, because rAF does not
        // run in a background tab and a recording started and forgotten must still stop at 300.
        ticker = setInterval(() => {
            if (state.value !== 'recording') {
                return;
            }

            const elapsed = (performance.now() - startedAt) / 1000;

            seconds.value = Math.min(elapsed, VOICE_MAX_SECONDS);

            if (elapsed >= VOICE_MAX_SECONDS) {
                cutoff.value = true;
                finish(true);
            }
        }, 100);
    }

    function stop(): void {
        finish(true);
    }

    function cancel(): void {
        if (state.value === 'preview') {
            discard();

            return;
        }

        generation += 1;
        finish(false);
    }

    function discard(): void {
        revoke();
        resetLevels();
        seconds.value = 0;
        error.value = null;
        cutoff.value = false;
        state.value = 'idle';
    }

    function dispose(): void {
        generation += 1;
        binning = true;

        if (recorder !== null && recorder.state !== 'inactive') {
            try {
                recorder.stop();
            } catch {
                // Unmounting: there is nobody left to tell.
            }
        }

        recorder = null;
        chunks = [];
        release();
        revoke();
        state.value = 'idle';
    }

    return { state, seconds, levels, level, error, clip, cutoff, start, stop, cancel, discard, dispose };
}
