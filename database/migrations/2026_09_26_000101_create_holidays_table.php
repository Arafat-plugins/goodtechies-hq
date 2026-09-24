<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company holidays (master prompt Part D §20: `holidays (date, name)`). Phase 5.
     *
     * Two columns and nothing else, which is the whole point: this table answers one question
     * — *is the company closed on this date, and what is the day called* — and everything that
     * reads it derives its answer at read time. Part D §9 gives it three readers: the Holiday
     * attendance status, the calendars' holiday markers, and the dashboards' "Upcoming
     * holidays" card.
     *
     * ## There is no `year` column, and a "year" is a range over `date`
     *
     * The seed is described as "the BD public-holiday list for the current year", which reads
     * like a year is a thing this table stores. It is not. A year is `date >= 1 Jan AND
     * date <= 31 Dec`, computed from the one column that already knows it — a `year` column
     * would be a second statement of a fact `date` states (decision 2-37), and the first time
     * somebody moved a lunar date across a new year's eve boundary the two would disagree.
     *
     * So the table is a flat list of dated rows. `HolidaySeeder` seeds one calendar year of
     * them and guards on that year already having rows; the screen opens on the current year
     * with a year switcher; and the following January the 2027 list is simply absent until an
     * Admin types it in, which is how it actually works — Bangladesh gazettes the following
     * year's list late in the preceding year, so a seeded 2027 would be an invention. See the
     * seeder for that argument in full.
     *
     * ## Unique on `(date, name)`, not on `date`
     *
     * A single unique `date` would read as "a day is a holiday or it is not", which is what the
     * derivation asks — but it would also make the table refuse a second observance falling on
     * a day that already has one, and in Bangladesh that is not hypothetical: in the seeded
     * year **Buddha Purnima and May Day both land in the first days of May** and published
     * calendars disagree about whether the full moon puts them on the same date. A unique
     * `date` would have made `firstOrCreate` silently drop the second row — a holiday missing
     * from the list with nothing to show it was ever there, which is worse than two rows an
     * Admin can merge.
     *
     * `(date, name)` still makes every seeded row idempotent, which is what the launcher needs:
     * `start-hq.bat` runs `db:seed` on every start, and a seeder that duplicates on the second
     * run breaks the user's machine (it has).
     *
     * The plain index on `date` is what every reader actually queries by — the derivation asks
     * for one date, the dashboards ask for a forward range, the screen asks for a year — and
     * the composite unique cannot serve a `name`-less lookup as cheaply.
     *
     * ## `date`, not `timestamp`
     *
     * A holiday is a calendar day, not a moment. `attendance_records.date` is the column it is
     * compared against and is also a `date`, so the comparison is two values of one type and
     * never a timezone conversion.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            $table->date('date');
            $table->string('name', 120);

            $table->timestamps();

            // Two observances may share a day; the same observance may not be listed twice on
            // one. See the docblock — this is also what makes the seeder's firstOrCreate safe.
            $table->unique(['date', 'name']);

            // Every read is by date: one day (the derivation), a forward range (the dashboard
            // cards), a calendar year (the admin screen).
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
