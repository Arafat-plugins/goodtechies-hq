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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            // Null client_id = an internal project (no client to bill).
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('project_type');
            $table->string('billing_type');
            $table->date('start_date')->nullable();
            $table->date('deadline')->nullable();
            $table->string('status')->default('active');
            $table->string('priority')->default('medium');
            $table->foreignId('pm_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('internal_notes')->nullable();
            $table->text('employee_notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index(['client_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
