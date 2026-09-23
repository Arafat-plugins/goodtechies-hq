import '@inertiajs/core';

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
    };
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}
