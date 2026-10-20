<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Gift, Plus } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import { formatMoney, payrollRoutes } from '@/Components/Payroll/payroll';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
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
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * **Bonuses** — Eid, annual, performance, special reward and custom bonuses, with their history.
 *
 * An approved bonus is paid with the payroll of the month its payment date falls in:
 * `BonusService::syncPeriodFor()` writes the sum of a line's approved bonuses into its `bonus`.
 * Which controls exist is the server's answer (`permissions`), never worked out here.
 */
defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

interface Option {
    value: string;
    label: string;
}

interface BonusRow {
    id: number;
    employee: { id: number; name: string };
    type: string;
    type_label: string;
    title: string | null;
    display_name: string;
    amount: string;
    currency: string;
    payment_date: string;
    reason: string | null;
    status: 'pending' | 'approved' | 'paid' | 'cancelled';
    status_label: string;
    period: { id: number; month: string } | null;
    created_by: string | null;
    approved_by: string | null;
    cancelled_by: string | null;
    cancellation_reason: string | null;
    permissions: { can_update: boolean; can_approve: boolean; can_cancel: boolean };
}

interface Paginator<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

const props = defineProps<{
    bonuses: Paginator<BonusRow>;
    filters: { employee: number | null; status: string | null; type: string | null; month: string | null };
    employees: { id: number; name: string }[];
    types: Option[];
    statuses: Option[];
    currency: string;
    permissions: { can_create: boolean; can_approve: boolean };
    today: string;
}>();

/* ------------------------------------------------------------ filters */

const filterEmployee = ref(props.filters.employee === null ? '' : String(props.filters.employee));
const filterStatus = ref(props.filters.status ?? '');
const filterType = ref(props.filters.type ?? '');
const filterMonth = ref(props.filters.month ?? '');

const hasFilters = computed(
    () => filterEmployee.value !== '' || filterStatus.value !== '' || filterType.value !== '' || filterMonth.value !== '',
);

function applyFilters(): void {
    const query: Record<string, string> = {};
    if (filterEmployee.value !== '') query.employee = filterEmployee.value;
    if (filterStatus.value !== '') query.status = filterStatus.value;
    if (filterType.value !== '') query.type = filterType.value;
    if (filterMonth.value !== '') query.month = filterMonth.value;

    router.get(payrollRoutes.bonuses.index(), query, { preserveState: true, replace: true });
}

function clearFilters(): void {
    filterEmployee.value = '';
    filterStatus.value = '';
    filterType.value = '';
    filterMonth.value = '';
    applyFilters();
}

/* ------------------------------------------------------------ display */

function badgeVariant(status: BonusRow['status']): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'approved':
            return 'default';
        case 'paid':
            return 'secondary';
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
    }
}

function monthLabelOf(date: string): string {
    const [year, month] = date.split('-').map(Number);
    if (!year || !month) {
        return date;
    }

    return new Date(Date.UTC(year, month - 1, 1)).toLocaleString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
}

/** The paginator's labels carry `&laquo;` / `&raquo;`; shown as text, never as HTML. */
function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

/* ------------------------------------------------------------ add */

const addOpen = ref(false);
const employeeSearch = ref('');
const approveNow = ref(false);
const ids = useId();

const addForm = useForm<{
    employee_ids: number[];
    type: string;
    title: string;
    amount: string;
    currency: string;
    payment_date: string;
    reason: string;
    status: 'pending' | 'approved';
}>({
    employee_ids: [],
    type: 'eid',
    title: '',
    amount: '',
    currency: props.currency,
    payment_date: props.today,
    reason: '',
    status: 'pending',
});

const shownEmployees = computed(() => {
    const needle = employeeSearch.value.trim().toLowerCase();

    return needle === '' ? props.employees : props.employees.filter((employee) => employee.name.toLowerCase().includes(needle));
});

const employeeErrors = computed(() => {
    const errors = addForm.errors as Record<string, string | undefined>;

    return Object.entries(errors)
        .filter(([key, message]) => (key === 'employee_ids' || key.startsWith('employee_ids.')) && message)
        .map(([, message]) => message as string);
});

function toggleEmployee(id: number, checked: boolean): void {
    const set = new Set(addForm.employee_ids);
    if (checked) {
        set.add(id);
    } else {
        set.delete(id);
    }
    addForm.employee_ids = [...set];
}

function selectAllShown(): void {
    addForm.employee_ids = [...new Set([...addForm.employee_ids, ...shownEmployees.value.map((employee) => employee.id)])];
}

