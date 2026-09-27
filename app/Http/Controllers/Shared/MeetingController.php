<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\MeetingStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\StoreMeetingRequest;
use App\Http\Requests\Meeting\UpdateMeetingRequest;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\CalendarLink;
use App\Services\MeetingService;
use App\Support\Permission;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Meetings screen: the list, the month grid, the week, and the create/edit form
 * (master prompt Part D §12, Phase 7).
 *
 * ## Shared, not one copy per surface
 *
 * Whose calendar a meeting is on belongs to the **person**, not to the shell they are looking
 * at — the same reasoning that put `/messages`, `/notifications`, `/attendance` and `/leave` in
 * `routes/shared.php`. Three copies of these five routes would have been three places for "may
 * this person see this meeting" to be answered differently, and one of the three would have
 * been the copy nobody tested. `Pages/Shared/Meetings/*.vue` pick their layout from
 * `auth.user.surface`, so an Admin gets `AdminLayout` and an employee `EmployeeLayout` from one
 * page. The Accountant never arrives: the whole group is gated on `meetings.use` and they hold
 * none, which is Part D §12's *"the Accountant has no meetings"* said as a capability rather
 * than as a role.
 *
 * ## 404 or 403
 *
 *   - **403** on every route here for somebody who may not use meetings at all — the route
 *     exists and their answer to it is no. That is the group's `can:meetings.use`.
 *   - **404** for a meeting this person may not see. `visible()` resolves every id through
 *     `Meeting::visibleTo()` *before* any policy is asked, so the 404 is what the lookup does
 *     rather than a decision this controller takes, and a stranger never learns the id exists.
 *   - **403** for a meeting they can see and may not edit — a participant who is not the
 *     organiser. Nothing is hidden from them; the act is refused, not the record.
 *
 * ## The date window is the server's, and it is a window
 *
 * `Meeting::overlapping()` bounds every calendar query, so the month grid fetches a month's
 * worth of rows and the week fetches a week's. **A calendar page that loaded every meeting ever
 * is the defect this is written against** — it is invisible in a seeded database of nine
 * meetings and fatal in a year-old one. The List is bounded too, by a cap per bucket rather
 * than by dates, and it says when it has hit one.
 *
 * Which view you are on, which month, and which filters, all live in the **URL** — so a month
 * is bookmarkable, the back button works, and a link to "the week of the 21st" is a link
 * (DESIGN.md §5.10, the same reason the Tasks view switcher is links).
 */
class MeetingController extends Controller
{
    /** The three views, and the one a missing or unknown `?view=` falls back to. */
    private const VIEWS = ['list', 'month', 'week'];

    /**
     * How many meetings the List loads per bucket before it says there are more.
     *
     * The List has no date window — "my next meetings" is not a month — so it is bounded by a
     * count instead. Fifty is well past what anybody scrolls and far short of a table scan.
     */
    private const LIST_PER_BUCKET = 50;

    public function __construct(private readonly MeetingService $meetings) {}

    /*
    |--------------------------------------------------------------------------
    | The screen
    |--------------------------------------------------------------------------
    */

