import {
    AlarmClock,
    Bell,
    BriefcaseBusiness,
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarOff,
    CalendarRange,
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
    PartyPopper,
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
            // An Admin's own leave. It points at the SHARED route, because applying for leave
            // is a fact about the person rather than about the shell — Part C §1 gives that
            // cell to every role — and the page picks its layout from the viewer's surface,
            // exactly as My Attendance above it does.
            { label: 'My Leave', href: '/leave', icon: CalendarOff },
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
            // Phase 6. The shared Team directory — who works here is a fact about the agency
            // and not about the shell, so this points at `/team` and the page picks
            // `AdminLayout` from the viewer's surface, exactly as My Leave does. Part D §2 puts
            // it in WORK, next to Messages, which is the only thing it does.
            { label: 'Team', href: '/team', icon: UsersRound },
            // Phase 6. Shared with the Employee surface — one route, one page, the layout
            // picked from the viewer's own surface.
            { label: 'Messages', href: '/messages', icon: MessagesSquare, badgeKey: 'messagesUnread' },
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
            // The approval queue and hours today/week. `activePrefix` is `/admin/time`, which
            // also claims the entry decisions' POST paths — they never render a page, but a
            // redirect back after one must not unlight the row it was made from.
            { label: 'Time', href: '/admin/time', activePrefix: '/admin/time', icon: Timer },
            // One employee's week, tasks × days. No id in the href: the controller opens on the
            // first timer-tracked name and the page's own picker moves between people, so the
            // nav row does not have to know who exists.
            { label: 'Timesheet', href: '/admin/timesheet', icon: CalendarRange },
            { label: 'Workload', href: '/admin/workload', icon: Weight },
            // The requests queue. `activePrefix` is `/admin/leave`, so the calendar, the
            // balances grid and the three decision POST paths all keep this row lit — a
            // redirect back after a decision must not unlight the row it was made from, which
            // is the same reason the Time row above carries one.
            { label: 'Leave', href: '/admin/leave', activePrefix: '/admin/leave', icon: CalendarOff },
            // Workforce → Leave → Holidays (Part D §9). The menu path names Leave, and this row
            // sits directly under it rather than inside it, because the sidebar is one level
            // deep by design (NavGroup → NavItem) and a holiday is not a leave request anyway:
            // no employee, no approval, no balance, a different permission key. The page's
            // breadcrumb carries the full path Workforce / Leave / Holidays.
            { label: 'Holidays', href: '/admin/holidays', icon: PartyPopper },
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
