<?php

namespace App\Support;

use App\Models\File;

/**
 * How a file rides on a message: as a plain attachment, as an image the bubble renders inline,
 * or as a voice note.
 *
 * Phase 2 wrote `file` and `image` and declared `voice` without ever writing it. Phase 6 writes
 * it, on the same tables and with no migration — which is what having the case here early was
 * for.
 *
 * ## `voice` is the only one a client may assert
 *
 * `file` and `image` are derived by `forFile()` below, and a client that could claim one would
 * be a client deciding whether its own upload renders inline in everybody else's thread.
 * `voice` cannot be derived — see the next paragraph — so it is the one value the composer
 * sends, and `StoreMessageRequest` accepts that one word and no other.
 *
 * A file carrying this kind is also checked against a different, narrow allow-list
 * (`FileService::VOICE_TYPES`) instead of the application-wide `FileService::TYPES`. That is the
 * whole reach of the word: audio is uploadable as a voice note and nowhere else.
 *
 * ## Why the column exists at all when two of its three values are derivable
 *
 * `file` versus `image` can be worked out from the file's MIME type, and `from()` below does
 * exactly that. `voice` cannot: a voice note is an audio file that somebody RECORDED rather
 * than attached, and no MIME type distinguishes the two. A column that is derived for two
 * values and authoritative for the third is still one column with one meaning — "how this was
 * attached" — and it is written once, at the only place an attachment is created.
 */
enum AttachmentKind: string
{
    case File = 'file';
    case Image = 'image';
    case Voice = 'voice';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The kind an uploaded file is attached as.
     *
     * Reads File::isImage(), which is the INLINE list and not `image/*` — an SVG is an image
     * everywhere except in the one place it matters, and it is not an accepted upload type at
     * all. Anything else is a plain file, so a document that lies about its type gets a
     * download bubble rather than an inline render.
     */
    public static function forFile(File $file): self
    {
        return $file->isImage() ? self::Image : self::File;
    }
}
