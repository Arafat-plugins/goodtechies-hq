<?php

namespace App\Support;

/**
 * What a day can be (master prompt Part D §8).
 *
 * Eight statuses, and they are not eight of a kind. Three groups, and which group a status is
 * in decides where it may ever come from:
 *
 *   - **Stored on clock-in** — Present, Late. `AttendanceService::clockIn()` writes one of the
 *     two, from the employee's own schedule plus `late_grace_minutes`.
 *   - **Stored by somebody or something else** — Half Day (an Admin, or the `half_day_auto`
 *     rule on clock-out), Absent (`hq:mark-absent` at 23:55), Leave (leave approval, Phase 5).
 *   - **Derived and never stored** — Off Day, Remote, Holiday. Off Day is the schedule's
 *     non-working days; Remote is a `tracking_mode = remote_timer` employee, who never clocks
 *     in at all; Holiday is a row in the `holidays` table (Phase 5).
 *
 * `storable()` is that split written down, and the `attendance_records` CHECK constraint is
 * built from it — so a derived status cannot be persisted even by a bug. That matters more
 * than it looks: a stored Off Day would survive an edit to the schedule it came from and start
 * disagreeing with it, and a stored Remote would be a second answer to a question
 * `tracking_mode` already answers. Deriving them means they cannot go stale (decision 3-1: the
 * database enforces what an `if` cannot).
 *
 * **Holiday moved into the third group in Phase 5**, and it is the same argument a third time
 * (decision 4-9). Phase 4 left it storable expecting Phase 5 to write it; Phase 5 derives it
 * instead, because a stored Holiday would outlive the `holidays` row it was copied from — an
 * Admin adding Victory Day in November has to change what every past 16 December reads, and an
 * Admin deleting one has to change it back. `2026_09_26_000102` rewrites the CHECK from this
 * method, so no hand can write a Holiday row. Leave did **not** move: a Leave row records a
 * decision somebody made about a person (Part D §9 has approval auto-mark the window), not a
 * fact derived from a calendar everybody shares.
 *
 * The tone is the `StatusBadge` key the UI paints it with. It lives here, once, for the reason
 * decision 2-37 gives: a second copy of a status-to-colour map in a Vue computed drifts. The
 * tone is never the only carrier — every surface prints `label()` beside it (DESIGN.md §5.6).
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case Leave = 'leave';
    case Holiday = 'holiday';
    case Remote = 'remote';
    case OffDay = 'off_day';

    /**
     * The statuses a row of `attendance_records` may hold.
     *
     * Leave is here although nothing writes it until Phase 5's leave approval: it is Part D
     * §8's list, the CHECK constraint is generated from this method, and a constraint that has
     * to be widened on the morning leave approval first runs is a constraint that will look
     * like a broken feature. **The three that are absent are absent on purpose** — Off Day,
     * Remote and, since Phase 5, Holiday are derived at read time and a bug must not be able to
     * persist one. See the class docblock.
     *
     * @return list<self>
     */
    public static function storable(): array
    {
        return [self::Present, self::Late, self::HalfDay, self::Absent, self::Leave];
    }

    /**
     * The statuses an Admin may write on an edit.
     *
     * Not the same list. Leave is owned by its own record — a leave request's approval — so
     * setting it by hand here would make an attendance row that no leave request explains, and
     * Phase 9 reads both. An Admin who wants a day to read Leave approves the leave; one who
     * wants a day to read Holiday adds it to the holidays table, and every employee's grid
     * follows in the same moment.
     *
     * @return list<self>
     */
    public static function editable(): array
    {
        return [self::Present, self::Late, self::HalfDay, self::Absent];
    }

    /**
     * @return list<string>
     */
    public static function storableValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::storable());
    }

    /**
     * @return list<string>
     */
    public static function editableValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::editable());
    }

    public function isStorable(): bool
    {
        return in_array($this, self::storable(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::HalfDay => 'Half day',
            self::Absent => 'Absent',
            self::Leave => 'Leave',
            self::Holiday => 'Holiday',
            self::Remote => 'Remote',
            self::OffDay => 'Off day',
        };
    }

    /**
     * The `StatusBadge` key. Eight statuses onto eight distinct keys, so no two ever share a
     * fill — see DESIGN.md §1.4 for why two statuses that look alike is a screen that lies.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Present => 'done',
            self::Late => 'waiting',
            self::HalfDay => 'review',
            self::Absent => 'cancelled',
            self::Leave => 'backlog',
            self::Holiday => 'changes',
            self::Remote => 'progress',
            self::OffDay => 'todo',
        };
    }
}