    /**
     * One page, three views, all of it in the query string.
     *
     * A hand-edited or stale `?view=`, `?month=` or `?week=` **falls back** rather than 422s: a
     * bookmark that has gone stale is not an error screen, which is the same call
     * `MessageController::index()` makes for a conversation id somebody no longer has. There is
     * nothing here that can be "backwards" the way a two-ended task-calendar window can, so
     * there is nothing worth naming as a mistake.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('viewAny', Meeting::class);

        $view = $this->requestedView($request);
        $scope = $request->string('scope')->toString() === 'all' ? 'all' : 'mine';
        $when = in_array($request->string('when')->toString(), ['upcoming', 'past'], true)
            ? $request->string('when')->toString()
            : 'all';

        $window = $view === 'list' ? null : $this->window($view, $request);

        // Is there anything this person can see that is not already theirs? If not, `mine` and
        // `all` are the same set — `Meeting::visibleTo()` has already narrowed it to exactly
        // the meetings they organise or attend — so the scope clause would be that same
        // predicate applied a second time, on every calendar query they ever run. One EXISTS
        // answers it, and the answer is also what decides whether the filter is drawn at all.
        $seesOthers = $this->seesOthersMeetings($user);
        $effective = $seesOthers ? $scope : 'all';

        return Inertia::render('Shared/Meetings/Index', [
            'view' => $view,
            'filters' => ['scope' => $scope, 'when' => $when],

            // Whether the Mine / Everyone chip is worth drawing at all: it is, only for
            // somebody who can see meetings that are not their own. Asked as a question about
            // the data rather than about a role — "is there anything this filter would change"
            // — so no controller here names ADMIN to decide what to render.
            'scopeOffered' => $seesOthers,

            'window' => $window === null ? null : $this->windowPayload($view, $window),

            // Month and week: the days in the window that have anything on them. The grid draws
            // the empty ones itself — it knows the shape of a month, and a payload with
            // thirty-one keys in it would say nothing the window does not already say.
            'days' => $view === 'list'
                ? []
                : $this->daysPayload($request, $this->windowed($user, $effective, $window), $window['from']),

            // The List: upcoming first, then past, each already grouped by day.
            'sections' => $view === 'list' ? $this->listPayload($request, $user, $effective, $when) : [],

            // "Nothing in September" and "nothing, ever" are different sentences and the screen
            // says whichever is true. One EXISTS query answers it.
            'hasAny' => Meeting::query()->visibleTo($user)->exists(),

            'permissions' => ['can_create' => Gate::allows('create', Meeting::class)],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The form
    |--------------------------------------------------------------------------
    */

    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('create', Meeting::class);

        $start = Carbon::now()->addHour()->startOfHour();

