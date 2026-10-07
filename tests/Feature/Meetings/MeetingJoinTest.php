<?php

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\MeetingService;
use App\Support\RsvpStatus;
use Illuminate\Support\Carbon;

/*
| Polish 030: pressing Join answers "Going", and a meeting that has ended reads "Completed".
*/

beforeEach(function () {
    $this->seed();

    Meeting::query()->delete();

    $this->shahadat = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $start = Carbon::now()->addHour()->startOfHour();

    $this->meeting = app(MeetingService::class)->schedule($this->shahadat, [
        'title' => 'Company',
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'meet_link' => 'https://meet.google.com/abc-defg-hij',
    ], [$this->faruk->id]);
});

function JOIN_seat(Meeting $meeting, User $user): ?MeetingParticipant
{
    return MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->first();
}

it('marks a participant who presses Join as Going', function () {
    expect(JOIN_seat($this->meeting, $this->faruk)->rsvp_status)->toBe(RsvpStatus::Pending);

    $this->actingAs($this->faruk)->post("/meetings/{$this->meeting->id}/join")->assertNoContent();

    expect(JOIN_seat($this->meeting, $this->faruk)->rsvp_status)->toBe(RsvpStatus::Accepted);
});

it('is absent for somebody not in the meeting', function () {
    $this->actingAs($this->yaseen)->post("/meetings/{$this->meeting->id}/join")->assertNotFound();
});

it('reads Completed once the meeting has ended', function () {
    $this->travelTo($this->meeting->end_at->copy()->addMinute());

    expect($this->meeting->fresh()->stateLabel())->toBe('Completed');
});
