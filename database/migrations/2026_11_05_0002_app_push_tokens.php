<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The goodERP Android app's own push tokens (Firebase Cloud Messaging), one row per installed
 * app (2026-10-05).
 *
 * Web Push reaches the app only while it runs through Chrome. When Chrome can't open it the app
 * uses its own WebView, and a WebView has no Web Push at all — so nothing reached the phone's
 * notification shade. The app now registers itself with Firebase and hands its token to the
 * server through a cookie only it can set (`RememberAppPushToken`).
 *
 * Unique on `md5(token)`, like `push_subscriptions.endpoint`: a token is long, and the btree
 * row limit is not something a third-party format should be able to hit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->timestamps();
            $table->index('user_id');
        });

        DB::statement('create unique index app_push_tokens_token_unique on app_push_tokens (md5(token))');
    }

    public function down(): void
    {
        Schema::dropIfExists('app_push_tokens');
    }
};
