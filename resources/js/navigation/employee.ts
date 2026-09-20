import {
    Bell,
    CalendarCheck,
    CalendarDays,
    CalendarOff,
    ChartColumn,
    FolderKanban,
    LayoutDashboard,
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
                { label: 'My Tasks', icon: ListTodo, phase: 2 },
                { label: 'Projects', icon: FolderKanban, phase: 1 },
                { label: 'Calendar', icon: CalendarDays, phase: 2 },
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
