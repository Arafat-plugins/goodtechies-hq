<?php

namespace Database\Seeders;

use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use App\Services\MeetingService;
use App\Support\RsvpStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo meetings for the seeded dev/demo environment (master prompt Part D §12, Phase 7).
 *
 * ## Everything goes through `MeetingService`
 *
 * Not a single `Meeting::create()` here. The seeder is a caller like any other, so the organiser
 * gets their seat, the participants are filtered by `meetings.use`, the calendar driver has its
 * turn and the activity trail is written — which means the demo data looks like data the
 * application produced, because it is. `LeaveSeeder` made the same choice for the same reason;
 * `TaskSeeder` needs `withoutStatusGuard()` to paint a board, and nothing equivalent is needed
 * or wanted here.
 *
 * It is **idempotent**: every meeting is looked up by title first, and the times are recomputed
 * relative to today on every reseed. A demo whose "Upcoming meetings" card is empty a week
 * after seeding is not a demo. Nothing is updated on a meeting that already exists — a reseed
 * must not undo an RSVP somebody clicked while demonstrating.
 *
 * ## What the four meetings are for
 *
 *   1. **The acceptance walk.** Phase 7 is done when *"a client review meeting for Buffalo
 *      Modular is scheduled, gets a Meet link, reminder fires, 3 action items become tasks"*.
 *      That meeting is here, with a Meet link already pasted, so the walk starts from it.
 *   2. **The privacy case.** The Buffalo review is linked to the Buffalo SEO project and
 *      Yaseen — who is not on that project — is in the room. So the seeded database itself
 *      demonstrates the thing `MeetingService::linkedContextFor()` exists for: Yaseen opens the
 *      meeting and never learns the project's name. Without this row, the rule would be true
 *      only in the test suite.
 *   3. **A past meeting**, so a screen has something to show that `hasHappened()` is true of
 *      without a `completed` status existing anywhere.
 *   4. **A cancelled one**, so the cancelled tone has a row, and so "linked tasks survive"
 *      has something to be visibly true of.
 *
 * The Accountant is in none of them and is not filtered out here either — they are not in the
 * lists below at all, which is what *"the Accountant has no meetings"* looks like from the
 * seeder's side. Had somebody put them in, `MeetingService` would have dropped them.
 */
class MeetingSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(MeetingService::class);

        $shahadat = $this->user('GT-001');
        $faruk = $this->user('GT-002');
        $tapu = $this->user('GT-003');
        $yaseen = $this->user('GT-004');

        if ($shahadat === null) {
            return;
        }

        $seo = Project::query()->where('name', 'Buffalo Modular — SEO')->first();

        $today = Carbon::today();

        // 1 + 2. The acceptance walk's meeting, and the privacy case. Yaseen is in the room and
        // is NOT on the SEO project; Tapu is both.
        $this->schedule(
            $service,
            title: 'Buffalo Modular — quarterly review',
            organizer: $shahadat,
            participants: array_filter([$tapu, $yaseen, $faruk]),
            start: $today->copy()->addDay()->setTime(15, 0),
            minutes: 60,
            project: $seo,
            agenda: "Ranking movement since July\nContent plan for Q4\nBudget for the new landing pages",
            meetLink: 'https://meet.google.com/qkd-mprv-tza',
        );

        // A stand-up nobody has answered yet, close enough to today that the Upcoming card and
        // the week calendar both have something in them.
        $this->schedule(
            $service,
            title: 'Weekly team stand-up',
            organizer: $faruk ?? $shahadat,
            participants: array_filter([$shahadat, $tapu, $yaseen]),
            start: $today->copy()->addDays(2)->setTime(10, 0),
            minutes: 30,
            project: null,
            agenda: 'What everybody is on this week, and anything blocked.',
        );

        // 3. One that has already happened. Still `scheduled` — the status records whether it
        // was called off, and this one was not. Whether it has happened is `end_at`.
        $this->schedule(
            $service,
            title: 'Heat Gap — retainer kick-off',
            organizer: $shahadat,
            participants: array_filter([$tapu]),
            start: $today->copy()->subDays(3)->setTime(11, 0),
            minutes: 45,
            project: Project::query()->where('name', 'Heat Gap — SEO Retainer')->first(),
            agenda: 'Scope, reporting cadence, access to Search Console.',
        );

        // 4. One that was called off. Cancelled through the service, so the participants got
        // their notification and the activity trail carries the manual driver's sentence.
        $cancelled = $this->schedule(
            $service,
            title: 'APH — design walkthrough',
            organizer: $shahadat,
            participants: array_filter([$yaseen]),
            start: $today->copy()->addDays(4)->setTime(14, 0),
            minutes: 45,
            project: Project::query()->where('name', 'APH — Website Maintenance')->first(),
            agenda: 'Postponed; the client is re-doing the brief.',
            meetLink: 'https://meet.google.com/pwr-ktma-hzb',
        );

        if ($cancelled !== null && ! $cancelled->isCancelled() && $cancelled->wasRecentlyCreated) {
            $service->cancel($shahadat, $cancelled);
        }

        $this->recordAnswers();
    }

    /**
     * Create one meeting if it is not already there, and return it either way.
     *
     * `wasRecentlyCreated` is carried on the returned model so the caller can tell the two
     * apart — the cancellation above must run once, on the run that created the row, and not
     * every time somebody reseeds.
     *
     * @param  list<User>  $participants
     */
    private function schedule(
        MeetingService $service,
        string $title,
        User $organizer,
        array $participants,
        Carbon $start,
        int $minutes,
        ?Project $project,
        ?string $agenda = null,
        ?string $meetLink = null,
    ): ?Meeting {
        $existing = Meeting::query()->where('title', $title)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $service->schedule(
            $organizer,
            [
                'title' => $title,
                'start_at' => $start,
                'end_at' => $start->copy()->addMinutes($minutes),
                'project_id' => $project?->getKey(),
                'agenda' => $agenda,
                'meet_link' => $meetLink,
            ],
            array_map(fn (User $user): int => (int) $user->getKey(), $participants),
        );
    }

    /**
     * A couple of answered invitations, so the RSVP column is not a wall of "No answer yet".
     *
     * Written straight to the pivot rather than through `MeetingService::rsvp()`, and this is
     * the one deviation from "everything goes through the service" in this file: `rsvp()` asks
     * the policy about the CURRENT user, and a seeder has none. Answering on somebody's behalf
     * is the exact thing that method refuses to let anybody do, and it is right to refuse it —
     * so the seeder does not ask it to, it writes demo data.
     */
    private function recordAnswers(): void
    {
        $meeting = Meeting::query()->where('title', 'Buffalo Modular — quarterly review')->first();
        $tapu = $this->user('GT-003');

        if ($meeting === null || $tapu === null) {
            return;
        }

        $meeting->participantSeats()
            ->where('user_id', $tapu->getKey())
            ->where('rsvp_status', RsvpStatus::Pending->value)
            ->update(['rsvp_status' => RsvpStatus::Accepted->value, 'updated_at' => now()]);
    }

    private function user(string $employeeNumber): ?User
    {
        return User::query()
            ->whereHas('employee', fn ($employee) => $employee->where('employee_number', $employeeNumber))
            ->first();
    }
}
