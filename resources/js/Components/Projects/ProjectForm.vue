<script lang="ts">
/** A `{value,label}` option list, as every project option prop arrives. */
export interface Option {
    value: string;
    label: string;
}

/** A client or PM reference on a project payload. */
export interface NamedRef {
    id: number;
    name: string;
}

/** An entry of `assignableEmployees`. */
export interface EmployeeOption {
    id: number;
    name: string;
    role: string;
}

export interface ProjectMember {
    id: number;
    name: string;
    role_on_project: string | null;
}

/** Money values arrive as decimal strings (`"4500.00"`) or null. */
export interface ProjectFinance {
    price?: string | number | null;
    recurring_amount?: string | number | null;
    billing_frequency?: string | null;
    billing_frequency_label?: string | null;
    contract_value?: string | number | null;
    contract_terms?: string | null;
    profitability_snapshot?: string | number | null;
}

export interface ProjectPermissions {
    can_update?: boolean;
    can_view_finance?: boolean;
    can_manage_members?: boolean;
    can_archive?: boolean;
}

/**
 * A project as `ProjectResource` serializes it. `client`, `internal_notes`, `billing_type`
 * and `finance` are **absent** for roles that may not see them, so every reader of this type
 * uses optional chaining rather than assuming a key is there.
 */
export interface Project {
    id: number;
    name: string;
    domain?: string | null;
    project_type: string;
    project_type_label: string;
    status: string;
    status_label: string;
    priority: string;
    priority_label: string;
    start_date?: string | null;
    deadline?: string | null;
    archived_at?: string | null;
    is_archived?: boolean;
    employee_notes?: string | null;
    pm?: NamedRef | null;
    members?: ProjectMember[];
    permissions?: ProjectPermissions;
    client?: NamedRef | null;
    internal_notes?: string | null;
    billing_type?: string | null;
    billing_type_label?: string | null;
    recurrence_frequency?: string | null;
    recurrence_frequency_label?: string | null;
    finance?: ProjectFinance | null;
}

/** reka-ui's Select has no empty value, so these sentinels stand in for "nothing chosen". */
export const INTERNAL = 'internal';
export const NO_PM = 'none';
export const NO_FREQUENCY = 'none';

/** The billing type whose deadline the server computes from the start date and frequency. */
export const RECURRING = 'recurring';

