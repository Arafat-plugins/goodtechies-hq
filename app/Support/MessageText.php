<?php

namespace App\Support;

/**
 * Polish 047: a message's words without its Discord-style formatting marks, for every place
 * that shows a message as one line of plain text — the chat list, a reply's quote, the
 * announcement strip, search snippets and the popup on a phone.
 *
 * The full parser lives in resources/js/Components/Messages/markdown.ts, which draws the
 * message itself; this is the same set of marks, taken off. A spoiler stays hidden (▒▒▒).
 */
final class MessageText
{
    public static function plain(string $body): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $body);

        // Code first: what is inside a code block or `code` is shown exactly as typed.
        $codes = [];
        $keep = function (string $value) use (&$codes): string {
            $codes[] = $value;

            return "\u{E000}".(count($codes) - 1)."\u{E001}";
        };

        $text = (string) preg_replace_callback('/```[\w+#.-]*\n?(.*?)\n?```/su', fn ($m) => $keep($m[1]), $text);
        $text = (string) preg_replace_callback('/`([^`\n]+)`/u', fn ($m) => $keep($m[1]), $text);

        // Escaped marks stay as the mark itself.
        $text = (string) preg_replace_callback('/\\\\([\\\\*_~|`#>])/u', fn ($m) => $keep($m[1]), $text);

        // Line starts: headings and quotes.
        $text = (string) preg_replace('/^#{1,3} (?=\S)/mu', '', $text);
        $text = (string) preg_replace('/^> ?/mu', '', $text);

        $text = (string) preg_replace('/\|\|(?!\s)(.+?)(?<!\s)\|\|/su', '▒▒▒', $text);

        foreach (['\*\*', '__', '~~'] as $mark) {
            $text = (string) preg_replace('/'.$mark.'(?!\s)(.+?)(?<!\s)'.$mark.'/su', '$1', $text);
        }

        $text = (string) preg_replace('/(?<!\*)\*(?![\s*])(.+?)(?<![\s*])\*(?!\*)/su', '$1', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}_])_(?!\s)(.+?)(?<!\s)_(?![\p{L}\p{N}_])/su', '$1', $text);

        return (string) preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn ($m) => $codes[(int) $m[1]] ?? '', $text);
    }
}
