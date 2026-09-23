<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files (master prompt Part D, Phase 2 slice 4) — one table for every file the application
     * stores, whoever it hangs off.
     *
     * ## Why one table and not `task_attachments` + `project_files` + `client_files`
     *
     * Three surfaces want files: a task's attachment panel, and the Files tabs on a project and
     * on a client. The plan names them all as "the same FileService", and a file belongs to
     * exactly ONE record — it is never shared between a task and a client. That is a foreign
     * key, not a pivot: three join tables would be three places ownership lives, three query
     * paths, and three chances for the version chain and the owner to disagree about who a
     * replacement belongs to.
     *
     * So ownership is an EXCLUSIVE ARC: three nullable foreign keys with a CHECK that exactly
     * one of them is set. It keeps real referential integrity — a polymorphic
     * `owner_type`/`owner_id` pair cannot be a foreign key in PostgreSQL, and this table is the
     * index of everything on disk, which is precisely the place not to give up on the database
     * knowing whether a row still points at anything. The cost is honest and small: a fourth
     * owner (slice 5's messages) is one nullable column and one wider CHECK, in a migration that
     * touches no data.
     *
     * ## Versions
     *
     * `version_of` is NULL on the first upload and holds the ROOT file's id on every later one,
     * so a chain is one indexed query rather than a walk, and a replacement never rewrites the
     * row it replaces. Superseding is a timestamp on the OLD row, not a delete: replacing a file
     * cannot orphan the one it replaced, because it never touches its bytes or its path.
     *
     * `files_one_live_version` is the database's half of that rule — a partial unique index on
     * `coalesce(version_of, id)` for the rows that are neither superseded nor deleted, so a
     * chain can never end up with two current versions however the rows were written.
     *
     * Nothing here stores a URL. A URL is minted per request, signed and expiring, by
     * FileService; a column would outlive its own signature.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            // ── Ownership: exactly one of the three, enforced by files_one_owner below ──
            //
            // cascadeOnDelete is the truth about the blob, not a policy: if the owning row is
            // really gone the file has nothing to belong to. Tasks are SOFT-deleted, so a
            // deleted task keeps its attachments and the cascade never fires for them.
            $table->foreignId('task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();

            // ── Where the bytes are ──
            //
            // The disk NAME is stored, not a URL and not an absolute path. Decision 2-2 puts
            // files on the local disk behind signed URLs and says the move to S3 is a
            // FILESYSTEM_DISK change; recording which disk a row was written to is what makes
            // that a change and not a migration — rows written before the switch still resolve.
            $table->string('disk', 64);
            $table->string('path', 1024)->unique();

            // ── Metadata ──
            //
            // `name` is what the uploader called it, kept for the download filename. It is
            // never part of `path`: a name is user input and a path is not.
            $table->string('name');
            $table->string('extension', 32);
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size');
            // sha-256 of the uploaded bytes. Not a unique key — two people may legitimately
            // attach the same PDF to two tasks — it is there so a download can be shown to be
            // the file that was uploaded.
            $table->string('checksum', 64)->nullable();

            // Who uploaded it: half of the delete rule ("the uploader or an Admin"), so it is
            // nullOnDelete rather than cascade — losing the user must not lose the file.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // ── Version history ──
            $table->foreignId('version_of')->nullable()->constrained('files')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            // NULL means "this is the current version of its chain".
            $table->timestampTz('superseded_at')->nullable();

            $table->timestamps();
            // Soft, for the same reason a task's delete is soft: the audit row has to keep
            // pointing at something. The bytes do go — see FileService::delete().
            $table->softDeletes();

            // A Files tab: everything of one owner, newest first.
            $table->index(['task_id', 'id']);
            $table->index(['project_id', 'id']);
            $table->index(['client_id', 'id']);

            // One file's history, in order.
            $table->index(['version_of', 'version']);
        });

        // Exactly one owner. Written as a sum rather than three OR'd pairs so that adding the
        // fourth owner is one more term and not a rewritten boolean.
        DB::statement(<<<'SQL'
            ALTER TABLE files ADD CONSTRAINT files_one_owner CHECK (
                (task_id IS NOT NULL)::int
                + (project_id IS NOT NULL)::int
                + (client_id IS NOT NULL)::int = 1
            )
        SQL);

        // One live version per chain. `coalesce(version_of, id)` is the chain's identity: the
        // root's own id, whether you ask the root or one of its versions.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX files_one_live_version
            ON files ((coalesce(version_of, id)))
            WHERE superseded_at IS NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
