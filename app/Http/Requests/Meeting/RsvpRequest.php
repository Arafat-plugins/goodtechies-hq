<?php

namespace App\Http\Requests\Meeting;

use App\Support\RsvpStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Answering an invitation: Going, Not going, or back to Undecided.
 *
 * ## `user_id` is **prohibited**, and that is the whole point of this class
 *
 * An RSVP is a statement about whether a named person will be somewhere, and there is no
 * seniority under which somebody else's attendance becomes your fact to assert —
 * `MeetingPolicy::rsvp()` says so by taking the subject as a second argument, and
 * `MeetingService::rsvp()` asks it that way so that *"a future endpoint accepting a `user_id`
 * cannot become a way to answer for somebody else"*.
 *
 * This is that endpoint, and it accepts no `user_id` at all. The subject is always
 * `$request->user()`, resolved in the controller from the session and never from the body.
 *
 * `prohibited` rather than "quietly ignored" is deliberate. A request carrying somebody else's
 * id is a request to do something this application does not do, and answering it by silently
 * recording the *sender's* attendance instead would be obeying a different instruction from the
 * one that was sent. So it is refused, in words, with nothing written — and
 * `MeetingDetailEndpointsTest` asserts that neither row moved.
 *
 * The prohibition is a second lock rather than the only one: even if it were removed tomorrow,
 * the controller passes `$request->user()` and the policy compares the subject against the
 * actor, so answering for somebody else would still be impossible. Three statements of one
 * rule, because this is the rule that would be expensive to get wrong.
 */
class RsvpRequest extends FormRequest
{
    /**
     * Authorization is the route's `can:meetings.use` gate and then `MeetingPolicy::rsvp`,
     * asked in the controller against a meeting that has already been resolved through
     * `Meeting::visibleTo()`. A Form Request cannot do that: it would have to resolve the
     * meeting itself, which is the lookup whose 404 is the privacy rule.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(RsvpStatus::class)],

            // See the class docblock. Not a field: a refusal.
            'user_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.prohibited' => 'You can only answer for yourself.',
        ];
    }

    public function status(): RsvpStatus
    {
        return RsvpStatus::from((string) $this->validated('status'));
    }
}
