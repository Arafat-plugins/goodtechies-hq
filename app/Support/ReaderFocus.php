<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Is the person behind this request actually looking at the thread it reads?
 *
 * Reliability slice 5. Reading an open thread is what marks it read (`last_read_at`), and the
 * page's background readers — the Messages rail and shell polls, which partially reload
 * `/messages?conversation=N`, and the thread's own refresh — kept doing it while the window sat
 * behind another one. They now send `X-HQ-Focused: 1|0` (`resources/js/lib/attention.ts`), from
 * `document.hasFocus()` and the tab being visible.
 *
 * The header only ever *withholds* a mark-read: it cannot reach a conversation the policy has not
 * already allowed, and it cannot mark anything that a plain visit would not. So it is a courtesy
 * flag, not an authority, and a client that lies about it lies only about its own unread line.
 */
final class ReaderFocus
{
    public const HEADER = 'X-HQ-Focused';

    /**
     * For the Inertia page: a visit the person made (no partial-reload header) marks read, as
     * it always has; a background partial reload marks read only when it says it is focused.
     */
    public static function pageMayMarkRead(Request $request): bool
    {
        if (! $request->hasHeader('X-Inertia-Partial-Component')) {
            return true;
        }

        return $request->header(self::HEADER) === '1';
    }

    /**
     * For the JSON thread reads: marks read unless the request says nobody is looking. A plain
     * GET with no flag — a link or the address bar — keeps marking read (decision 6-31's
     * behaviour for a navigation is unchanged).
     */
    public static function jsonMayMarkRead(Request $request): bool
    {
        return $request->header(self::HEADER) !== '0';
    }
}
