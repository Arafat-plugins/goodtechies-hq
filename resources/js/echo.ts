import type EchoClass from 'laravel-echo';
import type { Ref } from 'vue';
import { onScopeDispose, readonly, ref } from 'vue';

/**
 * The realtime transport, and the switch that turns it off.
 *
 * Phase 6 gives this application one socket: Laravel Reverb, self-hosted on the same VPS,
 * proxied by Nginx at `/app` (Part B §4). Everything that arrives on it was already readable
 * over HTTP by the person it reached — the channel callbacks in `app/Broadcasting/` ask the
 * same policies the routes do — so this file is a delivery mechanism and nothing else. It
 * never decides what somebody may see.
 *
 * ## Two supported modes, chosen at BUILD time
 *
 * `VITE_REALTIME` is read by Vite while bundling, so the mode is baked into the assets. That is
 * deliberate and it is why it is an `.env` key rather than a `settings` row — Part D §20 says
 * so in as many words ("Not settings, because they cannot switch at runtime"). The two halves
 * of the switch are:
 *
 *   VITE_REALTIME=reverb   + BROADCAST_CONNECTION=reverb  → socket
 *   VITE_REALTIME=polling  + BROADCAST_CONNECTION=log     → the 15 s poll (decision 2-39)
 *
 * They are set together, in `deploy/.env.production.example`, next to each other and with the
 * runbook beside them. Setting only one of the two is the failure this file cannot prevent and
 * `docs/runbooks/realtime.md` spends a paragraph on.
 *
 * **Polling is the default.** Anything other than the exact string `reverb` — unset, empty, a
 * typo, a key that was never filled in — is polling, because the failure mode of guessing
 * wrong in that direction is fifteen seconds of latency, and the other direction is a bell
 * that never updates and never says why.
 *
 * ## What happens when the socket drops
 *
 * `connectionState` is the honest answer and the bell reads it (`notifications.ts`): while the
 * socket is anything but `connected`, the bell goes back to polling and says so. A bell that
 * silently stops being right is worse than one that polls.
 */

/* ------------------------------------------------------------------ the mode */

export type RealtimeMode = 'reverb' | 'polling';

const REVERB_KEY = import.meta.env.VITE_REVERB_APP_KEY ?? '';

/**
 * The mode this bundle was built for.
 *
 * A missing app key forces polling however `VITE_REALTIME` was set: a socket that cannot
 * possibly connect is not a mode, it is a bundle somebody built with half the keys.
 */
export const realtimeMode: RealtimeMode =
    import.meta.env.VITE_REALTIME === 'reverb' && REVERB_KEY !== '' ? 'reverb' : 'polling';

/* ------------------------------------------------------------------ connection state */

/**
 * `connecting` is the honest state for "we do not know yet", and it is where a socket build
 * starts. A polling build never leaves `disabled`.
 */
export type RealtimeConnectionState = 'disabled' | 'connecting' | 'connected' | 'disconnected';

const connectionState = ref<RealtimeConnectionState>(realtimeMode === 'reverb' ? 'connecting' : 'disabled');

/** The socket's state, read-only. Nothing outside this file sets it. */
export const realtimeConnection: Readonly<Ref<RealtimeConnectionState>> = readonly(connectionState);

/**
 * Has the socket been up and come back?
 *
 * It matters because a socket delivers CHANGES, and a change that happened while it was down
 * was not delivered to anybody. Whatever a reconnect wakes has to re-read rather than assume,
 * which is what `notifications.ts` does with it.
 */
const reconnects = ref(0);

export const realtimeReconnects: Readonly<Ref<number>> = readonly(reconnects);

/* ------------------------------------------------------------------ the client */

type EchoClient = EchoClass<'reverb'>;

let clientPromise: Promise<EchoClient | null> | null = null;

/**
 * The one Echo instance, built on first use — and **imported** on first use.
 *
 * Both imports are dynamic, which is not a micro-optimisation: `laravel-echo` and `pusher-js`
 * are about 90 KB of JavaScript between them, and they are dead weight in every bundle a
 * POLLING build produces. Static imports put them in the layout chunk, which is the one chunk
 * every single page loads. Now a deployment that does not use the socket never downloads the
 * socket client, and one that does pays for it once, asynchronously, after the page is
 * interactive.
 *
 * `pusher-js` is the protocol client Reverb speaks; it goes on `window` because that is the
 * handle Echo's reverb connector looks for.
 */
