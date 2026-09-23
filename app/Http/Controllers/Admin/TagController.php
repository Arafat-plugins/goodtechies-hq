<?php

namespace App\Http\Controllers\Admin;

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
 * Tag management on the Admin surface.
 *
 * The employee surface has the same four endpoints, for the Manager who lives there — see
 * App\Http\Controllers\Employee\TagController. Both are four lines over ManagesTags, so the
 * two surfaces cannot end up with different rules about what a tag is.
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
