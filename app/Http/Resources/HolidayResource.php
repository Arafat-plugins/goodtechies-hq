<?php

namespace App\Http\Resources;

use App\Models\Holiday;
use App\Support\Weekday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * A holiday as a screen reads it.
 *
 * There is no privacy work to do here — a holiday is the same fact for everybody (see
 * `HolidayPolicy`) — so this exists for the other two reasons a resource does: one shape, and
 * `permissions` resolved **per record on the server**.
 *
 * The per-record block matters even though the answer is currently the same for every row:
 * decisions 2-28 and 2-31 say a screen never derives a control from a role in Vue, and the day
 * a rule grows a per-row exception (a locked past year, say) the screens need no change. It is
 * `ClientResource`'s block, same reasoning as decision 2-29.
 *
 * `weekday` and `is_past` are derived here rather than in Vue for the reason
 * `AttendanceDay::toArray()` gives: the week's day names come from `App\Support\Weekday`, and
 * a second copy of that in a Vue computed drifts — a screen formatting its own dates would also
 * use the browser's locale and timezone, which is not the agency's.
 *
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = Carbon::today(config('app.timezone'));
        $date = $this->resource->date;

        return [
            'id' => $this->id,
            'date' => $date->toDateString(),
            'name' => $this->name,

            // "Thursday". The server's week, not the browser's — Weekday is the same enum the
            // schedule editor's checkboxes come from.
            'weekday' => Weekday::of($date)->label(),

            // Three facts, not one flag, because a list of a whole year has to distinguish
            // "already happened" from "today" — and a row that is today is the one somebody
            // opened the page to check.
            'is_past' => $date->lessThan($today),
            'is_today' => $date->isSameDay($today),
            // Whole days away, negative in the past. The screen prints it in words; it is here
            // as a number so that the words are the screen's and the arithmetic is not.
            'days_away' => (int) $today->diffInDays($date, false),

            'permissions' => $this->permissions($request),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['can_update' => false, 'can_delete' => false];
        }

        return [
            'can_update' => Gate::forUser($user)->allows('update', $this->resource),
            'can_delete' => Gate::forUser($user)->allows('delete', $this->resource),
        ];
    }
}
