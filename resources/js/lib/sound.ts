import type { Ref } from 'vue';
import { computed, ref } from 'vue';
import { isSessionLive } from '@/lib/session';

/**
 * The chime for something new — reliability slice 5.
 *
 * Somebody with goodERP open behind another window hears a short chime when the bell's unread
 * count or the Messages unread total goes UP. On by default since 12-77 (the client asked for a
 * Telegram-like sound); switched off per person on the Profile page, and an explicit off is
 * remembered. The client chose this over a tab-title count and desktop pop-ups; there is no
 * Notification API, no service worker and no push here, by design (Part H).
 *
 * **Stored per browser, not on the server.** There is no per-person preference store to put it
 * in — `notification_preferences` is the Admin's global defaults and has no `user_id`, and
 * `users` / `employees` carry no JSON column — and a new column or Settings key would need a
 * migration or a recorded decision. So it is `localStorage` key `hq.sound.<userId>`, and the
 * Profile hint says "Saved on this device". Every storage call is wrapped (DESIGN.md §5.9).
 *
 * The tone is generated with the Web Audio API — an original, Telegram-like "pop" (a quick
 * triangle note with a falling pitch and a soft octave above it, then a quieter second note),
 * ~260 ms, modest gain — so there is no audio file and no dependency. A browser that blocks audio until the
 * page has had a gesture simply stays silent; the Profile page's *Play a test sound* is such a
 * gesture, and it also resumes the one shared `AudioContext`.
 */

/* ------------------------------------------------------------------ the preference */

const KEY_PREFIX = 'hq.sound.';

/** `userId → on`, so every mount of the toggle reads the same answer. */
const cache = ref<Record<number, boolean>>({});

function storageKey(userId: number): string {
    return `${KEY_PREFIX}${userId}`;
}

export function readSoundEnabled(userId: number): boolean {
    if (userId in cache.value) {
        return cache.value[userId];
    }

    // On by default since 12-77: only a stored '0' (an explicit off) turns it off.
    let on = true;

    try {
        on = window.localStorage.getItem(storageKey(userId)) !== '0';
    } catch {
        on = true;
    }

    cache.value = { ...cache.value, [userId]: on };

    return on;
}

export function writeSoundEnabled(userId: number, on: boolean): void {
    cache.value = { ...cache.value, [userId]: on };

    try {
        if (on) {
            window.localStorage.removeItem(storageKey(userId));
        } else {
            window.localStorage.setItem(storageKey(userId), '0');
        }
    } catch {
        // Private mode: the switch still works for this page's lifetime.
    }
}

/** A writable ref over this person's switch. */
export function useSoundPreference(userId: number): Ref<boolean> {
    return computed({
        get: () => readSoundEnabled(userId),
        set: (on: boolean) => writeSoundEnabled(userId, on),
    });
}

/* ------------------------------------------------------------------ the tone */

let context: AudioContext | null = null;

function audioContext(): AudioContext | null {
    if (context !== null) {
        return context;
    }

    const Ctor = typeof window === 'undefined'
        ? undefined
        : (window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext);

    if (Ctor === undefined) {
        return null;
    }

    try {
        context = new Ctor();
    } catch {
        context = null;
    }

    return context;
}

/**
 * One note with a soft envelope: an 8 ms attack to `peak`, then an exponential fade to silence
 * by `duration`, so neither edge clicks. `glideTo` drops (or raises) the pitch over 60 ms.
 */
function tone(
    ctx: AudioContext,
    frequency: number,
    startAt: number,
    duration: number,
    type: OscillatorType = 'sine',
    peak = 0.08,
    glideTo: number | null = null,
): void {
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();

    oscillator.type = type;
    oscillator.frequency.setValueAtTime(frequency, startAt);

    if (glideTo !== null) {
        oscillator.frequency.exponentialRampToValueAtTime(glideTo, startAt + 0.06);
    }

    gain.gain.setValueAtTime(0.0001, startAt);
    gain.gain.exponentialRampToValueAtTime(peak, startAt + 0.008);
    gain.gain.exponentialRampToValueAtTime(0.0001, startAt + duration);

    oscillator.connect(gain);
    gain.connect(ctx.destination);
    oscillator.start(startAt);
    oscillator.stop(startAt + duration + 0.02);
}

/** A short Telegram-like pop, ~260 ms. Silent — never an error — when the browser will not play. */
export function playChime(): void {
    try {
        const ctx = audioContext();

        if (ctx === null) {
            return;
        }

        if (ctx.state === 'suspended') {
            void ctx.resume().catch(() => undefined);
        }

        const now = ctx.currentTime;

        // The pop: ~C6 on a triangle, its pitch dropping to ~G5 over 60 ms, with a softer sine an
        // octave above it (×0.3), gone by 220 ms.
        tone(ctx, 1046.5, now, 0.22, 'triangle', 0.12, 784);
        tone(ctx, 2093, now, 0.22, 'sine', 0.12 * 0.3, 1568);
        // Then a second, quieter note (~G6) from +90 ms, gone by 260 ms.
        tone(ctx, 1568, now + 0.09, 0.17, 'sine', 0.06);
    } catch {
        // Blocked or unsupported: the chime is a courtesy, not a channel.
    }
}

