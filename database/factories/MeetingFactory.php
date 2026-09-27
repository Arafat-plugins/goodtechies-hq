<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\MeetingStatus;
use App\Support\RsvpStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    /**
     * A half-hour meeting tomorrow morning, linked to nothing, with no Meet link.
     *
     * Linked to nothing on purpose: *"a meeting may link a project, a task, both or neither"*
     * is Part D's own line, and a factory whose default invented a project would make "neither"
     * the state nobody ever tested. `->forProject()` and `->forTask()` are one call away.
     *
     * The factory writes no participants either — not even the organiser. That is deliberate
     * and it is the one place this factory differs from what `MeetingService::schedule()`
     * produces: a test that wants a real room calls `->withParticipants()`, and a test that
     * wants to prove the SERVICE always seats the organiser must be able to build a meeting
     * that has not been through the service. Nothing in the application creates a meeting this
     * way.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::tomorrow()->setTime(10, 0);

        return [
            'title' => 'Weekly catch-up',
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(30),
            'project_id' => null,
            'task_id' => null,
            'google_event_id' => null,
            'meet_link' => null,
            'organizer_id' => User::factory(),
            'agenda' => null,
            'status' => MeetingStatus::Scheduled,
        ];
    }

    public function organisedBy(User $user): static
    {
        return $this->state(fn (): array => ['organizer_id' => $user->getKey()]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (): array => ['project_id' => $project->getKey()]);
    }

    public function forTask(Task $task): static
    {
        return $this->state(fn (): array => ['task_id' => $task->getKey()]);
    }

    /** A window. `end_at` follows `start_at` by 30 minutes unless the caller gives both. */
    public function at(CarbonInterface|string $start, CarbonInterface|string|null $end = null): static
    {
        return $this->state(function () use ($start, $end): array {
            $from = $start instanceof CarbonInterface ? Carbon::instance($start) : Carbon::parse($start);

            $to = match (true) {
                $end === null => $from->copy()->addMinutes(30),
                $end instanceof CarbonInterface => Carbon::instance($end),
                default => Carbon::parse($end),
            };

            return ['start_at' => $from, 'end_at' => $to];
        });
    }

    /** Starting this many minutes from now — the shape every reminder test wants. */
    public function startingInMinutes(int $minutes): static
    {
        return $this->at(Carbon::now()->addMinutes($minutes));
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => MeetingStatus::Cancelled]);
    }

    public function withMeetLink(string $link = 'https://meet.google.com/abc-defg-hij'): static
    {
        return $this->state(fn (): array => ['meet_link' => $link]);
    }

    /**
     * Seat these people, at `pending`, plus the organiser at `accepted` — the same arrangement
     * `MeetingService::schedule()` produces, for the tests that want a room without going
     * through the service to get one.
     *
     * @param  iterable<int, User>  $users
     */
    public function withParticipants(iterable $users, RsvpStatus $rsvp = RsvpStatus::Pending): static
    {
        return $this->afterCreating(function (Meeting $meeting) use ($users, $rsvp): void {
            MeetingParticipant::query()->firstOrCreate(
                ['meeting_id' => $meeting->getKey(), 'user_id' => $meeting->organizer_id],
                ['rsvp_status' => RsvpStatus::Accepted],
            );

            foreach ($users as $user) {
                MeetingParticipant::query()->firstOrCreate(
                    ['meeting_id' => $meeting->getKey(), 'user_id' => $user->getKey()],
                    ['rsvp_status' => $rsvp],
                );
            }
        });
    }
}
