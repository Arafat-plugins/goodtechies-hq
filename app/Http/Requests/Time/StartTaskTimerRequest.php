<?php

namespace App\Http\Requests\Time;

use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\AttendanceService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ▶ on a task card or in the drawer (flow F3, decision 12-73).
 *
 * ## Every refusal about the PERSON comes before any about the input
 *
 * `authorize()` runs before the rules, so the order of answers is:
 *
 *   1. **403** — may not run a task timer at all (`TimeEntryPolicy::trackTasks`: the Accountant).
 *   2. **404** — the task is not one they may see (`Task::visibleTo()`), so its existence is not
 *      confirmed (Part C §1).
 *   3. **403** — they may see it but may not time it (`TimeEntryPolicy::trackTask`): archived,
 *      or not assigned. An Admin or Manager may time any task they see (brief 021); an employee
 *      sees only their own, so for them this is the archived case.
 *   4. **422 `clock_in`** — an office employee or Admin who is not clocked in and did not ask to
 *      be. The screen answers this one with its confirm, "Clock in and start?", and sends the
 *      same request again with `clock_in: true`. It is validation rather than a flash because it
 *      is a question about THIS request's input: the same press with the box ticked succeeds.
 *
 * `client_uuid` is optional here, unlike the remote widget's start: the card mints one per press
 * so a retry lands on the entry it made, and a caller without one gets a server uuid.
 */
class StartTaskTimerRequest extends FormRequest
{
    private ?Task $resolvedTask = null;

    public function authorize(): bool
    {
        if (! Gate::allows('trackTasks', TimeEntry::class)) {
            return false;
        }

        return Gate::allows('trackTask', [TimeEntry::class, $this->task()]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['nullable', 'uuid'],
            'clock_in' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $employee = $this->user()?->employee;
                $attendance = app(AttendanceService::class);

                if ($employee === null || ! $attendance->clocks($employee) || $this->clockIn()) {
                    return;
                }

                if (! $attendance->isClockedIn($employee)) {
                    $validator->errors()->add('clock_in', 'You are not clocked in. Clock in to start a task timer.');
                }
            },
        ];
    }

    /**
     * The task, resolved through the visibility scope: one the requester may not see is 404.
     */
    public function task(): Task
    {
        if ($this->resolvedTask !== null) {
            return $this->resolvedTask;
        }

        $bound = $this->route('task');
        $id = $bound instanceof Task ? $bound->getKey() : $bound;

        $task = Task::query()
            ->visibleTo($this->user())
            ->notArchived()
            ->with('assignees:id')
            ->whereKey($id)
            ->first();

        if ($task === null) {
            throw new NotFoundHttpException;
        }

        return $this->resolvedTask = $task;
    }

    public function clientUuid(): ?string
    {
        $value = $this->validated('client_uuid');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function clockIn(): bool
    {
        return $this->boolean('clock_in');
    }
}
