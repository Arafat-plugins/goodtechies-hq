<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TaskService;
use App\Support\TaskBucket;
use App\Support\TrackingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The plan's five dashboard cards, in its order: My Tasks, Due Today, Overdue, In
     * Progress, Completed.
     *
     * @var list<TaskBucket>
     */
    private const CARDS = [
        TaskBucket::Open,
        TaskBucket::DueToday,
        TaskBucket::Overdue,
        TaskBucket::InProgress,
        TaskBucket::Completed,
    ];

    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Employee/Dashboard', [
            'greetingName' => Str::before(trim($user->name), ' '),
            'today' => now(config('app.timezone'))->toDateString(),
            'trackingMode' => ($user->employee?->tracking_mode ?? TrackingMode::None)->value,
            'taskStats' => $this->taskStats($request),
        ]);
    }

    /**
     * Five counts, five links, all from TaskService.
     *
     * Every number is its own COUNT scoped by `Task::visibleTo()` and narrowed by `mine` —
     * never derived in Vue from a list, because there is no list on this page to derive it
     * from and a card that counted one would be counting a page.
     *
     * Each card links to the bucket it counted on the My Tasks page, which is the screen that
     * defines those buckets. So the number and what you get when you click it are the same
     * query, asked twice: "Overdue: 4" opens those four.
     *
     * A person who may not view tasks at all (no such role holds this surface today, but the
     * gate is the rule, not the roster) gets no cards rather than five zeroes — zero is an
     * answer about work, and "you may not see this" is not that answer.
     *
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private function taskStats(Request $request): array
    {
        if (! Gate::forUser($request->user())->allows('viewAny', Task::class)) {
            return [];
        }

        $cards = $this->tasks->bucketCards($request->user(), self::CARDS, [
            'mine' => true,
            'as_of' => Carbon::today(),
        ]);

        return array_map(fn (array $card): array => [
            ...$card,
            'href' => $card['key'] === TaskBucket::Open->value
                ? '/employee/my-tasks'
                : '/employee/my-tasks?bucket='.$card['key'],
        ], $cards);
    }
}
