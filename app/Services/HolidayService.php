<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\User;
use App\Support\AuditEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The company holiday calendar (master prompt Part D §9, Phase 5).
 *
 * ## One question, asked once
 *
 * Every reader of `holidays` asks the same thing — *is the company closed on this date, and
 * what is the day called* — and this service is the single spelling of it (decision 2-37).
 * `AttendanceService` asks it to derive the Holiday status and to skip the 23:55 absent sweep;
 * the two dashboards ask it for "Upcoming holidays"; the admin screen asks it for a year.
 * None of them queries the table directly and none of them holds a second opinion about what
 * a holiday is.
 *
 * ## Nothing here stamps a status onto a row
 *
 * There is no `markHoliday()`, and there is no code path in this application that writes
 * `AttendanceStatus::Holiday` into `attendance_records`. A holiday is **derived at read time**,
 * exactly as Off Day and Remote are (decision 4-9): a stored one would outlive the `holidays`
 * row it was copied from, and an Admin adding Victory Day in November has to change what every
 * past 16 December reads. `2026_09_26_000102` takes `holiday` out of the status CHECK so that
 * is true of the database and not only of this file.
 *
 * ## Cost
 *
 * `covers()` and `nameFor()` take one date, because that is the question a cell asks and a
 * per-cell signature is what let the Phase 4 seam be filled without any caller changing
 * (`trackedMinutes()`, same shape, same reasoning). A month grid or a roster would be thirty
 * or forty of them, so `prime()` reads a whole range in one query and every ask inside it is
 * then a cache hit — **including the days that are not holidays**, which are seeded as misses
 * first so a quiet Tuesday is answered from memory rather than by going to the database to be
 * told the same thing.
 *
 * The cache is request-scoped: the service is resolved per request and never outlives the
 * answer it is caching. A write clears it, so an Admin who adds a holiday and is redirected
 * back does not read a stale range in the same request.
 */
class HolidayService
{
    /**
     * How many upcoming holidays a dashboard card shows.
     *
     * Four, which is about a screenful on a phone and — in a calendar with twenty-odd public
     * holidays and several of them clustered into Eid weeks — reliably reaches past the end of
     * the current cluster into the next distinct occasion. A card showing three would spend all
     * of an Eid week saying only "Eid".
     */
    public const DASHBOARD_LIMIT = 4;

    /**
     * Names read so far, keyed `Y-m-d`. A day with no holiday caches `null`, which is the
     * answer and not the absence of one — see `prime()`.
     *
     * @var array<string, string|null>
     */
    private array $cache = [];

    public function __construct(private readonly AuditLogger $audit) {}

    // -----------------------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------------------

    /**
     * Is the company closed on this date?
     *
     * The whole of the Holiday half of `AttendanceService::coveredByLeaveOrHoliday()`, and the
     * whole of the derivation in `dayFor()`.
     */
    public function covers(CarbonInterface $date): bool
    {
        return $this->nameFor($date) !== null;
    }

    /**
     * What the day is called, or null when it is an ordinary day.
     *
     * The name matters as well as the fact: a grid cell reading *Holiday* tells somebody the
     * office was shut and *Victory Day* tells them why, and the second one is the one that
     * stops a reader opening the audit log to find out what happened on 16 December.
     *
     * Where two observances share a day the earliest-created row's name wins, because the table
     * allows both (see the create migration) and a cell has room for one. The screen shows both.
     */
    public function nameFor(CarbonInterface $date): ?string
    {
        $key = Carbon::parse($date)->toDateString();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $name = Holiday::query()
            ->whereDate('date', $key)
            ->orderBy('id')
            ->value('name');

        return $this->cache[$key] = $name === null ? null : (string) $name;
    }

