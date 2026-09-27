<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { EmployeeDetail, Option } from '@/Components/Employees/employees';
import { employeeRoutes, roleLabel, trackingModeLabel } from '@/Components/Employees/employees';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

/**
 * The two writable thirds of Part D §2's *"Employees list → employee detail →
 * role/schedule/tracking_mode"*. The third, the working week, is the Work Schedule editor's —
 * `ScheduleService` is the only writer of `schedules`.
 *
 * Until this card existed the screen named **Users & Roles** could not change a role:
 * `EmployeeAdministrationService::changeRole()` was written, audited and self-guarded, and had
 * no caller anywhere in the application. `employees.tracking_mode` had no writer at all.
 *
 * ## Neither control is drawn unless the server offered it
 *
 * `roleOptions` / `trackingModeOptions` are **null** for a viewer `EmployeePolicy` refuses —
 * which includes an Admin looking at their own record, because Part C §1 is *"ADMIN ✅ (not own
 * account)"*. Null means the block is absent, not disabled (DESIGN.md §5.12), and the options
 * themselves are the server's, so a select can never offer a value the Form Request would then
 * refuse. Nothing here tests a role name or a permission key.
 *
 * ## Both changes are confirmed, and the tracking one says what it costs
 *
 * A role change is confirmed because it changes what somebody may reach across the whole agency.
 * A tracking-mode change is confirmed because of decision 4-11: an office-attendance employee's
 * days are rows in `attendance_records` and a remote-timer employee's are rows in `time_entries`,
 * and a remote-timer employee has **no attendance row at all** — so switching somebody moves the
 * line, and nothing moves the days that are already on the other side of it. The dialog says that
 * in words rather than asking "are you sure", the way `EmployeeStatusDialog` does.
 */

const props = defineProps<{
    employee: EmployeeDetail;
    /** Absent means the role control is not drawn. MANAGER is never in this list (Part C §1). */
    roleOptions?: Option[];
    /** Absent means the tracking control is not drawn. */
    trackingModeOptions?: Option[];
}>();

const canChangeRole = computed(
    () => props.employee.permissions?.can_change_role === true && (props.roleOptions?.length ?? 0) > 0,
);

const canChangeTracking = computed(
    () =>
        props.employee.permissions?.can_change_tracking_mode === true &&
        (props.trackingModeOptions?.length ?? 0) > 0,
);

/* ------------------------------------------------------------------- role */

const roleForm = useForm({ role: props.employee.role ?? '' });
const askingRole = ref(false);

const roleChanged = computed(() => roleForm.role !== '' && roleForm.role !== (props.employee.role ?? ''));

const chosenRoleLabel = computed(
    () => props.roleOptions?.find((option) => option.value === roleForm.role)?.label ?? roleForm.role,
);

function submitRole(): void {
    roleForm.put(employeeRoutes.role(props.employee.id), {
        preserveScroll: true,
        onSuccess: () => (askingRole.value = false),
    });
}

/* ---------------------------------------------------------- tracking mode */

const trackingForm = useForm({ tracking_mode: props.employee.tracking_mode ?? '' });
const askingTracking = ref(false);

const trackingChanged = computed(
    () => trackingForm.tracking_mode !== '' && trackingForm.tracking_mode !== (props.employee.tracking_mode ?? ''),
);

const chosenTrackingLabel = computed(
    () =>
        props.trackingModeOptions?.find((option) => option.value === trackingForm.tracking_mode)?.label ??
        trackingForm.tracking_mode,
);

/**
 * What each mode means for the days from here on, in the words the rest of the app uses.
 *
 * Keyed on the mode's own value with a fallback, so a mode this file has not heard of loses its
 * sentence rather than the screen — the same contract every `*_label ?? humanise()` here keeps.
 */
const TRACKING_CONSEQUENCE: Record<string, string> = {
    office_attendance:
        'They clock in and out. Their days become attendance records, they can be marked Late against their start time, and the daily sweep can mark them Absent.',
    remote_timer:
        'They run the timer. Their days become tracked time entries against tasks, and they get no attendance record at all — so they are never marked Late and never marked Absent.',
    none: 'Nobody measures their time. No clock-in, no timer, no attendance record and no absent marking — which is how the Accountant is recorded.',
};

const trackingConsequence = computed(() => TRACKING_CONSEQUENCE[trackingForm.tracking_mode] ?? null);

function submitTracking(): void {
    trackingForm.put(employeeRoutes.trackingMode(props.employee.id), {
        preserveScroll: true,
        onSuccess: () => (askingTracking.value = false),
    });
}

/*
 * The selects follow the record after a save, so the control shows what is stored rather than
 * what was last typed — and a change somebody abandoned does not sit in the box looking pending.
 */
watch(
    () => props.employee.role,
    (role) => {
        roleForm.role = role ?? '';
        roleForm.clearErrors();
    },
);

watch(
    () => props.employee.tracking_mode,
    (mode) => {
        trackingForm.tracking_mode = mode ?? '';
        trackingForm.clearErrors();
    },
);
</script>

