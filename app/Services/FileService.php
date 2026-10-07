<?php

namespace App\Services;

use App\Exceptions\FileStateException;
use App\Models\Client;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\AttachmentKind;
use App\Support\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files: the one way a file is stored, replaced, fetched or removed.
 *
 * Nothing else in the application touches the disk. That is the whole point of this class —
 * three surfaces attach files (a task, a project, a client) and if each of them wrote its own
 * upload handler they would each get their own idea of what a safe filename is.
 *
 * ## The disk
 *
 * Decision 2-2: files go to the LOCAL disk behind signed, expiring URLs rather than to S3,
 * because no bucket exists yet. Everything here is written against Laravel's `Storage`
 * abstraction and never against a path on this machine, so the move is `FILESYSTEM_DISK=s3`
 * in `.env` and nothing else. The disk a row was written to is stored ON the row, so rows
 * written before a switch still resolve afterwards.
 *
 * ## The URL
 *
 * `url()` does NOT return `Storage::temporaryUrl()`. That would be a bearer capability: a link
 * that works for whoever holds it until it expires — and on the local disk Laravel's own
 * `/storage/{path}` route is exactly that, signature-checked but unauthenticated. This
 * application's rule is that a record the requester may not see is invisible, so a link that
 * hands a task attachment to an Accountant because somebody pasted it into a chat is the wrong
 * security model however short its life is.
 *
 * So the URL is a signed, expiring route into THIS application, and `FilePolicy` runs on every
 * fetch. The signature stops the id being tampered with and closes the window; the policy
 * decides the person. Both, not either. This is also why the move to S3 changes nothing: the
 * download route streams from the disk through `Storage`, whichever driver is behind it.
 *
 * ## Versions
 *
 * `replace()` never overwrites. It writes a new row with new bytes at a new path and stamps
 * `superseded_at` on the old one, which keeps its bytes, its path and its uploader. A partial
 * unique index makes two current versions of one file impossible at the database level.
 *
 * Validation lives in the Form Requests, as it does everywhere here — and ALSO here, at the one
 * place that writes bytes, so a job or a console command is refused the same way an upload is.
 * The constants below are what the Form Requests build their rules from, so there is one list.
 */
class FileService
{
    /**
     * The biggest file this application will store, in bytes.
     *
     * 25 MB, for task, project and client files (messages are exempt — see MESSAGE_MAX_BYTES).
     * `deploy/nginx.conf` allows a 256 MB body and `deploy/install.sh` sets PHP's
     * `upload_max_filesize` and `post_max_size` to match, so this refusal is always a validation
     * error against the field rather than an nginx 413. It is comfortably above the screenshots,
     * PDFs and deliverable zips an agency actually attaches.
     */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /**
     * The biggest MESSAGE attachment this application will store, in bytes.
     *
     * Messages: no application cap (12-82); nginx/PHP keep the transport limit
     * (`client_max_body_size` in deploy/nginx.conf, `upload_max_filesize` / `post_max_size` in
     * deploy/install.sh). Task, project and client uploads keep MAX_BYTES.
     */
    public const MESSAGE_MAX_BYTES = null;

    /**
     * What may be uploaded: extension → the content types that extension may legitimately be.
     *
     * Both halves are checked. The extension decides the name the file is stored and served
     * under; the detected content type decides whether it is what it says it is. A `.php`
     * renamed to `.pdf` fails the second check, and a genuine PDF named `.php` fails the first.
     *
     * Deliberately absent, and each for its own reason:
     *   - `svg`: an XML document that can carry script. It is an image everywhere except in the
     *     one place it matters, and we serve files from our own origin. (Messages accept it
     *     through MESSAGE_EXTRA_TYPES, script-checked and served sandboxed — 12-82.)
     *   - `html`, `htm`: the same hole without the disguise.
     *   - `exe`, `bat`, `sh`, `php`, `js`: nothing here ever needs to store an executable.
     * An allow-list rather than a deny-list, so the next extension somebody invents is refused
     * by default instead of being remembered about.
     *
     * The OOXML types also accept `application/zip` because that is what they are, and an older
     * `finfo` database says so.
     *
     * @var array<string, list<string>>
     */
    public const TYPES = [
        // Images — the set the detail page may preview inline.
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],

