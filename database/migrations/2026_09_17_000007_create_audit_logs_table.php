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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event')->index();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        $this->revokeMutationsFromRuntimeRole();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }

    /**
     * Make audit_logs append-only for the runtime role (hq_app): no UPDATE, DELETE or TRUNCATE.
     * Skipped when the role does not exist, so a superuser-only dev setup still migrates.
     */
    private function revokeMutationsFromRuntimeRole(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('database.connections.pgsql.username');

        $exists = $connection->selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [$role]);

        if ($exists === null) {
            return;
        }

        $quoted = '"'.str_replace('"', '""', $role).'"';

        $connection->statement("REVOKE UPDATE, DELETE, TRUNCATE ON TABLE audit_logs FROM {$quoted}");
    }
};
