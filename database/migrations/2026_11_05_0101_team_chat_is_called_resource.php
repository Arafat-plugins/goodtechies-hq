<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client doc 2026-10-05 item 7: the company-wide chat is called "Resource", not "Team".
 *
 * The channel is a singleton row (`ConversationService::team()`), and its name is that row's
 * `title`, so renaming the live one is one UPDATE. Only a title that is still the default is
 * changed. `down()` puts the old name back.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        DB::table('conversations')->where('type', 'team')->where('title', 'Team')->update(['title' => 'Resource']);
    }

    public function down(): void
    {
        DB::table('conversations')->where('type', 'team')->where('title', 'Resource')->update(['title' => 'Team']);
    }
};
