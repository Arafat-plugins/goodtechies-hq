<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('object_type');
            $table->unsignedBigInteger('object_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['object_type', 'object_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
