<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Support\TagColour;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The tag-management endpoints' plumbing, shared by the two thin controllers that expose them.
 *
 * Two controllers because the people who manage tags live on two different shells: an Admin is
 * on the Admin surface and a Manager is on the Employee one. That is the same shape the task
 * write endpoints already have — "the moves the plan gives to ADMIN/MANAGER are routed here and
 * refused to an employee by the policy, not by being absent" — and it is the only shape that
 * lets a Manager do what the plan says they may. One trait, because they are the same four
 * operations either way.
 *
 * Nothing here decides anything. TagPolicy decides who, TagService does the write, the Form
 * Requests decide what the input may be.
 *
 * ## Why the list is JSON and not an Inertia page
 *
 * The management panel is a later brief's Vue file and does not exist yet. A page rendered now
 * would be a page name with nothing behind it, invented to be thrown away — so the list is the
 * JSON endpoint that panel will call, exactly as ManagesFiles does for the attachment panel and
 * the two Files tabs.
 */
trait ManagesTags
{
    /**
     * Every tag this requester may manage, with its project, its usage count and their own
     * permissions on it.
     */
    private function tagIndex(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tag::class);

        return response()->json([
            'tags' => TagResource::collection(
                $this->tags->manageable($request->user()),
            )->toArray($request),
            // What the colour picker may offer. Sent from the server so the list of tones and
            // the CHECK constraint behind them cannot drift apart in a hand-written Vue array.
            'colours' => $this->tagColours(),
        ]);
    }

    private function tagStore(StoreTagRequest $request): RedirectResponse
    {
        $tag = $this->tags->create(
            $request->user(),
            $request->name(),
            $request->colour(),
            $request->project(),
        );

        return back()->with('success', 'Tag created: '.$tag->name.'.');
    }

    private function tagUpdate(UpdateTagRequest $request, Tag $tag): RedirectResponse
    {
        $tag = $this->tags->update($request->user(), $this->visibleTag($request, $tag), $request->changes());

        return back()->with('success', 'Tag updated: '.$tag->name.'.');
    }

    /**
     * Remove a tag, including one that tasks are wearing. The flash names the blast radius,
     * because the caller has just changed records that are not on their screen.
     */
    private function tagDestroy(Request $request, Tag $tag): RedirectResponse
    {
        $tag = $this->visibleTag($request, $tag);
        $name = $tag->name;
        $count = $this->tags->delete($request->user(), $tag);

        return back()->with('success', $count === 0
            ? 'Tag deleted: '.$name.'.'
            : sprintf('Tag deleted: %s. It came off %d task%s.', $name, $count, $count === 1 ? '' : 's'));
    }

    /**
     * The tag, if this requester may see it at all.
     *
     * 404 and never 403, and the two are genuinely different here. A tag scoped to a project
     * the requester cannot see is a record that must read as ABSENT — its name names that
     * client's work. A tag they can see but may not manage is a different answer: the role
     * refusal comes from TagPolicy through TagService, as a 403, because by then nothing is
     * being revealed except what this person is allowed to do.
     *
     * @throws AuthorizationException
     */
    private function visibleTag(Request $request, Tag $tag): Tag
    {
        return Tag::query()
            ->visibleTo($request->user())
            ->with('project')
            ->whereKey($tag->getKey())
            ->firstOrFail();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function tagColours(): array
    {
        return array_map(
            fn (TagColour $colour): array => [
                'value' => $colour->value,
                'label' => $colour->label(),
            ],
            TagColour::cases(),
        );
    }
}
