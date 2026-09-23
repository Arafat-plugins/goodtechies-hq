<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One stored file: a task attachment, a project file or a client file.
 *
 * Ownership is an exclusive arc — exactly one of `task_id`, `project_id`, `client_id` — and the
 * database enforces it (`files_one_owner`). See the migration for why that shape and not three
 * pivot tables.
 *
 * Nothing on this model touches the disk. FileService is the only thing that writes, reads,
 * signs or removes bytes, so "where the file actually is" has one owner in the codebase.
 *
 * @property-read Model|null $owner
 */
#[Fillable([
    'task_id',
    'project_id',
    'client_id',
    'message_id',
    'disk',
    'path',
    'name',
    'extension',
    'mime_type',
    'size',
    'checksum',
    'uploaded_by',
    'version_of',
    'version',
])]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    // Soft, so an audit row keeps pointing at something. The BYTES are removed — see
    // FileService::delete(); a row here is the record that a file existed, not the file.
    use SoftDeletes;

    /**
     * The owner columns, in the order a Files tab would ever ask about them.
     *
     * One list, read by the relation resolver, the scope and the service, so "which kinds of
     * record can own a file" is stated once. Adding slice 5's messages is one entry here plus
     * one column.
     *
     * @var array<class-string<Model>, string>
     */
    public const OWNERS = [
        Task::class => 'task_id',
        Project::class => 'project_id',
        Client::class => 'client_id',
        // Slice 4's message attachments — the fourth owner the files migration said would be
        // "one nullable column and one wider CHECK". It was.
        Message::class => 'message_id',
    ];

    /**
     * MIME types the detail page may render INLINE rather than hand to the browser as a
     * download — the plan's "inline preview image/PDF".
     *
     * Deliberately a list of exact types and not `image/*`: `image/svg+xml` is an XML document
     * that can carry script, and rendering one inline from our own origin is a stored
     * cross-site-scripting hole. SVG is not an accepted upload type at all (see FileService),
     * and it is absent here as well so that a row created some other way still cannot be framed.
     *
     * @var list<string>
     */
    public const INLINE_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The message this file is attached to, when it is one. A message attachment is visible to
     * whoever may see the message, which is whoever may see the task the conversation is about
     * — the same delegation the other three owners get from FilePolicy.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The first version of this file, or null when this row IS the first version.
     *
     * @return BelongsTo<File, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(File::class, 'version_of');
    }

    /**
     * The record this file hangs off, whichever kind it is.
     *
     * Not a morphTo: the columns are real foreign keys precisely so the database can police
     * them, and this resolves the one that is set.
     */
    public function owner(): ?Model
    {
        foreach (self::OWNERS as $class => $column) {
            if ($this->getAttribute($column) !== null) {
                /** @var Model|null $owner */
                $owner = $this->{$this->ownerRelation($class)};

                return $owner;
            }
        }

        return null;
    }

    /**
     * Which kind of record owns this file, as the column name behind it — `task_id`,
     * `project_id` or `client_id`, or null for a row the CHECK constraint should have refused.
     */
    public function ownerColumn(): ?string
    {
        foreach (self::OWNERS as $column) {
            if ($this->getAttribute($column) !== null) {
                return $column;
            }
        }

        return null;
    }

    /**
     * The chain this file belongs to, identified by its ROOT's id — the same expression
     * `files_one_live_version` is built on.
     *
     * This, and not a relation, is how a chain is read. There was a `versions()` hasMany here
     * and it was a trap: `version_of` holds the ROOT's id on every row, so the relation is
     * empty on every row but the root — and the root is superseded as soon as a replacement
     * exists, which is exactly when `FileService::for()` stops handing it out. So the relation
     * answered `[]` for every row anybody could actually reach, and the resource key and UI
     * branch built on it were dead. A chain is "the root, plus everything pointing at the
     * root", which is a query over two columns and not a property of either end of it:
     * `FileService::history()` states it once, and `promoteNewestSurvivor()` states the same
     * predicate for the same reason.
     */
    public function chainId(): ?int
    {
        return $this->version_of === null
            ? ($this->getKey() === null ? null : (int) $this->getKey())
            : (int) $this->version_of;
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/')
            && in_array($this->mime_type, self::INLINE_TYPES, true);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * May the browser render this inline? Anything else is served as a download, whatever it
     * claims to be — see FileService::download().
     */
    public function isInlineRenderable(): bool
    {
        return in_array($this->mime_type, self::INLINE_TYPES, true);
    }

    /**
     * The current version of everything a record owns. History is asked for per file, never
     * mixed into a Files tab — a tab listing every version of everything is a tab nobody can
     * read.
     *
     * @param  Builder<File>  $query
     * @return Builder<File>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * Everything owned by this record, newest first.
     *
     * @param  Builder<File>  $query
     * @return Builder<File>
     */
    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        $column = self::OWNERS[$owner::class] ?? null;

        // An unknown owner class gets no rows rather than every row. A scope that silently
        // widens when it does not recognise its argument is how a Files tab ends up showing
        // somebody else's files.
        return $column === null
            ? $query->whereRaw('1 = 0')
            : $query->where($column, $owner->getKey());
    }

    /**
     * Human size, for a list that has to fit in a column.
     */
    public function sizeLabel(): string
    {
        $bytes = (int) $this->size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 ? 1 : 0).' '.$units[$unit];
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function ownerRelation(string $class): string
    {
        return match ($class) {
            Task::class => 'task',
            Project::class => 'project',
            Message::class => 'message',
            default => 'client',
        };
    }
}
