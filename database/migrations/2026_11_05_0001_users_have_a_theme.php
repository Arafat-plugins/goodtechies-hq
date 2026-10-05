<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The person's Light / Dark / System choice, kept on their account (2026-10-05).
 *
 * It used to live only in the browser's `localStorage`, and the Android app has two browsers —
 * Chrome (the Trusted Web Activity) and its own WebView backup screen — each with its own
 * storage. The client saw the app open dark, then light, then dark again. On the account, every
 * browser, phone and computer reads the same choice. `null` = never chosen: the device decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 8)->nullable();
        });

        DB::statement("alter table users add constraint users_theme_check check (theme is null or theme in ('light', 'dark', 'system'))");
    }

    public function down(): void
    {
        DB::statement('alter table users drop constraint if exists users_theme_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
