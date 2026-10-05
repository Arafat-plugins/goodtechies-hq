<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { KeyRound, ListChecks, UserCheck, UserX } from '@lucide/vue';
import { computed, ref } from 'vue';
import type {
    EmployeeDetail,
    FirstSignInCredential,
    NamedRef,
    Option,
    WeekdayOption,
} from '@/Components/Employees/employees';
import {
    formatDate,
    openTasksUrl,
    roleLabel,
    statusLabel,
    statusTone,
    trackingModeLabel,
} from '@/Components/Employees/employees';
import EmployeeFirstSignInPanel from '@/Components/Employees/EmployeeFirstSignInPanel.vue';
import EmployeeRecordCard from '@/Components/Employees/EmployeeRecordCard.vue';
import EmployeeRoleCard from '@/Components/Employees/EmployeeRoleCard.vue';
import EmployeeStatusDialog from '@/Components/Employees/EmployeeStatusDialog.vue';
import ExtensionDevicesCard from '@/Components/Employees/ExtensionDevicesCard.vue';
import ProjectAccessCard from '@/Components/Employees/ProjectAccessCard.vue';
import PageShell from '@/Components/PageShell.vue';
import type { ExtensionDevice } from '@/Components/Profile/TimerExtensionCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * One employee: the record, the role, the tracking mode, the working week, the projects they
 * are on and the project-level permissions they have been granted.
 *
 * This is the second half of the screen family Part D §2 describes — *"Employees list →
 * employee detail → role/schedule/tracking_mode"* — and it is the one place the role, the
 * tracking mode and the two status moves live. The working week is the third of those three and
 * is read here but written on the Work Schedule screen, because `ScheduleService` is the only
 * writer of `schedules`. Deactivation is deliberately **not** on the list's row menu: the confirmation is
 * only worth reading when it can say how many open tasks are about to be left with nobody, and
 * that count arrives with this payload, not with a list row.
 *
 * Part B §3 rule 11 is the whole shape of this page: departure is `status = inactive`, the
 * record is kept for good, and the word for the opposite of active is *Inactive*.
 *
 * ## No permission is decided here, and no salary appears here
 *
 * `employee.permissions` arrives resolved by `EmployeePolicy`. An absent key is **no** and the
 * control is simply not drawn; the endpoint checks again regardless. There is no pay figure on
 * this screen — that is `/salaries` (Phase 9), behind its own permission.
 */

const props = defineProps<{
    /**
     * `EmployeeController::show()` resolves the resource flat; a `JsonResource` that wrapped in
     * `data` would arrive nested. Both are accepted so a later wrapping change is not a blank
     * page.
     */
    employee: EmployeeDetail | { data: EmployeeDetail };
    /** The seven weekday keys with their words. */
    weekdays?: WeekdayOption[];
    /**
     * What a project-permission grant may be made on. **Null** — which is what the controller
     * sends a viewer who may not grant — means no grant form is drawn at all.
     */
    grantableProjects?: NamedRef[] | null;
    grantablePermissions?: Option[] | null;
    /**
     * The roles and the tracking modes this viewer may assign. **Null** — what the controller
     * sends somebody `EmployeePolicy` refuses, their own record included — means the control is
     * not drawn at all. MANAGER is never in `roleOptions` (Part C §1 assigns it to nobody).
     */
    roleOptions?: Option[] | null;
    trackingModeOptions?: Option[] | null;
    /**
     * The generated first-sign-in password, on the redirect after a hire and **on no other
     * response**. Absent is the normal case; see `EmployeeFirstSignInPanel.vue` for why Back
     * cannot bring it back.
     */
    firstSignIn?: FirstSignInCredential | null;
    /** Phase 11: connected timer extensions. Null — no card — unless the server sent them. */
    extensionDevices: ExtensionDevice[] | null;
}>();

const employee = computed<EmployeeDetail>(() =>
    'data' in props.employee ? props.employee.data : props.employee,
);

const isActive = computed(() => employee.value.status === 'active');

/**
 * When the login was switched off, read by the server off the append-only audit trail — so the
 * date on this page is the date in the log by construction.
 */
const deactivatedOn = computed(() => formatDate(employee.value.deactivated_at));

/** The server counted their open tasks, so the page can show — and link — the number. */
const openTaskCount = computed<number | null>(() => employee.value.impact?.open_task_count ?? null);

const canDeactivate = computed(() => employee.value.permissions?.can_deactivate === true);
const canReactivate = computed(() => employee.value.permissions?.can_reactivate === true);

/**
 * Re-issuing a sign-in password.
 *
 * Offered on an ACTIVE record only: a password for somebody who cannot sign in at all is a
 * credential with nowhere to be used, and handing one out would say their access was restored
 * when it was not. Reactivate them first.
 */
