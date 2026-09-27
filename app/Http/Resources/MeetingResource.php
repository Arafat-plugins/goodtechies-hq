<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\MeetingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The only way a meeting leaves the server (master prompt Part D §12, Phase 7).
 *
 * One shape for every screen in the phase — the list, the month grid, the week, the form and
 * the detail page — so that "what does a meeting look like" is answered once. The list slice
 * writes this file and the detail slice reads it; neither builds a second payload.
 *
 * ## `project` and `task` are SPREAD IN, and they are ABSENT rather than null
 *
 * **Read this before adding a field.** `MeetingService::linkedContextFor()` is the one method
 * that decides what a viewer may know about the linked project and task, and it answers by
 * *omitting keys*:
 *
 *     ...$meetings->linkedContextFor($viewer, $this->resource)
 *
 * The spread is **last** and neither key is ever declared above it. That ordering is the whole
 * mechanism, and it is fragile in one specific way:
 *
 * > A Resource that wrote `'project' => $this->whenLoaded('project')` and then merged the
 * > context on top would put the key straight back — as `null` for exactly the people it is
 * > meant to be hidden from.
 *
 * And a `null` is not a smaller version of the truth here, it is a different sentence. Somebody
 * can be a participant of a meeting **without being a member of its project**: Tapu is invited
 * to "Buffalo Modular — quarterly review" and is not on Buffalo Modular. `MeetingPolicy::view()`
 * lets him open the meeting; `ProjectPolicy::view()` refuses him the project. A `project: null`
 * in his payload would say *"there is a project here and you may not have it"*, which is
 * precisely the fact Part C exists to keep him from learning. So: absent.
 *
 * `MeetingEndpointsTest` and `MeetingPolicyTest` both assert that absence with
 * `assertJsonMissingPath` / `array_key_exists`, because `=== null` passes for the right answer
 * and the wrong one alike.
 *
 * ## `state` is `Meeting::tone()`, unchanged
 *
 * It is a `StatusKey` — `waiting` for a meeting still to come, `done` for one the clock has
 * passed, `cancelled` for one called off — so a screen hands it straight to `StatusBadge` and
 * no Vue file holds a second copy of that mapping (DESIGN.md §4.2). `state_label` is the word
 * printed beside it, because a tone alone is state carried by colour (§5.6).
 *
 * ## `permissions` is the policy's answer, resolved per requester
 *
 * Every screen renders it and none of them computes it. The endpoints ask the same policy again
 * before they act, so a stale payload buys nobody anything.
 *
 * @mixin Meeting
 */
class MeetingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();

        return [
            'id' => (int) $this->resource->getKey(),
            'title' => (string) $this->resource->title,
            'start_at' => $this->resource->start_at?->toIso8601String(),
            'end_at' => $this->resource->end_at?->toIso8601String(),
            'status' => $this->resource->status->value,
            'agenda' => $this->resource->agenda,

            // The join link itself, and the question a screen actually asks of it. Both, because
            // "is there a link" is what decides whether a Join control exists and a screen
            // should not have to know that an empty string is not a link.
            'meet_link' => $this->resource->meet_link,
            'has_meet_link' => $this->resource->meet_link !== null && trim($this->resource->meet_link) !== '',

            // `tone()` folds the clock into the status — see the model. Never recomputed in Vue.
            'state' => $this->resource->tone(),
            'state_label' => $this->resource->stateLabel(),
            'duration_minutes' => $this->resource->durationMinutes(),

            'organizer' => $this->person($this->resource->organizer),
            'is_organizer' => $viewer !== null && $this->resource->isOrganizer($viewer),
            'is_participant' => $viewer !== null && $this->resource->hasParticipant($viewer),

            'participants' => $this->participants(),
            'my_rsvp' => $viewer === null ? null : $this->resource->rsvpOf($viewer)?->value,

            'permissions' => [
                'can_update' => $viewer !== null && Gate::forUser($viewer)->allows('update', $this->resource),
                'can_cancel' => $viewer !== null && Gate::forUser($viewer)->allows('cancel', $this->resource),
                'can_rsvp' => $viewer !== null && Gate::forUser($viewer)->allows('rsvp', [$this->resource, $viewer]),
            ],

            // LAST, and spread. `project` and `task` appear only when this viewer may see them,
            // and are absent otherwise. Do not declare either key above this line — see the
            // class docblock for what that would cost.
            ...($viewer === null ? [] : app(MeetingService::class)->linkedContextFor($viewer, $this->resource)),
        ];
    }

    /**
     * Everybody in the room, with their answer.
     *
     * Read off `participantSeats` rather than `participants`, because the RSVP is a fact about
     * the seat and this is the relation `MeetingPolicy` already needs loaded — so a list of
     * fifty meetings costs one query for the seats and not fifty for the pivots.
     *
     * Id and name only. A picker is not a directory and neither is an attendee list: an email
     * address, a role or a status belongs to the Team directory, which has its own gate.
     *
     * @return list<array{id: int, name: string, rsvp: string}>
     */
    private function participants(): array
    {
        if (! $this->resource->relationLoaded('participantSeats')) {
            $this->resource->load('participantSeats.user');
        }

        return $this->resource->participantSeats
            ->map(fn (MeetingParticipant $seat): array => [
                'id' => (int) $seat->user_id,
                'name' => (string) ($seat->user?->name ?? 'Unknown'),
                'rsvp' => $seat->rsvp_status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int|null, name: string}
     */
    private function person(?User $user): array
    {
        return [
            'id' => $user === null ? null : (int) $user->getKey(),
            'name' => (string) ($user?->name ?? 'Unknown'),
        ];
    }
}
