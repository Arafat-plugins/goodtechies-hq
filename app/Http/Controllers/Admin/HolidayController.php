<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Holiday\StoreHolidayRequest;
use App\Http\Requests\Holiday\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Services\HolidayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Workforce → Leave → Holidays (master prompt Phase 5, Part D §9).
 *
 * **This is the screen the client types their own calendar into.** `HolidaySeeder` puts the
 * Bangladesh public-holiday list for the current year in as a starting point and roughly two
 * thirds of those dates are lunar estimates, not gazetted dates — so the list arrives wrong in
 * places by construction, and this is where it is made right. That is also why the empty state
 * and the year switcher say what they say: the following January there is no seeded list at
 * all, and the Admin types the gazette in.
 *
 * ## One list, one year at a time
 *
 * A year is a range over `holidays.date` and not a column (see the create migration), so
 * `?year=` is the only parameter and `HolidayService::year()` is the only place the range is
 * stated. The switcher's options are `HolidayService::years()` — every year that has rows, plus
 * the current one, which is always offered even when it is empty because it is the year the
 * page opens on.
 *
 * An unparseable or out-of-range `year` falls back to the current one rather than 404ing: it is
 * a view parameter, not a record, and a mistyped URL should show you this year's holidays
 * rather than an error page.
 *
 * ## Every refusal here is 403
 *
 * There is no holiday somebody may see and somebody else may not — a holiday is the same fact
 * for the whole company (`HolidayPolicy`) — so Part C's absence rule has nothing to bite on and
 * no cell of the permission matrix is 404. The wrong shell is stopped by `surface:admin` and
 * the wrong role by `can:settings.manage`, both before anything is resolved. The one 404 that
 * exists is an id that is not in the table, which is route-model binding; it is asserted
 * directly in tests/Feature/Workforce/HolidayEndpointsTest.php, because every role that could
 * show it in the matrix is refused by the surface first (decision 3-8's shape).
 *
 * Writes go through `HolidayService`, which audit-logs each one as `configuration.changed` with
 * old and new values: adding a holiday quietly changes what a past month reads for everybody in
 * the agency, and Phase 9 pays from those days.
 */
class HolidayController extends Controller
{
    public function __construct(private readonly HolidayService $holidays) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Holiday::class);

        $year = $this->year($request);

        return Inertia::render('Admin/Holidays/Index', [
            'year' => $year,
            'years' => $this->holidays->years(),
            'currentYear' => $this->holidays->currentYear(),
            'holidays' => HolidayResource::collection($this->holidays->year($year))->resolve($request),
            // Whether there is an Add control at all, resolved on the server. The per-row Edit
            // and Remove come from each row's own `permissions` block — never from a role in
            // Vue (decisions 2-28, 2-31).
            'canManage' => $request->user() !== null
                && Gate::forUser($request->user())->allows('create', Holiday::class),
        ]);
    }

    public function store(StoreHolidayRequest $request): RedirectResponse
    {
        Gate::authorize('create', Holiday::class);

        $holiday = $this->holidays->create($request->holidayAttributes(), $request->user());

        return $this->backToYear($holiday, sprintf(
            '%s added on %s.',
            $holiday->name,
            $holiday->date->format('j F Y'),
        ));
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        Gate::authorize('update', $holiday);

        $updated = $this->holidays->update($holiday, $request->holidayAttributes(), $request->user());

        return $this->backToYear($updated, sprintf(
            '%s saved for %s.',
            $updated->name,
            $updated->date->format('j F Y'),
        ));
    }

    public function destroy(Request $request, Holiday $holiday): RedirectResponse
    {
        Gate::authorize('delete', $holiday);

        // Read before the delete, because the sentence names what went (decision 2-23's
        // reasoning: a record saying "deleted" without saying what is a record nobody can use).
        $name = $holiday->name;
        $date = $holiday->date->copy();

        $this->holidays->delete($holiday, $request->user());

        return redirect()
            ->route('admin.holidays.index', ['year' => $date->year])
            ->with('success', sprintf(
                '%s removed from %s. That day now follows each employee\'s work schedule again.',
                $name,
                $date->format('j F Y'),
            ));
    }

    /**
     * Back to the year the holiday is IN, not the year the form was on.
     *
     * Moving a holiday across a new year's eve — which is exactly what happens to a lunar date
     * seeded in early January — would otherwise redirect to a list the row has just left, and
     * the Admin would see their own edit vanish.
     */
    private function backToYear(Holiday $holiday, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.holidays.index', ['year' => $holiday->date->year])
            ->with('success', $message);
    }

    /**
     * The year being read: `?year=`, or the current one.
     *
     * Bounded rather than free, because the value reaches a `whereBetween` and an unbounded
     * integer would let a URL ask for a range no calendar has. Anything outside it — a word, a
     * negative, 90210 — falls back to the current year, which is a view choice and not a
     * refusal.
     */
    private function year(Request $request): int
    {
        $year = (int) $request->query('year', (string) $this->holidays->currentYear());

        return $year >= 2000 && $year <= 2100 ? $year : $this->holidays->currentYear();
    }
}