/** A money value for an `<input type="number">`: decimal strings pass through, null becomes ''. */
export function moneyInputValue(amount: string | number | null | undefined): string {
    return amount === null || amount === undefined ? '' : String(amount);
}
</script>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { CircleAlert, Loader2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { recurringDeadline } from '@/lib/recurrence';

const props = defineProps<{
    /** Absent on Create. */
    project?: Project;
    clients: NamedRef[];
    assignableEmployees: EmployeeOption[];
    projectTypes: Option[];
    /** Still sent by both pages; the form does not offer status, so nothing reads it. */
    statuses: Option[];
    priorities: Option[];
    billingTypes: Option[];
    recurrenceFrequencies: Option[];
    billingFrequencies: Option[];
    submitLabel: string;
}>();

const isEdit = computed(() => props.project !== undefined);

const form = useForm({
    client_id: props.project?.client ? String(props.project.client.id) : INTERNAL,
    name: props.project?.name ?? '',
    domain: props.project?.domain ?? '',
    project_type: props.project?.project_type ?? (props.projectTypes[0]?.value ?? ''),
    billing_type: props.project?.billing_type ?? (props.billingTypes[0]?.value ?? ''),
    recurrence_frequency: props.project?.recurrence_frequency ?? '',
    priority: props.project?.priority ?? (props.priorities[1]?.value ?? props.priorities[0]?.value ?? ''),
    start_date: props.project?.start_date ?? '',
    deadline: props.project?.deadline ?? '',
    pm_id: props.project?.pm ? String(props.project.pm.id) : NO_PM,
    internal_notes: props.project?.internal_notes ?? '',
    employee_notes: props.project?.employee_notes ?? '',
    // Create only. Update has its own members and finance endpoints.
    members: [] as number[],
    finance: {
        price: '',
        recurring_amount: '',
        billing_frequency: NO_FREQUENCY,
        contract_value: '',
        contract_terms: '',
        profitability_snapshot: '',
    },
});

const isRecurring = computed(() => form.billing_type === RECURRING);

// A Recurring project's deadline is the start date plus one period. The server computes the
// stored value; this keeps the read-only field showing the same date as the inputs change.
watch(
    () => [form.billing_type, form.start_date, form.recurrence_frequency] as const,
    ([billingType, start, frequency]) => {
        if (billingType !== RECURRING) {
            form.recurrence_frequency = '';

            return;
        }

        form.deadline = recurringDeadline(start ?? '', frequency) ?? '';
    },
);

// `useForm` types `errors` by top-level field, but Laravel also sends `finance.price` keys.
const errors = computed(() => form.errors as unknown as Record<string, string | undefined>);

function isMember(employeeId: number): boolean {
    return form.members.includes(employeeId);
}

function toggleMember(employeeId: number, checked: boolean): void {
    form.members = checked
        ? [...form.members, employeeId]
        : form.members.filter((id) => id !== employeeId);
}

/**
 * Blank values are "not set"; the server wants null, not ''. A `type="number"` v-model yields a
 * number, so the value is stringified before trimming.
 */
function blankToNull(value: string | number | null | undefined): string | null {
    if (value === null || value === undefined) {
        return null;
    }

    const text = String(value).trim();

    return text === '' ? null : text;
}

function submit(): void {
    if (form.processing) {
        return;
    }

    const project = props.project;

    form.transform((data) => {
        const shared = {
            client_id: data.client_id === INTERNAL ? null : Number(data.client_id),
            name: data.name,
            domain: blankToNull(data.domain),
            project_type: data.project_type,
            billing_type: data.billing_type,
            recurrence_frequency:
                data.billing_type === RECURRING && data.recurrence_frequency !== '' ? data.recurrence_frequency : null,
            priority: data.priority,
            start_date: blankToNull(data.start_date),
            deadline: blankToNull(data.deadline),
            pm_id: data.pm_id === NO_PM ? null : Number(data.pm_id),
            internal_notes: blankToNull(data.internal_notes),
            employee_notes: blankToNull(data.employee_notes),
        };

        // Editing sends the project's own fields only: status, members and finance each have
        // their own endpoint.
        if (project) {
            return shared;
        }

        return {
            ...shared,
            members: data.members,
            finance: {
                price: blankToNull(data.finance.price),
                recurring_amount: blankToNull(data.finance.recurring_amount),
                billing_frequency:
                    data.finance.billing_frequency === NO_FREQUENCY ? null : data.finance.billing_frequency,
                contract_value: blankToNull(data.finance.contract_value),
                contract_terms: blankToNull(data.finance.contract_terms),
                profitability_snapshot: blankToNull(data.finance.profitability_snapshot),
            },
        };
    });

    if (project) {
        form.put(`/admin/projects/${project.id}`, { preserveScroll: true, onError: focusFirstInvalid });

        return;
    }

    form.post('/admin/projects', { preserveScroll: true, onError: focusFirstInvalid });
}

/** Error keys → the control that shows them, in on-screen order. */
const FIELD_IDS: Record<string, string> = {
    client_id: 'project-client',
    name: 'project-name',
    domain: 'project-domain',
    project_type: 'project-type',
    billing_type: 'project-billing-type',
    recurrence_frequency: 'project-recurrence-frequency',
    priority: 'project-priority',
    pm_id: 'project-pm',
    start_date: 'project-start-date',
    deadline: 'project-deadline',
    internal_notes: 'project-internal-notes',
    employee_notes: 'project-employee-notes',
    members: 'project-members',
    'finance.price': 'project-price',
    'finance.recurring_amount': 'project-recurring-amount',
    'finance.billing_frequency': 'project-billing-frequency',
    'finance.contract_value': 'project-contract-value',
    'finance.profitability_snapshot': 'project-profitability-snapshot',
    'finance.contract_terms': 'project-contract-terms',
};

/** Keys whose control is not rendered on this form (Edit has no members or finance cards). */
function isOffScreen(key: string): boolean {
    if (!(key in FIELD_IDS)) {
        return true;
    }

    return isEdit.value && (key === 'members' || key.startsWith('finance.'));
}

/**
 * The messages that need the summary above the buttons: every finance error (that card sits far
 * below the details) and any error whose field is not on screen.
 */
const summaryErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([key, message]) => message && (key.startsWith('finance.') || isOffScreen(key)))
        .map(([, message]) => message as string),
);

