<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polish 030: a short name for a client ("Buffalo Modular" for Jeremy Bates), shown in the
     * Projects page's client list instead of the full name when it is set.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('nickname', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
