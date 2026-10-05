<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { AcceptableValue } from 'reka-ui';
import type { ActivityDayPayload } from '@/Components/Activity/activity';
import ActivityDay from '@/Components/Activity/ActivityDay.vue';
import PageShell from '@/Components/PageShell.vue';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * Admin → Workforce → Time → Activity: one remote employee's day, minute by minute. The list of
 * people is the server's — remote-timer employees this Admin may see — sorted by name.
 */
const props = defineProps<{
    activity: ActivityDayPayload;
    employees: { id: number; name: string }[];
}>();

function switchEmployee(value: AcceptableValue): void {
    const id = Number(value);

    if (!Number.isInteger(id) || id === props.activity.employee.id) {
        return;
    }

    router.visit(`/admin/time/activity/${id}?date=${props.activity.date.value}`);
}
</script>

<template>
    <Head title="Activity" />

    <PageShell title="Activity" :breadcrumb="[{ label: 'Workforce' }, { label: 'Time', href: '/admin/time' }, { label: 'Activity' }]">
        <template #actions>
            <Label for="activity-employee" class="sr-only">Employee</Label>
            <Select :model-value="String(activity.employee.id)" @update:model-value="switchEmployee">
                <SelectTrigger id="activity-employee" class="w-full min-w-0 sm:w-56">
                    <SelectValue placeholder="Employee" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="option in employees" :key="option.id" :value="String(option.id)">
                        {{ option.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </template>

        <ActivityDay :activity="activity" />
    </PageShell>
</template>
