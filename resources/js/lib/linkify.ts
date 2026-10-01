/**
 * Split plain text into text and link segments — brief 025, the task description.
 *
 * It never produces markup. The caller renders each segment as a text node or as an `<a>` it
 * builds itself, so Vue escapes everything and a `<script>` typed into a description is shown as
 * the characters it is. Only `http://`, `https://` and `www.` are recognised, and the href is
 * always one of those two schemes (a bare `www.` gets `https://`), so no `javascript:` or `data:`
 * URL can come out of it whatever the text says.
 *
 * Trailing punctuation that ends a sentence (`.`, `,`, `;`, `:`, `!`, `?`, quotes) is left out of
 * the link, and so is a closing bracket that has no opening one inside the URL — "(see
 * https://x.test/a)" links `https://x.test/a`, while a Wikipedia-style `…/Foo_(bar)` keeps its own.
 */

export type LinkifySegment = { kind: 'text'; text: string } | { kind: 'link'; text: string; href: string };

const URL_PATTERN = /(?<![\w@./-])(?:https?:\/\/|www\.)[^\s<>"'`]+/gi;
const TRAILING = /[.,;:!?'"*_~]+$/;

function trimUrl(raw: string): string {
    let url = raw;

    for (;;) {
        const before = url;

        url = url.replace(TRAILING, '');

        for (const [open, close] of [['(', ')'], ['[', ']'], ['{', '}']] as const) {
            while (url.endsWith(close) && url.split(close).length > url.split(open).length) {
                url = url.slice(0, -1);
            }
        }

        if (url === before) {
            return url;
        }
    }
}

export function linkify(text: string): LinkifySegment[] {
    const segments: LinkifySegment[] = [];
    let last = 0;

    for (const match of text.matchAll(URL_PATTERN)) {
        const start = match.index ?? 0;
        const url = trimUrl(match[0]);

        // "www." or "https://" with nothing after it is not a link.
        if (/^(?:https?:\/\/|www\.)$/i.test(url)) {
            continue;
        }

        if (start > last) {
            segments.push({ kind: 'text', text: text.slice(last, start) });
        }

        const href = /^www\./i.test(url) ? `https://${url}` : url;

        segments.push({ kind: 'link', text: url, href });
        last = start + url.length;
    }

    if (last < text.length) {
        segments.push({ kind: 'text', text: text.slice(last) });
    }

    return segments;
}
