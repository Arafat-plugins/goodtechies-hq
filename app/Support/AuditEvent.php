<?php

namespace App\Support;

/**
 * Every event written to audit_logs. Written only through App\Services\AuditLogger.
 */
enum AuditEvent: string
{
    case UserLogin = 'user.login';
    case RoleChanged = 'role.changed';
    case PermissionChanged = 'permission.changed';
    case ConfigurationChanged = 'configuration.changed';
    case EmployeeCreated = 'employee.created';
    case EmployeeDeactivated = 'employee.deactivated';
    // The inverse, and its own case rather than a second `employee.deactivated` row carrying
    // the opposite values. Part C §4 does not name it because spec §47 only describes the
    // departure — but somebody's login being switched back ON is the act an auditor asks about
    // first, and a reader filtering this log must be able to ask "who was let back in" without
    // reading the values of every deactivation row to find the ones that were really the
    // reverse. It carries old and new status exactly as the deactivation does.
    case EmployeeReactivated = 'employee.reactivated';
    // Part C §4 names "role changed" and stops there, and that is an omission rather than a
    // decision: `employees.tracking_mode` is the field that decides whether somebody clocks in
    // at the office, runs the remote timer, or is measured by neither — so it decides which
    // table their working day is recorded in, whether `hq:mark-absent` can ever mark them, and
    // which hours Phase 9 pays them for. It is the same shape of act as `schedule.changed`, one
    // step further up: a schedule says which days count, this says whether any of them are
    // counted at all. So it is recorded with old and new values, by the same logger.
    //
    // Its own case rather than a second `role.changed` row, because the two are separate fields
    // with separate endpoints and separate rules — Part D §1 is explicit that tracking mode "is
    // a per-employee field, not a role rule" — and a reader asking "who stopped being clocked
    // in" must not have to read the values of every role change to find out.
    case EmployeeTrackingModeChanged = 'employee.tracking_mode_changed';
    // An Admin re-issuing somebody's sign-in password.
    //
    // Its own case rather than a `role.changed`-style config row, because it is the one event
    // here that hands a working credential to a human being: the audit row is how you find out
    // afterwards WHO could have signed in as somebody else, and when. The password itself is
    // never in it — `old_value` is null, because a hash is not a before-value anybody should be
    // shown, and there is nothing about the new one worth recording except that it happened.
    case EmployeePasswordReset = 'employee.password_reset';
    // Somebody choosing a new password through the emailed reset link. The actor is the user
    // themselves; as above, no password or hash is ever in the row.
    case PasswordResetByEmail = 'user.password_reset_by_email';
    case ProjectCreated = 'project.created';
    case ProjectPriceChanged = 'project.price_changed';
    // An archived project deleted for good. The row and its activity timeline go with it, so
    // this is the only record left that it ever existed.
    case ProjectDeleted = 'project.deleted';
    case TaskAssigned = 'task.assigned';
    case TaskReassigned = 'task.reassigned';
    case TaskDeleted = 'task.deleted';
    case TaskStatusChanged = 'task.status_changed';
    // Uploading and replacing are recorded on the owning record's timeline (activity_logs),
    // the way a checklist item or a link is. Deleting is destructive and irreversible, so it
    // goes here as well — the same split TaskDeleted sits on.
    case FileDeleted = 'file.deleted';
    // Creating and renaming a tag are recorded on the tag's own timeline (activity_logs), the
    // way a checklist item is. Deleting one is neither — it silently takes a label off every
    // task that was wearing it, and those tasks belong to other people — so it goes here, with
    // the ids of the tasks it touched, where nobody can tidy it away afterwards.
    case TagDeleted = 'tag.deleted';
    // Editing a time entry is the one write on `time_entries` that changes what a day already
    // said. Starting, pausing and stopping a timer record what happened and are the employee's
    // own timeline; changing the hours afterwards — by the employee with a reason, or by an
    // Admin on somebody else's day — is a correction to a record payroll will read, so it goes
    // here with the old and the new values. The two watchdog rules write their own entries and
    // are not edits: they carry their reason on the row itself.
    case TimeEntryEdited = 'time_entry.edited';
    // Signing off hours, and refusing them. Both are recorded for the same reason the edit above
    // is: an approval is what turns a claimed afternoon into hours Phase 9 pays, and a refusal
    // is what takes somebody's afternoon back out of their total. Each carries old and new
    // values, so a reader can see what the row said before the decision and what it says after
    // — including the refusal's reason, which is the whole of the case for it.
    //
    // The SYSTEM's approval of an auto entry at stop is deliberately NOT here. That is the timer
    // recording its own measurement, not a person ruling on a claim, and a row per stop would
    // be one log entry per session recording that the software worked.
    case TimeEntryApproved = 'time_entry.approved';
    case TimeEntryRejected = 'time_entry.rejected';
    // Part C §4 does not name attendance, and that is an omission rather than a decision: the
    // list it gives is "events that must be recorded", and an attendance record is what Phase 9
    // pays somebody from. An Admin correcting one is the same shape of act as changing a salary
    // — it moves money, quietly, on somebody else's record — so it is recorded with the reason
    // the Form Request required and with old and new values.
    //
    // A clock-in is NOT here. It is the employee's own record of their own day, made by the
    // person it is about, and the row itself is the evidence; an audit entry per clock-in would
    // be one row per person per day recording that the system worked.
    case AttendanceEdited = 'attendance.edited';
    // Changing somebody's schedule changes what counts as Late and which days the absent sweep
    // will mark for every day after it — so it is a configuration change about one person, and
    // it goes in the log for the same reason `configuration.changed` does.
    case ScheduleChanged = 'schedule.changed';
    case LeaveApproved = 'leave.approved';
    case LeaveRejected = 'leave.rejected';
    // The applicant taking their own request back (decision 5-19). Its own case and not a
    // `leave.rejected` row, because WHO ended a request is the whole of what an auditor reads
    // here: a rejection is a decision about somebody, and a withdrawal is that somebody changing
    // their mind. Recorded with old and new values like the other two, even though nothing was
    // granted and no balance moved — a week disappearing off the leave calendar has to have a row
    // saying who removed it, and the applicant is not an approver, so `approver_id` stays as it
    // was and the `actor` on the audit row is the only name in it.
    case LeaveWithdrawn = 'leave.withdrawn';
    // Part C §4 names leave approved and rejected and stops there. Adjusting a BALANCE is
    // recorded as well, and Part D §9 is what asks for it: "no accrual logic in MVP — Admin
    // adjusts balances, **audit-logged**". It is the same shape of act as `attendance.edited`
    // — one person quietly changing a number on somebody else's record that decides what they
    // are allowed later — so it carries the reason the Form Request required and old and new
    // values. Approving a request decrements the same number and is NOT logged twice: the
    // `leave.approved` row already says which request spent the days, and a second row per
    // approval would be the log recording that the software worked.
    case LeaveBalanceAdjusted = 'leave.balance_adjusted';
    case SalaryChanged = 'salary.changed';
    // Polish 002: an Admin removes a salary row entered by mistake. A hard delete, so the
    // audit row's `old` value is the whole of what the row said.
    case SalaryDeleted = 'salary.deleted';
    case PayrollApproved = 'payroll.approved';
    // Polish 005: a draft moved to the month it actually pays for (old and new month).
    case PayrollMonthChanged = 'payroll.month_changed';
    case PayrollLockReversed = 'payroll.lock_reversed';
    case ExpenseCreated = 'expense.created';
    case ExpenseEdited = 'expense.edited';
    // Part C §4's list names "expense created" and "expense edited" and stops there, and that
    // is an omission rather than a decision: Part D §13 says "Accountant edits are audit-logged
    // with old/new values" without distinguishing the two sides, and an income is the side the
    // client's revenue reports are built from. A ledger that records every payment out and only
    // the deletion of a payment in is not a ledger anybody would sign off.
    //
    // Two cases rather than one `finance.record_created`, so a reader filtering the log can ask
    // about revenue without reading every row — the same reason the expense pair is two cases
    // and not one.
    case IncomeCreated = 'income.created';
    case IncomeEdited = 'income.edited';
    // Both sides, one event, exactly as Part C §4 names it. What was deleted is in the audit
    // row's old value, in full: `income` and `expenses` are HARD deletes and this row is the
    // only thing left of the one that went. See Income::auditValues().
    case FinanceRecordDeleted = 'finance.record_deleted';
    case RestrictedAccessAttempt = 'access.restricted_attempt';
    case TwoFactorDisabled = 'user.two_factor_disabled';
    // 12-79: an author edits or deletes-for-everyone their own message. The old body (and, on
    // delete, the attachment file ids) live in the old value — the only copy left of it.
    case MessageEdited = 'message.edited';
    case MessageDeleted = 'message.deleted';
    case AttendanceClockedIn = 'attendance.clocked_in';
    case AttendanceClockedOut = 'attendance.clocked_out';
    // 12-81: a holder of `messages.manage` creates a chat group, renames it or changes its
    // picture, or adds / removes members.
    case GroupCreated = 'message_group.created';
    case GroupUpdated = 'message_group.updated';
    case GroupMembersChanged = 'message_group.members_changed';

