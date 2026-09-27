<?php

namespace App\Http\Requests\Meeting;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\MeetLink;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Calling a meeting: the shape of what the organiser typed (master prompt Part D §12).
 *
 * ## The Meet link is checked by `MeetLink::looksValid()` and by nothing else
 *
 * That class says so in as many words — *"That slice must call `MeetLink::looksValid()` and
 * must not write a second regex"* — and the reason is that `MeetingService` checks the same
 * thing on the way to the column, for callers that never pass through HTTP at all (a seeder, a
 * console command, an import). One pattern, three callers, no drift.
 *
 * The one paste worth its own sentence is `https://meet.google.com/new`, because it is the
 * address the *Create Meet Link* button itself opens: somebody who pastes it back has pasted
 * the button rather than the room, and storing it would give every meeting in the agency the
 * same link to a different room each time it was clicked. `MeetLink` rejects it like any other
 * malformed value; this file is where that refusal gets told what to do about it.
 *
 * ## A linked project or task must be one the requester can already see
 *
 * `Rule::exists` alone would have been an oracle: an employee could discover which project ids
 * exist by watching which ones validated. So both are checked through `visibleTo()`, and the
 * refusal for "no such project" and for "not yours" is the **same sentence** — there is nothing
 * to learn from the difference (Part C).
 *
 * ## What is NOT here
 *
 * Authorization: `MeetingPolicy::create` / `::update`, asked in the controller and asked again
 * inside `MeetingService`. And the participant filtering — an invitee who is inactive or holds
 * no `meetings.use` is **dropped by the service**, not refused here, so that inviting the
 * Accountant is a meeting without the Accountant rather than a form error naming a role.
 */
class StoreMeetingRequest extends FormRequest
{
    /**
     * Blank text controls post `""`; the columns are nullable and mean "nothing here".
     */
    protected function prepareForValidation(): void
    {
        foreach (['agenda', 'meet_link', 'project_id', 'task_id'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],

            // `date` and not `date_format`: the form posts a `datetime-local` value
            // (`Y-m-d\TH:i`) and an API client may well send a full ISO string with an offset.
            // Both are real instants and Carbon reads both.
            'start_at' => ['required', 'date'],

            // `after`, not `after_or_equal`: a meeting of zero minutes is not a meeting, and the
            // database says so too (`meetings_end_after_start`). Refusing it here is what turns
            // a constraint violation into a field error.
            'end_at' => ['required', 'date', 'after:start_at'],

            'project_id' => ['nullable', 'integer', $this->linkedRule(Project::class)],
            'task_id' => ['nullable', 'integer', $this->linkedRule(Task::class)],

            'agenda' => ['nullable', 'string', 'max:5000'],

            'meet_link' => ['nullable', 'string', 'max:500', $this->meetLinkRule()],

            // Absent is not empty — see `participantIds()`.
            'participants' => ['sometimes', 'array', 'max:100'],
            'participants.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start_at' => 'start time',
            'end_at' => 'end time',
            'project_id' => 'linked project',
            'task_id' => 'linked task',
            'meet_link' => 'Meet link',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Give the meeting a title, so the people you invite know what it is.',
            'start_at.required' => 'Say when the meeting starts.',
            'end_at.required' => 'Say when the meeting ends.',
            'end_at.after' => 'The meeting has to end after it starts.',
            'participants.*.exists' => 'Pick the people from the list.',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What the controller reads
    |--------------------------------------------------------------------------
    */

    /**
     * The columns `MeetingService` may fill. `participants` is deliberately not among them.
     *
     * @return array<string, mixed>
     */
    public function attributesForMeeting(): array
    {
        $validated = $this->validated();

        return [
            'title' => trim((string) $validated['title']),
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'project_id' => $validated['project_id'] ?? null,
            'task_id' => $validated['task_id'] ?? null,
            'agenda' => $this->blankToNull($validated['agenda'] ?? null),
            'meet_link' => $this->blankToNull($validated['meet_link'] ?? null),
        ];
    }

    /**
     * Who to seat, besides the organiser.
     *
     * On a create, an absent key means *nobody but the organiser* — a meeting with one person
     * in it is a legitimate thing to book. On an **edit** the same absence means something
     * else entirely, which is why `UpdateMeetingRequest` overrides this rather than sharing it.
     *
     * @return list<int>|null
     */
    public function participantIds(): ?array
    {
        return array_values(array_map('intval', $this->validated()['participants'] ?? []));
    }

    /*
    |--------------------------------------------------------------------------
    | The two rules that are not one-liners
    |--------------------------------------------------------------------------
    */

    /**
     * A Meet link, as `MeetLink` defines one — with the button's own address named separately,
     * because it is the single likeliest wrong paste and "that is not a Meet link" would leave
     * somebody pasting it again.
     */
    private function meetLinkRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $url = trim((string) $value);

            if ($url === '') {
                return;
            }

            if ($url === MeetLink::NEW_MEETING_URL) {
                $fail(
                    'That is the address the Create Meet Link button opens, not a link to a room. '
                    .'Open it, start the meeting, then copy the link Google shows you and paste that here.'
                );

                return;
            }

            if (! MeetLink::looksValid($url)) {
                $fail('That does not look like a Google Meet link. It should look like https://meet.google.com/abc-defg-hij.');
            }
        };
    }

    /**
     * A project or a task the requester may already see.
     *
     * Both refusals — "no such record" and "not one of yours" — are the same sentence on
     * purpose: the difference between them is exactly the fact Part C does not let the
     * requester have.
     *
     * @param  class-string<Project|Task>  $model
     */
    private function linkedRule(string $model): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model): void {
            /** @var User|null $user */
            $user = $this->user();

            $visible = $user !== null
                && $model::query()->visibleTo($user)->whereKey((int) $value)->exists();

            if (! $visible) {
                $fail($model === Project::class
                    ? 'Pick a project from the list.'
                    : 'Pick a task from the list.');
            }
        };
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
