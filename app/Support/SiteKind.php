<?php

namespace App\Support;

/**
 * Where a slice of a minute went (docs/extension-api.md §5). Only `site` carries a host.
 */
enum SiteKind: string
{
    case Site = 'site';
    case OtherApp = 'other_app';
    case BrowserInternal = 'browser_internal';
    case Private = 'private';
}
