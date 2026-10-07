<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\MeetingStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\ActionItemRequest;
use App\Http\Requests\Meeting\MeetingNotesRequest;
use App\Http\Requests\Meeting\RsvpRequest;
use App\Http\Resources\MeetingResource;
use App\Models\Employee;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\CalendarLink;
use App\Services\Calendar\ManualLink;
use App\Services\MeetingService;
use App\Services\TaskService;
use App\Support\Surface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One meeting: the join link, who is coming, what was said, what was settled, and what somebody
 * agreed to do about it (master prompt Part D §12, Phase 7).
 *
 * ## Shared, for the reason every other `Shared\*` controller is
 *
 * Whose meeting this is belongs to the **person** and not to the shell they happen to be in:
 * an Admin and an employee sit in the same room, and three copies of these five routes — one
 * per surface file — would have been three places for *"may this person see this meeting"* to
 * be answered differently. `Pages/Shared/Meetings/Show.vue` picks its layout from
 * `auth.user.surface`, exactly as `Profile`, `Attendance`, `Leave` and `Messages` do. The
 * Accountant never reaches any of it: the route group is gated on `meetings.use` and they hold
 * none of it, which is Part D §12's *"the Accountant has no meetings"* said as a capability
 * rather than as a role.
 *
 * ## 404 before 403, every time, on every one of the five
 *
 * Every id on this controller is resolved through `Meeting::visibleTo($user)->findOrFail()`
 * **before** any ability is asked. Part C is explicit about why the order matters:
 *
 *   - a meeting this person may not see is **404** — they must not learn it exists, and
 *     "forbidden" would tell them that a meeting with that id exists and that they were not
 *     invited, which is a fact about other people's calendars;
 *   - a meeting they can see but may not act on is **403** — the act is refused, not the
 *     record, and pretending it had vanished would be a lie to somebody looking straight at it.
 *
 * The 404 is what the lookup *does*, not a decision taken afterwards, which is what stops the
 * two from ever being swapped by an edit.
 *
 * ## What is on the payload beyond `MeetingResource`
 *
 * The resource is the other slice's and is the shared half — identity, times, state, the room,
 * this viewer's own answer, and the spread `linkedContextFor()` whose `project` and `task` keys
 * are **absent** rather than null for a viewer who may not have them. This controller adds the
 * five things only a detail screen needs: the pad (`notes`, `decisions`), the tasks the action
 * items became, the pre-formatted `when`, the RSVP vocabulary, and what the calendar driver can
 * and cannot do about a cancellation. Nothing here re-states a key the resource already owns.
 *
 * ## A cancelled meeting is read-only, and two of the three refusals come for free
 *
 * `MeetingPolicy::update()` and `::rsvp()` both answer `false` on a cancelled meeting, so the
 * notes endpoint and the RSVP endpoint refuse it without this controller saying anything.
 * `MeetingService::convertActionItem()` asks only `view`, because converting is a *task*
 * creation and the task side owns who may do it — so the "no new action items on a cancelled
 * meeting" half is asserted here, in `actionItem()`, and it is the one read-only rule this file
 * adds rather than inherits.
 */
