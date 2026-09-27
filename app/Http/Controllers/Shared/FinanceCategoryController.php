<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\FinanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceCategoryRequest;
use App\Http\Requests\Finance\UpdateFinanceCategoryRequest;
use App\Http\Resources\FinanceCategoryResource;
use App\Models\FinanceCategory;
use App\Services\FinanceService;
use App\Support\FinanceCategoryKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The category list: the names money is filed under, on both sides of the ledger (master prompt
 * Part D §13 — *"Categories (seeded, Admin-editable)"*).
 *
 * ## Two different keys, and neither is named as a role here
 *
 * **Reading is `finance.view`; writing is `settings.manage`** — decision 8-12. So an Accountant
 * opens this screen, sees both lists and has no controls, and an Admin sees the same screen
 * with an Add button and a menu on every row. That asymmetry is `FinanceCategoryPolicy`'s, and
 * this controller **asks it rather than restating it**: there is not one `if` about a role in
 * this file, and the screen draws every control from each row's own `permissions` block
 * (decisions 2-28, 2-31).
 *
 * The route group is gated on `can:finance.view` like the rest of the finance screens, which is
 * what refuses an Employee, a Remote employee and a Manager at the door. The narrower write key
 * is asked per act, inside.
 *
 * ## A category in use cannot be deleted, and that is a sentence
 *
 * `ON DELETE RESTRICT` is the promise; `FinanceService::deleteCategory()` reads the count first
 * and throws `FinanceStateException::categoryInUse()` so the refusal arrives as words with the
 * blast radius in them — *"still on 6 finance records"* — rather than as
 * `SQLSTATE[23503]` on a settings screen. This controller catches that and flashes it. The
 * screen also **does not draw the control** where it would fail: `in_use` is on every row of
 * this payload, which is what `loadCount()` below is for. Both halves matter — hiding the
 * control is the courtesy, and catching the exception is the correctness, because the count is
 * a race with anybody recording income in the same millisecond and the database is what wins
 * it.
 */
class FinanceCategoryController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    /**
     * Both sides, in picker order, with the blast radius on every row.
     *
     * The order is `FinanceService::categories()`'s and not this file's — it is the order every
     * picker offers them in, and a screen that sorted them its own way would be showing the
     * Admin a list in an order the forms do not use. `loadCount()` adds the usage counts in two
     * queries rather than one per row.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FinanceCategory::class);

        $user = $request->user();

        return Inertia::render('Shared/Finance/Categories', [
            'income' => $this->side($request, FinanceCategoryKind::Income),
            'expense' => $this->side($request, FinanceCategoryKind::Expense),

            // Whether there is an Add control at all. Per-row Rename and Remove come from each
            // row's own `permissions` block.
            'permissions' => [
                'can_create' => $user !== null
                    && Gate::forUser($user)->allows('create', FinanceCategory::class),
            ],
        ]);
    }

    public function store(StoreFinanceCategoryRequest $request): RedirectResponse
    {
        Gate::authorize('create', FinanceCategory::class);

        $category = $this->finance->createCategory(
            $request->user(),
            $request->kind(),
            $request->categoryName(),
        );

        return $this->back(sprintf(
            '“%s” added to the %s categories.',
            $category->name,
            $category->kind->label(),
        ));
    }

    /**
     * Rename one, or move it in the list.
     *
     * Not its side of the ledger: `UpdateFinanceCategoryRequest` does not accept a `kind` and
     * `FinanceService::updateCategory()` refuses one that differs, because reclassifying a
     * category in use would move every record under it to the other side of the ledger without
     * touching a single finance row.
     */
    public function update(UpdateFinanceCategoryRequest $request, FinanceCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $was = $category->name;

        try {
            $updated = $this->finance->updateCategory($request->user(), $category, $request->categoryAttributes());
        } catch (FinanceStateException $exception) {
            return $this->back(null, $exception->getMessage());
        }

        return $this->back($was === $updated->name
            ? sprintf('“%s” saved.', $updated->name)
            : sprintf('“%s” is now “%s”. Every record already filed under it moved with the name.', $was, $updated->name));
    }

    /**
     * Remove a category nothing is filed under. See the class note for the other case.
     */
    public function destroy(Request $request, FinanceCategory $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $name = $category->name;
        $kind = $category->kind;

        try {
            $this->finance->deleteCategory($request->user(), $category);
        } catch (FinanceStateException $exception) {
            return $this->back(null, $exception->getMessage());
        }

        return $this->back(sprintf('“%s” was deleted from the %s categories.', $name, $kind->label()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function side(Request $request, FinanceCategoryKind $kind): array
    {
        $categories = $this->finance->categories($kind)->loadCount(['income', 'expenses']);

        return FinanceCategoryResource::collection($categories)->resolve($request);
    }

    private function back(?string $success, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route('finance.categories.index');

        return $error === null
            ? $redirect->with('success', $success)
            : $redirect->with('error', $error);
    }
}
