<?php

namespace App\Http\Controllers\Employee;

use App\Exceptions\TimerStateException;
use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\StoreTimeEntryRequest;
use App\Http\Requests\Time\UpdateTimeEntryRequest;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\TimerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The two writes that are not the timer: adding a stretch by hand, and correcting one.
 *
 * Both require a reason — the Form Requests enforce it — because both are a claim rather than a
 * measurement. An edit is additionally written to `audit_logs` with its old and new values, by
 * `TimerService::edit()`, whoever made it.
 *
 * ## The two refusals, and why they are different codes
 *
 * Not being allowed to use the timer at all is **403**: it is a fact about the requester, and an
 * office employee gets it from this controller as from every other timer endpoint.
 *
 * Somebody else's entry is **404**: `visibleEntry()` resolves through `TimeEntry::visibleTo()`
 * and `firstOrFail()`, so an id that is not theirs reads as absent. A 403 there would confirm
 * the record exists, which Part C §1 forbids.
 */
class TimeEntryController extends Controller
{
    use BuildsTimerState;

    public function __construct(private readonly TimerService $timer) {}

    /**
     * "Add manual entry" — start, end, task, reason.
     */
    public function store(StoreTimeEntryRequest $request): RedirectResponse
    {
        $employee = $this->employee($request);

        $task = Task::query()
            ->visibleTo($request->user())
            ->notArchived()
            ->whereKey($request->taskId())
            ->firstOrFail();

        try {
            $entry = $this->timer->manualEntry(
                $employee,
                $task,
                $request->startedAt(),
                $request->endedAt(),
                $request->reason(),
                $request->user(),
            );
        } catch (TimerStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $entry->awaitsApproval()
            ? 'Time added. It is waiting for approval before it counts toward the day.'
            : 'Time added.');
    }

    /**
     * Correcting the hours on an entry that has already been recorded.
     */
    public function update(UpdateTimeEntryRequest $request, TimeEntry $timeEntry): RedirectResponse
    {
        $entry = $this->visibleEntry($request, $timeEntry);

        // Per record, on the server. A running session is refused here — there is no agreed
        // ending to move yet, so it is stopped rather than edited.
        Gate::authorize('update', $entry);

        try {
            $this->timer->edit(
                $entry,
                $request->startedAt(),
                $request->endedAt(),
                $request->reason(),
                $request->user(),
            );
        } catch (TimerStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $entry->fresh()->awaitsApproval()
            ? 'Entry updated. It is waiting for approval before it counts toward the day.'
            : 'Entry updated.');
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        abort_if($employee === null, 403);

        return $employee;
    }
}
