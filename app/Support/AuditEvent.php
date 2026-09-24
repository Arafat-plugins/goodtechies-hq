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
    case ProjectCreated = 'project.created';
    case ProjectPriceChanged = 'project.price_changed';
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
    case PayrollApproved = 'payroll.approved';
    case PayrollLockReversed = 'payroll.lock_reversed';
    case ExpenseCreated = 'expense.created';
    case ExpenseEdited = 'expense.edited';
    case FinanceRecordDeleted = 'finance.record_deleted';
    case RestrictedAccessAttempt = 'access.restricted_attempt';
    case TwoFactorDisabled = 'user.two_factor_disabled';
}
