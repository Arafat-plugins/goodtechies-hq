<?php

namespace App\Http\Controllers\Accountant;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountantProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Accountant's single window onto projects (master prompt Part D §13).
 *
 * > *"Implemented as a **dedicated finance-only endpoint** (`/accountant/projects` → id,
 * > project name, domain, `project_finance` fields, read-only — no client name or contacts,
 * > AC5) used by the income form's project picker and the finance-by-project report; the
 * > Accountant gets 403 on every ordinary project route."*
 *
 * One route, one verb, one serializer. There is no `show`, because a picker and a report both
 * read the list and a per-id route would be a second place for the key set to drift. There is
 * no write of any kind: *read-only* is Part D's word.
 *
 * ## It deliberately does NOT use `Project::visibleTo()`
 *
 * That scope asks for `projects.view`, which the Accountant holds none of, and returns nothing
 * for them — `tests/Feature/Privacy/ProjectPrivacyTest.php` asserts exactly that and it stays
 * true. Using it here would make this endpoint return an empty list, which is the same as not
 * building it.
 *
 * The apparent contradiction is the point of the slice. *"Can this person work on this
 * project?"* and *"can this person invoice for it?"* are different questions with different
 * answers, and conflating them is what the dedicated endpoint exists to stop. The Accountant
 * may **link September's $500 to the Buffalo Modular SEO project** while being unable to open
 * that project, see its client, read a task on it or learn that it has any. What stops the
 * second set of facts reaching them is not a scope on the query — it is
 * `AccountantProjectResource`, which names four keys and can carry nothing else.
 *
 * ## Which key gates it
 *
 * `projects.view_finance` — the matrix row this endpoint IS: *"View project finance
 * (price/profit): ACCOUNTANT 🟡 read-only, linked to invoicing"*. The route declares
 * `can:projects.view_finance`, which is the per-permission gate `AppServiceProvider` defines
 * for every key, so an Employee or a Remote employee is refused by the key and no role is named
 * anywhere. A future bookkeeper role reaches this endpoint by being granted that key.
 *
 * That gate is the **unscoped** key check, and it is not the same question as
 * `ProjectPolicy::viewFinance($user, $project)`, which additionally asks for the ADMIN role or
 * a per-project grant. The policy answers *"may you see the money on THIS project inside the
 * ordinary project screens"*; the gate answers *"does your job involve project money at all"*.
 * Part D §13 needed a dedicated endpoint precisely because the Accountant fails the first and
 * passes the second.
 *
 * ## Every project, including the archived ones
 *
 * No filter. Money arrives after work stops — a final invoice on a finished project is the
 * ordinary case, not the odd one — so a picker that hid archived projects would make the last
 * payment of every engagement impossible to file. Ordered by name, because that is how somebody
 * looking for one reads a list.
 *
 * ## JSON, not Inertia
 *
 * The picker fetches it and the report reads it; there is no page here. Phase 8's finance
 * screens are a later slice and they consume this, rather than this rendering them.
 */
class ProjectController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $projects = Project::query()
            // Eager loaded, so a list of forty projects is two queries rather than forty-one.
            ->with('finance')
            ->orderBy('name')
            ->get();

        return AccountantProjectResource::collection($projects)->response();
    }
}
