import '@inertiajs/core';
import type { ShellLive } from '@/Components/Realtime/shell';

export type Role = 'ADMIN' | 'MANAGER' | 'EMPLOYEE' | 'REMOTE_EMPLOYEE' | 'ACCOUNTANT';

export type Surface = 'admin' | 'employee' | 'accountant';

export type TrackingMode = 'remote_timer' | 'office_attendance' | 'none';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: Role | null;
    surface: Surface | null;
    trackingMode: TrackingMode | null;
    twoFactorEnabled: boolean;
    /**
     * Whether this person may run the remote timer — `TimeEntryPolicy::track`, resolved on the
     * server. It is the ONLY thing that decides whether any timer control is rendered.
     *
     * Never re-derive it from `role` or `trackingMode` here: that is the second copy of a policy
     * decisions 2-28 and 2-31 were both recorded about, and it is the copy that goes stale.
     */
    canTrackTime: boolean;
}

export interface SharedProps {
    auth: {
        user: AuthUser | null;
    };
    flash: {
        success: string | null;
        error: string | null;
    };
    app: {
        name: string;
        /** `config('app.timezone')`: the zone a date-only due date ends in. */
        timezone: string;
    };
    /**
     * The shell's own live state — the announcement banner app-wide and the Messages nav row's
     * unread indicator (`HandleInertiaRequests::sharedShell()`).
     *
     * **Optional, and that is a fact about the server and not about TypeScript.** It is an
     * `Inertia::optional()` prop: absent from an ordinary page render and resolved only on a
     * partial reload that names it, because resolving it costs 5 to 12 statements and every page
     * would have paid them. So `undefined` is the normal case and nothing should read this
     * directly — `Components/Realtime/shell.ts` holds the last value it saw at module scope and
     * is what the shell renders from.
     */
    shell?: ShellLive;
    /**
     * `HandleInertiaRequests::sharedClock()`: whether the signer-in is clocked in, for an
     * employee on the office clock; `null` for everyone else. Read through
     * `Components/Attendance/clockState.ts`.
     */
    clock?: { clocked_in: boolean } | null;
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}
