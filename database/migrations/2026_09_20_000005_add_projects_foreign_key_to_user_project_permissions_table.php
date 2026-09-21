<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 0 deliberately deferred this foreign key until the projects table existed (decisions 0-4).
     */
    public function up(): void
    {
        Schema::table('user_project_permissions', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_project_permissions', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });
    }
};
