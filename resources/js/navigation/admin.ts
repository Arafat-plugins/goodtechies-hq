import {
    AlarmClock,
    Bell,
    BriefcaseBusiness,
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarOff,
    ChartPie,
    CircleAlert,
    ClipboardCheck,
    Clock,
    FolderKanban,
    Gauge,
    HandCoins,
    LayoutDashboard,
    ListChecks,
    ListTodo,
    MessagesSquare,
    Receipt,
    ScrollText,
    Settings,
    ShieldCheck,
    Timer,
    TrendingUp,
    UserCheck,
    Users,
    UsersRound,
    Video,
    Weight,
} from '@lucide/vue';
import type { NavGroup } from './types';

export const adminNav: NavGroup[] = [
    {
        label: 'My work',
        items: [
            // An Admin's own plate, which is not the agency's — that is the Tasks row below.
            //
            // Due Today and Overdue are BUCKETS of this page, not screens of their own: they
            // ask the same question about the same tasks, narrowed by a date, and My Tasks
            // already shows all seven counts at once. Two more routes would mean two more
            // controllers, two more permission-matrix rows and two more chances for "overdue"
            // to mean something slightly different — for a `where` clause that already exists.
            // They are deep links, and `activeItem()` lights whichever one you are on.
            { label: 'My Tasks', href: '/admin/my-tasks', icon: ListTodo },
            { label: 'Due Today', href: '/admin/my-tasks?bucket=due_today', icon: CalendarClock },
            { label: 'Overdue', href: '/admin/my-tasks?bucket=overdue', icon: CircleAlert },
            // An Admin's own attendance. It points at the SHARED route, because clocking in is
            // a fact about the person rather than about the shell — Part D §8's office
            // employees include both Admins — and the page picks its layout from the viewer's
            // surface, the way Profile does.
            { label: 'My Attendance', href: '/attendance', icon: UserCheck },
            { label: 'My Leave', icon: CalendarOff, phase: 5 },
        ],
    },
    {
        label: 'Company',
        items: [{ label: 'Company Dashboard', href: '/admin/dashboard', icon: LayoutDashboard }],
    },
    {
        label: 'Work',
        items: [
            { label: 'Clients', href: '/admin/clients', icon: Building2 },
            { label: 'Projects', href: '/admin/projects', icon: FolderKanban },
            // The Board is the default view (client's request). The List is one click away on the
            // view switcher, and /admin/tasks still serves it — this changes where the nav points,
            // not which views exist.
            { label: 'Tasks', href: '/admin/tasks/board', activePrefix: '/admin/tasks', icon: ListChecks },
            // Shipped in slice 3 and left marked unbuilt until now. The Tasks row above claims
            // this URL too through its `activePrefix`; `activeItem()` gives the row to the
            // longer claim, so the Calendar lights the Calendar and nothing else.
            { label: 'Calendar', href: '/admin/tasks/calendar', icon: CalendarDays },
            { label: 'Meetings', icon: Video, phase: 7 },
            { label: 'Team', icon: UsersRound, phase: 6 },
            { label: 'Messages', icon: MessagesSquare, phase: 6 },
        ],
    },
    {
        label: 'Workforce',
        items: [
            { label: 'Employees', icon: Users, phase: 12 },
            // The roster. `activePrefix` is the same URL, so a reader on a person's month —
            // which lives at the shared `/attendance/{id}` — is not claimed by this row; that
            // page belongs to My Attendance's claim or to no row at all, which is correct:
            // it is one employee's page reached from here, not a second Workforce screen.
            { label: 'Attendance', href: '/admin/attendance', icon: CalendarCheck },
            { label: 'Time', icon: Timer, phase: 4 },
            { label: 'Workload', icon: Weight, phase: 4 },
            { label: 'Leave', icon: CalendarOff, phase: 5 },
            { label: 'Work Schedule', href: '/admin/schedules', icon: AlarmClock },
        ],
    },
    {
        label: 'Reports',
        items: [
            { label: 'Task', icon: ClipboardCheck, phase: 10 },
            { label: 'Employee', icon: BriefcaseBusiness, phase: 10 },
            { label: 'Project', icon: FolderKanban, phase: 10 },
            { label: 'Time', icon: Clock, phase: 10 },
            { label: 'Attendance', icon: CalendarCheck, phase: 10 },
            { label: 'Performance', icon: Gauge, phase: 10 },
        ],
    },
    {
        label: 'Finance',
        items: [
            { label: 'Income', icon: TrendingUp, phase: 8 },
            { label: 'Expenses', icon: Receipt, phase: 8 },
            { label: 'Payroll', icon: HandCoins, phase: 9 },
            { label: 'Financial Reports', icon: ChartPie, phase: 8 },
        ],
    },
    {
        label: 'Admin',
        items: [
            { label: 'Users & Roles', icon: ShieldCheck, phase: 12 },
            { label: 'Notifications', icon: Bell, phase: 12 },
            { label: 'Settings', href: '/admin/settings', icon: Settings },
            { label: 'Audit Log', icon: ScrollText, phase: 12 },
        ],
    },
];
