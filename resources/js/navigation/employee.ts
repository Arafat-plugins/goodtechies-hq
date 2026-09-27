import { Bell, CalendarCheck, CalendarClock, CalendarDays, CalendarOff, CalendarRange, ChartColumn, CircleAlert, FileText, FolderKanban, LayoutDashboard, ListChecks, ListTodo, MessagesSquare, Timer, UserRound, UsersRound, Video } from '@lucide/vue';
import type { TrackingMode } from '@/types';
import type { NavGroup, NavItem } from './types';

/**
 * Remote-timer employees get "Time"; everyone else gets "Attendance".
 * This is a tracking-mode swap inside one nav, not a role switch.
 */
export function employeeNav(trackingMode: TrackingMode | null | undefined): NavGroup[] {
    const tracking: NavItem =
        trackingMode === 'remote_timer'
            ? // Shipped in Phase 4. Which of the two words this person's sidebar uses is a
              // navigation decision, never an authorisation one: `/employee/time` is gated by
              // `TimeEntryPolicy::track` on the server, so somebody who reached the URL another
              // way is refused whatever this file says.
              { label: 'Time', href: '/employee/time', icon: Timer }
            : // The SHARED route, not an employee-surface one: clocking in is a fact about the
              // person and not about the shell, so Yaseen and both Admins reach the same page
              // and it picks its layout from the viewer's surface. See routes/shared.php.
              { label: 'Attendance', href: '/attendance', icon: CalendarCheck };

    // The weekly grid, and only for somebody whose work the timer measures: a timesheet is a
    // view over `time_entries`, so an office employee's could only ever be empty. This is the
    // same navigation-not-authorisation decision the row above is — `/employee/timesheet` is
    // gated by `TimeEntryPolicy::viewAny` on the server either way.
    const timesheet: NavItem[] =
        trackingMode === 'remote_timer'
            ? [{ label: 'Timesheet', href: '/employee/timesheet', icon: CalendarRange }]
            : [];

    return [
        {
            label: 'Menu',
            items: [
                { label: 'Dashboard', href: '/employee/dashboard', icon: LayoutDashboard },
                // The plate: seven buckets and their counts. Due Today and Overdue are buckets
                // of it rather than routes of their own — one question, one screen, one query
                // per count; see the same three rows on the Admin nav.
                { label: 'My Tasks', href: '/employee/my-tasks', icon: ListTodo },
                { label: 'Due Today', href: '/employee/my-tasks?bucket=due_today', icon: CalendarClock },
                { label: 'Overdue', href: '/employee/my-tasks?bucket=overdue', icon: CircleAlert },
                // The List, Board and Calendar of the work this person can see — every task
                // they are assigned to, and for the Manager who shares this surface, every
                // task. It was labelled "My Tasks" while there was no My Tasks page to point
                // at; it is the Tasks views, and now says so. Board by default, as on Admin.
                { label: 'Tasks', href: '/employee/tasks/board', activePrefix: '/employee/tasks', icon: ListChecks },
                { label: 'Projects', href: '/employee/projects', icon: FolderKanban },
                // Shipped in slice 3. The longer claim wins the row — see `activeItem()`.
                { label: 'Calendar', href: '/employee/tasks/calendar', icon: CalendarDays },
                // Phase 7. The shared Meetings page — whose calendar a meeting is on belongs
                // to the person and not to the shell, so this points at `/meetings` and the
                // page picks `EmployeeLayout` from the viewer's surface.
                { label: 'Meetings', href: '/meetings', icon: Video },
                // Phase 6. The shared Messages page — whose mail a thread is belongs to the
                // person and not to the shell, so this points at `/messages` and the page picks
                // `EmployeeLayout` from the viewer's surface, exactly as My Leave does.
                { label: 'Messages', href: '/messages', icon: MessagesSquare },
                // Phase 6. Part D §2 words the employee's entry point as "Messages → Team",
                // and the Messages page has no sub-navigation to hang it off — so the row sits
                // directly under Messages, which is the same neighbourhood and one fewer click.
                // The alternative was a page nobody on this surface could reach.
                { label: 'Team', href: '/team', icon: UsersRound },
                // Phase 2's Notification Center, at the shared `/notifications` — the same page
                // the bell in the top bar opens, which is why it needs no surface of its own.
                //
                // **This row said `phase: 2` until Phase 12 and the route had existed since
                // Phase 2.** It was the last phase-gated row left in the application, and it
                // sat in the *Coming soon* disclosure labelled `P2` for ten phases while the
                // screen behind it worked perfectly — so an employee was told the thing they
                // were already being notified in was not built yet. Enabling a row is one line
                // (`href` in, `phase` out); the cost of forgetting it is a feature nobody knows
                // they have.
                { label: 'Notifications', href: '/notifications', icon: Bell },
                tracking,
                ...timesheet,
                // The shared My Leave page — applying for leave is a fact about the person, not
                // about the shell (Part C §1 gives the cell to every role), so this points at
                // `/leave` and the page picks `EmployeeLayout` from the viewer's surface.
                { label: 'My Leave', href: '/leave', icon: CalendarOff },

                // **My Payslip (Phase 9).** Part C §1 gives *view own payslip* to every role, so
                // this row exists on all three shells — and it points at the SHARED `/payslip`,
                // which picks `EmployeeLayout` from the viewer's surface exactly as My Leave
                // above does. The page shows only this person's own items: the scope is
                // `PayrollService::itemsFor()`, so a row somebody else's is not merely hidden by
                // a nav that does not link to it — it is absent from the listing, 404 by id, and
                // the attempt is audit-logged (Part B §3 rule 1).
                { label: 'My Payslip', href: '/payslip', icon: FileText },
                // Phase 10. Self-scoped and deliberately not the Admin catalogue: Part D §15
                // names a different list for an employee, so this is its own screen rather than
                // `/admin/reports` with a filter on it.
                { label: 'My Reports', href: '/employee/reports', icon: ChartColumn },
                { label: 'Profile', href: '/profile', icon: UserRound },
            ],
        },
    ];
}
