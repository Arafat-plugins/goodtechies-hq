<?php

namespace App\Support;

/**
 * Every permission key in the system. Scoped rules are a key plus a scope check in a Policy.
 */
enum Permission: string
{
    case ClientsViewFull = 'clients.view_full';
    case ClientsEdit = 'clients.edit';
    case ProjectsView = 'projects.view';
    case ProjectsViewFinance = 'projects.view_finance';
    case ProjectsEdit = 'projects.edit';
    case TasksView = 'tasks.view';
    case TasksCreate = 'tasks.create';
    case TasksDelete = 'tasks.delete';
    case TimerUse = 'timer.use';
    case AttendanceViewOwn = 'attendance.view_own';
    case AttendanceManageOthers = 'attendance.manage_others';
    case LeaveApply = 'leave.apply';
    case LeaveApprove = 'leave.approve';
    case PayrollViewOwn = 'payroll.view_own';
    case PayrollViewOthers = 'payroll.view_others';
    case PayrollDraft = 'payroll.draft';
    case PayrollApprove = 'payroll.approve';
    case FinanceView = 'finance.view';
    case FinanceManage = 'finance.manage';
    case AnnouncementsSend = 'announcements.send';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
}
