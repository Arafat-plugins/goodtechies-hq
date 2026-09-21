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
    finance?: ProjectFinance | null;
}

/** reka-ui's Select has no empty value, so these sentinels stand in for "nothing chosen". */
export const INTERNAL = 'internal';
export const NO_PM = 'none';
export const NO_FREQUENCY = 'none';

/** A money value for an `<input type="number">`: decimal strings pass through, null becomes ''. */
export function moneyInputValue(amount: string | number | null | undefined): string {
    return amount === null || amount === undefined ? '' : String(amount);
}
</script>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';

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

/** Blank strings are "not set"; the server wants null, not ''. */
function blankToNull(value: string): string | null {
    return value.trim() === '' ? null : value;
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
        form.put(`/admin/projects/${project.id}`, { preserveScroll: true });

        return;
    }

    form.post('/admin/projects', { preserveScroll: true });
}

const cancelHref = computed(() =>
    props.project ? `/admin/projects/${props.project.id}` : '/admin/projects',
);

/** The project's own page, which carries the status actions. Empty on Create, where it is unused. */
const projectHref = computed(() => (props.project ? `/admin/projects/${props.project.id}` : ''));
</script>

<template>
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <Card class="min-w-0 gap-4 shadow-xs">
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
                    <Label for="project-start-date">Start date</Label>
                    <Input
                        id="project-start-date"
                        v-model="form.start_date"
                        type="date"
                        name="start_date"
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
                        :disabled="form.processing"
                        :aria-invalid="errors.deadline ? true : undefined"
                    />
                    <p v-if="errors.deadline" class="text-xs text-destructive">{{ errors.deadline }}</p>
                </div>

                <p v-if="isEdit" class="min-w-0 text-xs text-muted-foreground md:col-span-2">
                    Status is changed from the
                    <Link :href="projectHref" class="underline underline-offset-2">project page</Link>.
                </p>
            </CardContent>
        </Card>

        <Card class="min-w-0 gap-4 shadow-xs">
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

        <Card v-if="!isEdit" class="min-w-0 gap-4 shadow-xs">
            <CardHeader>
                <CardTitle class="text-sm font-medium">Members</CardTitle>
                <CardDescription>
                    Who works on this. Roles on the project are set from the project page once it exists.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul class="grid gap-2 sm:grid-cols-2">
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

        <Card v-if="!isEdit" class="min-w-0 gap-4 shadow-xs">
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

        <div class="flex flex-wrap items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Saving…' : submitLabel }}
            </Button>
            <Button as-child type="button" variant="outline">
                <Link :href="cancelHref">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
