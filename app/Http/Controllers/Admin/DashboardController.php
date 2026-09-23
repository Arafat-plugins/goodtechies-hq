<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Task;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Support\TaskBucket;
use App\Support\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The four task questions the plan puts on the Company dashboard, each with the label it
     * uses there and the list it opens.
     *
     * These are the AGENCY's numbers, not this Admin's: no `mine` filter, so the scope is
     * whatever `Task::visibleTo()` gives them, which for an Admin is every task. Their own
     * plate is /admin/my-tasks, and that it is a different screen is the point — an Admin
     * opens this one to find out what is late across the agency.
     *
     * Each link carries the same `?bucket=` the count was made with, so "Overdue: 4" opens
     * exactly those four on the Tasks List. The fifth card, Active projects, is a question
     * about projects and is ProjectService's to answer.
     *
     * @var list<array{bucket: TaskBucket, label: string}>
     */
    private const TASK_CARDS = [
        ['bucket' => TaskBucket::DueToday, 'label' => 'Tasks due today'],
        ['bucket' => TaskBucket::Overdue, 'label' => 'Overdue'],
        ['bucket' => TaskBucket::InReview, 'label' => 'Awaiting review'],
        ['bucket' => TaskBucket::CompletedToday, 'label' => 'Completed today'],
    ];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ProjectService $projects,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'greetingName' => Str::before(trim($request->user()->name), ' '),
            'today' => now(config('app.timezone'))->toDateString(),
            'stats' => [
                'activeEmployees' => Employee::where('status', UserStatus::Active)->count(),
            ],
            'workStats' => $this->workStats($request),
        ]);
    }

    /**
     * The five task cards, counted in the database and each one clickable.
     *
     * Not one of them is computed in Vue and not one of them comes from a list: a paginated
     * list would under-count, and a number nobody can act on is a number that should not be
     * on a dashboard.
     *
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private function workStats(Request $request): array
    {
        $user = $request->user();

        if (! Gate::forUser($user)->allows('viewAny', Task::class)) {
            return [];
        }

        $counts = $this->tasks->bucketCounts(
            $user,
            array_map(fn (array $card): TaskBucket => $card['bucket'], self::TASK_CARDS),
            ['as_of' => Carbon::today()],
        );

        $cards = array_map(fn (array $card): array => [
            'key' => $card['bucket']->value,
            'label' => $card['label'],
            'count' => $counts[$card['bucket']->value],
            'href' => '/admin/tasks?bucket='.$card['bucket']->value,
        ], self::TASK_CARDS);

        // The fifth. `/admin/projects?status=active` applies the same two predicates
        // activeCount() does, so the card and the list it opens hold the same projects.
        $cards[] = [
            'key' => 'active_projects',
            'label' => 'Active projects',
            'count' => $this->projects->activeCount($user),
            'href' => '/admin/projects?status=active',
        ];

        return $cards;
    }
}