function focusFirstInvalid(): void {
    const first = Object.keys(FIELD_IDS).find((key) => errors.value[key] && !isOffScreen(key));

    if (!first) {
        return;
    }

    window.setTimeout(() => {
        const element = document.getElementById(FIELD_IDS[first]);
        const target = element?.matches('input, textarea, button, select')
            ? element
            : element?.querySelector<HTMLElement>('input, textarea, button, select');
        target?.focus();
    }, 0);
}

const cancelHref = computed(() =>
    props.project ? `/admin/projects/${props.project.id}` : '/admin/projects',
);

/** The project's own page, which carries the status actions. Empty on Create, where it is unused. */
const projectHref = computed(() => (props.project ? `/admin/projects/${props.project.id}` : ''));
</script>

<template>
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <Card class="min-w-0 gap-4">
            <CardHeader>
                <CardTitle class="text-sm font-medium">Details</CardTitle>
                <CardDescription>Who the work is for, what it is, and when it runs.</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4 md:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-client">Client</Label>
                    <Select v-model="form.client_id" :disabled="form.processing">
                        <SelectTrigger
                            id="project-client"
                            class="w-full"
                            :aria-invalid="errors.client_id ? true : undefined"
                        >
                            <SelectValue placeholder="Choose a client" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="INTERNAL">Internal (no client)</SelectItem>
                            <SelectItem v-for="client in clients" :key="client.id" :value="String(client.id)">
                                {{ client.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="errors.client_id" class="text-xs text-destructive">{{ errors.client_id }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-name">
                        Name <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Input
                        id="project-name"
                        v-model="form.name"
                        name="name"
                        required
                        :disabled="form.processing"
                        :aria-invalid="errors.name ? true : undefined"
                        :aria-describedby="errors.name ? 'project-name-error' : undefined"
                    />
                    <p v-if="errors.name" id="project-name-error" class="text-xs text-destructive">
                        {{ errors.name }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-domain">Domain</Label>
                    <Input
                        id="project-domain"
                        v-model="form.domain"
                        name="domain"
                        inputmode="url"
                        placeholder="example.com"
                        :disabled="form.processing"
                        :aria-invalid="errors.domain ? true : undefined"
                        aria-describedby="project-domain-help"
                    />
                    <p id="project-domain-help" class="text-xs text-muted-foreground">
                        Employees see the domain, never the client name.
                    </p>
                    <p v-if="errors.domain" class="text-xs text-destructive">{{ errors.domain }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-type">
                        Type <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Select v-model="form.project_type" :disabled="form.processing">
                        <SelectTrigger
                            id="project-type"
                            class="w-full"
                            :aria-invalid="errors.project_type ? true : undefined"
                        >
                            <SelectValue placeholder="Choose a type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="type in projectTypes" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="errors.project_type" class="text-xs text-destructive">{{ errors.project_type }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-billing-type">
                        Billing type <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Select v-model="form.billing_type" :disabled="form.processing">
                        <SelectTrigger
                            id="project-billing-type"
                            class="w-full"
                            :aria-invalid="errors.billing_type ? true : undefined"
                        >
                            <SelectValue placeholder="Choose a billing type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="type in billingTypes" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="errors.billing_type" class="text-xs text-destructive">{{ errors.billing_type }}</p>
                </div>

                <div v-if="isRecurring" class="flex min-w-0 flex-col gap-2">
                    <Label for="project-recurrence-frequency">
                        Frequency <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Select v-model="form.recurrence_frequency" required :disabled="form.processing">
                        <SelectTrigger
                            id="project-recurrence-frequency"
                            class="w-full"
                            :aria-invalid="errors.recurrence_frequency ? true : undefined"
                        >
                            <SelectValue placeholder="How often it recurs" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="frequency in recurrenceFrequencies"
                                :key="frequency.value"
                                :value="frequency.value"
                            >
                                {{ frequency.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="errors.recurrence_frequency" class="text-xs text-destructive">
                        {{ errors.recurrence_frequency }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-priority">
                        Priority <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Select v-model="form.priority" :disabled="form.processing">
                        <SelectTrigger
                            id="project-priority"
                            class="w-full"
                            :aria-invalid="errors.priority ? true : undefined"
                        >
                            <SelectValue placeholder="Choose a priority" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in priorities" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="errors.priority" class="text-xs text-destructive">{{ errors.priority }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-pm">Project manager</Label>
                    <Select v-model="form.pm_id" :disabled="form.processing">
                        <SelectTrigger id="project-pm" class="w-full" :aria-invalid="errors.pm_id ? true : undefined">
                            <SelectValue placeholder="Choose a PM" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NO_PM">No project manager</SelectItem>
                            <SelectItem
                                v-for="employee in assignableEmployees"
                                :key="employee.id"
                                :value="String(employee.id)"
                            >
                                {{ employee.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="errors.pm_id" class="text-xs text-destructive">{{ errors.pm_id }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-start-date">
                        Start date
                        <span v-if="isRecurring" class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Input
                        id="project-start-date"
                        v-model="form.start_date"
                        type="date"
                        name="start_date"
                        :required="isRecurring"
                        :disabled="form.processing"
                        :aria-invalid="errors.start_date ? true : undefined"
                    />
                    <p v-if="errors.start_date" class="text-xs text-destructive">{{ errors.start_date }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-deadline">Deadline</Label>
                    <Input
                        id="project-deadline"
                        v-model="form.deadline"
                        type="date"
                        name="deadline"
                        :readonly="isRecurring"
                        :class="isRecurring ? 'bg-muted text-muted-foreground' : undefined"
                        :disabled="form.processing"
                        :aria-invalid="errors.deadline ? true : undefined"
                        :aria-describedby="isRecurring ? 'project-deadline-help' : undefined"
                    />
                    <p v-if="isRecurring" id="project-deadline-help" class="text-xs text-muted-foreground">
                        Set automatically from the start date and frequency.
                    </p>
                    <p v-if="errors.deadline" class="text-xs text-destructive">{{ errors.deadline }}</p>
                </div>

                <p v-if="isEdit" class="min-w-0 text-xs text-muted-foreground md:col-span-2">
                    Status is changed from the
                    <Link :href="projectHref" class="underline underline-offset-2">project page</Link>.
                </p>
            </CardContent>
        </Card>

        <Card class="min-w-0 gap-4">
            <CardHeader>
                <CardTitle class="text-sm font-medium">Notes</CardTitle>
                <CardDescription>Two audiences, two boxes — keep them apart.</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-internal-notes">Internal notes</Label>
                    <Textarea
                        id="project-internal-notes"
                        v-model="form.internal_notes"
                        name="internal_notes"
                        rows="4"
                        :disabled="form.processing"
                        :aria-invalid="errors.internal_notes ? true : undefined"
                        aria-describedby="project-internal-notes-help"
                    />
                    <p id="project-internal-notes-help" class="text-xs text-muted-foreground">
                        Admins only. Employees never see this.
                    </p>
                    <p v-if="errors.internal_notes" class="text-xs text-destructive">{{ errors.internal_notes }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-employee-notes">Employee notes</Label>
                    <Textarea
                        id="project-employee-notes"
                        v-model="form.employee_notes"
                        name="employee_notes"
                        rows="4"
                        :disabled="form.processing"
                        :aria-invalid="errors.employee_notes ? true : undefined"
                        aria-describedby="project-employee-notes-help"
                    />
                    <p id="project-employee-notes-help" class="text-xs text-muted-foreground">
                        Shown to everyone assigned to the project.
                    </p>
                    <p v-if="errors.employee_notes" class="text-xs text-destructive">{{ errors.employee_notes }}</p>
                </div>
            </CardContent>
        </Card>

        <Card v-if="!isEdit" class="min-w-0 gap-4">
            <CardHeader>
                <CardTitle class="text-sm font-medium">Members</CardTitle>
                <CardDescription>
                    Who works on this. Roles on the project are set from the project page once it exists.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul id="project-members" class="grid gap-2 sm:grid-cols-2">
                    <li v-for="employee in assignableEmployees" :key="employee.id" class="flex items-center gap-2">
                        <Checkbox
                            :id="`project-member-${employee.id}`"
                            :model-value="isMember(employee.id)"
                            :disabled="form.processing"
                            @update:model-value="(checked) => toggleMember(employee.id, checked === true)"
                        />
                        <Label :for="`project-member-${employee.id}`" class="min-w-0 font-normal">
                            {{ employee.name }}
                        </Label>
                    </li>
                </ul>
                <p v-if="errors.members" class="mt-3 text-xs text-destructive">{{ errors.members }}</p>
            </CardContent>
        </Card>

        <Card v-if="!isEdit" class="min-w-0 gap-4">
            <CardHeader>
                <CardTitle class="text-sm font-medium">Finance</CardTitle>
                <CardDescription>All optional, and visible to Admins only.</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4 md:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-price">Price</Label>
                    <Input
                        id="project-price"
                        v-model="form.finance.price"
                        type="number"
                        step="0.01"
                        min="0"
                        inputmode="decimal"
                        :disabled="form.processing"
                        :aria-invalid="errors['finance.price'] ? true : undefined"
                    />
                    <p v-if="errors['finance.price']" class="text-xs text-destructive">
                        {{ errors['finance.price'] }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-recurring-amount">Recurring amount</Label>
                    <Input
                        id="project-recurring-amount"
                        v-model="form.finance.recurring_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        inputmode="decimal"
                        :disabled="form.processing"
                        :aria-invalid="errors['finance.recurring_amount'] ? true : undefined"
                    />
                    <p v-if="errors['finance.recurring_amount']" class="text-xs text-destructive">
                        {{ errors['finance.recurring_amount'] }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-billing-frequency">Billing frequency</Label>
                    <Select v-model="form.finance.billing_frequency" :disabled="form.processing">
                        <SelectTrigger
                            id="project-billing-frequency"
                            class="w-full"
                            :aria-invalid="errors['finance.billing_frequency'] ? true : undefined"
                        >
                            <SelectValue placeholder="Choose a frequency" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NO_FREQUENCY">No frequency</SelectItem>
                            <SelectItem
                                v-for="frequency in billingFrequencies"
                                :key="frequency.value"
                                :value="frequency.value"
                            >
                                {{ frequency.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="errors['finance.billing_frequency']" class="text-xs text-destructive">
                        {{ errors['finance.billing_frequency'] }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-contract-value">Contract value</Label>
                    <Input
                        id="project-contract-value"
                        v-model="form.finance.contract_value"
                        type="number"
                        step="0.01"
                        min="0"
                        inputmode="decimal"
                        :disabled="form.processing"
                        :aria-invalid="errors['finance.contract_value'] ? true : undefined"
                    />
                    <p v-if="errors['finance.contract_value']" class="text-xs text-destructive">
                        {{ errors['finance.contract_value'] }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="project-profitability-snapshot">Profitability snapshot</Label>
                    <Input
                        id="project-profitability-snapshot"
                        v-model="form.finance.profitability_snapshot"
                        type="number"
                        step="0.01"
                        inputmode="decimal"
                        :disabled="form.processing"
                        :aria-invalid="errors['finance.profitability_snapshot'] ? true : undefined"
                    />
                    <p v-if="errors['finance.profitability_snapshot']" class="text-xs text-destructive">
                        {{ errors['finance.profitability_snapshot'] }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2 md:col-span-2">
                    <Label for="project-contract-terms">Contract terms</Label>
                    <Textarea
                        id="project-contract-terms"
                        v-model="form.finance.contract_terms"
                        rows="3"
                        :disabled="form.processing"
                        :aria-invalid="errors['finance.contract_terms'] ? true : undefined"
                    />
                    <p v-if="errors['finance.contract_terms']" class="text-xs text-destructive">
                        {{ errors['finance.contract_terms'] }}
                    </p>
                </div>
            </CardContent>
        </Card>

        <Alert v-if="summaryErrors.length > 0" variant="destructive" role="alert">
            <CircleAlert aria-hidden="true" />
            <AlertTitle>Some fields need attention.</AlertTitle>
            <AlertDescription>
                <ul class="list-disc pl-4">
                    <li v-for="(message, index) in summaryErrors" :key="index">{{ message }}</li>
                </ul>
            </AlertDescription>
        </Alert>

        <div class="flex flex-wrap items-center gap-2">
            <Button type="submit" :disabled="form.processing" :aria-busy="form.processing ? true : undefined">
                <Loader2
                    v-if="form.processing"
                    class="size-4 animate-spin motion-reduce:animate-none"
                    aria-hidden="true"
                />
                {{ form.processing ? (isEdit ? 'Saving…' : 'Creating…') : submitLabel }}
            </Button>
            <Button as-child type="button" variant="outline">
                <Link :href="cancelHref">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
