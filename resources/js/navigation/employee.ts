import {
    Bell,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarOff,
    ChartColumn,
    CircleAlert,
    FolderKanban,
    LayoutDashboard,
    ListChecks,
    ListTodo,
    MessagesSquare,
    Timer,
    UserRound,
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
            ? { label: 'Time', icon: Timer, phase: 4 }
            : { label: 'Attendance', icon: CalendarCheck, phase: 4 };

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
                { label: 'Messages', icon: MessagesSquare, phase: 6 },
                { label: 'Notifications', icon: Bell, phase: 2 },
                tracking,
                { label: 'Leave', icon: CalendarOff, phase: 5 },
                { label: 'My Reports', icon: ChartColumn, phase: 10 },
                { label: 'Profile', href: '/profile', icon: UserRound },
            ],
        },
    ];
}