        // Documents.
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],

        // One deliverable handed over as one file.
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    /**
     * What a VOICE NOTE may be: extension → the content types that extension may legitimately be.
     *
     * ## Why this is a second list and not five more rows in TYPES
     *
     * TYPES is the allow-list for every file panel in the application — a task's attachments, a
     * project's deliverables, a client's documents, and an ordinary file dropped on a message.
     * Adding audio there to make one feature work would widen all of them at once, and the blast
     * radius of "the Files panel now accepts media" is far larger than Phase 6 asked for: a
     * 25 MB `.wav` in a client folder is a storage bill, not a deliverable, and nobody chose it.
     *
     * So a recording is checked against THIS list and only this list, reached only when the
     * caller says `AttachmentKind::Voice` — which only the composer's mic button ever says. A
     * plain upload stays exactly as restricted as it was before Phase 6, and the test that proves
     * it is `tests/Feature/Messages/VoiceMessageTest.php` → *"refuses an audio file attached
     * through the ordinary Files panel"*. Somebody will eventually want to merge the two lists;
     * that test is the argument, and this paragraph is the reason behind it.
     *
     * ## The pairings, measured rather than remembered
     *
     * `assertAcceptable()` reads `getMimeType()`, which is **finfo over the temp file's bytes**,
     * not the `Content-Type` the browser claimed. finfo does not care what a container is being
     * used for, so an audio-only WebM is `video/webm` and an audio-only MP4 is `video/mp4` —
     * which is exactly how Chrome's and Safari's recordings arrive. Both spellings are listed
     * because a different finfo database answers differently, and a recording refused on one
     * server and accepted on another is the bug nobody can reproduce.
     *
     * Measured 2026-09-24 on this box, on files ffmpeg actually produced:
     *
     *     ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a libopus rec.webm   # and so on
     *     php -r 'echo (new finfo(FILEINFO_MIME_TYPE))->file("rec.webm");'
     *
     *   | webm (opus) | video/webm  |   | m4a (aac) | audio/x-m4a |   | ogg (opus)   | audio/ogg |
     *   | mp4 (aac)   | video/mp4   |   | mp3       | audio/mpeg  |   | wav (pcm16)  | audio/x-wav |
     *
     * @var array<string, list<string>>
     */
    public const VOICE_TYPES = [
        // Chrome and Firefox: Opus in a WebM container. finfo reports the CONTAINER, and a
        // WebM with no video track is still a WebM — hence `video/webm`, measured, not assumed.
        'webm' => ['audio/webm', 'video/webm'],

        // Safari: AAC in an MP4 container, handed over as either extension.
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'mp4' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],

        // Ogg, whichever codec is inside it. `application/ogg` is what an older finfo says when
        // it can see the container but not the stream.
        'ogg' => ['audio/ogg', 'application/ogg'],
        'oga' => ['audio/ogg', 'application/ogg'],

        'mp3' => ['audio/mpeg'],

        // Three spellings of the same 1991 header, and finfo picks between them by database
        // vintage. This box says `audio/x-wav`.
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
    ];

    /**
     * What a MESSAGE attachment may be on top of TYPES (12-82). Messages only: the Files panels
     * on tasks, projects and clients keep TYPES alone. `zip` is already in TYPES.
     *
     * Measured 2026-10-02 on this box with finfo over real samples (an SVG text file with and
     * without an XML prolog, `ffmpeg -f lavfi -i sine -t 1 x.mp3` / `x.mp4`, a video+audio mp4,
     * a plain zip renamed .apk and a zip carrying AndroidManifest.xml):
     *
     *   | svg | image/svg+xml |   | mp3 | audio/mpeg |   | mp4 | video/mp4 |
     *   | apk | application/vnd.android.package-archive (manifest first), application/zip |
     *
     * Only the reported strings plus the canonical one are listed. An SVG is additionally
     * refused if it carries script (see `assertSafeSvg()`), and is served sandboxed.
     *
     * @var array<string, list<string>>
     */
    public const MESSAGE_EXTRA_TYPES = [
        'svg' => ['image/svg+xml'],
        'mp3' => ['audio/mpeg'],
        'mp4' => ['video/mp4'],
        'apk' => ['application/vnd.android.package-archive', 'application/zip'],
    ];

    /**
     * How much of an SVG is scanned for script before it is accepted: the first 1 MB.
     */
    private const SVG_SCAN_BYTES = 1024 * 1024;

    /**
     * How long a download link lives: until the start of the day after tomorrow.
     *
     * Polish 030. It was fifteen minutes, and a chat left open showed "Link expired" on every
     * picture after a quarter of an hour — the client compared it with Telegram, where a picture
     * once seen stays seen. The signature was never what kept a file private: the route is
     * behind `auth` and FilePolicy runs on every fetch, so a link in the wrong hands is a 404 at
     * any age. The expiry is only there so a link does not live for ever.
     *
     * Anchored to a day boundary on purpose: every link minted today for a file is the SAME
     * URL, so the browser's cache (see `download()`) hits on every re-read of the chat and a
     * picture is drawn from disk instead of fetched again. It lives between one and two days.
     */
    public const URL_TTL_DAYS = 2;

    /** The directory each kind of owner's files live under. */
    private const DIRECTORIES = [
        Task::class => 'tasks',
        Project::class => 'projects',
        Client::class => 'clients',
        // A message attachment. Same writer, same rules, same signed expiring URL — the only
        // thing slice 4's discussion added to this class is this line and an entry in
        // File::OWNERS, which is what "do not write a second path to disk" looks like when the
        // first one was built properly.
        Message::class => 'messages',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityLogger $activity,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The limits, as the Form Requests need them
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * @return list<string>
     */
    public static function mimeTypes(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::TYPES))));
    }

    /**
     * The extensions a RECORDING may have. Separate from `extensions()` on purpose — see
     * VOICE_TYPES for why the two lists are not one.
     *
     * @return list<string>
     */
    public static function voiceExtensions(): array
    {
        return array_keys(self::VOICE_TYPES);
    }

    /**
     * The allow-list a given kind of attachment is checked against.
     *
     * One line, and the only place the two lists are chosen between, so "which list applies"
     * cannot come to be answered differently in the validator and at the write.
     *
     * @return array<string, list<string>>
     */
    private static function acceptedTypes(?AttachmentKind $kind, bool $forMessage = false): array
    {
        if ($kind === AttachmentKind::Voice) {
            return self::VOICE_TYPES;
        }

        return $forMessage ? self::TYPES + self::MESSAGE_EXTRA_TYPES : self::TYPES;
    }

    /** The size limit in kilobytes, which is the unit Laravel's `max:` rule counts in. */
    public static function maxKilobytes(): int
    {
        return intdiv(self::MAX_BYTES, 1024);
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    /**
     * Store an upload against a record.
     *
     * `$kind` is passed through to `assertAcceptable()` and used for nothing else: it decides
     * which allow-list this upload is measured against, not where the bytes go or what the row
     * says. A voice note is an ordinary file row on an ordinary message — the pivot is what
     * remembers it was recorded, and that is MessageService's business, not this class's.
     *
     * `$internal` files the upload under a project's INTERNAL NOTES instead of its Files tab:
     * only a project may take one, and the row is then visible only to whoever may see the
     * internal notes (FilePolicy::view) and is never listed by `for()` — see `internalFor()`.
     *
     * @throws AuthorizationException
     * @throws FileStateException
     */
    public function store(User $actor, Model $owner, UploadedFile $upload, ?AttachmentKind $kind = null, bool $internal = false): File
    {
        $this->guardOwner($owner);

        if ($internal && ! $owner instanceof Project) {
            throw FileStateException::internalNeedsProject($owner::class);
        }

        $this->guardMayAttach($actor, $owner);
        self::assertAcceptable($upload, $kind, forMessage: $owner instanceof Message);

        return DB::transaction(function () use ($actor, $owner, $upload, $internal): File {
            $file = $this->write($owner, $upload, $actor, null, 1, $internal);

            $this->activity->record($owner, 'File attached: '.$file->name, $actor);

            return $file;
        });
    }

    /**
     * Replace a file with a new version.
     *
     * The old row is NOT touched beyond a `superseded_at` stamp: it keeps its bytes, its path,
     * its size and the name of whoever uploaded it, and it is still downloadable from the
     * history panel. A version chain is the point of the feature — an upload that overwrote
     * its predecessor would be a worse `store()`, not a version.
     *
     * Only the current version can be replaced. Branching a chain from an old version would
     * give it two heads, which `files_one_live_version` refuses anyway; this says so with a
     * sentence instead of a constraint violation.
     *
     * @throws AuthorizationException
     * @throws FileStateException
     */
    public function replace(User $actor, File $file, UploadedFile $upload): File
    {
        $owner = $file->owner();

        if ($owner === null) {
            throw FileStateException::unknownOwner(File::class);
        }

        // `replace` rather than `update` on the owner: replacing is its own ability on
        // FilePolicy and this is the write it governs, so asking the gate for it here is what
        // makes the policy the rule instead of a hint the resource reports. It is the same
        // answer as guardMayAttach() for a task, a project or a client — view plus update on
        // the owner — and a different one for a message attachment, which nobody may replace.
        if (! Gate::forUser($actor)->allows('replace', $file)) {
            throw new AuthorizationException('You are not allowed to replace this file.');
        }

        if (! $file->isCurrent()) {
            throw FileStateException::notTheCurrentVersion();
        }

        self::assertAcceptable($upload);

        return DB::transaction(function () use ($actor, $owner, $file, $upload): File {
            // Supersede BEFORE inserting: the partial unique index allows exactly one live row
            // per chain and is not deferrable, so the old head steps down before the new one
            // arrives — the same ordering task_assignees uses for its one primary.
            $file->forceFill(['superseded_at' => now()])->save();

            $new = $this->write(
                $owner,
                $upload,
                $actor,
                $file->chainId(),
                (int) $file->version + 1,
                // A new version stays where the old one was filed: internal notes or Files tab.
                (bool) $file->internal,
            );

            $this->activity->record($owner, sprintf(
                'File replaced: %s (version %d, was %s)',
                $new->name,
                $new->version,
                $file->name,
            ), $actor);

            return $new;
        });
    }

    /**
     * Remove one version of a file.
     *
     * The ROW is soft-deleted, because the audit entry written a line earlier has to keep
     * pointing at something. The BYTES go: a file is the expensive thing, and a delete that
     * frees no storage is a hidden flag, not a delete.
     *
     * Deleting the current version of a chain promotes the newest surviving version back to
     * current, so history is never left headless and the Files tab keeps showing the file at
     * the last version somebody kept. Deleting the only version removes the file entirely.
     *
     * @throws AuthorizationException
     */
    public function delete(User $actor, File $file): void
    {
        if (! Gate::forUser($actor)->allows('delete', $file)) {
            throw new AuthorizationException('You are not allowed to delete this file.');
        }

        $owner = $file->owner();

        DB::transaction(function () use ($actor, $file, $owner): void {
            // Audit first, while the row is still there to be described. audit_logs is
            // append-only, so this cannot be tidied up afterwards.
            $this->audit->record(AuditEvent::FileDeleted, $file, $this->snapshot($file), null, $actor);

            if ($owner !== null) {
                $this->activity->record($owner, sprintf(
                    'File deleted: %s (version %d)',
                    $file->name,
                    $file->version,
                ), $actor);
            }

            $wasCurrent = $file->isCurrent();
            $chainId = $file->chainId();

            $file->delete();

            if ($wasCurrent && $chainId !== null) {
                $this->promoteNewestSurvivor($chainId);
            }

            $this->disk($file)->delete($file->path);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    /**
     * The current version of everything this record owns, newest first.
     *
     * Not scoped by the requester: the CALLER has already established that the requester may
     * see the owning record, which is the whole of the visibility rule for a file — a file is
     * as visible as the thing it hangs off, and no more. See FilePolicy.
     *
     * @return Collection<int, File>
     */
    public function for(Model $owner): Collection
    {
        // Never the internal-notes attachments: they are not part of the record's Files tab
        // and are visible to fewer people than the record is. See internalFor().
        return File::query()
            ->ownedBy($owner)
            ->where('internal', false)
            ->current()
            ->with('uploader')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The current version of everything attached to a project's INTERNAL NOTES, newest first.
     *
     * As with for(), the caller has established the requester may see them — here that is
     * `viewCommercial` on the project, not merely `view`.
     *
     * @return Collection<int, File>
     */
    public function internalFor(Project $project): Collection
    {
        return File::query()
            ->ownedBy($project)
            ->where('internal', true)
            ->current()
            ->with('uploader')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One file's version history, oldest first, including the current version.
     *
     * @return Collection<int, File>
     */
    public function history(File $file): Collection
    {
        $chainId = $file->chainId();

        if ($chainId === null) {
            return new Collection;
        }

        return File::query()
            ->where(fn ($query) => $query->whereKey($chainId)->orWhere('version_of', $chainId))
            ->with('uploader')
            ->orderBy('version')
            ->get();
    }

    /**
     * A signed, expiring URL for this file — into this application, never into the bucket.
     *
     * The signature binds the file id and the expiry, so the link cannot be edited into a
     * different file or a longer life. It binds nothing about the viewer on purpose: a URL that
     * carried an identity would be a second authentication mechanism to keep correct. The
     * download route is behind `auth` and FilePolicy, which is the first one.
     */
    public function url(File $file, ?Carbon $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            'files.download',
            $expiresAt ?? $this->expiry(),
            ['file' => $file->getKey()],
        );
    }

    /** When a URL minted now stops working. */
    public function expiry(): Carbon
    {
        return now()->startOfDay()->addDays(self::URL_TTL_DAYS);
    }

    /**
     * Stream the bytes back.
     *
     * Two things are decided here rather than by the browser:
     *
     *   - the DISPOSITION. Only the handful of types in File::INLINE_TYPES are rendered in
     *     place; everything else is handed over as a download whatever it claims to be, so an
     *     uploaded document can never execute in this origin.
     *   - the CONTENT TYPE, from the row rather than from the request. With `nosniff` beside it
     *     the browser is told not to go looking for a more interesting interpretation.
     *
     * `Storage::response()` streams through the disk, so this is identical on S3.
     *
     * @throws FileStateException
     */
    public function download(File $file): StreamedResponse
    {
        $disk = $this->disk($file);

        if (! $disk->exists($file->path)) {
            throw FileStateException::upload();
        }

        $inline = $file->isInlineRenderable();

        return $disk->response($file->path, $file->name, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->name,
                // The fallback is what a browser that cannot read the UTF-8 form uses, so it
                // has to be plain ASCII with no separators in it.
                Str::of($file->name)->ascii()->replace(['"', '\\', '/'], '-')->value() ?: 'download',
            ),
            // Private content: no shared cache may keep it. A picture or PDF shown inline is
            // kept by the viewer's own browser for a day (polish 030), so a chat full of images
            // re-opens instantly instead of fetching every one again; a download is not kept.
            'Cache-Control' => $inline ? 'private, max-age=86400' : 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            // An inline PDF or image is rendered in this origin. The sandbox and the empty
            // default source are what stop anything in it reaching the session that opened it.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ], $inline ? 'inline' : 'attachment');
    }

    /*
    |--------------------------------------------------------------------------
    | The rules behind the writes
    |--------------------------------------------------------------------------
    */

    /**
     * Is this something the application will store at all?
     *
     * Public because it is the rule, and the Form Requests state the same rule against the
     * field so the refusal has somewhere to render. This is the copy a job or a console command
     * hits, and the copy that would still refuse if somebody added a second upload endpoint
     * without a Form Request.
     *
     * `$kind` picks the allow-list, and **nothing else**: the size rule, the empty rule and the
     * extension-versus-contents rule are identical for a recording and for a spreadsheet. Left
     * null — which is every caller that existed before Phase 6 — it is TYPES, unchanged.
     *
     * `$forMessage` is true only on the message upload path: it lifts the MAX_BYTES check (see
     * MESSAGE_MAX_BYTES) and widens the allow-list by MESSAGE_EXTRA_TYPES.
     *
     * @throws FileStateException
     */
    public static function assertAcceptable(UploadedFile $upload, ?AttachmentKind $kind = null, bool $forMessage = false): void
    {
        if (! $upload->isValid()) {
            throw FileStateException::upload();
        }

        $size = (int) $upload->getSize();

        if ($size <= 0) {
            throw FileStateException::empty();
        }

        if (! $forMessage && $size > self::MAX_BYTES) {
            throw FileStateException::tooLarge($size, self::MAX_BYTES);
        }

        $types = self::acceptedTypes($kind, $forMessage);
        $extension = strtolower($upload->getClientOriginalExtension());

        if (! array_key_exists($extension, $types)) {
            throw FileStateException::typeNotAllowed($extension, array_keys($types));
        }

        $mime = strtolower((string) $upload->getMimeType());

        if (! in_array($mime, $types[$extension], true)) {
            throw FileStateException::mimeMismatch($extension, $mime === '' ? 'unreadable' : $mime);
        }

        if ($extension === 'svg') {
            self::assertSafeSvg($upload, array_keys($types));
        }
    }

    /**
     * Refuse an SVG that can run anything. A malicious SVG is the risk, so it is refused rather
     * than sanitized: script elements, inline event handlers, `javascript:` URLs and
     * `<foreignObject>` (which can embed HTML) anywhere in the first 1 MB, case-insensitive.
     *
     * @param  list<string>  $allowed
     *
     * @throws FileStateException
     */
    private static function assertSafeSvg(UploadedFile $upload, array $allowed): void
    {
        $head = @file_get_contents($upload->getRealPath(), false, null, 0, self::SVG_SCAN_BYTES);

        if ($head === false) {
            throw FileStateException::upload();
        }

        if (preg_match('/<script|[\s\/"\']on[a-z]+\s*=|javascript:|<foreignObject/i', $head) === 1) {
            throw FileStateException::typeNotAllowed('svg', array_values(array_diff($allowed, ['svg'])));
        }
    }

    /**
     * Write the bytes and the row. The one place either happens.
     */
    private function write(
        Model $owner,
        UploadedFile $upload,
        User $actor,
        ?int $versionOf,
        int $version,
        bool $internal = false,
    ): File {
        $disk = config('filesystems.default');
        $extension = strtolower($upload->getClientOriginalExtension());

        // The stored name is a ULID, never the uploader's. Two people attaching `report.pdf` to
        // the same task must not collide, and a path assembled from user input is a path
        // traversal waiting for somebody to try it. The original name is a column.
        $path = sprintf(
            'files/%s/%s/%s.%s',
            self::DIRECTORIES[$owner::class],
            $owner->getKey(),
            (string) Str::ulid(),
            $extension,
        );

        Storage::disk($disk)->putFileAs(dirname($path), $upload, basename($path));

        return File::create([
            File::OWNERS[$owner::class] => $owner->getKey(),
            'disk' => $disk,
            'path' => $path,
            // Trimmed of any directory the client put in front of it — some browsers send a
            // whole relative path when a folder is dropped on the input.
            'name' => $this->safeName($upload->getClientOriginalName(), $extension),
            'extension' => $extension,
            'mime_type' => strtolower((string) $upload->getMimeType()),
            'size' => (int) $upload->getSize(),
            'checksum' => $this->checksum($upload),
            'uploaded_by' => $actor->getKey(),
            'version_of' => $versionOf,
            'version' => $version,
            'internal' => $internal,
        ]);
    }

    /**
     * After the head of a chain is deleted, the newest surviving version becomes current again.
     *
     * Without this, deleting the current version would leave a chain of live rows with no live
     * head: the file would vanish from its Files tab while its history sat there intact. The
     * partial unique index permits exactly one, and the row being promoted is the newest one
     * left, which is the last version anybody chose to keep.
     */
    private function promoteNewestSurvivor(int $chainId): void
    {
        $survivor = File::query()
            ->where(fn ($query) => $query->whereKey($chainId)->orWhere('version_of', $chainId))
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();

        $survivor?->forceFill(['superseded_at' => null])->save();
    }

    /**
     * May this user attach a file to this record?
     *
     * One ability for all three owners: `update`. A file on a record is a change to that
     * record, so whoever may change it may attach to it — and, just as importantly, whoever may
     * not, may not. That puts the whole rule in the owners' own policies rather than in a
     * second set of file permissions that could drift from them: an employee attaches to a task
     * they are assigned to (TaskPolicy), an Admin to a project or a client (ProjectPolicy,
     * ClientPolicy), an archived record takes nothing at all, and the Accountant — who holds
     * none of those — is refused everywhere without being named once.
     *
     * @throws AuthorizationException
     */
    private function guardMayAttach(User $actor, Model $owner): void
    {
        if (! Gate::forUser($actor)->allows('update', $owner)) {
            throw new AuthorizationException('You are not allowed to attach files to this record.');
        }
    }

    /**
     * @throws FileStateException
     */
    private function guardOwner(Model $owner): void
    {
        if (! array_key_exists($owner::class, File::OWNERS)) {
            throw FileStateException::unknownOwner($owner::class);
        }
    }

    private function disk(File $file): Filesystem
    {
        return Storage::disk($file->disk);
    }

    /**
     * sha-256 of what actually arrived, so a download can be shown to be the file that was
     * uploaded. Null rather than an exception if the temporary file cannot be read: a checksum
     * is evidence, not a precondition, and losing it must not lose the upload.
     */
    private function checksum(UploadedFile $upload): ?string
    {
        $path = $upload->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $hash = @hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    /**
     * The name the file is shown and downloaded under: the uploader's, with anything that could
     * be read as a path taken out of it. It is never used to build a storage path — that is a
     * ULID — but it does end up in a `Content-Disposition` header and in a list on a page.
     */
    private function safeName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file.'.$extension;
        }

        return Str::limit($name, 255, '');
    }

    /**
     * The file as the audit trail should remember it once it is gone.
     *
     * @return array<string, mixed>
     */
    private function snapshot(File $file): array
    {
        return [
            'name' => $file->name,
            'path' => $file->path,
            'disk' => $file->disk,
            'mime_type' => $file->mime_type,
            'size' => (int) $file->size,
            'checksum' => $file->checksum,
            'version' => (int) $file->version,
            'version_of' => $file->version_of,
            'uploaded_by' => $file->uploaded_by,
            'owner' => $file->ownerColumn(),
            'owner_id' => $file->ownerColumn() === null ? null : $file->getAttribute($file->ownerColumn()),
        ];
    }
}
