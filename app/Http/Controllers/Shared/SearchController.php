<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\SearchResultResource;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;

/**
 * `GET /search` — the one global search endpoint (master prompt Part D §17, Phase 10).
 *
 * ## Shared, and behind nothing but `auth`
 *
 * For the reason Messages, Leave, Attendance, Meetings and Finance are shared: what a person
 * can find is a fact about the PERSON, not about the shell they happen to be looking at, and
 * three copies of this route would be three places for "may this person find this row" to be
 * answered differently — with one of the three the copy nobody tested.
 *
 * And, unlike every other group in `routes/shared.php`, it carries **no `can:` gate**. That is
 * not an oversight and it is worth being explicit about, because every neighbouring group has
 * one: `can:messages.use`, `can:meetings.use`, `can:finance.view`, `can:payroll.view_own`.
 * Every signed-in role may search. What differs is what they find. Gating the route would
 * mean inventing a `search.use` key that every role holds, which is not a capability — it is a
 * key that says nothing.
 *
 * The scoping is therefore entirely `SearchService`'s, which is where it can be tested as one
 * thing. The Accountant reaching this route is correct: they get their finance records and an
 * empty set of everything else, and neither this controller nor that service says the word
 * "Accountant" to achieve it.
 *
 * ## JSON, not Inertia
 *
 * The palette fetches it on a keystroke. An Inertia visit would push a history entry per
 * letter typed. There is no `Pages/Shared/Search.vue` and there should not be one: the
 * command palette in `Components/Shell/GlobalSearch.vue` IS the search screen, which is what
 * its Phase 0.5 docblock said it would be.
 */
class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    /**
     * Grouped results for this viewer, or an empty envelope.
     *
     * The envelope keeps its shape whatever happens — a term of one character, a bare `@`, a
     * viewer who can see nothing — so the palette has one thing to render and never a special
     * case. `total` is a count of what is IN this response, which is the only count anybody is
     * entitled to: it is the size of the scoped result set, never the size of the match before
     * scoping, because no such number is ever computed (see `SearchService`).
     */
    public function index(SearchRequest $request): JsonResponse
    {
        $results = $this->search->search($request->user(), $request->term(), $request->types());

        return response()->json([
            'term' => $results['term'],
            'total' => $results['total'],

            // True when a cap bit, so the palette can say "showing the first 40" rather than
            // implying it showed everything. It is a fact about the RESPONSE, not about the
            // database: it never reveals how many more there were, because that number is not
            // computed either.
            'truncated' => $results['truncated'],

            'groups' => array_map(
                fn (array $group): array => [
                    'type' => $group['type'],
                    'label' => $group['label'],
                    'results' => SearchResultResource::collection($group['hits'])->resolve($request),
                ],
                $results['groups'],
            ),
        ]);
    }
}