        return Inertia::render('Shared/Meetings/Form', [
            ...$this->formOptions($user, $user),
            'meeting' => null,
            'initial' => [
                'title' => '',
                'start_at' => $start->format('Y-m-d\TH:i'),
                'end_at' => $start->copy()->addMinutes(30)->format('Y-m-d\TH:i'),
                'project_id' => $this->requestedLink($request, 'project_id', Project::class, $user),
                'task_id' => $this->requestedLink($request, 'task_id', Task::class, $user),
                'agenda' => '',
                'meet_link' => '',
                'participants' => [],
            ],
        ]);
    }

    public function store(StoreMeetingRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('create', Meeting::class);

        $meeting = $this->meetings->schedule(
            $user,
            $request->attributesForMeeting(),
            $request->participantIds() ?? [],
        );

        return redirect()
            ->route('meetings.index')
            ->with('success', sprintf('“%s” is on the calendar.', $meeting->title));
    }

    /**
     * The edit form for a meeting this person may see **and** may edit.
     *
     * The two are asked separately and answer differently on purpose: `visible()` 404s a
     * meeting they may not see, then `Gate::authorize` 403s one they may see and may not edit.
     * A participant who is not the organiser gets the second.
     */
    public function edit(Request $request, Meeting $meeting): Response
    {
        /** @var User $user */
        $user = $request->user();

        $meeting = $this->visible($user, $meeting);

        Gate::authorize('update', $meeting);

        $organizer = $meeting->organizer ?? $user;

        return Inertia::render('Shared/Meetings/Form', [
            ...$this->formOptions($user, $organizer),
            'meeting' => (new MeetingResource($meeting))->toArray($request),
            'initial' => [
                'title' => (string) $meeting->title,
                // Formatted here rather than in the browser: `datetime-local` wants a local
                // wall-clock string and the ISO instant in the payload is not one. Doing it on
                // the server keeps the form in the application's timezone whatever the viewer's
                // laptop is set to.
                'start_at' => $meeting->start_at?->format('Y-m-d\TH:i') ?? '',
                'end_at' => $meeting->end_at?->format('Y-m-d\TH:i') ?? '',

                // The ids, not the names — and only when this viewer may see them. Somebody
                // editing a meeting they may not see the project of keeps the link by leaving
                // the field alone; what they must not get is the id of a project they cannot
                // open (Part C).
                'project_id' => $this->visibleLinkId($user, Project::class, $meeting->project_id),
                'task_id' => $this->visibleLinkId($user, Task::class, $meeting->task_id),

                'agenda' => (string) ($meeting->agenda ?? ''),
                'meet_link' => (string) ($meeting->meet_link ?? ''),
                'participants' => $meeting->participantSeats
                    ->pluck('user_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->reject(fn (int $id): bool => $id === (int) $organizer->getKey())
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function update(UpdateMeetingRequest $request, Meeting $meeting): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $meeting = $this->visible($user, $meeting);

        Gate::authorize('update', $meeting);

        try {
            $this->meetings->update(
                $user,
                $meeting,
                $request->attributesForMeeting(),
                $request->participantIds(),
            );
        } catch (MeetingStateException $refused) {
            // A meeting cancelled between loading the form and saving it. The policy already
            // refuses an edit on a cancelled meeting, so this is the race and not the rule —
            // and a sentence about what happened beats a 403 on a form somebody just filled in.
            return back()->with('error', $refused->getMessage());
        }

        return redirect()
            ->route('meetings.index')
            ->with('success', sprintf('“%s” has been updated.', $meeting->title));
    }

    /*
    |--------------------------------------------------------------------------
    | The window
    |--------------------------------------------------------------------------
    */

    /**
     * The half-open window a calendar view is asking for: `[from, to)`.
     *
     * **The month view's window is the GRID's span, not the month's** — Monday of the week the
     * 1st falls in, to the Sunday after the last. A month grid draws the last few days of the
     * previous month and the first few of the next in its corner cells, and a payload clipped
     * to the month itself would have drawn those cells empty, which is a calendar quietly
     * lying. It is at most 42 days either way.
     *
     * @return array{from: Carbon, to: Carbon, anchor: Carbon}
     */
    private function window(string $view, Request $request): array
    {
        if ($view === 'week') {
            $anchor = $this->parsedDay($request->string('week')->toString()) ?? Carbon::now();
            $from = $anchor->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

            return ['from' => $from, 'to' => $from->copy()->addDays(7), 'anchor' => $from];
        }

        $anchor = $this->parsedMonth($request->string('month')->toString()) ?? Carbon::now()->startOfMonth();
        $from = $anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $to = $anchor->copy()->endOfMonth()->startOfDay()->addDay()->startOfWeek(Carbon::MONDAY)->addDays(7);

        return ['from' => $from, 'to' => $to, 'anchor' => $anchor->copy()->startOfMonth()];
    }

    /**
     * @param  array{from: Carbon, to: Carbon, anchor: Carbon}  $window
     * @return array<string, mixed>
     */
    private function windowPayload(string $view, array $window): array
    {
        $anchor = $window['anchor'];
        $today = Carbon::now();

        if ($view === 'week') {
            $last = $anchor->copy()->addDays(6);

            return [
                'from' => $window['from']->toDateString(),
                'to' => $window['to']->copy()->subDay()->toDateString(),
                'label' => $anchor->format('j M').' – '.$last->format('j M Y'),
                'key' => $anchor->toDateString(),
                'previous' => $anchor->copy()->subWeek()->toDateString(),
                'next' => $anchor->copy()->addWeek()->toDateString(),
                'current' => $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),

                // Today's date, so a cell knows it is today even when nothing is on it. It
                // cannot be read off `days`, which only carries the days that have meetings —
                // the bug this line exists to have fixed.
                'today' => $today->toDateString(),
                // The seven days this view draws, so the grid never does date arithmetic of
                // its own and cannot disagree with the query that filled it.
                'days' => collect(range(0, 6))
                    ->map(fn (int $offset): string => $anchor->copy()->addDays($offset)->toDateString())
                    ->all(),
            ];
        }

        return [
            'from' => $window['from']->toDateString(),
            'to' => $window['to']->copy()->subDay()->toDateString(),
            'label' => $anchor->format('F Y'),
            'key' => $anchor->format('Y-m'),
            'previous' => $anchor->copy()->subMonthNoOverflow()->format('Y-m'),
            'next' => $anchor->copy()->addMonthNoOverflow()->format('Y-m'),
            'current' => $today->format('Y-m'),
            'today' => $today->toDateString(),
            'days' => collect(range(0, (int) $window['from']->diffInDays($window['to']) - 1))
                ->map(fn (int $offset): string => $window['from']->copy()->addDays($offset)->toDateString())
                ->all(),
            'month_from' => $anchor->toDateString(),
            'month_to' => $anchor->copy()->endOfMonth()->toDateString(),
        ];
    }

    private function parsedMonth(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', $value.'-01') ?: null;
    }

    private function parsedDay(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', $value) ?: null;
    }

    private function requestedView(Request $request): string
    {
        $view = $request->string('view')->toString();

        return in_array($view, self::VIEWS, true) ? $view : 'list';
    }

    /*
    |--------------------------------------------------------------------------
    | The queries
    |--------------------------------------------------------------------------
    */

    /**
     * Every meeting this person may see, narrowed to the ones they are in when the scope says
     * so, with everything the payload needs already loaded.
     *
     * The eager loads are not an optimisation, they are the difference between one query and
     * one per row: `MeetingPolicy::view()` reads `participantSeats` and
     * `MeetingResource::permissions` asks it once per meeting.
     *
     * @return Builder<Meeting>
     */
    private function base(User $user, string $scope): Builder
    {
        $query = Meeting::query()
            ->visibleTo($user)
            ->with(['organizer', 'participantSeats.user', 'project', 'task']);

        if ($scope === 'mine') {
            $id = (int) $user->getKey();

            $query->where(fn (Builder $mine) => $mine
                ->where('organizer_id', $id)
                ->orWhereHas('participantSeats', fn (Builder $seat) => $seat->where('user_id', $id)));
        }

        return $query;
    }

    /**
     * The calendar's query: **`overlapping()` and nothing else unbounded.**
     *
     * @param  array{from: Carbon, to: Carbon, anchor: Carbon}  $window
     * @return EloquentCollection<int, Meeting>
     */
    private function windowed(User $user, string $scope, array $window): EloquentCollection
    {
        return $this->base($user, $scope)
            ->overlapping($window['from'], $window['to'])
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Is there a meeting this person can see that is neither theirs to run nor theirs to
     * attend? If not, a Mine / Everyone filter would be a control that does nothing.
     */
    private function seesOthersMeetings(User $user): bool
    {
        $id = (int) $user->getKey();

        return Meeting::query()
            ->visibleTo($user)
            ->where('organizer_id', '!=', $id)
            ->whereDoesntHave('participantSeats', fn (Builder $seat) => $seat->where('user_id', $id))
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Shaping
    |--------------------------------------------------------------------------
    */

    /**
     * Group meetings under the day they start on.
     *
     * A meeting that started before the window opens is filed on the window's first day rather
     * than dropped: it is genuinely on that screen, `overlapping()` fetched it for exactly that
     * reason, and a day cell that omitted it would be the calendar disagreeing with itself.
     *
     * @param  EloquentCollection<int, Meeting>  $meetings
     * @return list<array<string, mixed>>
     */
    private function daysPayload(Request $request, EloquentCollection $meetings, ?Carbon $floor = null): array
    {
        $today = Carbon::now()->toDateString();

        return $meetings
            ->groupBy(function (Meeting $meeting) use ($floor): string {
                $start = $meeting->start_at;

                if ($floor !== null && $start !== null && $start->lessThan($floor)) {
                    return $floor->toDateString();
                }

                return $start?->toDateString() ?? '';
            })
            ->map(fn (SupportCollection $onTheDay, string $date): array => [
                'date' => $date,
                'label' => $this->dayLabel($date),
                'is_today' => $date === $today,
                'meetings' => MeetingResource::collection($onTheDay)->toArray($request),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * The List: what is coming, then what has been.
     *
     * Two bounded queries rather than one unbounded one. `end_at` is the divider and not
     * `start_at`, so a meeting that is running right now is *upcoming* — which is where
     * somebody looking for the Join button expects to find it.
     *
     * @return list<array<string, mixed>>
     */
    private function listPayload(Request $request, User $user, string $scope, string $when): array
    {
        $now = Carbon::now();
        $sections = [];

        if ($when !== 'past') {
            $upcoming = $this->base($user, $scope)
                ->where('end_at', '>=', $now)
                ->orderBy('start_at')
                ->orderBy('id')
                ->limit(self::LIST_PER_BUCKET + 1)
                ->get();

            $sections[] = [
                'key' => 'upcoming',
                'label' => 'Upcoming',
                'has_more' => $upcoming->count() > self::LIST_PER_BUCKET,
                'days' => $this->daysPayload($request, $upcoming->take(self::LIST_PER_BUCKET)),
            ];
        }

        if ($when !== 'upcoming') {
            $past = $this->base($user, $scope)
                ->where('end_at', '<', $now)
                ->orderByDesc('start_at')
                ->orderByDesc('id')
                ->limit(self::LIST_PER_BUCKET + 1)
                ->get();

            $sections[] = [
                'key' => 'past',
                'label' => 'Past',
                'has_more' => $past->count() > self::LIST_PER_BUCKET,
                // Newest first, which is the direction somebody reads a history in.
                'days' => array_reverse($this->daysPayload($request, $past->take(self::LIST_PER_BUCKET))),
            ];
        }

        return $sections;
    }

    private function dayLabel(string $date): string
    {
        $day = Carbon::createFromFormat('!Y-m-d', $date);
        $today = Carbon::now()->startOfDay();

        return match ($day->toDateString()) {
            $today->toDateString() => 'Today',
            $today->copy()->addDay()->toDateString() => 'Tomorrow',
            $today->copy()->subDay()->toDateString() => 'Yesterday',
            default => $day->format('l j F Y'),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | The form's option lists
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the form picks from, and nothing it does not.
     *
     * The lists are **props on the page**, exactly as the Messages page gets its `people` — no
     * lookup endpoint is invented for a picker, and nothing is hard-coded in Vue.
     *
     * @return array<string, mixed>
     */
    private function formOptions(User $user, User $organizer): array
    {
        return [
            'organizer' => ['id' => (int) $organizer->getKey(), 'name' => (string) $organizer->name],

            // Active people holding `meetings.use`, by id and name and nothing else: a picker
            // is not a directory. Asked as a permission, so the Accountant is absent from it
            // without this file naming a role — and `MeetingService::syncParticipants()` drops
            // them again on the way in, so a hand-posted id buys nothing either.
            'people' => User::query()
                ->where('status', UserStatus::Active->value)
                ->orderBy('name')
                ->get()
                ->filter(fn (User $person): bool => $person->hasPermission(Permission::MeetingsUse))
                ->reject(fn (User $person): bool => (int) $person->getKey() === (int) $organizer->getKey())
                ->map(fn (User $person): array => ['id' => (int) $person->getKey(), 'name' => (string) $person->name])
                ->values()
                ->all(),

            // Only what this person may see — `visibleTo()`, never the whole table. The Form
            // Request checks the same scope again, so a hand-posted id is refused rather than
            // silently linked.
            'projects' => Project::query()
                ->visibleTo($user)
                ->notArchived()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Project $project): array => [
                    'id' => (int) $project->getKey(),
                    'name' => (string) $project->name,
                ])
                ->all(),

            'tasks' => Task::query()
                ->visibleTo($user)
                ->notArchived()
                ->orderBy('title')
                ->limit(300)
                ->get(['id', 'title'])
                ->map(fn (Task $task): array => [
                    'id' => (int) $task->getKey(),
                    'name' => (string) $task->title,
                ])
                ->all(),

            // The driver answers whether there is a *Create Meet Link* button at all, and what
            // it opens. No Vue file holds a Google URL, and on the API driver — where the link
            // arrives with the event — the button simply is not there.
            'calendar' => [
                'driver' => app(CalendarLink::class)->name(),
                'creates_itself' => app(CalendarLink::class)->createsLinksItself(),
                'start_url' => app(CalendarLink::class)->startUrl(),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Resolving an id
    |--------------------------------------------------------------------------
    */

    /**
     * The meeting this id names, through the visibility scope — so one this person may not see
     * is **absent** rather than refused, and they never learn whether the id exists (Part C).
     */
    private function visible(User $user, Meeting $meeting): Meeting
    {
        $found = Meeting::query()
            ->visibleTo($user)
            ->with(['organizer', 'participantSeats.user', 'project', 'task'])
            ->whereKey($meeting->getKey())
            ->first();

        if ($found === null) {
            throw new NotFoundHttpException;
        }

        return $found;
    }

    /**
     * A stored link id, but only when this viewer may see the thing it points at.
     *
     * @param  class-string<Project|Task>  $model
     */
    private function visibleLinkId(User $user, string $model, ?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return $model::query()->visibleTo($user)->whereKey($id)->exists() ? $id : null;
    }

    /**
     * A `?project_id=` or `?task_id=` carried onto a blank form — from a project page's
     * *Schedule a meeting*, say. Validated the same way the POST will be, so a hand-edited
     * query string pre-fills nothing.
     *
     * @param  class-string<Project|Task>  $model
     */
    private function requestedLink(Request $request, string $key, string $model, User $user): ?int
    {
        $value = $request->integer($key);

        return $value > 0 ? $this->visibleLinkId($user, $model, $value) : null;
    }
}
