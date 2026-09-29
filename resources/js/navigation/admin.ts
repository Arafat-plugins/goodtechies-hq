import {
    AlarmClock,
    BadgeDollarSign,
    Bell,
    BriefcaseBusiness,
    Building2,
    CalendarCheck,
    CalendarDays,
    CalendarOff,
    CalendarRange,
    ChartColumn,
    ChartPie,
    ClipboardCheck,
    Clock,
    FileText,
    FolderKanban,
    Gauge,
    HandCoins,
    LayoutDashboard,
    ListChecks,
    MessagesSquare,
    PartyPopper,
    Receipt,
    ScrollText,
    Settings,
    ShieldCheck,
    Tags,
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
            // My Tasks, Due Today and Overdue used to open here. They are now the scope dropdown
            // on the Tasks toolbar (`/admin/tasks?scope=mine|due-today|overdue`), so an Admin's
            // own plate and the agency's are one screen read two ways; the old `/admin/my-tasks`
            // URLs 302 there. What is left in this group is the Admin as a person, not as a
            // manager of work.
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
            // Phase 9. An Admin's own payslip. It points at the SHARED `/payslip`, because
            // Part C §1 gives *every* role "view own payslip" and Part E's Phase 9 names
            // "Employee/Remote/**Admin (self)** and the Accountant in its own shell". Being
            // able to read everybody's payroll is a different question, asked by a different
            // key on a different screen — this row is the personal one, which is why it sits in
            // MY WORK beside My Attendance and My Leave rather than in FINANCE beside Payroll.
            { label: 'My Payslip', href: '/payslip', activePrefix: '/payslip', icon: FileText },
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
            // Phase 7. The shared Meetings page — whose calendar a meeting is on belongs to
            // the person and not to the shell, so this points at `/meetings` and the page picks
            // `AdminLayout` from the viewer's surface, exactly as Messages and My Leave do.
            { label: 'Meetings', href: '/meetings', icon: Video },
            // Phase 6. The shared Team directory — who works here is a fact about the agency
            // and not about the shell, so this points at `/team` and the page picks
            // `AdminLayout` from the viewer's surface, exactly as My Leave does. Part D §2 puts
            // it in WORK, next to Messages, which is the only thing it does.
            { label: 'Team', href: '/team', icon: UsersRound },
            // Phase 6. Shared with the Employee surface — one route, one page, the layout
            // picked from the viewer's own surface.
            { label: 'Messages', href: '/messages', icon: MessagesSquare },
        ],
    },
    {
        label: 'Workforce',
        items: [
            // Phase 12. The roster, and the root of the screen family Part D §2 names:
            // *"Users & Roles is the same screen family (Employees list → employee detail →
            // role/schedule/tracking_mode)"*. `activePrefix` is the bare path, so the detail
            // page and the create dialog's page keep this row lit — and so the ADMIN group's
            // Users & Roles row below, which points at the same list with `?view=access`,
            // makes the LONGER claim and wins that one URL from `activeItem()`.
            //
            // Inactive employees are on this list and marked, never hidden: Part B §3 rule 11
            // keeps the record for good, so the roster is also where somebody who has left is
            // found.
            { label: 'Employees', href: '/admin/employees', activePrefix: '/admin/employees', icon: Users },
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
            // Phase 10. The catalogue: every report the viewer's permissions allow, grouped,
            // each with the question it answers. It is where a report nobody gave a sidebar row
            // to — Overdue, Finance, Payroll — is found, and it is the parent of every row
            // below, which is why its `activePrefix` claims the whole prefix. `activeItem()`
            // gives a specific report's page to the longer claim, exactly as it does for
            // Tasks and Calendar, so opening Task lights Task and not this row.
            {
                label: 'All Reports',
                href: '/admin/reports',
                activePrefix: '/admin/reports',
                icon: ChartColumn,
            },
            // Part D §2's own six names, pointed at the reports they name. A row here is a
            // `ReportKey`'s address — `/admin/reports/<key>` — and not a screen of its own:
            // all sixteen are one page component, so these are deep links into it.
            { label: 'Task', href: '/admin/reports/task', icon: ClipboardCheck },
            { label: 'Employee', href: '/admin/reports/employee-work', icon: BriefcaseBusiness },
            { label: 'Project', href: '/admin/reports/project', icon: FolderKanban },
            { label: 'Time', href: '/admin/reports/time', icon: Clock },
            { label: 'Attendance', href: '/admin/reports/attendance', icon: CalendarCheck },
            // Part D §2 defines Performance as the PROJECT-level report — estimated vs
            // tracked hours, completion rate, overdue rate, **never per person** (spec §30
            // forbids scoring). It was the one row that kept its phase marker through the
            // first Reports slice, because a menu entry with no builder is a 500 with a label
            // on it; the second slice gave it a `ReportKey` case, so it is a link now.
            { label: 'Performance', href: '/admin/reports/performance', icon: Gauge },
        ],
    },
    {
        label: 'Finance',
        items: [
            // Phase 8. Part D's Phase 8 heading is "Screens (Accountant shell + Admin →
            // Finance)", and these are the Admin half of it: the SAME routes the Accountant
            // shell points at, because whose money it is belongs to the agency rather than to
            // the shell somebody is looking at. Each page picks `AdminLayout` from the viewer's
            // surface, exactly as Meetings, Team and My Leave above do.
            //
            // `activePrefix` keeps the row lit on `/finance/income/create` and
            // `/finance/income/{id}/edit` — the form is a real address, and the nav must not
            // unlight while it is open.
            {
                label: 'Income',
                href: '/finance/income',
                activePrefix: '/finance/income',
                icon: TrendingUp,
            },
            {
                label: 'Expenses',
                href: '/finance/expenses',
                activePrefix: '/finance/expenses',
                icon: Receipt,
            },
            // Phase 9. The SAME shared `/payroll` routes the Accountant shell points at, for
            // the reason Income and Expenses above are shared: whose pay it is belongs to the
            // agency rather than to the shell somebody is looking at. Part E splits the VERBS
            // across the two surfaces — the Accountant drafts and calculates, the Admin
            // reviews, approves, locks, reverses and marks paid — and the page renders whichever
            // of those the server says this viewer may make. It picks `AdminLayout` from the
            // viewer's surface, exactly as Meetings, Team and My Leave above do.
            //
            // `activePrefix` keeps the row lit on `/payroll/{period}`, which is where the
            // month's own screen lives and where every transition posts back to.
            { label: 'Payroll', href: '/payroll', activePrefix: '/payroll', icon: HandCoins },
            // Part D §14's *"salary settings per employee (base salary, allowances —
            // audit-logged)"*, which Part E puts on the Admin surface and nowhere else: the
            // Accountant fills a month's figures but does not decide what anybody earns. It
            // sits beside Payroll rather than under WORKFORCE because a salary is the input the
            // month's draft is built from, and this is the group somebody is in when they
            // discover a line is missing.
            //
            // Part D §2's FINANCE list is Income · Expenses · Payroll · Financial Reports, so
            // this is a recorded addition to that sidebar — the same shape as Categories below,
            // which is also a screen only an Admin may write and which would otherwise be
            // unreachable from the nav.
            { label: 'Salaries', href: '/salaries', activePrefix: '/salaries', icon: BadgeDollarSign },
            { label: 'Financial Reports', href: '/finance/report', icon: ChartPie },
            // Part D §2's FINANCE group is Income · Expenses · Payroll · Financial Reports, so
            // Categories is not one of its four rows — but the Admin is the only person who may
            // change the list (decision 8-12), and a screen only they can use that only they
            // cannot find is not much use. It is reached from either ledger's header as well.
            { label: 'Categories', href: '/finance/categories', icon: Tags },
        ],
    },
    {
        label: 'Admin',
        items: [
            // Phase 12. **The same screen, asked the other question** — Part D §2 says so in
            // one line, so this is a deep link into the Employees list and not a second
            // feature: `?view=access` swaps the workforce columns (tracking mode, working
            // week) for the access ones (role, project-level grants). Two sections that each
            // listed the same people would be two lists to keep in step, and a role changed on
            // one of them would be a role stale on the other.
            //
            // It is the same shape as Due Today and Overdue in MY WORK: one page, one
            // controller, one permission-matrix row, and `activeItem()` lights whichever row
            // named the longer claim — here this one, because `/admin/employees?view=access`
            // is longer than the Employees row's `/admin/employees`.
            {
                label: 'Users & Roles',
                href: '/admin/employees?view=access',
                icon: ShieldCheck,
            },
            // Phase 12. The agency-wide notification defaults — which kinds of event the app
            // tells people about, on which channel. Not a person's own mail: that is the bell
            // and the Notification Center at the shared `/notifications`, which is why this row
            // points at `/admin/notifications` and sits in ADMIN beside Settings rather than
            // anywhere near MY WORK.
            { label: 'Notifications', href: '/admin/notifications', icon: Bell },
            { label: 'Settings', href: '/admin/settings', icon: Settings },
            // Phase 12. The compliance log, read-only — Part C §4's events, every one of them
            // written by a phase that never had a screen to show them on. There is no second row
            // for it and no deep link: unlike Users & Roles above, one question is all this
            // screen is asked, and every way of narrowing it is a filter in the query string
            // rather than an address of its own.
            { label: 'Audit Log', href: '/admin/audit-log', icon: ScrollText },
        ],
    },
];