    /*
    |--------------------------------------------------------------------------
    | Reading the log (Phase 12 — Admin → Audit Log)
    |--------------------------------------------------------------------------
    |
    | Eleven phases wrote to this table before anything read it, so until now a case needed
    | nothing but its value. A viewer needs two more things from each one, and both belong here
    | rather than in a Vue lookup or a controller array: the value is written into an indexed
    | string column with **no CHECK behind it**, so the enum is the only place that knows what a
    | value means, and a second copy of that mapping in TypeScript would drift the first time a
    | phase added an event.
    |
    | `label()` is what a reader sees instead of `payroll.lock_reversed`. `group()` is which of
    | the nine families it belongs to, which is what makes a filter over thirty-five events
    | scannable — the picker lists them family by family instead of alphabetically, so "the money
    | ones" is one glance rather than a memory test.
    |
    | The two `…For(string)` statics are the important half. A row written by an older build can
    | carry an event string this enum no longer has a case for, and the viewer's job is to render
    | that row, not to crash on it: both fall back to humanising the raw string and to the
    | `GROUP_UNRECOGNISED` family, which is a fact about the row worth showing rather than an
    | error worth raising.
    */

    /** Signing in, signing in as somebody else, and being refused. */
    public const GROUP_ACCESS = 'Access';

