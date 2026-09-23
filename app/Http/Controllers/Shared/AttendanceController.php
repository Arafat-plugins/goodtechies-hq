<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\AttendanceStateException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Services\SettingsService;
use App\Support\AttendanceDay;
use App\Support\AttendanceStatus;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Somebody's attendance: the month page, and the clock.
 *
 * ## Why this is shared and not one controller per surface
 *
 * Both Admins clock in and out (Part D §8 is explicit — "office employees, incl. both Admins")
 * and so does Yaseen, and each of them has a self page. Clocking in is a fact about the person
 * and not about the shell they happened to open, exactly as a person's own mail is
 * (`NotificationController`) and their own profile is (`ProfileController`). Three copies of
 * these three routes would be three places for "whose day is this" to be answered differently,
 * and the page picks its layout from `auth.user.surface` the way `Pages/Shared/Profile.vue`
 * does.
 *
 * The Admin's roster and the edit control are **not** here. Reading the agency's morning and
 * correcting somebody's pay record are Admin acts on the Admin surface; see
 * `Admin\AttendanceController`.
 *
 * ## 404, not 403
 *
 * `GET /attendance/{employee?}` takes an id, and an employee asking for a colleague's gets a
 * **404**: the lookup goes through `Employee::attendanceVisibleTo()`, so the row is not found
 * and absence is the answer rather than a decision to refuse (Part C). An Admin sees everyone
 * and a Manager sees their own team, by the same scope and without this file naming a role.
 *
 * The 403s on this controller are the two that are about the requester rather than the record:
 * a signed-out visitor is sent to log in, and clocking in without
 * `attendance.view_own` — or as somebody the office clock does not track — is refused by
 * `AttendanceRecordPolicy::clock`.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly SettingsService $settings,
    ) {}

    /**
     * One employee's month. Defaults to the requester's own, which is what the sidebar's
     * *My Attendance* and *Attendance* rows point at.
     */
    public function show(Request $request, ?Employee $employee = null): Response
    {
        $subject = $this->visible($request, $employee);
        $month = $this->month($request);

        $days = $this->attendance->month($subject, $month);
        $today = $this->attendance->dayFor(
            $subject,
            Carbon::today(),
            AttendanceRecord::query()
                ->where('employee_id', $subject->getKey())
                ->forDate(Carbon::today())
                ->with('editor')
                ->first(),
        );

        $gate = Gate::forUser($request->user());

        return Inertia::render('Shared/Attendance', [
            'subject' => [
                'id' => $subject->getKey(),
                'name' => $subject->user?->name ?? 'Unknown',
                'is_self' => $request->user()?->employee?->getKey() === $subject->getKey(),
                'tracking_mode' => $subject->tracking_mode->value,
            ],

            // What makes a day an Off Day and an arrival Late, printed on the page so that a
            // status nobody expected can be read back to the rule that produced it instead of
            // looking like a bug.
            'schedule' => $subject->schedule === null ? null : [
                'working_days' => $subject->schedule->working_days,
                'working_hours_per_day' => (float) $subject->schedule->working_hours_per_day,
                'start_time' => $subject->schedule->start_time === null
                    ? null
                    : substr((string) $subject->schedule->start_time, 0, 5),
                'office_or_remote' => $subject->schedule->office_or_remote,
                'late_grace_minutes' => (int) $this->settings->get('late_grace_minutes'),
            ],

            'month' => $this->monthPayload($month),
            'days' => $days->map(fn (AttendanceDay $day): array => $day->toArray())->all(),
            'summary' => $this->summary($days),
            'today' => $today->toArray(),

            // The four statuses an edit may write, sent as data so the dialog's options and
            // `UpdateAttendanceRecordRequest`'s `Rule::in` are one list. Sent only to somebody
            // who may edit — a screen with no edit control has no use for them, and a payload
            // that carries what the reader cannot act on is a payload that invites a control
            // the server would refuse.
            'statuses' => Gate::forUser($request->user())->allows('update', [AttendanceRecord::class, $subject])
                ? array_map(fn (AttendanceStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'tone' => $status->tone(),
                ], AttendanceStatus::editable())
                : [],

            'permissions' => [
                // The clock-in widget exists because the TRACKING MODE says this person clocks
                // in, resolved on the server per record — never from a role in Vue
                // (decisions 2-28, 2-31).
                'can_clock' => $gate->allows('clock', [AttendanceRecord::class, $subject]),
                'can_edit' => $gate->allows('update', [AttendanceRecord::class, $subject]),
            ],
        ]);
    }

    /**
     * Clock in. The whole endpoint is a policy check, a service call and a sentence.
     *
     * A refusal from the service is a flash error and not a status code: the person holding
     * the phone at the office door needs to be told that they already clocked in at 08:58, and
     * an HTTP code is not that. Authorization refusals stay 403, because those are about who
     * is asking.
     */
    public function clockIn(Request $request): RedirectResponse
    {
        return $this->clock($request, fn (Employee $employee): AttendanceRecord => $this->attendance->clockIn($employee));
    }

    public function clockOut(Request $request): RedirectResponse
    {
        return $this->clock($request, fn (Employee $employee): AttendanceRecord => $this->attendance->clockOut($employee));
    }

    /**
     * @param  Closure(Employee): AttendanceRecord  $act
     */
    private function clock(Request $request, Closure $act): RedirectResponse
    {
        $employee = $request->user()?->employee;

        if ($employee === null) {
            throw new NotFoundHttpException;
        }

        Gate::authorize('clock', [AttendanceRecord::class, $employee]);

        try {
            /** @var AttendanceRecord $record */
            $record = $act($employee);
        } catch (AttendanceStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $out = $record->clock_out !== null;

        return back()->with('success', sprintf(
            '%s at %s. Today is %s.',
            $out ? 'Clocked out' : 'Clocked in',
            ($out ? $record->clock_out : $record->clock_in)?->format('H:i') ?? '—',
            $record->status->label(),
        ));
    }

    /**
     * The employee whose page this is, or 404.
     *
     * No parameter means "mine". A parameter means that employee, resolved through the
     * visibility scope — so Yaseen asking for Tapu's month gets absence, not a refusal, and
     * never learns whether the id existed.
     */
    private function visible(Request $request, ?Employee $employee): Employee
    {
        $user = $request->user();
        $subject = $employee ?? $user?->employee;

        if ($subject === null) {
            throw new NotFoundHttpException;
        }

        $visible = Employee::query()
            ->attendanceVisibleTo($user)
            ->with(['user', 'schedule'])
            ->whereKey($subject->getKey())
            ->first();

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }

    /**
     * The month being shown. `?month=YYYY-MM`, because which month you are looking at is
     * shareable and belongs in the URL (DESIGN.md §5.10). Anything unparseable falls back to
     * this month rather than throwing — a mistyped link should show a calendar.
     */
    private function month(Request $request): Carbon
    {
        $value = trim((string) $request->query('month', ''));

        if ($value === '') {
            return Carbon::today()->startOfMonth();
        }

        try {
            return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    /**
     * @return array<string, string>
     */
    private function monthPayload(Carbon $month): array
    {
        return [
            'value' => $month->format('Y-m'),
            'label' => $month->isoFormat('MMMM YYYY'),
            'previous' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'next' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
        ];
    }

    /**
     * How many days of the month wore each status.
     *
     * Counts of days, in Part D §8's order, and nothing else. There is no total, no percentage
     * and no comparison with anybody: this is a record of presence, not a rating (Part H §1).
     *
     * @param  Collection<int, AttendanceDay>  $days
     * @return list<array{key: string, label: string, tone: string, count: int}>
     */
    private function summary(Collection $days): array
    {
        $counts = $days->countBy(fn (AttendanceDay $day): string => $day->status?->value ?? 'none');

        return array_values(array_filter(
            array_map(fn (AttendanceStatus $status): array => [
                'key' => $status->value,
                'label' => $status->label(),
                'tone' => $status->tone(),
                'count' => (int) $counts->get($status->value, 0),
            ], AttendanceStatus::cases()),
            fn (array $row): bool => $row['count'] > 0,
        ));
    }
}
