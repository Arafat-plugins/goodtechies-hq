<script lang="ts">
export interface LoginAttempt {
    succeeded: boolean;
    ip: string | null;
    userAgent: string | null;
    at: string;
}
</script>

<script setup lang="ts">
import { History } from '@lucide/vue';
import { computed } from 'vue';
import { deviceSummary } from '@/Components/Profile/SessionsCard.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { cn } from '@/lib/utils';

const props = defineProps<{
    attempts: LoginAttempt[];
}>();

// Shown in the browser's own locale and timezone.
const dateTime = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

function formatWhen(iso: string): string {
    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? iso : dateTime.format(date);
}

const rows = computed(() =>
    props.attempts.map((attempt, index) => ({
        key: `${attempt.at}-${index}`,
        when: formatWhen(attempt.at),
        succeeded: attempt.succeeded,
        ip: attempt.ip ?? '—',
        device: deviceSummary(attempt.userAgent),
    })),
);

const headClass = 'text-xs uppercase text-muted-foreground';
</script>

<template>
    <Card class="min-w-0 gap-4 shadow-xs">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Login history</CardTitle>
            <CardDescription>Your most recent sign-in attempts.</CardDescription>
        </CardHeader>
        <CardContent class="min-w-0">
            <div v-if="rows.length > 0" class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead :class="headClass">When</TableHead>
                            <TableHead :class="headClass">Result</TableHead>
                            <TableHead :class="headClass">IP</TableHead>
                            <TableHead :class="headClass">Device</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in rows" :key="row.key" class="even:bg-muted/40">
                            <TableCell class="tabular-nums">{{ row.when }}</TableCell>
                            <TableCell>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium"
                                >
                                    <span
                                        :class="
                                            cn(
                                                'size-1.5 rounded-full',
                                                row.succeeded ? 'bg-status-done' : 'bg-status-cancelled',
                                            )
                                        "
                                        aria-hidden="true"
                                    />
                                    {{ row.succeeded ? 'Success' : 'Failed' }}
                                </span>
                            </TableCell>
                            <TableCell class="tabular-nums text-muted-foreground">{{ row.ip }}</TableCell>
                            <TableCell>{{ row.device }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-else class="flex flex-col items-center gap-2 py-4 text-center">
                <span class="rounded-full bg-muted p-3 text-muted-foreground" aria-hidden="true">
                    <History class="size-5" />
                </span>
                <p class="text-sm font-medium">No sign-ins recorded yet.</p>
                <p class="text-xs text-muted-foreground">Each sign-in to your account will be listed here.</p>
            </div>
        </CardContent>
    </Card>
</template>
