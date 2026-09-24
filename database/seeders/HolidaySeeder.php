<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The Bangladesh public-holiday list, as a **starting point the client edits** (master prompt
 * Phase 5: "seeded with the BD public-holiday list for the current year as a starting point —
 * Admin edits").
 *
 * ## Read this before trusting a date in here
 *
 * **Roughly two thirds of these dates are estimates, and the government's are not.** Bangladesh
 * gazettes its holiday list one year at a time, and a large part of it is lunar:
 *
 *   - the **Islamic** observances follow the Hijri calendar, which is 10–11 days shorter than
 *     the Gregorian one, so they move every year — and in Bangladesh the exact day is confirmed
 *     by the National Moon Sighting Committee only a day or two beforehand. Eid can and does
 *     land a day either side of every almanac's prediction;
 *   - the **Hindu and Buddhist** observances follow a lunisolar calendar, so they move by about
 *     eleven days a year and jump forward by about nineteen in a year with an intercalary
 *     month. Published 2026 dates for Buddha Purnima in particular disagree by a month.
 *
 * Every row below therefore carries a `certainty` of `fixed` or `moving`, and the seeder prints
 * the moving ones when it runs, so nobody meets one of these dates for the first time in
 * production believing a government gazette put it there. **`fixed` means the date is the same
 * every year by statute** — it does not mean the government cannot withdraw the holiday, which
 * it has (see "What is deliberately not here").
 *
 * The screen at Admin → Workforce → Leave → Holidays is the answer to all of this: the Admin
 * corrects a seeded estimate the morning the gazette is published, and nothing else in the
 * application has to be touched, because every reader derives from this table.
 *
 * ## What a "year" is here, and what happens next January
 *
 * A year is a plain calendar year over `holidays.date` — there is no year column (see the
 * create migration). `YEARS` holds one hard-coded list per year, and this seeder seeds **the
 * current calendar year only, and only if that year has no rows yet**.
 *
 * That guard does two things at once. It makes a second run a no-op, which the user's launcher
 * requires — `start-hq.bat` runs `db:seed` on every start, and a seeder that duplicates on the
 * second run breaks their machine; it has. And it stops the seed from **resurrecting a holiday
 * the Admin deleted on purpose**, which a per-row `firstOrCreate` alone would do at the next
 * start. The per-row `firstOrCreate` is still there underneath, so no single row can duplicate
 * even if the guard is ever loosened.
 *
 * Next January the year rolls over, the guard finds no 2027 rows — and there is no 2027 list,
 * so **nothing is seeded and the seeder says so**. That is the correct behaviour and not a gap
 * to fill with arithmetic: Bangladesh publishes the following year's list late in the preceding
 * year, and a generated 2027 would be this file's guesses wearing the authority of a seed.
 * The Admin types the gazette in; the screen opens on the current year, offers a year switcher,
 * and shows an empty state for a year with no rows telling them exactly that.
 *
 * ## What is deliberately not here
 *
 * Three holidays that a pre-2024 list would carry — **7 March**, **17 March** (Sheikh Mujibur
 * Rahman's birthday / National Children's Day) and **15 August** (National Mourning Day) —
 * were withdrawn as public holidays by the interim government in 2024, and the days declared
 * since then have not settled. Seeding a withdrawn holiday would shut the office on a working
 * day; seeding a new one this file cannot verify would do the same. They are named here so
 * that their absence reads as a decision rather than an oversight, and the Admin adds whatever
 * the current gazette says.
 */
class HolidaySeeder extends Seeder
{
    /**
     * `fixed` — the same Gregorian date every year by statute.
     * `moving` — lunar or lunisolar. **The date below is an estimate.**
     */
    private const FIXED = 'fixed';

    private const MOVING = 'moving';

    /**
     * One hard-coded list per year. Nothing is generated.
     *
     * @var array<int, list<array{date: string, name: string, certainty: string}>>
     */
    private const YEARS = [
        2026 => [
            // ---- Lunar: Islamic. Every one of these is an estimate. --------------------
            // Shab e-Meraj, 27 Rajab 1447. Hijri, so it moves ~11 days earlier each year.
            ['date' => '2026-01-16', 'name' => 'Shab e-Meraj', 'certainty' => self::MOVING],
            // Shab e-Barat, 15 Sha'ban 1447. Observed on the night before, so the office day
            // that is closed can slip by one even when the Hijri date is right.
            ['date' => '2026-02-02', 'name' => 'Shab e-Barat', 'certainty' => self::MOVING],

            // ---- Fixed by statute -------------------------------------------------------
            ['date' => '2026-02-21', 'name' => 'Shaheed Day and International Mother Language Day', 'certainty' => self::FIXED],

            // ---- Ramadan and Eid ul-Fitr ------------------------------------------------
            // Jumatul Bidha is the last Friday of Ramadan, so it is only as certain as the
            // start of Ramadan is; Shab e-Qadr is 27 Ramadan, same caveat.
            ['date' => '2026-03-13', 'name' => 'Jumatul Bidha', 'certainty' => self::MOVING],
            ['date' => '2026-03-16', 'name' => 'Shab e-Qadr', 'certainty' => self::MOVING],
            // Three days, as Bangladesh gazettes them: the day before, the day, the day after.
            // The whole block moves together, and it moves as one — if the moon is sighted a
            // day late, all three shift, so correcting these on the screen is three edits.
            ['date' => '2026-03-19', 'name' => 'Eid ul-Fitr holiday', 'certainty' => self::MOVING],
            ['date' => '2026-03-20', 'name' => 'Eid ul-Fitr', 'certainty' => self::MOVING],
            ['date' => '2026-03-21', 'name' => 'Eid ul-Fitr holiday', 'certainty' => self::MOVING],

            ['date' => '2026-03-26', 'name' => 'Independence and National Day', 'certainty' => self::FIXED],

            ['date' => '2026-04-14', 'name' => 'Pahela Baishakh (Bengali New Year)', 'certainty' => self::FIXED],

            ['date' => '2026-05-01', 'name' => 'May Day', 'certainty' => self::FIXED],
            // Buddha Purnima (Vesak). Lunisolar, and published 2026 dates disagree by a month —
            // some put the full moon of Vaisakha at the start of May and some at the end of it.
            // It is seeded on 1 May, which is the LOWER-RISK of the two guesses rather than the
            // more likely one: 1 May is already May Day, so if this estimate is wrong the
            // office loses nothing (the day is closed either way) and the Admin moves the row.
            // Seeded on 31 May and wrong, it would have shut the office on a working Sunday.
            // Two rows on one date is exactly what `unique(date, name)` exists to allow.
            ['date' => '2026-05-01', 'name' => 'Buddha Purnima', 'certainty' => self::MOVING],

            // ---- Eid ul-Adha ------------------------------------------------------------
            // 10 Dhul-Hijjah 1447, with the two days around it, as gazetted.
            ['date' => '2026-05-26', 'name' => 'Eid ul-Adha holiday', 'certainty' => self::MOVING],
            ['date' => '2026-05-27', 'name' => 'Eid ul-Adha', 'certainty' => self::MOVING],
            ['date' => '2026-05-28', 'name' => 'Eid ul-Adha holiday', 'certainty' => self::MOVING],

            // Ashura, 10 Muharram 1448 — the Hijri new year falls in June 2026, so this one is
            // in the year twice over in some calendars and not at all in others.
            ['date' => '2026-06-25', 'name' => 'Ashura', 'certainty' => self::MOVING],

            // Eid e-Milad un-Nabi, 12 Rabi' al-Awwal 1448.
            ['date' => '2026-08-25', 'name' => 'Eid e-Milad un-Nabi', 'certainty' => self::MOVING],

            // ---- Lunisolar: Hindu -------------------------------------------------------
            ['date' => '2026-09-04', 'name' => 'Janmashtami', 'certainty' => self::MOVING],
            ['date' => '2026-10-20', 'name' => 'Durga Puja (Bijoya Dashami)', 'certainty' => self::MOVING],

            // ---- Fixed by statute -------------------------------------------------------
            ['date' => '2026-12-16', 'name' => 'Victory Day', 'certainty' => self::FIXED],
            ['date' => '2026-12-25', 'name' => 'Christmas Day', 'certainty' => self::FIXED],
        ],
    ];

    public function run(): void
    {
        $year = (int) Carbon::today(config('app.timezone'))->year;
        $list = self::YEARS[$year] ?? null;

        if ($list === null) {
            $this->command?->warn(sprintf(
                'HolidaySeeder: no Bangladesh holiday list is held for %d, so nothing was seeded. '
                .'Add %d\'s gazetted holidays at Admin → Workforce → Leave → Holidays.',
                $year,
                $year,
            ));

            return;
        }

        // The guard. A year that already has rows is a year somebody may have edited, and
        // re-seeding it would quietly undo a deletion. See the class docblock.
        if (Holiday::query()->inYear($year)->exists()) {
            return;
        }

        foreach ($list as $holiday) {
            // Never a raw create: the launcher runs `db:seed` on every start, and the guard
            // above is a decision while this is a guarantee.
            Holiday::firstOrCreate(
                ['date' => $holiday['date'], 'name' => $holiday['name']],
            );
        }

        $moving = array_values(array_filter(
            $list,
            fn (array $holiday): bool => $holiday['certainty'] === self::MOVING,
        ));

        $this->command?->info(sprintf(
            'HolidaySeeder: seeded %d Bangladesh holidays for %d.',
            count($list),
            $year,
        ));

        // Printed, not just commented. Somebody running the seeder is the person who can act on
        // this, and a warning only a reader of the file would meet is a warning nobody meets.
        $this->command?->warn(sprintf(
            '  %d of them are lunar or lunisolar and the dates are ESTIMATES, not gazetted: %s. '
            .'Check them against the government list and correct them on the Holidays screen.',
            count($moving),
            implode(', ', array_map(
                fn (array $holiday): string => $holiday['name'].' ('.$holiday['date'].')',
                $moving,
            )),
        ));
    }
}
