<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polish 029: an employee asks for a day to be corrected (Late by mistake, say), and an Admin
     * approves or declines it.
     *
     * One row per request. The day itself is only changed when it is approved, through
     * `AttendanceService::edit()`, so the attendance row and its audit trail stay the one record
     * of what the day is. A day has at most one PENDING request (the partial unique index); once
     * one is decided, another can be sent.
     */
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->string('current_status', 20);
            $table->text('reason');
            $table->string('status', 10)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
            $table->index('status');
        });

        DB::statement(<<<'SQL'
            alter table attendance_corrections
            add constraint attendance_corrections_status_check
            check (status in ('pending','approved','rejected'))
        SQL);

        DB::statement(<<<'SQL'
            create unique index attendance_corrections_one_pending_per_day
            on attendance_corrections (employee_id, date)
            where status = 'pending'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
