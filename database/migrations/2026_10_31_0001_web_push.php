<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push: one device's subscription per `push_subscriptions` row.
 *
 * The endpoint is unique per device; the unique index is on `md5(endpoint)` because endpoints
 * can exceed the btree row-size limit. The two `users` booleans are the person's Messages /
 * Alerts switches on Profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        DB::statement('create unique index push_subscriptions_endpoint_unique on push_subscriptions (md5(endpoint))');

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('push_messages')->default(true);
            $table->boolean('push_alerts')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['push_messages', 'push_alerts']);
        });

        Schema::dropIfExists('push_subscriptions');
    }
};
