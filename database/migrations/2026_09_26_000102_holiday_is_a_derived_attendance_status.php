<?php

use App\Support\AttendanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Take `holiday` out of `attendance_records.status`. Phase 5.
     *
     * Phase 4 generated `attendance_records_status_is_storable` from
     * `AttendanceStatus::storable()`, and that list carried `holiday` on the expectation that
     * Phase 5 would *write* Holiday rows — "a constraint that has to be widened on the morning
     * leave approval first runs is a constraint that will look like a broken feature", as the
     * enum put it. Phase 5 does not write them.
     *
     * **A holiday is derived at read time, exactly as Off Day and Remote are (decision 4-9).**
     * The reasoning is that decision's, unchanged: a stored Off Day would outlive the schedule
     * it was copied from, and a stored Holiday would outlive the `holidays` row it was copied
     * from. An Admin who adds Victory Day in November must see every 16 December in the grid
     * change, including the ones already past; an Admin who deletes one must see them change
     * back. A column stamped by a job at 23:55 cannot do that, and the first time somebody
     * corrected a date in the holidays table the two records would start disagreeing about a
     * day Phase 9 pays from.
     *
     * So `holiday` leaves `storable()` and the CHECK follows it, which is what makes the
     * derivation structural rather than a convention: no hand — service, command, seeder or
     * console one-liner — can write a Holiday row, because the database refuses one. That is
     * the same argument the Phase 4 migration makes for Off Day and Remote, applied to the
     * status that turned out to belong with them.
     *
     * `leave` stays storable and is untouched: Part D §9 has leave approval auto-mark
     * `attendance_records` for each working date in the window, so a Leave row records a
     * decision somebody made about a person, not a fact derived from a shared calendar. The
     * two statuses look alike in Part D §8's list and are not alike at all.
     *
     * Rewritten from the enum (the shape `extend_notifications_type_check` established) so
     * nothing states the list twice, and re-running on a current database is a no-op with the
     * same text.
     */
    public function up(): void
    {
        $this->applyCheck(AttendanceStatus::storableValues());
    }

    /**
     * Back to Phase 4's six, written out rather than derived: a `down()` restores what was
     * there, and deriving it from the enum would only produce today's list again.
     */
    public function down(): void
    {
        $this->applyCheck(['present', 'late', 'half_day', 'absent', 'leave', 'holiday']);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function applyCheck(array $statuses): void
    {
        $list = implode(', ', array_map(fn (string $value): string => "'".$value."'", $statuses));

        DB::statement('ALTER TABLE attendance_records DROP CONSTRAINT IF EXISTS attendance_records_status_is_storable');
        DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_status_is_storable CHECK (status IN ({$list}))");
    }
};
