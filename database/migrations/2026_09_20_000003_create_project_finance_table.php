<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * project_finance is a separate table on purpose (Part B §3 rule 7): hiding money from a
     * requester who cannot see it is a join to omit, not a per-field filter on projects.
     */
    public function up(): void
    {
        Schema::create('project_finance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('recurring_amount', 12, 2)->nullable();
            $table->string('billing_frequency')->nullable();
            $table->decimal('contract_value', 12, 2)->nullable();
            $table->text('contract_terms')->nullable();
            $table->decimal('profitability_snapshot', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_finance');
    }
};
