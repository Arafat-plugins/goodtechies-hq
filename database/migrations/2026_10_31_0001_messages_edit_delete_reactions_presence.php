<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages may be edited and deleted by their author, carry emoji reactions, and every user has
 * a last-seen time (decision 12-79, superseding the "no edit, no delete" note on the messages
 * table).
 *
 * - `messages.edited_at` / `deleted_at` / `deleted_by`: a deleted message keeps its row (so the
 *   thread keeps its shape and replies keep their context) but loses its body; the original text
 *   lives only in the `message.deleted` audit row.
 * - `message_reactions`: one row per (message, user, emoji).
 * - `users.last_seen_at`: written by the presence heartbeat.
 * - Permission `messages.manage`, granted to ADMIN.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestampTz('edited_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('deleted_by');
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->index('message_id');
            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('last_seen_at')->nullable();
        });

        DB::statement("insert into permissions (key, created_at, updated_at) values ('messages.manage', now(), now()) on conflict (key) do nothing");

        DB::statement(<<<'SQL'
            insert into role_permissions (role_id, permission_id)
            select r.id, p.id
            from roles r, permissions p
            where r.name = 'ADMIN' and p.key = 'messages.manage'
            on conflict do nothing
        SQL);
    }

    public function down(): void
    {
        DB::statement("delete from role_permissions where permission_id in (select id from permissions where key = 'messages.manage')");
        DB::statement("delete from permissions where key = 'messages.manage'");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });

        Schema::dropIfExists('message_reactions');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['edited_at', 'deleted_at']);
        });
    }
};
