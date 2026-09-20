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
