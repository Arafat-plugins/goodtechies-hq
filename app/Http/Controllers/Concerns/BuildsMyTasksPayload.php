<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\TaskResource;
use App\Support\TaskBucket;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The My Tasks page's payload, built once for both surfaces.
 *
 * My Tasks is not the Tasks List with a filter pre-applied. The List answers "show me the
 * work" and wears a chip bar to narrow it; this answers "what is on my plate", which is a
 * question with exactly seven shapes and no filters at all. The screen is a strip of seven
 * counts over the tasks in whichever one you asked for.
 *
 * "My" is the signed-in person, whatever their role. An Admin's Task::visibleTo() is the whole
 * agency, so the narrowing is the `mine` filter — see TaskService::onlyMine() — and an Admin
 * therefore gets their own plate here and everybody's on /admin/tasks, which is the point of
 * having both screens.
 *
 * The using class supplies `private readonly TaskService $tasks` through its constructor, the
 * way every other controller here takes its service.
 */
trait BuildsMyTasksPayload
{
    /** How many of a bucket's tasks the page lists. Beyond this, the Tasks List is the tool. */
    private const MY_TASKS_LIMIT = 100;

    /**
     * The bucket asked for, or the umbrella one.
     *
     * An unknown `?bucket=` falls back rather than 404s: it is a question that used to exist
     * or never did, and the useful answer to "what is on my plate" is the plate.
     */
    private function myBucket(Request $request): TaskBucket
    {
        return TaskBucket::tryFrom((string) $request->query('bucket', '')) ?? TaskBucket::Open;
    }

    /**
     * Everything the page draws: the seven counts, which one is open, and its tasks.
     *
     * Every count is its own query through TaskService, scoped by Task::visibleTo() and
     * narrowed to this person — never a length taken from the list below, which is capped and
     * would therefore under-report the moment somebody had more than a hundred of anything.
     *
     * @return array<string, mixed>
     */
    private function myTasksPayload(Request $request, string $base): array
    {
        $user = $request->user();
        $asOf = Carbon::today();
        $current = $this->myBucket($request);

        $tasks = $this->tasks
            ->query($user, ['mine' => true, 'bucket' => $current->value, 'as_of' => $asOf])
            ->limit(self::MY_TASKS_LIMIT)
            ->get();

        return [
            'buckets' => array_map(fn (array $bucket): array => [
                ...$bucket,
                // A number that is not clickable is a number nobody can act on. The umbrella
                // bucket is the bare page, so the strip's first card is also the way back out
                // of any other one.
                'href' => $bucket['key'] === TaskBucket::Open->value ? $base : $base.'?bucket='.$bucket['key'],
            ], $this->tasks->bucketCards($user, TaskBucket::myTasks(), ['mine' => true, 'as_of' => $asOf])),
            'bucket' => $current->value,
            'tasks' => TaskResource::collection($tasks)->toArray($request),
            // What the list is showing, against what the card claims — they differ only when
            // somebody has more than MY_TASKS_LIMIT in one bucket, and the screen says so
            // rather than letting the two numbers silently disagree.
            'limit' => self::MY_TASKS_LIMIT,
            'today' => $asOf->toDateString(),
        ];
    }
}
