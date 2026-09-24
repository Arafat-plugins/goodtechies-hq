<?php

namespace App\Models;

use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One company holiday: a date and what the day is called (master prompt Part D §20).
 *
 * A row here is a statement about the **company**, not about a person: there is no employee
 * on it, no status, and nothing that could be true of one colleague and false of another. That
 * is what lets every reader derive from it instead of copying it — `AttendanceService::dayFor()`
 * asks whether a date is in this table and answers Holiday, and deleting the row makes the same
 * day read as a working day again, in the grid, in the roster and on a month already past.
 *
 * Nothing in the application writes a Holiday into `attendance_records`, and the CHECK
 * constraint `attendance_records_status_is_storable` is what makes that true rather than
 * customary — see `2026_09_26_000102` and decision 4-9.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $name
 */
#[Fillable(['date', 'name'])]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    /**
     * The longest a holiday's name may be.
     *
     * 120 characters, and the column matches. Bangladeshi holiday names are routinely compound
     * — *"Eid ul-Fitr holiday (day 2)"*, *"Shab e-Barat"*, *"Birthday of Rabindranath Tagore"* —
     * and where two observances share a day an Admin writes both into the one name. Sixty, the
     * tag limit, would have forced an abbreviation on the client's own calendar.
     */
    public const MAX_NAME = 120;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * Holidays falling inside a calendar year, earliest first.
     *
     * A year is a range over `date` and never a column (see the create migration): the same
     * scope answers "this year" for the admin screen and "1998" for a reader who typed it into
     * the year switcher, with no second statement of what a year is.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInYear(Builder $query, int $year): void
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();

        $query->whereBetween('date', [
            $start->toDateString(),
            $start->copy()->endOfYear()->toDateString(),
        ]);
    }

    /**
     * Holidays on or after a date, earliest first — the dashboards' "Upcoming holidays".
     *
     * Inclusive of `$from`, because a holiday that is *today* is the most upcoming one there
     * is: a card that dropped it at midnight would hide the day it is most useful on.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUpcoming(Builder $query, Carbon $from): void
    {
        $query->where('date', '>=', $from->toDateString())->orderBy('date')->orderBy('name');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }
}
