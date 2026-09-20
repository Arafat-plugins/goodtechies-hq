<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    settings: { key: string; value: unknown }[];
    backup: { lastVerifiedAt: string | null };
    drivers: { realtime: string; googleCalendar: string };
}>();

interface SettingMeta {
    label: string;
    section: string;
    unit?: string;
}

// Display metadata per settings key. `backup_last_verified_at` is shown in the Backup health card.
const META: Record<string, SettingMeta> = {
    timezone: { label: 'Timezone', section: 'General' },
    currency: { label: 'Currency', section: 'General' },
    late_grace_minutes: { label: 'Late grace period', section: 'Attendance', unit: 'min' },
    half_day_auto: { label: 'Automatic half day', section: 'Attendance' },
    timer_max_session_hours: { label: 'Maximum timer session', section: 'Timer', unit: 'h' },
    heartbeat_timeout_minutes: { label: 'Heartbeat timeout', section: 'Timer', unit: 'min' },
    manual_time_requires_approval: { label: 'Manual time needs approval', section: 'Timer' },
    idle_pause_minutes: { label: 'Pause timer when idle for', section: 'Timer', unit: 'min' },
    idle_flag_percent: { label: 'Flag sessions idle for more than', section: 'Timer', unit: '%' },
    notification_group_window_minutes: { label: 'Group notifications within', section: 'Notifications', unit: 'min' },
};

const SECTIONS = ['General', 'Attendance', 'Timer', 'Notifications'];

function formatValue(value: unknown, unit?: string): string {
    if (typeof value === 'boolean') {
        return value ? 'On' : 'Off';
    }

    if (value === null || value === undefined || value === '') {
        return 'Not set';
    }

    if (typeof value === 'number') {
        return unit ? `${value} ${unit}` : String(value);
    }

    return typeof value === 'string' ? value : JSON.stringify(value);
}

const sections = computed(() =>
    SECTIONS.map((section) => ({
        title: section,
        rows: props.settings
            .filter((setting) => META[setting.key]?.section === section)
            .map((setting) => ({
                key: setting.key,
                label: META[setting.key].label,
                value: formatValue(setting.value, META[setting.key].unit),
            })),
    })).filter((section) => section.rows.length > 0),
);

const integrations = computed(() => [
    { label: 'Realtime driver', value: props.drivers.realtime },
    { label: 'Google Calendar driver', value: props.drivers.googleCalendar },
]);

const verifiedAt = computed(() => {
    if (!props.backup.lastVerifiedAt) {
        return null;
    }

    const date = new Date(props.backup.lastVerifiedAt);

    return Number.isNaN(date.getTime())
        ? props.backup.lastVerifiedAt
        : new Intl.DateTimeFormat('en-GB', { dateStyle: 'long', timeStyle: 'short' }).format(date);
});

const rowClass = 'flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4';
</script>

<template>
    <Head title="Settings" />

    <div class="flex flex-col gap-6">
        <PageHeader title="Settings" description="Read-only for now — editing arrives in Phase 12." />

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <Card v-for="section in sections" :key="section.title" class="gap-2 shadow-xs">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">{{ section.title }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="divide-y">
                        <div v-for="row in section.rows" :key="row.key" :class="rowClass">
                            <dt class="text-sm text-muted-foreground">{{ row.label }}</dt>
                            <dd class="text-sm font-medium tabular-nums">{{ row.value }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card class="gap-2 shadow-xs">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Integrations</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="divide-y">
                        <div v-for="row in integrations" :key="row.label" :class="rowClass">
                            <dt class="text-sm text-muted-foreground">{{ row.label }}</dt>
                            <dd class="flex items-center gap-2">
                                <span class="text-sm font-medium">{{ row.value }}</span>
                                <Badge variant="outline" class="text-muted-foreground">Set in .env</Badge>
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card class="gap-2 shadow-xs">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Backup health</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-2 py-3">
                    <p v-if="verifiedAt" class="flex items-center gap-2 text-sm font-medium">
                        <span class="size-2 shrink-0 rounded-full bg-status-done" aria-hidden="true" />
                        Verified {{ verifiedAt }}
                    </p>
                    <template v-else>
                        <p class="flex items-center gap-2 text-sm font-medium">
                            <span class="size-2 shrink-0 rounded-full bg-status-waiting" aria-hidden="true" />
                            Not verified yet
                        </p>
                        <p class="text-xs text-muted-foreground">
                            The weekly backup check writes this after the first successful restore test.
                        </p>
                    </template>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
