<?php

use App\Support\AttachmentKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a file rides on a message.
     *
     * ## Why this table exists next to `files.message_id`
     *
     * `files.message_id` is OWNERSHIP: which record the bytes belong to, which is what gives
     * the row a foreign key, a cascade, and a visibility rule (a file is exactly as visible as
     * the thing it hangs off). It is the arc `files_one_owner` polices, and it is what lets
     * FileService store a message attachment without a second code path.
     *
     * This table is PRESENTATION: how the message shows the file. `kind` decides whether the
     * bubble renders an image inline or offers a download, and `duration_seconds` is the length
     * of a Phase 6 voice note — neither is a fact about the bytes, and neither belongs in a
     * table that is the index of everything on disk.
     *
     * ## The two `message_id`s cannot disagree
     *
     * Splitting them that way does put the message's id in two places, which is normally how
     * two rows end up pointing at different messages. Here it cannot happen: the primary key
     * `(message_id, file_id)` is also a composite FOREIGN key into `files (message_id, id)`,
     * whose unique index the previous migration created for exactly this. PostgreSQL therefore
     * refuses an attachment row whose file is not owned by that same message — the duplication
     * is checked by the database rather than by whoever writes the next service method.
     *
     * A separate `message_id → messages` foreign key would be redundant and is deliberately
     * absent: `files.message_id` already has one, and this row cannot exist without that file.
     *
     * ## Phase 6
     *
     * `kind` accepts `voice` from today and `duration_seconds` is already here, so recording a
     * voice note is an INSERT with a third value in a column that already allows it. Phase 2
     * writes `file` and `image` only. The CHECK below says a duration belongs to a voice note
     * and to nothing else, without insisting that every voice note has one — Phase 6 gets to
     * decide whether a duration is mandatory, and a constraint written now would be this
     * phase guessing at that.
     */
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            // No surrogate id: the pair IS the identity, and it is also the composite foreign
            // key below. A file cannot be attached to the same message twice.
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('file_id');

            $table->string('kind', 16);

            // Phase 6's voice notes. Nullable and unwritten here.
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            $table->primary(['message_id', 'file_id']);

            // "Which message is this file on" — the direction the primary key cannot serve.
            $table->index('file_id');
        });

        $kinds = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            AttachmentKind::values(),
        ));

        DB::statement("ALTER TABLE message_attachments ADD CONSTRAINT message_attachments_kind_is_known CHECK (kind IN ({$kinds}))");

        // A duration is a property of a recording. Nothing else may carry one.
        DB::statement(<<<'SQL'
            ALTER TABLE message_attachments ADD CONSTRAINT message_attachments_duration_is_voice_only CHECK (
                duration_seconds IS NULL OR kind = 'voice'
            )
        SQL);

        // The composite foreign key that makes the two message ids the same message id. See
        // the docblock; `files_message_identity` is its target.
        DB::statement(<<<'SQL'
            ALTER TABLE message_attachments
            ADD CONSTRAINT message_attachments_file_belongs_to_message
            FOREIGN KEY (message_id, file_id) REFERENCES files (message_id, id)
            ON DELETE CASCADE
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
