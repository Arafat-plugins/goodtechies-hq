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
