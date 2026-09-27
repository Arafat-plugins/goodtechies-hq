<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { computed } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import SettingsSectionCard from '@/Components/Settings/SettingsSectionCard.vue';
import type { IntegrationRow, SettingField, SettingRow } from '@/Components/Settings/settings';
import { valuesByKey } from '@/Components/Settings/settings';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * Admin → Settings: Part D §20's key list, editable, grouped the way Part E groups it.
 *
 * ## There is no `META` table in this file any more
 *
 * It used to hold the label, the group and the unit for every key — a second list, in Vue, of
 * facts the server also knew. Now the server sends `fields`, generated from
 * `UpdateSettingsRequest::FIELDS`, which is the same table `rules()` is generated from. So the
 * label on the box, the bounds on the arrows, the sentence underneath and the validation that
 * refuses a bad value are one fact in one place, and a key whose range changes changes here by
 * itself. This file decides the layout and nothing else.
 *
 * ## Three things on this page cannot be edited, and each says why
 *
 * The realtime driver and the Google Calendar driver are read-only because Part D §20 puts them
 * under *"Not settings, because they cannot switch at runtime"* — and the card prints the actual
 * reason, plus the `.env` key to change instead. A read-only row that only says *read-only*
 * reads as an unfinished feature, which is what the disabled-looking rows on this page used to
 * read as.
 *
 * `backup_last_verified_at` is the third: `hq:verify-backup` is its only writer, it is in
 * `SettingsService::READ_ONLY`, and it is absent from `fields` — so it is drawn as a health card
 * rather than as a date somebody can type over.
 */

const props = defineProps<{
    settings: SettingRow[];
    fields: SettingField[];
    sections: string[];
    backup: { lastVerifiedAt: string | null };
    drivers: {
        realtime: string;
        realtimeEnv: string;
        realtimeWhy: string;
        googleCalendar: string;
        googleCalendarEnv: string;
        googleCalendarWhy: string;
    };
}>();

const values = computed(() => valuesByKey(props.settings));

/** The groups, in the server's order, each with the fields the server put in it. */
const sections = computed(() =>
    props.sections
        .map((section) => ({
            title: section,
            fields: props.fields.filter((field) => field.section === section),
        }))
        .filter((section) => section.fields.length > 0),
);

const integrations = computed<IntegrationRow[]>(() => [
    {
        label: 'Realtime driver',
        value: props.drivers.realtime,
        env: props.drivers.realtimeEnv,
        why: props.drivers.realtimeWhy,
    },
    {
        label: 'Google Calendar driver',
        value: props.drivers.googleCalendar,
        env: props.drivers.googleCalendarEnv,
        why: props.drivers.googleCalendarWhy,
    },
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
</script>

<template>
    <Head title="Settings" />

    <PageShell
        title="Settings"
        description="How the agency's rules are set. Every change is recorded in the audit log."
    >
        <div class="grid min-w-0 items-start gap-4 lg:grid-cols-2">
            <SettingsSectionCard
                v-for="section in sections"
                :key="section.title"
                :title="section.title"
                :fields="section.fields"
                :values="values"
            />

            <Card class="min-w-0 gap-4">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Integrations</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="flex min-w-0 flex-col divide-y">
                        <div v-for="row in integrations" :key="row.label" class="flex min-w-0 flex-col gap-2 py-3">
                            <div
                                class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                            >
                                <dt class="text-sm text-muted-foreground">{{ row.label }}</dt>
                                <dd class="flex min-w-0 flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{ row.value }}</span>
                                    <!--
                                        The icon is a second carrier of "you cannot change this
                                        here" beside the words, never instead of them — §5.6.
                                    -->
                                    <Badge variant="outline" class="gap-1 text-muted-foreground">
                                        <Lock class="size-3" aria-hidden="true" />
                                        {{ row.env }}
                                    </Badge>
                                </dd>
                            </div>
                            <p class="text-xs text-muted-foreground">{{ row.why }}</p>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card class="min-w-0 gap-4">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Backup health</CardTitle>
                </CardHeader>
                <CardContent class="flex min-w-0 flex-col gap-2">
                    <p v-if="verifiedAt" class="flex min-w-0 items-center gap-2 text-sm font-medium">
                        <span class="size-2 shrink-0 rounded-full bg-status-done" aria-hidden="true" />
                        Verified {{ verifiedAt }}
                    </p>
                    <p v-else class="flex min-w-0 items-center gap-2 text-sm font-medium">
                        <span class="size-2 shrink-0 rounded-full bg-status-waiting" aria-hidden="true" />
                        Not verified yet
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Written by the weekly backup check, which restores the latest dump into a scratch database
                        and counts the rows. It is read-only here because the date is a fact about what was tested,
                        not a setting — typing one in would only make the app claim something nobody verified.
                    </p>
                </CardContent>
            </Card>
        </div>
    </PageShell>
</template>
