<script lang="ts">
export interface ActiveSession {
    id: string;
    ipAddress: string | null;
    userAgent: string | null;
    lastActiveAt: string;
    isCurrent: boolean;
}

const BROWSERS: [needle: string, label: string][] = [
    ['Edg/', 'Edge'],
    ['OPR/', 'Opera'],
    ['Firefox/', 'Firefox'],
    ['FxiOS/', 'Firefox'],
    ['CriOS/', 'Chrome'],
    ['Chrome/', 'Chrome'],
    ['Safari/', 'Safari'],
];

const SYSTEMS: [needle: string, label: string][] = [
    ['iPhone', 'iOS'],
    ['iPad', 'iPadOS'],
    ['Android', 'Android'],
    ['CrOS', 'ChromeOS'],
    ['Windows', 'Windows'],
    ['Mac OS X', 'macOS'],
    ['Macintosh', 'macOS'],
    ['Linux', 'Linux'],
];

function firstMatch(userAgent: string, table: [string, string][]): string | null {
    return table.find(([needle]) => userAgent.includes(needle))?.[1] ?? null;
}

/** "Chrome on macOS" from a user-agent string, by simple substring checks. */
export function deviceSummary(userAgent: string | null): string {
    if (!userAgent) {
        return 'Unknown device';
    }

    const browser = firstMatch(userAgent, BROWSERS);
    const system = firstMatch(userAgent, SYSTEMS);

    if (browser && system) {
        return `${browser} on ${system}`;
    }

    return browser ?? system ?? 'Unknown device';
}

export function isMobileAgent(userAgent: string | null): boolean {
    return userAgent !== null && /iPhone|iPad|Android|Mobile/.test(userAgent);
}

const UNITS: [unit: Intl.RelativeTimeFormatUnit, seconds: number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

/** "5 minutes ago", "yesterday", "now" — in the browser's language. */
export function relativeTime(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(0, 'second');
}
</script>

<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CircleAlert, Laptop, MonitorSmartphone, Smartphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

const props = defineProps<{
    sessions: ActiveSession[];
}>();

const page = usePage();

const sessionError = computed(() => page.props.errors?.session ?? null);

// Current device first, then most recently active.
const rows = computed(() =>
    [...props.sessions]
        .sort(
            (a, b) =>
                Number(b.isCurrent) - Number(a.isCurrent) ||
                new Date(b.lastActiveAt).getTime() - new Date(a.lastActiveAt).getTime(),
        )
        .map((session) => ({
            ...session,
            device: deviceSummary(session.userAgent),
            mobile: isMobileAgent(session.userAgent),
            active: relativeTime(session.lastActiveAt),
        })),
);

const hasOtherSessions = computed(() => props.sessions.some((session) => !session.isCurrent));

const pendingId = ref<string | null>(null);

function signOut(id: string): void {
    if (pendingId.value !== null) {
        return;
    }

    router.delete(`/profile/sessions/${encodeURIComponent(id)}`, {
        preserveScroll: true,
        onStart: () => (pendingId.value = id),
        onFinish: () => (pendingId.value = null),
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4 shadow-xs">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Active sessions</CardTitle>
            <CardDescription>Browsers and devices signed in to your account.</CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <Alert v-if="sessionError" variant="destructive">
                <CircleAlert />
                <AlertDescription>{{ sessionError }}</AlertDescription>
            </Alert>

            <ul v-if="rows.length > 0" class="divide-y">
                <li v-for="session in rows" :key="session.id" class="flex items-center gap-3 py-3">
                    <span class="shrink-0 rounded-md bg-muted p-2 text-muted-foreground" aria-hidden="true">
                        <Smartphone v-if="session.mobile" class="size-4" />
                        <Laptop v-else class="size-4" />
                    </span>
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                            <span class="min-w-0 break-words">{{ session.device }}</span>
                            <Badge v-if="session.isCurrent" variant="secondary">This device</Badge>
                        </p>
                        <p class="text-xs break-words text-muted-foreground">
                            {{ session.ipAddress ?? 'Unknown IP' }} · Active {{ session.active }}
                        </p>
                    </div>
                    <Button
                        v-if="!session.isCurrent"
                        variant="ghost"
                        size="sm"
                        class="shrink-0"
                        :disabled="pendingId !== null"
                        @click="signOut(session.id)"
                    >
                        {{ pendingId === session.id ? 'Signing out…' : 'Sign out' }}
                    </Button>
                </li>
            </ul>

            <div v-if="!hasOtherSessions" class="flex flex-col items-center gap-2 py-4 text-center">
                <span class="rounded-full bg-muted p-3 text-muted-foreground" aria-hidden="true">
                    <MonitorSmartphone class="size-5" />
                </span>
                <p class="text-sm font-medium">No other sessions</p>
                <p class="text-xs text-muted-foreground">You are not signed in anywhere else.</p>
            </div>
        </CardContent>
    </Card>
</template>