    /** What somebody is allowed to reach. */
    public const GROUP_PERMISSIONS = 'Roles & permissions';

    /** Who works here, and how their day is measured. */
    public const GROUP_PEOPLE = 'People';

    /** The work, and what it is sold for. */
    public const GROUP_PROJECTS = 'Projects';

    public const GROUP_TASKS = 'Tasks & files';

    public const GROUP_TIME = 'Time & attendance';

    public const GROUP_LEAVE = 'Leave';

    /** Every event that moves, or decides, money. */
    public const GROUP_MONEY = 'Money';

    /** Settings, categories, holidays — the shape of the system rather than a record in it. */
    public const GROUP_CONFIGURATION = 'Configuration';

    /**
     * An event string no case matches — a row written by a build this one no longer is.
     *
     * It is a group and not an error: the row is real, it happened, and an audit log that hid
     * what it could not label would be hiding exactly the rows a reader came for.
     */
    public const GROUP_UNRECOGNISED = 'Unrecognised';

    /**
     * Every group, in the order a reader is offered them. Access first because that is the
     * question an auditor opens this screen with; configuration last because it is the
     * background rather than an act on anybody.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return [
            self::GROUP_ACCESS,
            self::GROUP_PERMISSIONS,
            self::GROUP_PEOPLE,
            self::GROUP_PROJECTS,
            self::GROUP_TASKS,
            self::GROUP_TIME,
            self::GROUP_LEAVE,
            self::GROUP_MONEY,
            self::GROUP_CONFIGURATION,
            self::GROUP_UNRECOGNISED,
        ];
    }

    /**
     * What this event is called in English, for a reader who does not know the value.
     *
     * Past tense throughout, because every row in this table is something that already
     * happened. Sentence case, because these are rendered as labels and as chip values and a
     * Title Cased list reads as a menu of things you could do rather than a list of things
     * somebody did.
     */
    public function label(): string
    {
        return match ($this) {
            self::UserLogin => 'Signed in',
            self::TwoFactorDisabled => 'Two-factor turned off',
            self::EmployeePasswordReset => 'Password re-issued',
            self::PasswordResetByEmail => 'Password reset by email',
            self::RestrictedAccessAttempt => 'Restricted access attempt',

            self::RoleChanged => 'Role changed',
            self::PermissionChanged => 'Project permission changed',

            self::EmployeeCreated => 'Employee created',
            self::EmployeeDeactivated => 'Employee deactivated',
            self::EmployeeReactivated => 'Employee reactivated',
            self::EmployeeTrackingModeChanged => 'Tracking mode changed',
            self::ScheduleChanged => 'Working schedule changed',

            self::ProjectCreated => 'Project created',
            self::ProjectPriceChanged => 'Project price changed',
            self::ProjectDeleted => 'Project deleted',

            self::TaskAssigned => 'Task assigned',
            self::TaskReassigned => 'Task reassigned',
            self::TaskDeleted => 'Task deleted',
            self::TaskStatusChanged => 'Task status changed',
            self::FileDeleted => 'File deleted',
            self::TagDeleted => 'Tag deleted',
            self::MessageEdited => 'Message edited',
            self::MessageDeleted => 'Message deleted',
            self::GroupCreated => 'Message group created',
            self::GroupUpdated => 'Message group updated',
            self::GroupMembersChanged => 'Message group members changed',

            self::TimeEntryEdited => 'Time entry edited',
            self::TimeEntryApproved => 'Time entry approved',
            self::TimeEntryRejected => 'Time entry rejected',
            self::AttendanceEdited => 'Attendance corrected',

            self::LeaveApproved => 'Leave approved',
            self::LeaveRejected => 'Leave rejected',
            self::LeaveWithdrawn => 'Leave withdrawn',
            self::LeaveBalanceAdjusted => 'Leave balance adjusted',

            self::SalaryChanged => 'Salary changed',
            self::SalaryDeleted => 'Salary deleted',
            self::PayrollApproved => 'Payroll approved',
            self::PayrollMonthChanged => 'Payroll month changed',
            self::PayrollLockReversed => 'Payroll lock reversed',
            self::ExpenseCreated => 'Expense recorded',
            self::ExpenseEdited => 'Expense edited',
            self::IncomeCreated => 'Income recorded',
            self::IncomeEdited => 'Income edited',
            self::FinanceRecordDeleted => 'Finance record deleted',

            self::ConfigurationChanged => 'Configuration changed',

            self::AttendanceClockedIn => 'Clocked in',
            self::AttendanceClockedOut => 'Clocked out',
        };
    }