function echo(): Promise<EchoClient | null> {
    if (realtimeMode !== 'reverb') {
        return Promise.resolve(null);
    }

    clientPromise ??= (async (): Promise<EchoClient | null> => {
        const [{ default: Echo }, { default: Pusher }] = await Promise.all([
            import('laravel-echo'),
            import('pusher-js'),
        ]);

        (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

        const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
        const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

        const client = new Echo<'reverb'>({
            broadcaster: 'reverb',
            key: REVERB_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            // `ws` and `wss` only. Pusher's fallbacks are XHR streaming and JSONP against
            // pusher.com's own hosts, and this application talks to one box.
            enabledTransports: ['ws', 'wss'],
        });

        const connection = (
            client.connector as unknown as {
                pusher: {
                    connection: { bind(event: string, handler: (payload: { current?: string }) => void): void };
                };
            }
        ).pusher.connection;

        connection.bind('state_change', (payload: { current?: string }) => {
            const next = payload.current;

            if (next === 'connected') {
                // Not the first connection: something may have been missed while it was down.
                if (connectionState.value === 'disconnected') {
                    reconnects.value += 1;
                }

                connectionState.value = 'connected';

                return;
            }

            // `connecting` is a reconnect attempt and is not a state worth telling a reader
            // about on its own; `unavailable` and `failed` are, and so is a deliberate
            // disconnect.
            connectionState.value = next === 'connecting' || next === 'initialized' ? 'connecting' : 'disconnected';
        });

        return client;
    })();

    return clientPromise;
}

/* ------------------------------------------------------------------ subscribing */

/** One handler per event name, as the server spells it (`broadcastAs()`). */
export type RealtimeHandlers = Record<string, (payload: never) => void>;

/**
 * Listen to a private channel. Returns the function that stops listening.
 *
 * The channel name is given WITHOUT the `private-` prefix, exactly as `routes/channels.php`
 * registers it: `notifications.7`, `task.41`, `conversation.12`.
 *
 * A polling build makes no subscription and opens no socket, and returns a no-op — callers do
 * not branch on the mode. They subscribe, and then ask `realtimeConnection` whether they also
 * need to poll.
 *
 * This is the low-level half. Anything living inside a component should use
 * `useRealtimeChannel()` below, which cannot forget to call the returned function. This one
 * exists for the module-scoped, reference-counted subscription in
 * `Components/Notifications/notifications.ts`, whose lifetime is the bell's and not any one
 * bell component's.
 */
export function listenPrivate(channel: string, handlers: RealtimeHandlers): () => void {
    // Synchronous in, synchronous out, even though the client is now imported asynchronously:
    // a caller that had to await a subscription would have to be an async setup, and a
    // component unmounted before the import resolved would leak a channel. So the unsubscribe
    // exists from the first tick and the `left` flag is what a torn-down caller sets.
    let left = false;
    let leave: (() => void) | null = null;

    void echo().then((connection) => {
        if (connection === null || left) {
            return;
        }

        const subscription = connection.private(channel);

        for (const [event, handler] of Object.entries(handlers)) {
            // Echo prefixes a client-declared name with `.` to mean "this is the literal name
            // the server broadcast, do not namespace it".
            subscription.listen(`.${event}`, handler as (payload: unknown) => void);
        }

        leave = () => connection.leave(channel);
    });

    return () => {
        left = true;
        leave?.();
        leave = null;
    };
}

/**
 * Listen to a private channel for as long as the calling scope lives.
 *
 * The subscription is torn down by `onScopeDispose`, because a component that has to remember
 * to unsubscribe is a component that will not, and a leaked channel keeps delivering into a
 * handler whose component is gone.
 */
export function useRealtimeChannel(channel: string, handlers: RealtimeHandlers): void {
    const stop = listenPrivate(channel, handlers);

    onScopeDispose(stop);
}