class MeetingDetailController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly TaskService $tasks,
        private readonly CalendarLink $calendar,
    ) {}

    /**
     * The meeting.
     */
    public function show(Request $request, Meeting $meeting): Response
    {
        $meeting = $this->visible($request, $meeting);
        $user = $request->user();

        return Inertia::render('Shared/Meetings/Show', [
            'meeting' => $this->payload($request, $meeting),

            // The assignee picker for the convert control. A permission question, not a role
            // one: `assignableEmployees()` is everybody active whose role holds `tasks.view`,
            // so the Accountant is out of it without being named and a future role that can
            // hold tasks turns up without anybody editing this list.
            //
            // Absent — an empty list — when this viewer cannot convert anything anyway, so the
            // screen is never handed a picker for a form it does not draw.
            'assignable' => $this->convertRefusal($user, $meeting) === null ? $this->assignable() : [],
        ]);
    }

    /**
     * Call it off (Part D §12: *"status Cancelled, participants notified, linked tasks not
     * deleted"*).
     *
     * `Gate::authorize` first, so a second cancel is a **403** rather than a flash message:
     * `MeetingPolicy::cancel()` answers false for a meeting that is already cancelled, and
     * refusing an act that would fire a second round of notifications for no new fact is a
     * refusal, not a form error. The `MeetingStateException` catch below is the belt to that
     * braces — the service checks the same thing first and would otherwise surface as a 500.
     */
    public function cancel(Request $request, Meeting $meeting): RedirectResponse
    {
        $meeting = $this->visible($request, $meeting);

        Gate::authorize('cancel', $meeting);

        try {
            $this->meetings->cancel($request->user(), $meeting);
        } catch (MeetingStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Back to the meeting, which is still there. Part D §12 cancels a meeting; it does not
        // delete one, and the record of a call that was called off is the point.
        return back()->with('success', 'Meeting cancelled. Everybody in it has been told.');
    }

    /**
     * Answer the invitation — **your own, and only ever your own.**
     *
     * The subject is `$request->user()` and comes from the session. `RsvpRequest` prohibits a
     * `user_id` outright, and `MeetingPolicy::rsvp()` compares the subject against the actor a
     * third time inside the service. An Admin may edit and cancel anybody's meeting and may not
     * answer for them; nobody may.
     */
    public function rsvp(RsvpRequest $request, Meeting $meeting): RedirectResponse
    {
        $meeting = $this->visible($request, $meeting);
        $user = $request->user();

        // The ability takes the SUBJECT as well as the record, so this reads as the sentence it
        // enforces: may this person answer for this person.
        Gate::authorize('rsvp', [$meeting, $user]);

        $status = $request->status();

        $this->meetings->rsvp($user, $meeting, $status);

        return back()->with('success', sprintf('Your answer: %s.', $status->label()));
    }

    /**
     * Polish 030: the Join button's beacon. Marks this person Going (see
     * `MeetingService::joined()`) while the browser opens the meeting in a new tab. 204, so the
     * page that pressed it does not move.
     */
    public function join(Request $request, Meeting $meeting): HttpResponse
    {
        $meeting = $this->visible($request, $meeting);
        $user = $request->user();

        Gate::authorize('rsvp', [$meeting, $user]);

        $this->meetings->joined($user, $meeting);

        return response()->noContent();
    }

    /**
     * Write the meeting up. Two fields, one save — see `MeetingNotesRequest`.
     *
     * The `update` ability, asked here so the refusal is a 403 from the gate rather than an
     * exception caught out of the service. Who that is — the organiser or an Admin — is slice
     * 1's decision 7-15; everybody else reads the pad and cannot type into it, which is why the
     * screen renders it read-only rather than hiding what was written.
     */
    public function notes(MeetingNotesRequest $request, Meeting $meeting): RedirectResponse
    {
        $meeting = $this->visible($request, $meeting);

        Gate::authorize('update', $meeting);

        $this->meetings->recordNotes($request->user(), $meeting, $request->notes(), $request->decisions());

        return back()->with('success', 'Notes saved.');
    }

    /**
     * An action item becomes a task, in one move.
     *
     * Everything about *how* is `MeetingService::convertActionItem()`'s, which goes **through**
     * `TaskService::create()` rather than round it — so the new task gets its discussion
     * conversation, its board position, its audit row and its assignment notification like any
     * other, and carries `source_meeting_id` back here.
     *
     * Two things are decided in this method and nowhere else:
     *
     *   1. **A cancelled meeting takes no new action items.** The service asks only `view`,
     *      because converting is a task creation and `TaskPolicy::create` is the ability that
     *      matters; "this meeting is closed" is a fact about the meeting and is asserted here.
     *   2. **The task is born `todo`, not `backlog`.** An action item is something a room
     *      agreed somebody would do. `backlog` — `TaskService::create()`'s default — means
     *      "someday"; that is not what was agreed, and a task that arrives on the board already
     *      owing work is the honest reading of Part D's sentence.
     */
    public function actionItem(ActionItemRequest $request, Meeting $meeting): RedirectResponse
    {
        $meeting = $this->visible($request, $meeting);

        if ($meeting->isCancelled()) {
            throw new AuthorizationException('This meeting has been cancelled, so it takes no new action items.');
        }

        try {
            $task = $this->meetings->convertActionItem(
                $request->user(),
                $meeting,
                [
                    'title' => $request->title(),
                    'due_date' => $request->dueDate(),
                    'status' => 'todo',
                ],
                $request->assigneeIds(),
            );
        } catch (MeetingStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf('“%s” is now a task.', $task->title));
    }

    /*
    |--------------------------------------------------------------------------
    | The payload
    |--------------------------------------------------------------------------
    */

    /**
     * `MeetingResource` plus what only this screen needs.
     *
     * The resource is spread first and this controller's keys follow, so nothing here can
     * overwrite one of its — in particular not `project` or `task`, whose **absence** for a
     * viewer who may not see them is the whole privacy story of this slice. A key put back as
     * null would say *"there is a project here and you may not have it"*, which is the sentence
     * Part C exists to avoid.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, Meeting $meeting): array
    {
        $user = $request->user();
        $note = $meeting->note;
        $refusal = $this->convertRefusal($user, $meeting);

        return [
            ...(new MeetingResource($meeting))->resolve($request),

            // The pad. Null is right here and is not the Part C case: everybody who can open
            // the meeting can read its notes, and "nothing written yet" is a real state the
            // screen says out loud.
            'notes' => $note?->notes,
            'decisions' => $note?->decisions,
            'notes_updated_at' => $note?->updated_at?->toIso8601String(),

            // The tasks the action items became — **only the ones this viewer may see**. A
            // converted task on a project they are not on is absent from the list and from its
            // count, for the same reason its project's name is.
            'action_items' => $this->actionItems($request, $meeting),

            // One question, two keys: may they, and — when they may not — the sentence that
            // says why. Asked once; the screen never works either of them out from a role.
            'can_convert_action_items' => $refusal === null,
            'action_item_note' => $refusal,

            'calendar' => $this->calendarNotice($meeting),
        ];
    }

    /**
     * The tasks that came out of this meeting, scoped to what this viewer may see.
     *
     * A **stub** and deliberately not a `TaskResource`: the panel prints a title, where the
     * task has got to, when it is due and who has it, and then links to the task itself for
     * everything else. Sending a full task detail per row for a link that is one click away
     * would be a list payload pretending to be a detail payload, which is the distinction
     * `TaskResource`'s own `whenLoaded` blocks exist to keep.
     *
     * `href` is resolved against the **reader's own surface** — the same task is
     * `/admin/tasks/41` for Shahadat and `/employee/tasks/41` for Tapu — exactly as
     * `NotificationResource` resolves a deep link, and `Route::has()` guards it because a
     * surface does not necessarily have a screen for every kind of object.
     *
     * @return list<array<string, mixed>>
     */
    private function actionItems(Request $request, Meeting $meeting): array
    {
        $user = $request->user();

        $tasks = Task::query()
            ->visibleTo($user)
            ->where('source_meeting_id', $meeting->getKey())
            ->with(['assignees.user'])
            ->orderBy('id')
            ->get();

        $prefix = match ($user?->surface()) {
            Surface::Admin => 'admin',
            Surface::Employee => 'employee',
            default => null,
        };

        return $tasks->map(function (Task $task) use ($prefix): array {
            $route = $prefix === null ? null : $prefix.'.tasks.show';

            return [
                'id' => (int) $task->getKey(),
                'title' => (string) $task->title,
                'status' => $task->status?->value,
                'status_label' => $task->status?->label(),
                'status_tone' => $task->status?->tone(),
                'due_date' => $task->due_date?->toDateString(),
                'due_date_label' => $task->due_date?->format('j M Y'),
                'assignees' => $task->assignees
                    ->map(fn (Employee $employee): array => [
                        'id' => (int) $employee->getKey(),
                        'name' => $employee->user?->name ?? 'Unknown',
                    ])
                    ->values()
                    ->all(),
                'href' => $route !== null && RouteFacade::has($route) ? route($route, $task->getKey(), false) : null,
            ];
        })->values()->all();
    }

    /**
     * May this person turn an action item into a task, right now, on this meeting?
     *
     * Three conditions, and the third is the one that is easy to miss:
     *
     *   1. the meeting is not cancelled — a closed meeting takes no new work;
     *   2. **the meeting has a linked project this viewer may see.** `tasks.project_id` is NOT
     *      NULL, so a task has to go somewhere, and `convertActionItem()` puts it on the
     *      meeting's project or nowhere;
     *   3. they may create tasks at all (`TaskPolicy::create`, which is what the service asks
     *      through `TaskService::create()` — an employee who may see a meeting but may not
     *      create tasks cannot mint them here either).
     *
     * Condition 2 reads the **presence of the `project` key** from `linkedContextFor()` rather
     * than `$meeting->project_id`, and that is the Part C rule doing real work: a participant
     * who may not see the linked project is told exactly what somebody on a meeting with no
     * project at all is told. Two situations, one sentence, and no way to tell them apart —
     * which is the point, because the difference between them is the fact being withheld.
     *
     * ## It returns the SENTENCE, not a bare false, and the three are not interchangeable
     *
     * The first draft of this method answered `bool` and the screen printed one line under it.
     * That line then had to be true of all three refusals at once, so it said *"there is no
     * project here you can add to"* to a remote employee who was looking straight at the
     * project's name — the meeting was linked, he could see it, and what he could not do was
     * create tasks. A screen that explains a refusal with the wrong reason is worse than one
     * that gives none.
     *
     * So only condition 2's two halves share a sentence — *no project* and *a project you may
     * not see* — because telling those apart is exactly the fact Part C withholds. Conditions 1
     * and 3 get their own: *this meeting is closed* is a fact about the meeting they are already
     * reading, and *you do not create tasks here* is reached only by somebody who can already
     * see the project, so it is a fact about the reader alone. Neither tells anybody anything
     * they did not already have.
     *
     * Null means they may. Anything else is what the screen prints instead of the form.
     */
    private function convertRefusal(?User $user, Meeting $meeting): ?string
    {
        if ($meeting->isCancelled()) {
            return 'This meeting was cancelled, so nothing new can be added. The tasks it already produced are unaffected.';
        }

        // **This one is asked first, and the order is the privacy rule.** "Is there anywhere to
        // put it" is a fact about the meeting; "may you" is a fact about the reader. Asked the
        // other way round, somebody who holds neither would be told about the one that is safe
        // to say — and the conflated sentence, which is the whole defence, would then never be
        // reached by the very people it is there to protect.
        if ($user === null || ! array_key_exists('project', $this->meetings->linkedContextFor($user, $meeting))) {
            // The one sentence that has to cover two situations. See the docblock.
            return 'An action item becomes a task on the meeting\'s project, and there is none here you can add to.';
        }

        if (! Gate::forUser($user)->allows('create', Task::class)) {
            // Reached only by somebody who can already see the project, so this tells them
            // nothing about the meeting — only about themselves, which is safe to say plainly.
            return 'An action item becomes a task, and creating tasks is not something you do here.';
        }

        return null;
    }

    /**
     * The employees an action item may be given to: an id and a name, never an EmployeeResource.
     *
     * @return list<array{id: int, name: string}>
     */
    private function assignable(): array
    {
        return $this->tasks->assignableEmployees()
            ->map(fn (Employee $employee): array => [
                'id' => (int) $employee->getKey(),
                'name' => $employee->user?->name ?? 'Unknown',
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * What the calendar driver will and will not do when this meeting is called off.
     *
     * Under the manual driver a Meet room stays open until a human closes it, and
     * `MeetingService::cancel()` records that on the activity trail. The trail is a record, not
     * a warning: the person who has to go and do it is looking at the confirm dialog, so the
     * sentence is on the dialog too — and it is `ManualLink::CANCEL_INSTRUCTION`, the same
     * constant the service writes, rather than a second copy of the wording.
     *
     * **The driver is asked what it is, never asked to rehearse.** Calling `cancel()` here to
     * find out what it would say would, under the API driver, delete the Google event of a
     * meeting nobody has cancelled yet.
     *
     * @return array{creates_links: bool, cancel_notice: string|null}
     */
    private function calendarNotice(Meeting $meeting): array
    {
        $createsLinks = $this->calendar->createsLinksItself();

        // No link means no room standing open, so there is nothing for anybody to close — the
        // same condition `ManualLink::cancel()` applies before it says anything at all.
        $needsHand = ! $createsLinks && trim((string) $meeting->meet_link) !== '';

        return [
            'creates_links' => $createsLinks,
            'cancel_notice' => $needsHand ? ManualLink::CANCEL_INSTRUCTION : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * The meeting, **if this person may see it at all**, with what every one of the five
     * actions reads off it eager-loaded.
     *
     * Re-resolved through the scope rather than trusted from the route binding, so a meeting
     * this person is not in is *not found* rather than *forbidden*. See the class docblock.
     */
    private function visible(Request $request, Meeting $meeting): Meeting
    {
        $found = Meeting::query()
            ->visibleTo($request->user())
            // `participantSeats.user` and not `participants`: the RSVP is a fact about the
            // seat, and `MeetingResource::participants()` reads the seats — loading only the
            // seats would leave it lazy-loading a user per row.
            ->with(['organizer', 'participantSeats.user', 'project', 'task', 'note'])
            ->whereKey($meeting->getKey())
            ->first();

        if ($found === null) {
            throw new NotFoundHttpException;
        }

        return $found;
    }
}
