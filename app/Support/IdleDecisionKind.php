<?php

namespace App\Support;

/**
 * An answer to the idle prompt (docs/extension-api.md §6), or the server's own auto-pause.
 */
enum IdleDecisionKind: string
{
    case Keep = 'keep';
    case Discard = 'discard';
    case Meeting = 'meeting';
    case Stop = 'stop';
    case AutoPause = 'auto_pause';
}
