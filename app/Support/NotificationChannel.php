<?php

namespace App\Support;

/**
 * The ways a notification could be delivered.
 *
 * **Only `InApp` is built.** The master prompt's §11 is explicit: "Channels in MVP: in-app
 * only … the engine keeps a `channels` list per notification type so they can be switched on
 * later without a rewrite, but no Web Push or mail sending is built." The other two cases exist
 * so that Phase 12 turns one on by editing NotificationType::channels() and writing the sender —
 * not by threading a new concept through the engine, the events and the listener.
 *
 * Nothing in this phase sends through anything but the in-app channel, and
 * NotificationService::deliver() is the one place that says so out loud.
 */
enum NotificationChannel: string
{
    /** A row in `notifications`, read by the bell and the Notification Center. */
    case InApp = 'in_app';

    /** Browser push (spec post-MVP §44) — Phase 12. Never in a channels() list today. */
    case WebPush = 'web_push';

    /** The email digest (spec post-MVP §44) — Phase 12. Never in a channels() list today. */
    case Mail = 'mail';
}