    /**
     * Read a whole range in one query, so the cells inside it cost nothing.
     *
     * The misses are seeded first and deliberately: without them a month with two holidays in
     * it would be two cache hits and twenty-eight queries, which is the N+1 the caller came
     * here to avoid. Public because the roster, the month grid and both dashboards prime it —
     * private state only its own class could fill would force every caller into that N+1
     * (`AttendanceService::primeTrackedMinutes()`, same shape, same reason).
     */
    public function prime(CarbonInterface $from, CarbonInterface $to): void
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->greaterThan($to)) {
            return;
        }

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $this->cache[$day->toDateString()] ??= null;
        }

        Holiday::query()
            ->between($from, $to)
            ->orderBy('id')
            ->get(['date', 'name'])
            ->each(function (Holiday $holiday): void {
                // `??=` rather than `=`, so the FIRST row of a day that carries two wins —
                // matching nameFor()'s `orderBy('id')`. Two readers must not be able to answer
                // differently about the same day.
                $this->cache[$holiday->date->toDateString()] ??= $holiday->name;
            });
    }

    /** Throw away everything read so far. Called after a write. */
    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * The next few holidays, today included.
     *
     * Today is in the range because a holiday that is *today* is the most upcoming one there
     * is, and a card that dropped it at midnight would go quiet on the one day it is most
     * useful. The screen labels it "Today" rather than pretending it is ahead.
     *
     * @return Collection<int, Holiday>
     */
    public function upcoming(?CarbonInterface $from = null, int $limit = self::DASHBOARD_LIMIT): Collection
    {
        return Holiday::query()
            ->upcoming($from === null ? Carbon::today(config('app.timezone')) : Carbon::parse($from))
            ->limit($limit)
            ->get();
    }

    /**
     * One calendar year, earliest first — the admin screen's list.
     *
     * @return Collection<int, Holiday>
     */
    public function year(int $year): Collection
    {
        return Holiday::query()
            ->inYear($year)
            ->orderBy('date')
            ->orderBy('name')
            ->get();
    }

    /**
     * Every year that has at least one holiday in it, plus the current one.
     *
     * The current year is always in the list even when it is empty, because it is the year the
     * screen opens on and a switcher that could not offer the year you are looking at would be
     * a switcher you cannot get back to. Next year appears the moment somebody adds a row to it
     * — which is how the following January is meant to work (see `HolidaySeeder`).
     *
     * @return list<int>
     */
    public function years(): array
    {
        $years = Holiday::query()
            ->selectRaw('distinct extract(year from date)::int as year')
            ->pluck('year')
            ->map(fn (mixed $year): int => (int) $year)
            ->push($this->currentYear())
            ->unique()
            ->sort()
            ->values()
            ->all();

        /** @var list<int> $years */
        return $years;
    }

    public function currentYear(): int
    {
        return (int) Carbon::today(config('app.timezone'))->year;
    }

    // -----------------------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------------------

    /**
     * Add a holiday.
     *
     * Audited as `configuration.changed`, which is the event this is: the holiday calendar is a
     * company-wide setting that decides what a day is called for everybody, which is also why
     * the screen sits behind `settings.manage`. Part C §4 names "configuration changes" in its
     * own list, so no new audit event was invented for it — and an added holiday quietly
     * changes what a past month reads, so it is exactly the kind of change that list is for.
     *
     * @param  array{date: string, name: string}  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Holiday
    {
        return DB::transaction(function () use ($attributes, $actor): Holiday {
            $holiday = Holiday::create([
                'date' => Carbon::parse($attributes['date'])->toDateString(),
                'name' => trim((string) $attributes['name']),
            ]);

            $this->forget();

            $this->audit->record(AuditEvent::ConfigurationChanged, $holiday, null, $this->values($holiday), $actor);

            return $holiday;
        });
    }

    /**
     * Rename a holiday, or move it to another date.
     *
     * Both in one act, because both are the same correction: a lunar date seeded from an
     * almanac and then gazetted a day later is moved, and the day it names is usually renamed
     * at the same time. The audit row carries old and new in one shape so a reader diffs them
     * by eye — `AttendanceService::edit()`'s rule, applied here.
     *
     * @param  array{date: string, name: string}  $attributes
     */
    public function update(Holiday $holiday, array $attributes, ?User $actor = null): Holiday
    {
        return DB::transaction(function () use ($holiday, $attributes, $actor): Holiday {
            $old = $this->values($holiday);

            $holiday->date = Carbon::parse($attributes['date'])->toDateString();
            $holiday->name = trim((string) $attributes['name']);
            $holiday->save();

            $this->forget();

            $this->audit->record(AuditEvent::ConfigurationChanged, $holiday, $old, $this->values($holiday), $actor);

            return $holiday;
        });
    }

    /**
     * Remove a holiday. The day goes back to being whatever the schedule says it is.
     *
     * The audit row is written with the values the row still had, and `new` is null — the same
     * shape `TagService`'s delete uses, and for the same reason: a record saying "deleted"
     * without saying what was deleted is the kind of record you only notice is useless the day
     * you need it.
     */
    public function delete(Holiday $holiday, ?User $actor = null): void
    {
        DB::transaction(function () use ($holiday, $actor): void {
            $old = $this->values($holiday);

            $holiday->delete();

            $this->forget();

            $this->audit->record(AuditEvent::ConfigurationChanged, $holiday, $old, null, $actor);
        });
    }

    /**
     * The shape an audit row records a holiday in. One shape for both halves of an edit.
     *
     * @return array<string, mixed>
     */
    private function values(Holiday $holiday): array
    {
        return [
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
        ];
    }
}