/* ------------------------------------------------------------------ the message sounds */

/**
 * Telegram's two message sounds (decided with the client, brief 009): one for a message that
 * arrives, one for a message of yours the server has accepted. Plain files under
 * `public/sounds/`, each played through ONE lazily created `HTMLAudioElement` that is reused —
 * rewound to the start each time, so two quick sends replay rather than stack up.
 */
// Polish 013: the sent sound is quieter than the arrival sound — it confirms your own action.
// Polish 021: the `sent` FILE now rings for a bell notification (client: "swap the notification
// and the sending sounds"), so it plays at the arrival volume; sending plays the chime instead.
const MESSAGE_SOUND_VOLUME: Record<'received' | 'sent', number> = { received: 0.6, sent: 0.6 };
const players: Partial<Record<'received' | 'sent', HTMLAudioElement>> = {};

function playFile(which: 'received' | 'sent'): void {
    try {
        if (typeof Audio === 'undefined') {
            return;
        }

        let player = players[which];

        if (player === undefined) {
            player = new Audio(`/sounds/message-${which}.mp3`);
            player.preload = 'auto';
            player.volume = MESSAGE_SOUND_VOLUME[which];
            players[which] = player;
        }

        player.currentTime = 0;
        void player.play().catch(() => undefined);
    } catch {
        // Blocked or unsupported: a sound is a courtesy, not a channel.
    }
}

/** The switch, the session: the same gate `noteUnread()` puts in front of `playChime()`. */
function soundAllowed(): boolean {
    return isSessionLive() && boundUser !== null && readSoundEnabled(boundUser);
}

/** A message arrived. Called from `noteUnread('messages', …)`, which owns the quiet windows. */
export function playMessageReceived(): void {
    playFile('received');
}

/**
 * The server accepted a message of yours. Your own action, so no quiet window holds it back —
 * only the on/off switch and a live session.
 */
export function playMessageSent(): void {
    if (soundAllowed()) {
        // Polish 021: sending plays the short "pop" that used to be the bell's sound.
        playChime();
    }
}

/** Polish 021: a new bell notification plays the file that used to confirm a send. */
export function playNotification(): void {
    playFile('sent');
}

/* ------------------------------------------------------------------ when it plays */

export type ChimeSource = 'bell' | 'messages';

/** At most one chime in this window, whichever count rose. */
export const CHIME_QUIET_MS = 10_000;

/**
 * One DM is TWO rises: the bell gains a *message.received* notification and the Messages total
 * goes up — read by different polls (15 s and 30 s) that can land up to one shell interval apart.
 * So a rise in the OTHER count within this window of a chime is the same arrival and stays quiet;
 * the same count rising again after `CHIME_QUIET_MS` is a new arrival and chimes.
 */
export const CHIME_SAME_ARRIVAL_MS = 30_000;

let boundUser: number | null = null;
const previous: Record<ChimeSource, number | null> = { bell: null, messages: null };
let lastChimeAt = 0;
let lastChimeSource: ChimeSource | null = null;

/**
 * Who is signed in on this page. Called from the shell's composables, which have the page
 * props; a different person resets the baselines so their first read is not "new".
 */
export function bindChimeUser(userId: number | null): void {
    if (userId === boundUser) {
        return;
    }

    boundUser = userId;
    previous.bell = null;
    previous.messages = null;
}

/**
 * Move a count's baseline without playing anything: the count changed for a reason that is not an
 * arrival. Messaging polish — the Messages count leaves out the conversation on screen, so
 * looking away from a thread with something unread in it raises the count with nothing new.
 */
export function rebaseUnread(source: ChimeSource, count: number): void {
    if (previous[source] !== null) {
        previous[source] = count;
    }
}

/**
 * A successful read of an unread count. Plays when it went UP compared with the previous read
 * of the same count — never on the first read, never on a fall, at most once per
 * `CHIME_QUIET_MS` (and not for the other half of one DM — `CHIME_SAME_ARRIVAL_MS`), never
 * while the session is not `ok`, and only with the switch on.
 */
export function noteUnread(source: ChimeSource, count: number): void {
    const before = previous[source];

    previous[source] = count;

    if (before === null || count <= before) {
        return;
    }

    if (!soundAllowed()) {
        return;
    }

    const now = Date.now();

    if (now - lastChimeAt < CHIME_QUIET_MS) {
        return;
    }

    if (lastChimeSource !== null && lastChimeSource !== source && now - lastChimeAt < CHIME_SAME_ARRIVAL_MS) {
        return;
    }

    lastChimeAt = now;
    lastChimeSource = source;

    // Brief 009: a message rise plays Telegram's incoming sound. Polish 021: the bell plays the
    // former send sound (and sending plays the former bell chime).
    if (source === 'messages') {
        playMessageReceived();
    } else {
        playNotification();
    }
}
