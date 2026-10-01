<?php

namespace App\Support;

/**
 * Which of a person's two push switches (Profile → Notifications on this device) a push obeys.
 */
enum PushCategory: string
{
    /** A new message in a chat the person can read — `users.push_messages`. */
    case Messages = 'messages';

    /** Anything that rings the bell — `users.push_alerts`. */
    case Alerts = 'alerts';
}
