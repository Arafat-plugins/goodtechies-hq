<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import type { EmployeeFormOptions } from '@/Components/Employees/employees';
import { employeeRoutes } from '@/Components/Employees/employees';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

/**
 * Add an employee — `docs/design-refs/04-add-employee-form.png`.
 *
 * Part I's Take column for that reference: *two-card form (Basic information / Employment),
 * required-field asterisks, helper text under fields, weekly day toggle row, Cancel / Create
 * employee footer*, with Part D §20's employee fields. Its Leave column — Branch, Department,
 * Designation, Nationality, Arabic display name, Middle name — is why there is one **Full
 * name** field here and not three: one field per meaning.
 *
 * It is a dialog rather than a page because the Employees family is a list and a detail, and a
 * third address for a form that is four selects and six inputs would be a third screen to keep
 * in step. The reference's two cards survive as the dialog's two `<fieldset>` sections; at
 * phone width it is the full-height sheet `QuickAddTaskModal` already established.
 *
 * Nothing here decides who may create an employee: the caller draws the button only when the
 * server sent the options, and `StoreEmployeeRequest` + `EmployeePolicy` decide again.
 */

const props = defineProps<{
    open: boolean;
    options: EmployeeFormOptions;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    created: [];
}>();

const weekdays = computed(() => props.options.weekdays ?? []);
const managers = computed(() => props.options.managers ?? []);

const form = useForm({
    name: '',
    email: '',
    employee_number: '',
    phone: '',
    role: '',
    employment_type: '',
    joining_date: '',
    manager_id: '',
    tracking_mode: '',
    working_days: ['sun', 'mon', 'tue', 'wed', 'thu'] as string[],
    working_hours_per_day: '8',
    start_time: '',
    office_or_remote: 'office',
});

function toggleDay(day: string, checked: boolean): void {
    form.working_days = checked
        ? [...new Set([...form.working_days, day])]
        : form.working_days.filter((value) => value !== day);
}

function submit(): void {
    if (form.processing) {
        return;
    }

    form
        .transform((data) => ({
            ...data,
            // An empty optional field is *absent*, not an empty string: `''` is a value the
            // request would have to special-case, and a blank start time is the difference
            // between a flexible day and a day that can be Late.
            email: data.email.trim(),
            employee_number: data.employee_number.trim() === '' ? null : data.employee_number.trim(),
            phone: data.phone.trim() === '' ? null : data.phone.trim(),
            joining_date: data.joining_date === '' ? null : data.joining_date,
            manager_id: data.manager_id === '' ? null : Number(data.manager_id),
            start_time: data.start_time === '' ? null : data.start_time,
        }))
        .post(employeeRoutes.store, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                emit('created');
                emit('update:open', false);
            },
        });
}

