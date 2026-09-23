<?php

namespace App\Support;

use App\Models\File;

/**
 * How a file rides on a message: as a plain attachment, as an image the bubble renders inline,
 * or as a voice note.
 *
 * Phase 2 writes `file` and `image`. `voice` is declared and never written — Phase 6 adds
 * recording on these same tables, and having the case here now is what makes that a feature
 * rather than a migration.
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
