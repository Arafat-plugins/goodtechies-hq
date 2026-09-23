<?php

namespace App\Services;

use App\Exceptions\FileStateException;
use App\Models\Client;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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
     * 25 MB. The number is set by the transport, not by taste: `deploy/nginx.conf` allows a
     * 50 MB body and `deploy/install.sh` sets PHP's `upload_max_filesize` and `post_max_size`
     * to match. A limit at the transport ceiling means the file that is one byte over comes
     * back as an nginx 413 with no message anybody can read; a limit at half of it means
     * multipart overhead, a long filename and a second field still fit, and the refusal is a
     * validation error against the field. It is comfortably above the screenshots, PDFs and
     * deliverable zips an agency actually attaches.
     */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /**
     * What may be uploaded: extension → the content types that extension may legitimately be.
     *
     * Both halves are checked. The extension decides the name the file is stored and served
     * under; the detected content type decides whether it is what it says it is. A `.php`
     * renamed to `.pdf` fails the second check, and a genuine PDF named `.php` fails the first.
     *
     * Deliberately absent, and each for its own reason:
     *   - `svg`: an XML document that can carry script. It is an image everywhere except in the
     *     one place it matters, and we serve files from our own origin.
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
     * How long a download link lives.
     *
     * Fifteen minutes. A link is minted when a page is rendered and used when somebody clicks
     * it, which is seconds later; the rest of the window is for a slow reader and a slow
     * connection. Long enough that nobody meets an expired link in normal use, short enough
     * that one pasted into a chat log is dead before anybody scrolls back to it. The policy,
     * not this number, is what stops the wrong person using it inside the window — see the
     * class docblock.
     */
    public const URL_TTL_MINUTES = 15;

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
     * @throws AuthorizationException
     * @throws FileStateException
     */
    public function store(User $actor, Model $owner, UploadedFile $upload): File
    {
        $this->guardOwner($owner);
        $this->guardMayAttach($actor, $owner);
        self::assertAcceptable($upload);

        return DB::transaction(function () use ($actor, $owner, $upload): File {
            $file = $this->write($owner, $upload, $actor, null, 1);

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
        return File::query()
            ->ownedBy($owner)
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
        return now()->addMinutes(self::URL_TTL_MINUTES);
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
            // The URL is expiring and the content is private. Neither the browser nor anything
            // between here and it should be keeping a copy.
            'Cache-Control' => 'private, no-store, max-age=0',
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
     * @throws FileStateException
     */
    public static function assertAcceptable(UploadedFile $upload): void
    {
        if (! $upload->isValid()) {
            throw FileStateException::upload();
        }

        $size = (int) $upload->getSize();

        if ($size <= 0) {
            throw FileStateException::empty();
        }

        if ($size > self::MAX_BYTES) {
            throw FileStateException::tooLarge($size, self::MAX_BYTES);
        }

        $extension = strtolower($upload->getClientOriginalExtension());

        if (! array_key_exists($extension, self::TYPES)) {
            throw FileStateException::typeNotAllowed($extension, self::extensions());
        }

        $mime = strtolower((string) $upload->getMimeType());

        if (! in_array($mime, self::TYPES[$extension], true)) {
            throw FileStateException::mimeMismatch($extension, $mime === '' ? 'unreadable' : $mime);
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