function openAdd(): void {
    addForm.reset();
    addForm.clearErrors();
    addForm.currency = props.currency;
    addForm.payment_date = props.today;
    employeeSearch.value = '';
    approveNow.value = false;
    addOpen.value = true;
}

function submitAdd(): void {
    addForm
        .transform((data) => ({
            ...data,
            title: data.type === 'custom' ? data.title : null,
            status: props.permissions.can_approve && approveNow.value ? 'approved' : 'pending',
        }))
        .post(payrollRoutes.bonuses.store(), {
            preserveScroll: true,
            onSuccess: () => {
                addOpen.value = false;
            },
        });
}

/* ------------------------------------------------------------ edit */

const editing = ref<BonusRow | null>(null);

const editForm = useForm({
    type: 'eid',
    title: '',
    amount: '',
    currency: props.currency,
    payment_date: props.today,
    reason: '',
});

function openEdit(bonus: BonusRow): void {
    editForm.clearErrors();
    editForm.type = bonus.type;
    editForm.title = bonus.title ?? '';
    editForm.amount = bonus.amount;
    editForm.currency = bonus.currency;
    editForm.payment_date = bonus.payment_date;
    editForm.reason = bonus.reason ?? '';
    editing.value = bonus;
}

function submitEdit(): void {
    const bonus = editing.value;
    if (!bonus) {
        return;
    }

    editForm
        .transform((data) => ({ ...data, title: data.type === 'custom' ? data.title : null }))
        .put(payrollRoutes.bonuses.update(bonus.id), {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = null;
            },
        });
}

/* ------------------------------------------------------------ cancel */

const cancelling = ref<BonusRow | null>(null);
const cancelForm = useForm({ reason: '' });

function openCancel(bonus: BonusRow): void {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelling.value = bonus;
}

