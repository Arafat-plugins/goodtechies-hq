<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\ManagesTags;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use App\Models\Tag;
use App\Services\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tag management on the Employee surface — which is where the MANAGER is.
 *
 * The plan says "Admin/Manager create (global or per project); employees only assign existing
 * tags". A Manager never reaches the Admin shell, so routing tag management there alone would
 * have made half of that sentence unreachable. These endpoints exist here for the same reason
 * delete, archive and the review verdicts do: the refusal belongs to TagPolicy, not to the
 * route being absent. An employee who calls them is refused with a 403 about their role —
 * which is a fact about them, not about a record, so it is not a 404.
 *
 * Identical to App\Http\Controllers\Admin\TagController, four lines each over ManagesTags, so
 * the two surfaces cannot end up with different rules about what a tag is.
 */
class TagController extends Controller
{
    use ManagesTags;

    public function __construct(private readonly TagService $tags) {}

    public function index(Request $request): JsonResponse
    {
        return $this->tagIndex($request);
    }

    public function store(StoreTagRequest $request): RedirectResponse
    {
        return $this->tagStore($request);
    }

    public function update(UpdateTagRequest $request, Tag $tag): RedirectResponse
    {
        return $this->tagUpdate($request, $tag);
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        return $this->tagDestroy($request, $tag);
    }
}
