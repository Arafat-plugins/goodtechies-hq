<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fourth owner of a file: a message.
     *
     * `files` was created an hour ago with an exclusive arc — three nullable foreign keys and a
     * CHECK that exactly one is set — and its migration says, in as many words, that "a fourth
     * owner (slice 5's messages) is one nullable column and one wider CHECK, in a migration
     * that touches no data". This is that migration, and it is exactly that: a column, a wider
     * CHECK, an index, and nothing written.
     *
     * The alternative was a second path to disk for message attachments. There is one
     * FileService, it is the only thing that writes bytes, and it works against `File::OWNERS`
     * — so adding the owner here and the entry there is the whole of "message attachments use
     * FileService". The upload rules, the size limit, the extension/MIME pairing, the signed
     * expiring URL and the `nosniff`/sandbox download headers all apply to a message attachment
     * without one line of them being restated.
     *
     * ## `files_message_identity`
     *
     * A unique index on `(message_id, id)`, which exists to be the target of a composite
     * foreign key from `message_attachments`. It makes it impossible for an attachment row to
     * claim a file that belongs to a different message — see that migration. `id` is already
     * unique, so this index adds no new rule of its own; it only gives PostgreSQL something to
     * point the foreign key at.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // cascadeOnDelete for the same reason the other three have it: if the owning row is
            // really gone, the file has nothing to belong to. Messages are never deleted in
            // this application, so it is a statement about ownership rather than an expected
            // path.
            $table->foreignId('message_id')->nullable()->after('client_id')
                ->constrained('messages')->cascadeOnDelete();

            // One message's attachments, in order — the same shape as the other three owners'.
            $table->index(['message_id', 'id']);
        });

        // The arc, one term wider. Written as a sum in the original migration precisely so that
        // this is an added term and not a rewritten boolean.
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_one_owner');
        DB::statement(<<<'SQL'
            ALTER TABLE files ADD CONSTRAINT files_one_owner CHECK (
                (task_id IS NOT NULL)::int
                + (project_id IS NOT NULL)::int
                + (client_id IS NOT NULL)::int
                + (message_id IS NOT NULL)::int = 1
            )
        SQL);

        // The foreign-key target for message_attachments. See the docblock.
        DB::statement('CREATE UNIQUE INDEX files_message_identity ON files (message_id, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS files_message_identity');
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_one_owner');
        DB::statement(<<<'SQL'
            ALTER TABLE files ADD CONSTRAINT files_one_owner CHECK (
                (task_id IS NOT NULL)::int
                + (project_id IS NOT NULL)::int
                + (client_id IS NOT NULL)::int = 1
            )
        SQL);

        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['message_id', 'id']);
            $table->dropConstrainedForeignId('message_id');
        });
    }
};