<template>
    <!-- `#role-and-tracking` so the record card's Role row has somewhere to point. -->
    <Card v-if="canChangeRole || canChangeTracking" id="role-and-tracking" class="min-w-0 scroll-mt-20 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Role and tracking mode</CardTitle>
            <CardDescription>
                What {{ employee.name }} can reach across the agency, and how their working time is measured. Both
                changes are recorded in the audit log.
            </CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-6">
            <section v-if="canChangeRole" class="flex min-w-0 flex-col gap-2">
                <Label for="employee-role">Role</Label>
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <Select v-model="roleForm.role" :disabled="roleForm.processing">
                        <SelectTrigger
                            id="employee-role"
                            class="w-full sm:max-w-xs"
                            :aria-invalid="roleForm.errors.role ? true : undefined"
                        >
                            <SelectValue placeholder="Choose a role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in roleOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <!--
                        Outline, not primary: the page's other two controls (Deactivate, Grant
                        permission) are outline, and the primary button of this act belongs to
                        the confirmation that follows. DESIGN.md §5.3 — the accent appears once
                        per screen, and three orange things on one page means two are wrong.
                    -->
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="sm:shrink-0"
                        :disabled="!roleChanged || roleForm.processing"
                        @click="askingRole = true"
                    >
                        Change role
                    </Button>
                </div>
                <p v-if="roleForm.errors.role" class="text-xs text-destructive">{{ roleForm.errors.role }}</p>
                <p class="text-xs text-muted-foreground">
                    Currently {{ roleLabel(employee) }}. A role decides what they can reach everywhere, not on one
                    project — that is a grant, below.
                </p>
            </section>

            <section v-if="canChangeTracking" class="flex min-w-0 flex-col gap-2">
                <Label for="employee-tracking-mode">Tracking mode</Label>
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <Select v-model="trackingForm.tracking_mode" :disabled="trackingForm.processing">
                        <SelectTrigger
                            id="employee-tracking-mode"
                            class="w-full sm:max-w-xs"
                            :aria-invalid="trackingForm.errors.tracking_mode ? true : undefined"
                        >
                            <SelectValue placeholder="Choose a tracking mode" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in trackingModeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="sm:shrink-0"
                        :disabled="!trackingChanged || trackingForm.processing"
                        @click="askingTracking = true"
                    >
                        Change tracking
                    </Button>
                </div>
                <p v-if="trackingForm.errors.tracking_mode" class="text-xs text-destructive">
                    {{ trackingForm.errors.tracking_mode }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Currently {{ trackingModeLabel(employee) }}. This is how their time is measured, not where they
                    work — where is the working week, on the Work Schedule screen.
                </p>
            </section>
        </CardContent>
    </Card>

    <Dialog :open="askingRole" @update:open="(open) => !open && (askingRole = false)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Make {{ employee.name }} {{ chosenRoleLabel }}?</DialogTitle>
                <DialogDescription>
                    A role is agency-wide. This is the only thing that changes — their tasks, tracked time,
                    attendance, leave and messages all stay as they are.
                </DialogDescription>
            </DialogHeader>

            <ul class="flex flex-col gap-2 text-sm">
                <li class="flex gap-2">
                    <span aria-hidden="true" class="text-muted-foreground">•</span>
                    <span>
                        They go from <strong class="font-medium">{{ roleLabel(employee) }}</strong> to
                        <strong class="font-medium">{{ chosenRoleLabel }}</strong
                        >, and what they can reach changes with it.
                    </span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="text-muted-foreground">•</span>
                    <span>
                        They stay signed in. The new role applies from their next request, and if it belongs to a
                        different part of the app that is where they land.
                    </span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="text-muted-foreground">•</span>
                    <span>The change is recorded in the audit log, with the old role and the new one.</span>
                </li>
            </ul>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="roleForm.processing" @click="askingRole = false">
                    Cancel
                </Button>
                <Button type="button" :disabled="roleForm.processing" @click="submitRole">
                    {{ roleForm.processing ? 'Changing…' : 'Change role' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog :open="askingTracking" @update:open="(open) => !open && (askingTracking = false)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Measure {{ employee.name }}'s time by {{ chosenTrackingLabel.toLowerCase() }}?</DialogTitle>
                <DialogDescription>
                    This decides how their working day is recorded from now on. It does not move anything that is
                    already recorded.
                </DialogDescription>
            </DialogHeader>

            <ul class="flex flex-col gap-2 text-sm">
                <li v-if="trackingConsequence" class="flex gap-2">
                    <span aria-hidden="true" class="text-muted-foreground">•</span>
                    <span>{{ trackingConsequence }}</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="text-muted-foreground">•</span>
                    <span>
                        <strong class="font-medium">Their history is not converted.</strong> Attendance records they
                        already have stay attendance records, and tracked time they already have stays tracked time.
                        Nothing is rewritten, nothing is deleted, and no day is counted twice.
                    </span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="text-muted-foreground">•</span>
                    <span>
                        So their record will read in two halves — the days before today the old way, the days after it
                        the new way. Reports and payroll read both.
                    </span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="text-muted-foreground">•</span>
                    <span>The change is recorded in the audit log, with the old mode and the new one.</span>
                </li>
            </ul>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="trackingForm.processing"
                    @click="askingTracking = false"
                >
                    Cancel
                </Button>
                <Button type="button" :disabled="trackingForm.processing" @click="submitTracking">
                    {{ trackingForm.processing ? 'Changing…' : 'Change tracking mode' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
