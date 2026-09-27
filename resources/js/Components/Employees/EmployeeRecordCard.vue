<script setup lang="ts">
import { computed } from 'vue';
import type { EmployeeDetail, WeekdayOption } from '@/Components/Employees/employees';
import { formatDate, formatDateTime, humanise, roleLabel, scheduleSummary, trackingModeLabel } from '@/Components/Employees/employees';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * The record: who they are, what they do here, and how their time is measured.
 *
 * Every row is drawn **only when the payload carries it**. That is not politeness, it is the
 * privacy rule: `EmployeeResource` omits a field this viewer may not see (Part B §3 rule 1),
 * so an absent key is an answer and the screen's job is to render nothing rather than a dash
 * that implies the field is empty.
 *
 * There is no money on this card. Salary is `/salaries` (Phase 9) behind its own permission,
 * and nothing about pay reaches this screen.
 */

const props = defineProps<{
    employee: EmployeeDetail;
    weekdays?: WeekdayOption[];
}>();

interface Row {
    label: string;
    value: string;
    hint?: string;
}

const rows = computed<Row[]>(() => {
    const employee = props.employee;
    const list: Row[] = [];

    const push = (label: string, value: string | null | undefined, hint?: string): void => {
        if (value !== null && value !== undefined && value !== '') {
            list.push({ label, value, hint });
        }
    };

    push('Employee number', employee.employee_number);
    push('Email', employee.email);
    push('Phone', employee.phone);
    push('Role', roleLabel(employee), 'What they can reach across the whole agency.');
    push(
        'Employment type',
        employee.employment_type_label ?? humanise(employee.employment_type),
    );
    push('Joining date', formatDate(employee.joining_date));
    push('Manager', employee.manager?.name);
    push(
        'Tracking mode',
        trackingModeLabel(employee),
        'Whether they clock in at the office, run the remote timer, or neither.',
    );
    push(
        'Working week',
        scheduleSummary(employee, props.weekdays ?? []),
        'This is what decides an off day and a late arrival.',
    );
    push('Last signed in', formatDateTime(employee.last_login_at));

    return list;
});

/** A tracked person with no working week cannot clock in and is never marked absent. */
const missingSchedule = computed(
    () =>
        scheduleSummary(props.employee, props.weekdays ?? []) === null &&
        props.employee.tracking_mode !== null &&
        props.employee.tracking_mode !== undefined &&
        props.employee.tracking_mode !== 'none',
);
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Record</CardTitle>
            <CardDescription>Their details, their role, and how their working time is measured.</CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-4">
            <dl class="grid min-w-0 gap-4 sm:grid-cols-2">
                <div v-for="row in rows" :key="row.label" class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">{{ row.label }}</dt>
                    <dd class="text-sm break-words">{{ row.value }}</dd>
                    <p v-if="row.hint" class="text-xs text-muted-foreground">{{ row.hint }}</p>
                </div>
            </dl>

            <p v-if="missingSchedule" class="text-xs text-destructive">
                No working week is set. Until one is, this employee cannot clock in and is never marked absent.
            </p>
        </CardContent>
    </Card>
</template>
