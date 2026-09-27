<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\Permission;
use App\Support\ReportFilter;
use App\Support\ReportGroup;
use App\Support\ReportKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Reports (master prompt Part D §15, Phase 10; report contract §5).
 *
 * Two routes and one page component for all sixteen reports. The index is the catalogue; the
 * show route is `ReportService` rendered by a screen that knows `ReportFormat` and nothing
 * else — it has no idea what a salary is, which is why a restricted column cannot be forgotten
 * once per screen.
 *
 * ## 403, 404 and the third answer
 *
 *   - **403** is about the asker. `/admin/reports` sits behind `surface:admin` like every other
 *     `/admin/*` route, so the Accountant is refused here before a query runs — their finance
 *     reporting is Phase 8's `/finance/report` on their own surface. And a report whose
 *     permission the viewer does not hold is 403 too: the permission is asked per report,
 *     because which permission applies depends on which report was asked for.
 *   - **404** is the route parameter. `{report}` binds to a `ReportKey`, so a value that is not
 *     a case — including one of the eight the next slice adds — is a 404 from the router rather
 *     than a 500 from a missing builder.
 *   - **Neither** is a filter id. An `?employee=`, `?project=` or `?client=` the viewer may not
 *     see is not an error: it is dropped into a query already scoped by the model's own
 *     `visibleTo()`, matches nothing, and the report is empty. A 404 there would confirm that
 *     the row exists (Part C §1).
 *
 * ## The filter options are scoped too
 *
 * The pickers the filter bar is built from are the viewer's own scopes — `Project::visibleTo()`,
 * `Employee::attendanceVisibleTo()`, and the client list only for a holder of
 * `clients.view_full`. A dropdown is a list, and a list that named a record the reader may not
 * open would leak exactly what the scopes are there to hide.
 *
 * No export control, not even a disabled one: spec §44 puts PDF and CSV in post-MVP Phase 2,
 * and a greyed-out button is a promise with a date nobody set.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly SettingsService $settings,
    ) {}

    /**
     * The catalogue — grouped cards, each a title, its question and the filters it accepts.
     *
     * Only the reports this viewer's permissions allow. No role is named: a card is on the
     * menu exactly when its data's permission is held, so the menu and the route agree by
     * construction rather than by two lists being kept in step.
     */
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        abort_if($viewer === null, 403);

        $available = $this->reports->catalogueFor($viewer);

        $groups = [];

        foreach (ReportGroup::inDisplayOrder() as $group) {
            $reports = array_values(array_map(
                fn (ReportKey $key): array => $key->toCard(),
                array_filter($available, fn (ReportKey $key): bool => $key->group() === $group),
            ));

            // A group with nothing in it for this viewer is absent, not an empty heading — an
            // empty heading is a list of what somebody else can see.
            if ($reports !== []) {
                $groups[] = [
                    'key' => $group->value,
                    'label' => $group->label(),
                    'reports' => $reports,
                ];
            }
        }

        return Inertia::render('Admin/Reports/Index', [
            'groups' => $groups,
        ]);
    }

    /**
     * One report, built and rendered.
     *
     * Thin like every controller here: resolve the viewer, ask the one permission, hand the
     * validated filters to the service, render. Every rule about what the numbers mean is
     * `ReportService`'s.
     */
    public function show(ReportRequest $request, ReportKey $report): Response
    {
        $viewer = $request->user();
        abort_if($viewer === null, 403);

        // The permission of the DATA, asked here because the route parameter is what decides
        // which one applies. A `can:` on the route could only name one key for all sixteen.
        abort_unless($this->reports->may($viewer, $report), 403);

        $filters = $request->filters($report);

        return Inertia::render('Admin/Reports/Show', [
            'report' => $report->toCard(),
            'filters' => $filters,
            'options' => $this->options($viewer, $report),
            'result' => $this->reports->build($report, $viewer, $filters),
            // Every `money` cell wears it (contract §3). It is the SETTING, not a constant, for
            // the same reason the payslip and the ledger read it from here: a report that
            // printed a hard-coded symbol would disagree with the Finance screens the day
            // somebody changes it.
            'currency' => (string) $this->settings->get('currency'),
        ]);
    }

    /**
     * What the filter bar may offer — each list the viewer's own scope, and each list absent
     * when this report does not accept that filter.
     *
     * @return array<string, mixed>
     */
    private function options(User $viewer, ReportKey $report): array
    {
        $options = [];

        if ($report->accepts(ReportFilter::Employee)) {
            $options['employees'] = Employee::query()
                ->attendanceVisibleTo($viewer)
                ->join('users', 'users.id', '=', 'employees.user_id')
                ->orderBy('users.name')
                ->get(['employees.id', 'users.name'])
                ->map(fn (Employee $employee): array => [
                    'id' => (int) $employee->getKey(),
                    'name' => (string) $employee->getAttribute('name'),
                ])
                ->all();
        }

        if ($report->accepts(ReportFilter::Project)) {
            $options['projects'] = Project::query()
                ->visibleTo($viewer)
                ->notArchived()
                ->orderBy('projects.name')
                ->get(['projects.id', 'projects.name'])
                ->map(fn (Project $project): array => [
                    'id' => (int) $project->getKey(),
                    'name' => (string) $project->name,
                ])
                ->all();
        }

        // The client picker is the one option that is a permission question rather than a
        // scope question: a viewer without `clients.view_full` gets no list at all, because
        // the names ARE the restricted field (Part C §2 gives them the domain instead).
        if ($report->accepts(ReportFilter::Client) && $viewer->hasPermission(Permission::ClientsViewFull)) {
            $options['clients'] = Client::query()
                ->whereHas('projects', fn (Builder $projects) => $projects->visibleTo($viewer))
                ->orderBy('clients.name')
                ->get(['clients.id', 'clients.name'])
                ->map(fn (Client $client): array => [
                    'id' => (int) $client->getKey(),
                    'name' => (string) $client->name,
                ])
                ->all();
        }

        return $options;
    }
}