const canResetPassword = computed(
    () => isActive.value && employee.value.permissions?.can_reset_password === true,
);

/* ----------------------------------------------------------------- actions */

const pending = ref<EmployeeDetail | null>(null);
const pendingIntent = ref<'deactivate' | 'reactivate' | 'reset-password'>('deactivate');

function ask(intent: 'deactivate' | 'reactivate' | 'reset-password'): void {
    pendingIntent.value = intent;
    pending.value = employee.value;
}
</script>

<template>
    <Head :title="employee.name" />

    <PageShell
        :title="employee.name"
        :description="`${roleLabel(employee)} · ${trackingModeLabel(employee)}`"
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Employees', href: '/admin/employees' }, { label: employee.name }]"
    >
        <template #actions>
            <!-- `PageShell` already wraps this slot in a wrapping flex row. -->
            <StatusBadge :status="statusTone(employee.status)" :label="statusLabel(employee)" />

            <Button v-if="canResetPassword" type="button" variant="outline" @click="ask('reset-password')">
                <KeyRound aria-hidden="true" />
                Re-issue password
            </Button>

            <Button v-if="isActive && canDeactivate" type="button" variant="outline" @click="ask('deactivate')">
                <UserX aria-hidden="true" />
                Deactivate
            </Button>
            <Button v-else-if="!isActive && canReactivate" type="button" @click="ask('reactivate')">
                <UserCheck aria-hidden="true" />
                Reactivate
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <!--
                Said once, above everything else, because it is the only thing on this page that
                cannot be looked up again five minutes from now.
            -->
            <EmployeeFirstSignInPanel v-if="firstSignIn" :credential="firstSignIn" />

            <!--
                The state in WORDS, not in a grey row (DESIGN.md §5.6). An inactive record is
                still a record, and this says exactly what that means.
            -->
            <Alert v-if="!isActive">
                <AlertTitle>{{ employee.name }} is inactive</AlertTitle>
                <AlertDescription>
                    <p>
                        They cannot sign in<template v-if="deactivatedOn"> — their login was switched off on
                        {{ deactivatedOn }}</template
                        >. Their record, their tasks, their tracked time, their attendance and their leave are all
                        kept: this page is the history, not a leftover.
                    </p>
                    <p v-if="employee.is_you">This is your own account.</p>
                </AlertDescription>
            </Alert>

            <Alert v-else-if="employee.is_you">
                <AlertTitle>This is your own account</AlertTitle>
                <AlertDescription>
                    You cannot change your own role or take away your own access — another Admin does that. It is
                    what stops the last Admin locking everybody out, this account included.
                </AlertDescription>
            </Alert>

            <div class="grid min-w-0 gap-4 lg:grid-cols-3">
                <div :class="openTaskCount === null ? 'min-w-0 lg:col-span-3' : 'min-w-0 lg:col-span-2'">
                    <EmployeeRecordCard :employee="employee" :weekdays="weekdays" />
                </div>

                <!--
                    A count of something openable is a link carrying the filters it was counted
                    with — here, this employee and the open bucket, which is exactly what
                    `/admin/tasks` reads. It is absent when the server did not count, because a
                    zero we invented is a zero somebody would act on.
                -->
                <Link
                    v-if="openTaskCount !== null"
                    :href="openTasksUrl(employee)"
                    class="flex h-fit min-w-0 items-center gap-3 rounded-xl border bg-card p-4 shadow-raised hover:bg-accent"
                >
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                        <ListChecks class="size-4 text-muted-foreground" aria-hidden="true" />
                    </span>
                    <span class="flex min-w-0 flex-col">
                        <span class="text-sm font-medium tabular-nums">
                            {{ openTaskCount }} open {{ openTaskCount === 1 ? 'task' : 'tasks' }}
                        </span>
                        <span class="text-xs text-muted-foreground">Assigned to {{ employee.name }}</span>
                    </span>
                </Link>
            </div>

            <EmployeeRoleCard
                :employee="employee"
                :role-options="roleOptions ?? undefined"
                :tracking-mode-options="trackingModeOptions ?? undefined"
            />

            <ProjectAccessCard
                :employee="employee"
                :grantable-projects="grantableProjects ?? undefined"
                :grantable-permissions="grantablePermissions ?? undefined"
            />

            <ExtensionDevicesCard
                v-if="extensionDevices !== null"
                :devices="extensionDevices"
                :employee-id="employee.id"
            />
        </div>
    </PageShell>

    <EmployeeStatusDialog :employee="pending" :intent="pendingIntent" @close="pending = null" />
</template>
