<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\FinanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreIncomeRequest;
use App\Http\Resources\FinanceCategoryResource;
use App\Http\Resources\IncomeResource;
use App\Models\Income;
use App\Models\Project;
use App\Services\FinanceService;
use App\Services\SettingsService;
use App\Support\FinanceCategoryKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The income ledger: a month of money in, and the form that writes it (master prompt Part D
 * §13, Phase 8).
 *
 * ## Why this is SHARED and not one controller per shell
 *
 * Part D's screen list reads *"Screens (Accountant shell + Admin → Finance)"*, and the answer
 * this repo has now reached four times — notifications, attendance, leave, messages, meetings —
 * is the same one: **whose money this is belongs to the agency, not to the shell somebody is
 * looking at.** An Admin and the Accountant are reading the same ledger, with the same rights
 * over every row (`IncomePolicy` grants nothing by who recorded it), so two copies of these six
 * routes would have been two places for that to be answered differently — and one of the two
 * would have been the copy nobody tested.
 *
 * `Pages/Shared/Finance/Income.vue` picks `AdminLayout` or `AccountantLayout` from
 * `auth.user.surface`, exactly as `Pages/Shared/Messages.vue` does, so the Accountant shell
 * still imports nothing from `Layouts/AdminLayout.vue` or `Pages/Admin/`.
 *
 * **`can:finance.view` on the route group is the phase's own security line**, *"Employees/
 * Remote get 403 on every finance route"*, spelled as a permission rather than as a list of
 * roles — so a Manager is refused by the same absence of a key, and nothing here names anybody.
 * It is 403 and not 404 deliberately: the whole feature is refused rather than one row of it,
 * and there is no id that would behave differently (see `IncomePolicy`'s class note).
 *
 * ## One page component, three GET routes
 *
 * `index`, `create` and `edit` all render `Shared/Finance/Income` — the form is a dialog over
 * the ledger, and which state it is in is in the **URL** rather than in component state. So a
 * half-written income survives a refresh, the back button closes the form, and a validation
 * failure redirects back to a real address that renders the form again with the errors on it.
 * `create` and `edit` are the only two routes that carry the project list.
 *
 * ## The month is in the URL, and the totals are the service's
 *
 * `?month=YYYY-MM`, because a month is what Part D's rollup and every report are denominated
 * in, and because which month you are reading is shareable (DESIGN.md §5 rule 10) — the same
 * shape as the attendance month and the meetings calendar. Anything unparseable falls back to
 * this month rather than throwing: a mistyped link should show a ledger.
 *
 * **The per-category totals and the grand total come from `FinanceService::monthlyRollup()`**
 * and are never added up in Vue. A second sum is a second statement of a fact about money
 * (decision 8-10), and the day it disagreed with the dashboard's, neither screen would be
 * provably right.
 */
class IncomeController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Income::class);

        return $this->page($request, null, false);
    }

    /** The ledger with the form open and empty. */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Income::class);

        return $this->page($request, null, true);
    }

    /**
     * The ledger with the form open on one row.
     *
     * The month defaults to the **record's** month rather than to today's, so the row being
     * edited is on the list behind the dialog. An explicit `?month=` still wins: that is
     * somebody arriving from a list they were already reading.
     */
    public function edit(Request $request, Income $income): Response
    {
        Gate::authorize('update', $income);

        return $this->page($request, $income->load(['category', 'project']), true);
    }

    public function store(StoreIncomeRequest $request): RedirectResponse
    {
        Gate::authorize('create', Income::class);

        try {
            $income = $this->finance->recordIncome($request->user(), $request->incomeAttributes());
        } catch (FinanceStateException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return $this->backToMonth($income->date, sprintf(
            'Income of %s recorded for %s.',
            $this->money($income->amount),
            $income->date->format('j F Y'),
        ));
    }

    public function update(StoreIncomeRequest $request, Income $income): RedirectResponse
    {
        Gate::authorize('update', $income);

        try {
            $updated = $this->finance->updateIncome($request->user(), $income, $request->incomeAttributes());
        } catch (FinanceStateException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return $this->backToMonth($updated->date, sprintf(
            'Income of %s saved for %s.',
            $this->money($updated->amount),
            $updated->date->format('j F Y'),
        ));
    }

    /**
     * Remove one, for good.
     *
     * A **hard** delete (decision 8-5) — what survives is the audit row, with the whole of the
     * old record in it. The sentence is read before the delete and names the amount and the
     * date, because a confirmation that says only "deleted" is one nobody can check afterwards.
     */
    public function destroy(Request $request, Income $income): RedirectResponse
    {
        Gate::authorize('delete', $income);

        $amount = $this->money($income->amount);
        $date = $income->date->copy();

        try {
            $this->finance->deleteIncome($request->user(), $income);
        } catch (FinanceStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->backToMonth($date, sprintf(
            'Income of %s on %s was deleted. The audit log keeps the record.',
            $amount,
            $date->format('j F Y'),
        ));
    }

    /**
     * The one payload all three GET routes render.
     *
     * @param  Income|null  $editing  the row the form is open on, or null to add
     * @param  bool  $formOpen  whether the form is open at all
     */
    private function page(Request $request, ?Income $editing, bool $formOpen): Response
    {
        $month = $this->month($request, $editing?->date);
        $user = $request->user();

        $records = Income::query()
            // Two queries instead of one per row — every row prints its category, and most
            // print their project.
            ->with(['category', 'project'])
            ->inMonth($month->year, $month->month)
            // Newest first, then by id so two rows on the same day keep a stable order between
            // renders. There is no `sort` parameter: a header that pushed one the controller
            // ignored would be a lie in the UI (decisions 0.5-16, 0.5-19).
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $rollup = $this->finance->monthlyRollup($user, $month->year, $month->month);

        return Inertia::render('Shared/Finance/Income', [
            'month' => $this->monthPayload($month),

            'records' => IncomeResource::collection($records)->resolve($request),

            // The month's figure per category and the grand total, straight from the service.
            // Never recomputed here and never in Vue — see the class note.
            'totals' => $rollup['income'],

            // Only this side of the ledger. The composite foreign key makes the wrong one a
            // constraint violation, so the picker must not be able to offer it (decision 8-2).
            'categories' => FinanceCategoryResource::collection(
                $this->finance->categories(FinanceCategoryKind::Income)
            )->resolve($request),

            // Sent only while the form is open: a ledger that is not being written to has no
            // use for a list of projects, and a payload that carries what the screen cannot act
            // on is a payload that invites a control the server would refuse.
            'projects' => $formOpen ? $this->projects() : [],

            'form' => $formOpen
                ? [
                    'mode' => $editing === null ? 'create' : 'edit',
                    'record' => $editing === null
                        ? null
                        : (new IncomeResource($editing))->resolve($request),
                ]
                : null,

            // Today in the agency's timezone, not the browser's: it is the date a new
            // record defaults to, and a laptop set to another zone would otherwise file a
            // payment into the wrong month for six hours a day.
            'today' => Carbon::today(config('app.timezone'))->toDateString(),

            'currency' => (string) $this->settings->get('currency'),

            'permissions' => [
                'can_create' => $user !== null && Gate::forUser($user)->allows('create', Income::class),
            ],
        ]);
    }

    /**
     * The income form's project picker: **`id`, `name`, `domain`, for every viewer.**
     *
     * ## This supersedes decision 8-16
     *
     * 8-16 decided that the Admin's income form would pick its project from
     * `admin.projects.index` — through `ProjectResource`, with the client attached — because
     * the Admin is refused `/accountant/projects` by `surface:accountant` and may see clients
     * anyway. That was decided before this screen was shared, and it does not survive the
     * screen being one page: it would have meant one picker with two payloads and two code
     * paths, branching on the viewer's surface, in a form where the branch buys nothing.
     *
     * **A picker on an income form does not need a client name, so it does not get one.** The
     * three keys are what a person needs to recognise which of four *Website Maintenance* rows
     * they are filing a payment against — Part C §2 already settles that the domain is what
     * stands in place of the client's name — and they are the same three for an Admin and for
     * the Accountant. Narrower for the Admin than 8-16 would have been, identical on both
     * surfaces, and one key set to test.
     *
     * The query is `Accountant\ProjectController`'s, deliberately: every project, including the
     * archived ones, ordered by name, and **not** scoped by `Project::visibleTo()` — that scope
     * returns nothing for an Accountant, which is exactly why Part D §13 needed a dedicated
     * window in the first place (decision 8-9). What differs is the serializer: that endpoint
     * sends `AccountantProjectResource`'s fourth key, the `project_finance` block, because a
     * finance-by-project report needs it. A ledger row does not, so it is not here.
     *
     * @return list<array{id: int, name: string, domain: string|null}>
     */
    private function projects(): array
    {
        return Project::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => IncomeResource::projectPayload($project))
            ->all();
    }

    /**
     * The month being read. `?month=YYYY-MM`, or the record's month, or this one.
     *
     * Anything unparseable falls back rather than throwing — it is a view parameter, not a
     * record, and a mistyped link should show a ledger (`Shared\AttendanceController` resolves
     * its month the same way).
     */
    private function month(Request $request, ?Carbon $fallback = null): Carbon
    {
        $value = trim((string) $request->query('month', ''));

        if ($value !== '') {
            try {
                return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
            } catch (\Throwable) {
                // fall through
            }
        }

        return ($fallback?->copy() ?? Carbon::today(config('app.timezone')))->startOfMonth();
    }

    /**
     * @return array<string, string>
     */
    private function monthPayload(Carbon $month): array
    {
        return [
            'value' => $month->format('Y-m'),
            'label' => $month->isoFormat('MMMM YYYY'),
            'previous' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'next' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
        ];
    }

    /**
     * Back to the month the record is IN, not the month the form was on.
     *
     * Moving an income from September to October would otherwise return to a list the row has
     * just left, and the person would watch their own edit vanish — `Admin\HolidayController`
     * learned this with a lunar date crossing a new year.
     */
    private function backToMonth(Carbon $date, string $message): RedirectResponse
    {
        return redirect()
            ->route('finance.income.index', ['month' => $date->format('Y-m')])
            ->with('success', $message);
    }

    /**
     * The amount in a flash sentence.
     *
     * The server formats money in exactly one place — this one — and the browser formats it in
     * exactly one place, `Components/Finance/finance.ts`. Both print two decimals and the
     * agency's currency, because a ledger where two screens round differently is a ledger
     * nobody trusts (decision 8-4).
     */
    private function money(string $amount): string
    {
        return $this->formatMoney($amount, (string) $this->settings->get('currency'));
    }

    /**
     * The server's one money formatter, and it is deliberately the same SHAPE as the browser's
     * (`Components/Finance/finance.ts`): the currency's symbol, grouped thousands, always two
     * decimals. A flash that said "USD 123.45" beside a table that said "$123.45" would be two
     * renderings of the same figure on one screen, which is the thing this slice spent its whole
     * design on not doing.
     *
     * `ext-intl` is installed by `deploy/install.sh` and present in development, but it is not a
     * declared requirement in `composer.json`, so an unknown code or a build without the
     * extension falls back to the ISO code and the grouped number rather than fataling inside a
     * success message.
     */
    private function formatMoney(string $amount, string $currency): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $formatted = (new \NumberFormatter('en_US', \NumberFormatter::CURRENCY))
                ->formatCurrency((float) $amount, $currency);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return sprintf('%s %s', $currency, number_format((float) $amount, 2));
    }
}
