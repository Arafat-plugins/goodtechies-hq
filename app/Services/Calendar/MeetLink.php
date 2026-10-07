<?php

namespace App\Services\Calendar;

/**
 * What a Google Meet URL looks like, decided in exactly one place.
 *
 * ## Why this is a class and not a regex in a Form Request
 *
 * The check has three callers and they are not all HTTP:
 *
 *   1. `MeetingService`, which refuses to store a link that is not one — because the service is
 *      reachable from a seeder, a console command and a future import, none of which pass
 *      through a Form Request.
 *   2. **The Form Request the controller slice writes**, so that a bad paste is a field error on
 *      the form rather than an exception. *That slice must call `MeetLink::looksValid()` and
 *      must not write a second regex* — a rule stated twice is a rule that gets changed once.
 *   3. `ManualLink::startUrl()`, which hands the *Create Meet Link* button the URL that starts
 *      Google's instant-meeting flow, so no Vue file holds a hard-coded Google address.
 *
 * ## What it accepts, and what it deliberately does not
 *
 * A real Meet link is `https://meet.google.com/abc-defg-hij` — three lowercase letter groups of
 * 3-4-3 — and Workspace also issues `https://meet.google.com/lookup/<alias>` for named rooms.
 * Both pass. So does an optional `?hs=` or `?authuser=` query, because that is what lands in the
 * clipboard when somebody copies from the Meet tab rather than from the *Copy joining info*
 * button, and rejecting it would teach people the field is broken.
 *
 * What does **not** pass: any other host, `http://`, and `meet.google.com/new` — the last one
 * because it is the URL that *starts* a meeting rather than one that joins a particular one.
 * Somebody who pastes it back has pasted the button's own address, which is the single most
 * likely wrong paste there is, and storing it would give every meeting in the agency the same
 * link to a different room each time it is clicked.
 *
 * The check is on **shape only**. Nothing here asks Google whether the room exists; that would
 * be a network call inside a validator, and under the manual driver there is no Google client
 * to make it with.
 */
final class MeetLink
{
    /**
     * Google's instant-meeting flow — the URL Part D §12's *"Create Meet Link"* button opens.
     */
    public const NEW_MEETING_URL = 'https://meet.google.com/new';

    /**
     * A meeting code (`abc-defg-hij`) or a named room (`lookup/<alias>`), on the Meet host,
     * over HTTPS, with an optional query string and nothing else.
     */
    private const PATTERN = '#^https://meet\.google\.com/(?:lookup/[A-Za-z0-9_-]{1,64}|[a-z]{3}-[a-z]{4}-[a-z]{3})(?:\?[^\s]*)?$#';

    /**
     * Does this look like a link to a particular Meet room?
     *
     * An empty string answers `false`: "no link" is expressed by a NULL column, and a caller
     * that means "clear it" says so rather than sending a blank through here.
     */
    /**
     * Polish 030: a link typed or pasted as `http://meet.google.com/…` or `meet.google.com/…`
     * is the same room — read it as the https address rather than refusing it.
     */
    public static function canonical(string $url): string
    {
        $url = trim($url);

        if (str_starts_with(strtolower($url), 'http://meet.google.com/')) {
            return 'https://'.substr($url, 7);
        }

        if (str_starts_with(strtolower($url), 'meet.google.com/')) {
            return 'https://'.$url;
        }

        return $url;
    }

    public static function looksValid(string $url): bool
    {
        $url = self::canonical($url);

        if ($url === '' || $url === self::NEW_MEETING_URL) {
            return false;
        }

        return preg_match(self::PATTERN, $url) === 1;
    }

    /**
     * The value to store for a pasted link: the trimmed URL, or null when there is nothing
     * usable. Never throws — the caller decides whether a bad paste is an error or an omission.
     */
    public static function normalise(?string $url): ?string
    {
        $url = self::canonical((string) $url);

        return self::looksValid($url) ? $url : null;
    }
}
