<?php

use App\Support\Weekday;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Friday and Saturday are everybody's weekly days off (the client, item 10). Every schedule loses
 * `fri` and `sat`; a schedule left with no day at all gets the Sunday-to-Thursday week. Days stay
 * in the week order `ScheduleService` writes them in (`Weekday::values()`).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        DB::connection($this->getConnection())->table('schedules')->orderBy('id')
            ->get(['id', 'working_days'])
            ->each(function (object $row): void {
                $days = json_decode((string) $row->working_days, true);
                $next = $this->withoutWeekend(is_array($days) ? $days : []);

                if ($next !== $days) {
                    DB::connection($this->getConnection())->table('schedules')
                        ->where('id', $row->id)
                        ->update(['working_days' => json_encode($next), 'updated_at' => now()]);
                }
            });
    }

    /**
     * Nothing to put back: which schedules had Friday or Saturday before is not recorded, and
     * adding them to every schedule would be wrong for most of them.
     */
    public function down(): void {}

    /**
     * @param  array<int, mixed>  $days
     * @return list<string>
     */
    public function withoutWeekend(array $days): array
    {
        $kept = array_values(array_filter(
            Weekday::values(),
            fn (string $day): bool => $day !== 'fri' && $day !== 'sat' && in_array($day, $days, true),
        ));

        return $kept === [] ? ['sun', 'mon', 'tue', 'wed', 'thu'] : $kept;
    }
};
