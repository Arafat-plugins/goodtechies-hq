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
    case SalaryChanged = 'salary.changed';
    case PayrollApproved = 'payroll.approved';
    case PayrollLockReversed = 'payroll.lock_reversed';
    case ExpenseCreated = 'expense.created';
    case ExpenseEdited = 'expense.edited';
    case FinanceRecordDeleted = 'finance.record_deleted';
    case RestrictedAccessAttempt = 'access.restricted_attempt';
    case TwoFactorDisabled = 'user.two_factor_disabled';
}
