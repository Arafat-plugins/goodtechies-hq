<?php

namespace App\Support;

use App\Models\AttendanceRecord;
use Illuminate\Support\Carbon;

/**
 * One day, answered.
 *
 * `AttendanceService::dayFor()` is the only thing that builds one, and it is the single
 * statement of "what was this day" (decision 2-37): the roster row, the month grid cell, the
 * clock-in widget's *today* and the self page's summary are all this object, so none of them
 * can develop its own opinion about whether Friday is an Off Day.
 *
 * It is a value object rather than a `JsonResource` because most days have no model behind
 * them at all: an Off Day and a Remote day are derived from the schedule and the tracking mode
 * and have no `attendance_records` row to serialise. A resource over a nullable model would
 * have been a resource that spends its life explaining that its subject is absent.
 *
 * There is no score here, no rating, and nothing that compares one person's day with another's
 * (Part H §1). `worked_minutes` is a duration, `tracked_minutes` is a duration, and the word
 * the UI prints beside them is a status from Part D §8.
 */
final readonly class AttendanceDay
{
    public function __construct(
        public Carbon $date,
        public ?AttendanceStatus $status,
        public ?AttendanceRecord $record = null,
        /**
         * The company holiday this date falls on, or null on an ordinary day.
         *
         * It is carried **independently of `status`**, because the two answer different
         * questions: `status` says whether the office was open, and this says why it was not.
         * A holiday falling on somebody's own off day still reads *Off day* (decision 4-9's
         * ordering, see `dayFor()`) and should still be able to say "Eid ul-Fitr" beside it;
         * a day somebody clocked in on reads *Present* and should still name the holiday they
         * worked through.
         *
         * Derived on every read from the `holidays` table and stored on no row — adding or
         * removing a holiday changes what a past day says, which is the whole point.
         */
        public ?string $holidayName = null,
        /** Minutes between clock-in and clock-out; null while the day is open or never clocked. */
        public ?int $workedMinutes = null,
        /**
         * Minutes the remote timer recorded. **Null means "not known here"**, not zero: until
         * the timer slice lands `AttendanceService::trackedMinutes()` returns null for
         * everybody, and a roster that printed "0m tracked" for Tapu would be stating a fact
         * nothing in this half of the phase measured. See that method for the seam.
         */
        public ?int $trackedMinutes = null,
        /** Is this day the one the schedule says the employee works? */
        public bool $scheduled = false,
        public bool $isToday = false,
        public bool $isFuture = false,
    ) {}

    public function hasRecord(): bool
    {
        return $this->record !== null;
    }

    /**
     * The payload every screen reads. One shape for the roster, the month grid and the widget,
     * so a status cannot be spelled one way in a cell and another way in a row.
     *
     * `status` is null on a day that has simply not happened — a working day with no record
     * yet, and today before anybody clocked in. Null is printed as "No record", never as
     * Absent: Absent is a thing the 23:55 sweep decides, and guessing it at 10 a.m. would put a
     * word on somebody's pay record that no job has written.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'weekday' => Weekday::of($this->date)->label(),
            'day_of_month' => $this->date->day,

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Null on an ordinary day. Present even when the status is not Holiday — see the
            // property. Never the only carrier of anything: the screens print it beside the
            // status word, not instead of it (DESIGN.md §5.6).
            'holiday_name' => $this->holidayName,
            // The StatusBadge key. Resolved here, from AttendanceStatus::tone(), because a
            // second status-to-colour map in a Vue computed drifts (decision 2-37).
            'tone' => $this->status?->tone(),

            'clock_in' => $this->record?->clock_in?->format('H:i'),
            'clock_out' => $this->record?->clock_out?->format('H:i'),
            // The full moment, not just the wall clock above, so a screen can count up from
            // it while the day is still open. `worked_minutes` is null until a clock-out, by
            // definition — it is a difference, and one end of it has not happened yet — and
            // without this the widget could say when somebody arrived but not how long they
            // have been here, which is the one thing they are looking at it to find out.
            'clock_in_at' => $this->record?->clock_in?->toIso8601String(),
            'worked_minutes' => $this->workedMinutes,
            'tracked_minutes' => $this->trackedMinutes,

            'note' => $this->record?->note,
            // Present only when somebody corrected the row by hand, which is what a reader of
            // the grid wants flagged; the full before-and-after is in the audit log.
            'edited_by' => $this->record?->editor?->name,

            'scheduled' => $this->scheduled,
            'is_today' => $this->isToday,
            'is_future' => $this->isFuture,
        ];
    }
}
