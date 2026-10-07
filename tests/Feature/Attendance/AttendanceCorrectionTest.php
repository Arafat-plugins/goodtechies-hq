<?php

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AttendanceStatus;
use App\Support\AuditEvent;
use App\Support\NotificationType;
use Illuminate\Support\Carbon;

/*
| Polish 029: an employee asks for a day to be corrected (Late by mistake), an Admin approves
| or declines, and both sides are told.
*/

beforeEach(function () {
    $this->seed();

    TimeEntry::query()->delete();
    AttendanceRecord::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00'));

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    // Wednesday 7 Oct, Late at 9:24.
    $this->record = AttendanceRecord::factory()
        ->forEmployee($this->yaseen->employee)
        ->on(Carbon::parse('2026-10-07'))
        ->late()
        ->create(['clock_in' => '2026-10-07 09:24:00', 'clock_out' => null]);
});

afterEach(fn () => Carbon::setTestNow());

it('lets an employee ask for their Late day to be corrected, and tells the Admins', function () {
    $this->actingAs($this->yaseen)
        ->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'Clocked in late by mistake, I was here at 8:50.'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $correction = AttendanceCorrection::sole();

    expect($correction->status->value)->toBe('pending')
        ->and($correction->current_status)->toBe('late')
        ->and($correction->employee_id)->toBe($this->yaseen->employee->id);

    $row = Notification::where('user_id', $this->admin->id)
        ->where('type', NotificationType::AttendanceCorrectionRequested->value)
        ->sole();

    expect($row->summary())->toBe('Yaseen asked to correct 7 Oct (Late)');

    // The employee's month shows the request on that day.
    $props = $this->actingAs($this->yaseen)->get('/attendance')->assertOk()->inertiaProps();

    expect($props['corrections']['2026-10-07']['status'])->toBe('pending')
        ->and($props['permissions']['can_request_correction'])->toBeTrue();
});

it('refuses a second request while one waits, and days that are not Late, Half day or Absent', function () {
    $this->actingAs($this->yaseen)->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'First one']);

    $this->actingAs($this->yaseen)
        ->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'Second one'])
        ->assertSessionHas('error', 'A correction for that day is already waiting for an answer.');

    AttendanceRecord::factory()->forEmployee($this->yaseen->employee)->on(Carbon::parse('2026-10-06'))
        ->create(['status' => AttendanceStatus::Present, 'clock_in' => '2026-10-06 08:55:00']);

    $this->actingAs($this->yaseen)
        ->post('/attendance/corrections', ['date' => '2026-10-06', 'reason' => 'Nothing wrong here'])
        ->assertSessionHas('error', 'Only a Late, Half day or Absent day can be sent for correction.');

    $this->actingAs($this->yaseen)
        ->post('/attendance/corrections', ['date' => '2026-10-08', 'reason' => 'Tomorrow'])
        ->assertSessionHas('error', 'That day has not happened yet.');

    expect(AttendanceCorrection::count())->toBe(1);
});

it('needs a reason', function () {
    $this->actingAs($this->yaseen)
        ->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => ''])
        ->assertSessionHasErrors('reason');
});

it('does not let somebody the office clock does not track ask', function () {
    $this->actingAs($this->tapu)
        ->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'Not my clock'])
        ->assertForbidden();
});

it('approves: the day becomes Present with the same time, audited, and the employee is told', function () {
    $this->actingAs($this->yaseen)->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'Mistake']);
    $correction = AttendanceCorrection::sole();

    $this->actingAs($this->admin)
        ->post("/attendance/corrections/{$correction->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('success');

    $record = $this->record->fresh();

    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->clock_in->format('H:i'))->toBe('09:24')
        ->and($record->note)->toBe('Correction approved: Mistake')
        ->and($correction->fresh()->status->value)->toBe('approved')
        ->and($correction->fresh()->decided_by)->toBe($this->admin->id)
        ->and(AuditLog::where('event', AuditEvent::AttendanceEdited->value)->count())->toBe(1);

    $told = Notification::where('user_id', $this->yaseen->id)
        ->where('type', NotificationType::AttendanceCorrectionApproved->value)
        ->sole();

    expect($told->summary())->toBe('Your attendance correction for 7 Oct is approved');

    // The Admin's own request row is answered by deciding it.
    expect(Notification::where('user_id', $this->admin->id)
        ->where('type', NotificationType::AttendanceCorrectionRequested->value)
        ->sole()->resolved_at)->not->toBeNull();

    // Answered once only.
    $this->actingAs($this->admin)
        ->post("/attendance/corrections/{$correction->id}/approve")
        ->assertSessionHas('error', 'That request has already been answered.');
});

it('declines: the day stays Late, and the employee reads why', function () {
    $this->actingAs($this->yaseen)->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'Mistake']);
    $correction = AttendanceCorrection::sole();

    $this->actingAs($this->admin)
        ->post("/attendance/corrections/{$correction->id}/reject", ['note' => 'Door log says 9:24'])
        ->assertSessionHas('success');

    expect($this->record->fresh()->status)->toBe(AttendanceStatus::Late)
        ->and($correction->fresh()->status->value)->toBe('rejected');

    expect(Notification::where('user_id', $this->yaseen->id)
        ->where('type', NotificationType::AttendanceCorrectionRejected->value)
        ->sole()->summary())->toBe('Your attendance correction for 7 Oct was declined: Door log says 9:24');

    // A declined day can be asked about again.
    $this->actingAs($this->yaseen)
        ->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'Here is more detail'])
        ->assertSessionHas('success');
});

it('lists pending requests on the Admin roster', function () {
    $this->actingAs($this->yaseen)->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'Mistake']);

    $props = $this->actingAs($this->admin)->get('/admin/attendance')->assertOk()->inertiaProps();

    expect($props['corrections'])->toHaveCount(1)
        ->and($props['corrections'][0]['employee']['name'])->toBe('Yaseen')
        ->and($props['corrections'][0]['current_label'])->toBe('Late')
        ->and($props['corrections'][0]['reason'])->toBe('Mistake');
});

it('does not let an employee decide their own request', function () {
    $this->actingAs($this->yaseen)->post('/attendance/corrections', ['date' => '2026-10-07', 'reason' => 'Mistake']);
    $correction = AttendanceCorrection::sole();

    $this->actingAs($this->yaseen)
        ->post("/attendance/corrections/{$correction->id}/approve")
        ->assertForbidden();

    expect($this->record->fresh()->status)->toBe(AttendanceStatus::Late);
});
