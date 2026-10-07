<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polish 030: the agency's one connected Google account, which creates the Meet links.
     *
     * An Admin presses "Connect Google" in Admin → Settings and signs in; this row keeps what
     * Google hands back. The tokens are ENCRYPTED with the app key (the model casts them) — the
     * refresh token is the key to that Google calendar, so it is never stored readable.
     */
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->timestampTz('access_token_expires_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
