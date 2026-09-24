import {
    Bell,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarOff,
    CalendarRange,
    ChartColumn,
    CircleAlert,
    FolderKanban,
    LayoutDashboard,
    ListChecks,
    ListTodo,
    MessagesSquare,
    Timer,
    UserRound,
    UsersRound,
    Video,
} from '@lucide/vue';
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
                { label: 'Meetings', icon: Video, phase: 7 },
                // Phase 6. The shared Messages page — whose mail a thread is belongs to the
                // person and not to the shell, so this points at `/messages` and the page picks
                // `EmployeeLayout` from the viewer's surface, exactly as My Leave does.
                { label: 'Messages', href: '/messages', icon: MessagesSquare },
                // Phase 6. Part D §2 words the employee's entry point as "Messages → Team",
                // and the Messages page has no sub-navigation to hang it off — so the row sits
                // directly under Messages, which is the same neighbourhood and one fewer click.
                // The alternative was a page nobody on this surface could reach.
                { label: 'Team', href: '/team', icon: UsersRound },
                { label: 'Notifications', icon: Bell, phase: 2 },
                tracking,
                ...timesheet,
                // The shared My Leave page — applying for leave is a fact about the person, not
                // about the shell (Part C §1 gives the cell to every role), so this points at
                // `/leave` and the page picks `EmployeeLayout` from the viewer's surface.
                { label: 'My Leave', href: '/leave', icon: CalendarOff },
                { label: 'My Reports', icon: ChartColumn, phase: 10 },
                { label: 'Profile', href: '/profile', icon: UserRound },
            ],
        },
    ];
}