function submitCancel(): void {
    const bonus = cancelling.value;
    if (!bonus) {
        return;
    }

    cancelForm.post(payrollRoutes.bonuses.cancel(bonus.id), {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

/* ------------------------------------------------------------ approve */

const approving = ref<BonusRow | null>(null);
const approveProcessing = ref(false);

function submitApprove(): void {
    const bonus = approving.value;
    if (!bonus) {
        return;
    }

    router.post(payrollRoutes.bonuses.approve(bonus.id), {}, {
        preserveScroll: true,
        onStart: () => {
            approveProcessing.value = true;
        },
        onFinish: () => {
            approveProcessing.value = false;
            approving.value = null;
        },
    });
}
</script>

<template>
    <Head title="Bonuses" />

    <PageShell
        title="Bonuses"
        description="Eid, annual, performance and other bonuses. An approved bonus is paid with the payroll of the month its payment date falls in."
        :breadcrumb="[{ label: 'Finance' }, { label: 'Payroll', href: payrollRoutes.index() }, { label: 'Bonuses' }]"
    >
        <template v-if="permissions.can_create" #actions>
            <Button size="sm" @click="openAdd">
                <Plus aria-hidden="true" />
                Add bonus
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <!-- Filters -->
            <div class="flex min-w-0 flex-wrap items-end gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <Label :for="`${ids}-f-employee`" class="text-xs text-muted-foreground">Employee</Label>
                    <NativeSelect :id="`${ids}-f-employee`" v-model="filterEmployee" class="h-9" @change="applyFilters">
                        <option value="">All employees</option>
                        <option v-for="employee in employees" :key="employee.id" :value="String(employee.id)">
                            {{ employee.name }}
                        </option>
                    </NativeSelect>
                </div>
                <div class="flex min-w-0 flex-col gap-1">
                    <Label :for="`${ids}-f-status`" class="text-xs text-muted-foreground">Status</Label>
                    <NativeSelect :id="`${ids}-f-status`" v-model="filterStatus" class="h-9" @change="applyFilters">
                        <option value="">All statuses</option>
                        <option v-for="status in statuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                    </NativeSelect>
                </div>
                <div class="flex min-w-0 flex-col gap-1">
                    <Label :for="`${ids}-f-type`" class="text-xs text-muted-foreground">Type</Label>
                    <NativeSelect :id="`${ids}-f-type`" v-model="filterType" class="h-9" @change="applyFilters">
                        <option value="">All types</option>
                        <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </NativeSelect>
                </div>
                <div class="flex min-w-0 flex-col gap-1">
                    <Label :for="`${ids}-f-month`" class="text-xs text-muted-foreground">Month</Label>
                    <Input :id="`${ids}-f-month`" v-model="filterMonth" type="month" class="h-9 w-44" @change="applyFilters" />
                </div>
                <Button v-if="hasFilters" type="button" variant="ghost" size="sm" @click="clearFilters">Clear filters</Button>
            </div>

            <Card v-if="bonuses.data.length === 0">
                <CardContent>
                    <EmptyState
                        :icon="Gift"
                        :title="hasFilters ? 'No bonuses match these filters.' : 'No bonuses yet.'"
                        description="An approved bonus is added to the payroll of the month its payment date falls in."
                    />
                </CardContent>
            </Card>

            <template v-else>
                <!-- Wide table -->
                <div class="hidden min-w-0 overflow-x-auto rounded-lg border border-border bg-card shadow-raised md:block">
                    <table class="hidden w-full text-sm md:table">
                        <caption class="sr-only">Bonuses, newest payment date first.</caption>
                        <thead>
                            <tr class="border-b border-border">
                                <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Employee</th>
                                <th scope="col" class="px-3 py-3 text-left font-medium text-muted-foreground">Bonus</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium text-muted-foreground">Amount</th>
                                <th scope="col" class="px-3 py-3 text-left font-medium text-muted-foreground">Payment date</th>
                                <th scope="col" class="px-3 py-3 text-left font-medium text-muted-foreground">Status</th>
                                <th scope="col" class="px-3 py-3 text-left font-medium text-muted-foreground">Payroll month</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="bonus in bonuses.data" :key="bonus.id" class="border-b border-border/60 last:border-0">
                                <th scope="row" class="px-4 py-3 text-left font-normal">{{ bonus.employee.name }}</th>
                                <td class="px-3 py-3">
                                    <span class="block">{{ bonus.display_name }}</span>
                                    <span v-if="bonus.reason" class="block max-w-64 truncate text-xs text-muted-foreground" :title="bonus.reason">
                                        {{ bonus.reason }}
                                    </span>
                                    <span v-if="bonus.status === 'cancelled' && bonus.cancellation_reason" class="block text-xs text-muted-foreground">
                                        Cancelled<template v-if="bonus.cancelled_by"> by {{ bonus.cancelled_by }}</template>: {{ bonus.cancellation_reason }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ formatMoney(bonus.amount, bonus.currency) }}</td>
                                <td class="px-3 py-3 tabular-nums">{{ bonus.payment_date }}</td>
                                <td class="px-3 py-3"><Badge :variant="badgeVariant(bonus.status)">{{ bonus.status_label }}</Badge></td>
                                <td class="px-3 py-3">
                                    <Link v-if="bonus.period" :href="payrollRoutes.show(bonus.period.id)" class="text-primary underline-offset-4 hover:underline">
                                        {{ bonus.period.month }}
                                    </Link>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1">
                                        <Button v-if="bonus.permissions.can_update" type="button" variant="ghost" size="sm" @click="openEdit(bonus)">Edit</Button>
                                        <Button v-if="bonus.permissions.can_approve" type="button" variant="ghost" size="sm" @click="approving = bonus">Approve</Button>
                                        <Button v-if="bonus.permissions.can_cancel" type="button" variant="ghost" size="sm" @click="openCancel(bonus)">Cancel</Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Stacked cards on small screens -->
                <ul class="flex min-w-0 list-none flex-col gap-3 md:hidden">
                    <li v-for="bonus in bonuses.data" :key="bonus.id" class="min-w-0 rounded-lg border border-border bg-card p-4 shadow-raised">
                        <div class="flex min-w-0 items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ bonus.employee.name }}</p>
                                <p class="text-sm">{{ bonus.display_name }}</p>
                            </div>
                            <Badge :variant="badgeVariant(bonus.status)">{{ bonus.status_label }}</Badge>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <dt class="text-muted-foreground">Amount</dt>
                            <dd class="text-right tabular-nums">{{ formatMoney(bonus.amount, bonus.currency) }}</dd>
                            <dt class="text-muted-foreground">Payment date</dt>
                            <dd class="text-right tabular-nums">{{ bonus.payment_date }}</dd>
                            <dt class="text-muted-foreground">Payroll month</dt>
                            <dd class="text-right">
                                <Link v-if="bonus.period" :href="payrollRoutes.show(bonus.period.id)" class="text-primary underline-offset-4 hover:underline">
                                    {{ bonus.period.month }}
                                </Link>
                                <span v-else class="text-muted-foreground">—</span>
                            </dd>
                        </dl>
                        <p v-if="bonus.reason" class="mt-2 text-xs text-muted-foreground">{{ bonus.reason }}</p>
                        <div
                            v-if="bonus.permissions.can_update || bonus.permissions.can_approve || bonus.permissions.can_cancel"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <Button v-if="bonus.permissions.can_update" type="button" variant="outline" size="sm" @click="openEdit(bonus)">Edit</Button>
                            <Button v-if="bonus.permissions.can_approve" type="button" variant="outline" size="sm" @click="approving = bonus">Approve</Button>
                            <Button v-if="bonus.permissions.can_cancel" type="button" variant="outline" size="sm" @click="openCancel(bonus)">Cancel</Button>
                        </div>
                    </li>
                </ul>

                <nav v-if="bonuses.last_page > 1" class="flex min-w-0 flex-wrap items-center gap-1" aria-label="Bonus pages">
                    <template v-for="(link, index) in bonuses.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="rounded-md px-3 py-1 text-sm"
                            :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                            :aria-current="link.active ? 'page' : undefined"
                        >{{ pageLabel(link.label) }}</Link>
                        <span v-else class="px-3 py-1 text-sm text-muted-foreground">{{ pageLabel(link.label) }}</span>
                    </template>
                </nav>
            </template>
        </div>
    </PageShell>

    <!-- Add -->
    <Dialog :open="addOpen" @update:open="(open: boolean) => { addOpen = open; }">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Add bonus</DialogTitle>
                <DialogDescription>One bonus for each employee you choose.</DialogDescription>
            </DialogHeader>
            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submitAdd">
                <fieldset class="flex min-w-0 flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">Employees</legend>
                    <p v-for="message in employeeErrors" :key="message" class="text-xs text-destructive">{{ message }}</p>
                    <div class="flex min-w-0 gap-2">
                        <Input v-model="employeeSearch" type="search" placeholder="Filter names" aria-label="Filter employees by name" class="h-9" />
                        <Button type="button" variant="outline" size="sm" @click="selectAllShown">Select all shown</Button>
                    </div>
                    <div class="max-h-48 overflow-y-auto rounded-md border border-border p-2">
                        <div v-for="employee in shownEmployees" :key="employee.id" class="flex items-center gap-2 py-1">
                            <Checkbox
                                :id="`${ids}-emp-${employee.id}`"
                                :model-value="addForm.employee_ids.includes(employee.id)"
                                @update:model-value="toggleEmployee(employee.id, $event === true)"
                            />
                            <Label :for="`${ids}-emp-${employee.id}`" class="text-sm font-normal">{{ employee.name }}</Label>
                        </div>
                        <p v-if="shownEmployees.length === 0" class="text-xs text-muted-foreground">No employee matches.</p>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ addForm.employee_ids.length }} selected</p>
                </fieldset>

                <div class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="`${ids}-type`">Type</Label>
                        <NativeSelect :id="`${ids}-type`" v-model="addForm.type">
                            <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </NativeSelect>
                        <p v-if="addForm.errors.type" class="text-xs text-destructive">{{ addForm.errors.type }}</p>
                    </div>
                    <div v-if="addForm.type === 'custom'" class="flex min-w-0 flex-col gap-2">
                        <Label :for="`${ids}-title`">Title</Label>
                        <Input :id="`${ids}-title`" v-model="addForm.title" maxlength="120" required />
                        <p v-if="addForm.errors.title" class="text-xs text-destructive">{{ addForm.errors.title }}</p>
                    </div>
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="`${ids}-amount`">Amount</Label>
                        <Input :id="`${ids}-amount`" v-model="addForm.amount" type="number" inputmode="decimal" step="0.01" min="0.01" required class="tabular-nums" />
                        <p class="text-xs text-muted-foreground">Paid in {{ currency }} with payroll</p>
                        <p v-if="addForm.errors.amount" class="text-xs text-destructive">{{ addForm.errors.amount }}</p>
                        <p v-if="addForm.errors.currency" class="text-xs text-destructive">{{ addForm.errors.currency }}</p>
                    </div>
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="`${ids}-date`">Payment date</Label>
                        <Input :id="`${ids}-date`" v-model="addForm.payment_date" type="date" required />
                        <p v-if="addForm.errors.payment_date" class="text-xs text-destructive">{{ addForm.errors.payment_date }}</p>
                    </div>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="`${ids}-reason`">Reason</Label>
                    <Textarea :id="`${ids}-reason`" v-model="addForm.reason" rows="3" maxlength="1000" />
                    <p v-if="addForm.errors.reason" class="text-xs text-destructive">{{ addForm.errors.reason }}</p>
                </div>

                <div v-if="permissions.can_approve" class="flex items-center gap-2">
                    <Checkbox :id="`${ids}-approve`" :model-value="approveNow" @update:model-value="approveNow = $event === true" />
                    <Label :for="`${ids}-approve`" class="text-sm font-normal">Approve now</Label>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="addForm.processing" @click="addOpen = false">Cancel</Button>
                    <Button type="submit" :disabled="addForm.processing">{{ addForm.processing ? 'Saving…' : 'Save' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Edit -->
    <Dialog :open="editing !== null" @update:open="(open: boolean) => { if (!open) editing = null; }">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Edit bonus</DialogTitle>
                <DialogDescription v-if="editing">{{ editing.display_name }} for {{ editing.employee.name }}</DialogDescription>
            </DialogHeader>
            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submitEdit">
                <div class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="`${ids}-e-type`">Type</Label>
                        <NativeSelect :id="`${ids}-e-type`" v-model="editForm.type">
                            <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </NativeSelect>
                        <p v-if="editForm.errors.type" class="text-xs text-destructive">{{ editForm.errors.type }}</p>
                    </div>
                    <div v-if="editForm.type === 'custom'" class="flex min-w-0 flex-col gap-2">
                        <Label :for="`${ids}-e-title`">Title</Label>
                        <Input :id="`${ids}-e-title`" v-model="editForm.title" maxlength="120" required />
                        <p v-if="editForm.errors.title" class="text-xs text-destructive">{{ editForm.errors.title }}</p>
                    </div>
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="`${ids}-e-amount`">Amount</Label>
                        <Input :id="`${ids}-e-amount`" v-model="editForm.amount" type="number" inputmode="decimal" step="0.01" min="0.01" required class="tabular-nums" />
                        <p class="text-xs text-muted-foreground">Paid in {{ currency }} with payroll</p>
                        <p v-if="editForm.errors.amount" class="text-xs text-destructive">{{ editForm.errors.amount }}</p>
                        <p v-if="editForm.errors.currency" class="text-xs text-destructive">{{ editForm.errors.currency }}</p>
                    </div>
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="`${ids}-e-date`">Payment date</Label>
                        <Input :id="`${ids}-e-date`" v-model="editForm.payment_date" type="date" required />
                        <p v-if="editForm.errors.payment_date" class="text-xs text-destructive">{{ editForm.errors.payment_date }}</p>
                    </div>
                </div>
                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="`${ids}-e-reason`">Reason</Label>
                    <Textarea :id="`${ids}-e-reason`" v-model="editForm.reason" rows="3" maxlength="1000" />
                    <p v-if="editForm.errors.reason" class="text-xs text-destructive">{{ editForm.errors.reason }}</p>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="editForm.processing" @click="editing = null">Cancel</Button>
                    <Button type="submit" :disabled="editForm.processing">{{ editForm.processing ? 'Saving…' : 'Save' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Cancel -->
    <Dialog :open="cancelling !== null" @update:open="(open: boolean) => { if (!open) cancelling = null; }">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Cancel bonus</DialogTitle>
                <DialogDescription v-if="cancelling">
                    Cancel {{ cancelling.display_name }} for {{ cancelling.employee.name }}? It will be removed from payroll. This is
                    recorded in the audit log.
                </DialogDescription>
            </DialogHeader>
            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submitCancel">
                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="`${ids}-c-reason`">Reason</Label>
                    <Textarea :id="`${ids}-c-reason`" v-model="cancelForm.reason" rows="3" maxlength="1000" required />
                    <p v-if="cancelForm.errors.reason" class="text-xs text-destructive">{{ cancelForm.errors.reason }}</p>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="cancelForm.processing" @click="cancelling = null">Keep it</Button>
                    <Button type="submit" variant="destructive" :disabled="cancelForm.processing">Cancel bonus</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Approve -->
    <Dialog :open="approving !== null" @update:open="(open: boolean) => { if (!open) approving = null; }">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Approve bonus</DialogTitle>
                <DialogDescription v-if="approving">
                    Approve {{ approving.display_name }} of {{ formatMoney(approving.amount, approving.currency) }} for
                    {{ approving.employee.name }}? It will be added to the payroll for {{ monthLabelOf(approving.payment_date) }}.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="approveProcessing" @click="approving = null">Not now</Button>
                <Button type="button" :disabled="approveProcessing" @click="submitApprove">Approve</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
