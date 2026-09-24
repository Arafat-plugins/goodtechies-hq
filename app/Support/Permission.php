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

    /**
     * May this person use the messaging system at all (Phase 6).
     *
     * The 24th key, and the only one Part C §1's matrix does not have a row for — because the
     * matrix says nothing about messaging and Phase 6's security paragraph says the one thing
     * that matters: *"Accountant has no messaging routes (the spec gives ACCOUNTANT no messaging
     * access by default)"*.
     *
     * That sentence could have been implemented by naming the role, and decisions 2-13 and 2-31
     * are both about why it must not be. It could also have been implemented by borrowing
     * `tasks.view`, whose holders happen to be exactly the four non-Accountant roles today —
     * but that key means "you may see tasks", and a future role that may see tasks without being
     * given the team chat would then have to be carved out by name after all. So the actual
     * reason gets its own key: messaging is its own capability, held by ADMIN, MANAGER, EMPLOYEE
     * and REMOTE_EMPLOYEE, and by nobody else.
     *
     * It gates the messaging ROUTES (one `can:` on the group), the four non-task conversation
     * types in ConversationPolicy, who may be @mentioned, and — through
     * `NotificationType::requires()` — who can receive a message notification at all. One key,
     * four places, no role named in any of them.
     */
    case MessagesUse = 'messages.use';

    case AnnouncementsSend = 'announcements.send';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
}