    /**
     * Which family this event belongs to.
     *
     * The grouping is by **the question a reader is asking**, not by the table the row points
     * at. `employee.password_reset` is grouped with signing in rather than with the rest of the
     * employee events, because somebody asking "who could have signed in as her" is asking an
     * access question; `project.price_changed` stays with the project rather than with the
     * money, because it is the number on the record and not a payment.
     */
    public function group(): string
    {
        return match ($this) {
            self::UserLogin,
            self::TwoFactorDisabled,
            self::EmployeePasswordReset,
            self::PasswordResetByEmail,
            self::RestrictedAccessAttempt => self::GROUP_ACCESS,

            self::RoleChanged,
            self::PermissionChanged => self::GROUP_PERMISSIONS,

            self::EmployeeCreated,
            self::EmployeeDeactivated,
            self::EmployeeReactivated,
            self::EmployeeTrackingModeChanged,
            self::ScheduleChanged => self::GROUP_PEOPLE,

            self::ProjectCreated,
            self::ProjectPriceChanged,
            self::ProjectDeleted => self::GROUP_PROJECTS,

            self::TaskAssigned,
            self::TaskReassigned,
            self::TaskDeleted,
            self::TaskStatusChanged,
            self::FileDeleted,
            self::TagDeleted,
            self::MessageEdited,
            self::MessageDeleted,
            self::GroupCreated,
            self::GroupUpdated,
            self::GroupMembersChanged => self::GROUP_TASKS,

            self::TimeEntryEdited,
            self::TimeEntryApproved,
            self::TimeEntryRejected,
            self::AttendanceEdited => self::GROUP_TIME,

            self::LeaveApproved,
            self::LeaveRejected,
            self::LeaveWithdrawn,
            self::LeaveBalanceAdjusted => self::GROUP_LEAVE,

            self::SalaryChanged,
            self::SalaryDeleted,
            self::PayrollApproved,
            self::PayrollMonthChanged,
            self::PayrollLockReversed,
            self::ExpenseCreated,
            self::ExpenseEdited,
            self::IncomeCreated,
            self::IncomeEdited,
            self::FinanceRecordDeleted => self::GROUP_MONEY,

            self::ConfigurationChanged => self::GROUP_CONFIGURATION,

            self::AttendanceClockedIn,
            self::AttendanceClockedOut => self::GROUP_TIME,
        };
    }

    /**
     * The label for a value read back out of `audit_logs.event`.
     *
     * The column is a plain indexed string with no CHECK, so what comes out of it is **not**
     * guaranteed to be a case here: a row written before an event was renamed, or by a build
     * that had one this one does not, is a perfectly valid audit row. It gets its raw value
     * humanised rather than a blank cell or an exception.
     */
    public static function labelFor(string $event): string
    {
        return self::tryFrom($event)?->label() ?? self::humanise($event);
    }

    /** The family for a value read back out of the column. See `labelFor()`. */
    public static function groupFor(string $event): string
    {
        return self::tryFrom($event)?->group() ?? self::GROUP_UNRECOGNISED;
    }

    /**
     * `payroll.lock_reversed` → `Payroll lock reversed`.
     *
     * Only ever reached for a value no case matches, which is why it is this crude: it is a
     * best effort at reading an unknown string aloud, not a second naming scheme.
     */
    private static function humanise(string $event): string
    {
        $words = trim(str_replace(['.', '_', '-'], ' ', $event));

        return $words === '' ? $event : ucfirst($words);
    }
}