/** A cancelled form does not leave its errors behind for the next person who opens it. */
watch(
    () => props.open,
    (open) => {
        if (!open) {
            form.clearErrors();
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="flex max-h-[90svh] w-[calc(100vw-2rem)] max-w-2xl flex-col gap-0 p-0 sm:w-full">
            <form class="flex min-h-0 flex-col" novalidate @submit.prevent="submit">
                <DialogHeader class="gap-1 border-b p-4 pr-12 text-left sm:p-6 sm:pr-12">
                    <DialogTitle class="text-xl">Add an employee</DialogTitle>
                    <DialogDescription>
                        They get an account, a role and a working week. Everything here can be changed afterwards.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 sm:p-6">
                    <fieldset class="flex min-w-0 flex-col gap-4">
                        <legend class="text-sm font-medium">Basic information</legend>
                        <p class="text-xs text-muted-foreground">Who they are and how to reach them.</p>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-name">
                                    Full name <span class="text-destructive" aria-hidden="true">*</span>
                                </Label>
                                <Input
                                    id="employee-name"
                                    v-model="form.name"
                                    name="name"
                                    autocomplete="name"
                                    required
                                    :disabled="form.processing"
                                    :aria-invalid="form.errors.name ? true : undefined"
                                    :aria-describedby="form.errors.name ? 'employee-name-error' : undefined"
                                />
                                <p v-if="form.errors.name" id="employee-name-error" class="text-xs text-destructive">
                                    {{ form.errors.name }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-email">
                                    Email <span class="text-destructive" aria-hidden="true">*</span>
                                </Label>
                                <Input
                                    id="employee-email"
                                    v-model="form.email"
                                    name="email"
                                    type="email"
                                    autocomplete="email"
                                    required
                                    :disabled="form.processing"
                                    :aria-invalid="form.errors.email ? true : undefined"
                                    aria-describedby="employee-email-help"
                                />
                                <p id="employee-email-help" class="text-xs text-muted-foreground">
                                    This is what they sign in with.
                                </p>
                                <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-number">Employee number</Label>
                                <Input
                                    id="employee-number"
                                    v-model="form.employee_number"
                                    name="employee_number"
                                    :disabled="form.processing"
                                    :aria-invalid="form.errors.employee_number ? true : undefined"
                                    aria-describedby="employee-number-help"
                                />
                                <p id="employee-number-help" class="text-xs text-muted-foreground">
                                    Unique across the agency. Leave it empty and the next one is assigned.
                                </p>
                                <p v-if="form.errors.employee_number" class="text-xs text-destructive">
                                    {{ form.errors.employee_number }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-phone">Phone</Label>
                                <Input
                                    id="employee-phone"
                                    v-model="form.phone"
                                    name="phone"
                                    type="tel"
                                    autocomplete="tel"
                                    :disabled="form.processing"
                                    :aria-invalid="form.errors.phone ? true : undefined"
                                />
                                <p v-if="form.errors.phone" class="text-xs text-destructive">{{ form.errors.phone }}</p>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="flex min-w-0 flex-col gap-4">
                        <legend class="text-sm font-medium">Employment</legend>
                        <p class="text-xs text-muted-foreground">
                            What they do here, and how their working time is measured.
                        </p>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-role">
                                    Role <span class="text-destructive" aria-hidden="true">*</span>
                                </Label>
                                <Select v-model="form.role" :disabled="form.processing">
                                    <SelectTrigger
                                        id="employee-role"
                                        class="w-full"
                                        :aria-invalid="form.errors.role ? true : undefined"
                                    >
                                        <SelectValue placeholder="Choose a role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="role in options.roles" :key="role.value" :value="role.value">
                                            {{ role.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p id="employee-role-help" class="text-xs text-muted-foreground">
                                    The role decides what they can reach across the whole agency.
                                </p>
                                <p v-if="form.errors.role" class="text-xs text-destructive">{{ form.errors.role }}</p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-tracking-mode">
                                    Tracking mode <span class="text-destructive" aria-hidden="true">*</span>
                                </Label>
                                <Select v-model="form.tracking_mode" :disabled="form.processing">
                                    <SelectTrigger
                                        id="employee-tracking-mode"
                                        class="w-full"
                                        :aria-invalid="form.errors.tracking_mode ? true : undefined"
                                    >
                                        <SelectValue placeholder="Choose how time is recorded" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="mode in options.tracking_modes"
                                            :key="mode.value"
                                            :value="mode.value"
                                        >
                                            {{ mode.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p id="employee-tracking-mode-help" class="text-xs text-muted-foreground">
                                    Whether they clock in at the office, run the remote timer, or neither.
                                </p>
                                <p v-if="form.errors.tracking_mode" class="text-xs text-destructive">
                                    {{ form.errors.tracking_mode }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-employment-type">
                                    Employment type <span class="text-destructive" aria-hidden="true">*</span>
                                </Label>
                                <Select v-model="form.employment_type" :disabled="form.processing">
                                    <SelectTrigger
                                        id="employee-employment-type"
                                        class="w-full"
                                        :aria-invalid="form.errors.employment_type ? true : undefined"
                                    >
                                        <SelectValue placeholder="Choose an employment type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="type in options.employment_types"
                                            :key="type.value"
                                            :value="type.value"
                                        >
                                            {{ type.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="form.errors.employment_type" class="text-xs text-destructive">
                                    {{ form.errors.employment_type }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-joining-date">Joining date</Label>
                                <Input
                                    id="employee-joining-date"
                                    v-model="form.joining_date"
                                    name="joining_date"
                                    type="date"
                                    :disabled="form.processing"
                                    :aria-invalid="form.errors.joining_date ? true : undefined"
                                />
                                <p v-if="form.errors.joining_date" class="text-xs text-destructive">
                                    {{ form.errors.joining_date }}
                                </p>
                            </div>

                            <!-- Only when the server sent a list to choose from. -->
                            <div v-if="managers.length > 0" class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-manager">Manager</Label>
                                <Select v-model="form.manager_id" :disabled="form.processing">
                                    <SelectTrigger
                                        id="employee-manager"
                                        class="w-full"
                                        :aria-invalid="form.errors.manager_id ? true : undefined"
                                    >
                                        <SelectValue placeholder="Nobody" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="manager in managers"
                                            :key="manager.id"
                                            :value="String(manager.id)"
                                        >
                                            {{ manager.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="form.errors.manager_id" class="text-xs text-destructive">
                                    {{ form.errors.manager_id }}
                                </p>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="flex min-w-0 flex-col gap-4">
                        <legend class="text-sm font-medium">Working week</legend>
                        <p class="text-xs text-muted-foreground">
                            Which days count as working days, how long a day is, and when it starts. This is what
                            decides an off day and a late arrival.
                        </p>

                        <div v-if="weekdays.length > 0" class="flex min-w-0 flex-col gap-2">
                            <span id="employee-working-days-label" class="text-sm font-medium">Working days</span>
                            <div class="flex flex-wrap gap-3" role="group" aria-labelledby="employee-working-days-label">
                                <div v-for="day in weekdays" :key="day.value" class="flex items-center gap-2">
                                    <Checkbox
                                        :id="`employee-day-${day.value}`"
                                        :model-value="form.working_days.includes(day.value)"
                                        :disabled="form.processing"
                                        @update:model-value="toggleDay(day.value, $event === true)"
                                    />
                                    <Label :for="`employee-day-${day.value}`" class="text-sm">{{ day.short }}</Label>
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Anything left unticked is an off day — they are never marked absent on it.
                            </p>
                            <p v-if="form.errors.working_days" class="text-xs text-destructive">
                                {{ form.errors.working_days }}
                            </p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-hours">Hours a day</Label>
                                <Input
                                    id="employee-hours"
                                    v-model="form.working_hours_per_day"
                                    name="working_hours_per_day"
                                    type="number"
                                    step="0.25"
                                    min="0.5"
                                    max="24"
                                    :disabled="form.processing"
                                    :aria-invalid="form.errors.working_hours_per_day ? true : undefined"
                                />
                                <p v-if="form.errors.working_hours_per_day" class="text-xs text-destructive">
                                    {{ form.errors.working_hours_per_day }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-start-time">Start time</Label>
                                <Input
                                    id="employee-start-time"
                                    v-model="form.start_time"
                                    name="start_time"
                                    type="time"
                                    :disabled="form.processing"
                                    :aria-invalid="form.errors.start_time ? true : undefined"
                                    aria-describedby="employee-start-time-help"
                                />
                                <p id="employee-start-time-help" class="text-xs text-muted-foreground">
                                    Leave it empty for a flexible day — somebody with no start time is never Late.
                                </p>
                                <p v-if="form.errors.start_time" class="text-xs text-destructive">
                                    {{ form.errors.start_time }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="employee-location">Work location</Label>
                                <Select v-model="form.office_or_remote" :disabled="form.processing">
                                    <SelectTrigger
                                        id="employee-location"
                                        class="w-full"
                                        :aria-invalid="form.errors.office_or_remote ? true : undefined"
                                    >
                                        <SelectValue placeholder="Office or remote" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="office">Office</SelectItem>
                                        <SelectItem value="remote">Remote</SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="form.errors.office_or_remote" class="text-xs text-destructive">
                                    {{ form.errors.office_or_remote }}
                                </p>
                            </div>
                        </div>
                    </fieldset>
                </div>

                <DialogFooter class="flex-col gap-2 border-t p-4 sm:flex-row sm:justify-end sm:p-6">
                    <Button
                        type="button"
                        variant="outline"
                        class="w-full sm:w-auto"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" class="w-full sm:w-auto" :disabled="form.processing">
                        {{ form.processing ? 'Creating…' : 'Create employee' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
