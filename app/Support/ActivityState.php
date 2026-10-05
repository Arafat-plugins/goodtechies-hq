<?php

namespace App\Support;

/**
 * What one minute of a running timer looked like (docs/extension-api.md §5).
 */
enum ActivityState: string
{
    case Active = 'active';
    case Media = 'media';
    case Call = 'call';
    case Idle = 'idle';
}
