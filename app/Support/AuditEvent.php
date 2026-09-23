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
