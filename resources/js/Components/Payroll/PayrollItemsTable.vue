<script setup lang="ts">
import { Pencil } from '@lucide/vue';
import { computed } from 'vue';
import type { PayrollItem, PayrollLeaveMap, PayrollMoneyField } from '@/Components/Payroll/payroll';
import {
    PAYROLL_FIELD_LABELS,
    PAYROLL_FIELD_LONG_LABELS,
    payrollMoneyFieldsFor,
    PAYROLL_SUBTRACTED,
    formatMoney,
    leaveImpactSummary,
    receivesAdminNotes,
} from '@/Components/Payroll/payroll';
import { Button } from '@/Components/ui/button';

/**
 * **One month of payroll, line by line** — the seven money columns Part D §14 names, and what
 * each of them is made of.
 *
 * ## What it becomes at 360 px, and why it is not `DataTable`
 *
 * Eight columns of money do not fit on a phone, and they do not fit on a tablet either. So
 * this renders **two shapes of the same data**, and both of them are real tables:
 *
 *   - **Below `xl` (1280 px): one two-column table per employee**, inside a card. The figures
 *     become rows — `<th scope="row">Base</th>` against its amount — so a screen reader still
 *     announces *"Base, 800 dollars"* and there is nothing to scroll sideways. At 360 that is
 *     a single column of eight short rows, which is the only honest thing a seven-column money
 *     grid can become on a phone.
 *   - **At `xl` and up: the wide table**, employee down the side and the figures across, which
 *     is how a payroll sheet has always been read and the only layout in which two people's
 *     bonuses can be compared at a glance.
 *
 * `DataTable` is not used, and this is not a second table component in the sense DESIGN.md §5
 * rule 8 forbids. `DataTable` is a list of records to scan, sort and select; this is a **figure
 * grid with a row-editor**, with no sort the controller could honour, no selection, no bulk
 * verb and no pagination. It also could not be used safely: decision 8-30 records that
 * `DataTable`'s card is `overflow-hidden` with **no** horizontal scroller, so a table wider
 * than its container is clipped rather than scrolled — and the first thing to go is the row's
 * action control, which reads as a permission problem rather than a layout one. Nine columns is
 * exactly the case that hits it. The wide table below keeps a real scroll region with an
 * accessible name and a tab stop, for the narrow end of `xl` where a long name pushes it over.
 *
 * ## Two columns are read-only for everybody, always
 *
 * `leave_impact` is Calculate's, from approved unpaid leave; `net_salary` is PostgreSQL's,
 * a `GENERATED ALWAYS … STORED` column (decision 9-3). Neither is an input here, on either
 * shape, for any role — and the caption says the rule out loud so that nobody reading the
 * screen has to infer it from the absence of a control.
 *
 * ## `admin_notes` appears only for somebody who received the key
 *
 * `receivesAdminNotes()` tests for the key itself, never for a null value — the server leaves
 * it **out** of the payload for the Accountant and for the employee it is about (decision
 * 9-10). Anybody who did not receive it sees no note line, no placeholder and no empty box.
 */
const props = defineProps<{
    items: PayrollItem[];
    /** Unpaid and payable day counts per item id — what the leave impact is made of. */
    leave: PayrollLeaveMap;
    currency: string;
    /** "September 2026". The caption names it, for a reader arriving out of context. */
    monthLabel: string;
}>();

const emit = defineEmits<{ edit: [item: PayrollItem] }>();

/** The id an Edit control carries, so a dialog can put focus back on the right one. */
function editButtonId(item: PayrollItem): string {
    return `payroll-item-edit-${item.id}`;
}

function amount(item: PayrollItem, field: PayrollMoneyField): string {
    const money = formatMoney(item[field], props.currency);

    // A typographic minus on the three figures that come OFF the pay, so a column of eight
    // numbers reads the way a payslip reads. The stored value is never negative — a CHECK on
    // the table sees to that — so the sign is about the arithmetic, not about the figure.
    return PAYROLL_SUBTRACTED.includes(field) && Number.parseFloat(item[field]) !== 0
        ? `−${money}`
        : money;
}

function employeeName(item: PayrollItem): string {
    return item.employee?.name ?? `Employee #${item.employee?.id ?? item.id}`;
}

function leaveFor(item: PayrollItem) {
    return props.leave[String(item.id)];
}

/** Does anybody's line on this period accept an edit? Decides whether the column exists. */
const anyEditable = () => props.items.some((item) => item.permissions.can_update || item.permissions.can_annotate);

// Polish 002: allowance is a column only while a line in this month still carries one.
const moneyFields = computed(() => payrollMoneyFieldsFor(props.items));
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <!--
            ── Below xl: one two-column table per employee ────────────────────────────
            A real table each, so the row headings survive. `xl:hidden` is the pair of
            `hidden xl:block` on the wide table below; exactly one of the two is ever on
            screen, which is why the Edit controls can share an id scheme without colliding.
        -->
        <ul class="flex min-w-0 list-none flex-col gap-3 xl:hidden">
            <li
                v-for="item in items"
                :key="item.id"
                class="min-w-0 rounded-lg border border-border bg-card p-4 shadow-raised"
            >
                <div class="flex min-w-0 flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-card-foreground">
                            {{ employeeName(item) }}
                        </p>
                        <p
                            v-if="receivesAdminNotes(item) && item.admin_notes"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            <span class="font-medium">Personal note:</span> {{ item.admin_notes }}
                        </p>
                    </div>

                    <Button
                        v-if="item.permissions.can_update || item.permissions.can_annotate"
                        :id="editButtonId(item)"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="emit('edit', item)"
                    >
                        <Pencil aria-hidden="true" />
                        Edit<span class="sr-only"> the figures for {{ employeeName(item) }}</span>
                    </Button>
                </div>

                <table class="mt-3 w-full text-sm">
                    <caption class="sr-only">
                        {{ employeeName(item) }}’s payroll figures for {{ monthLabel }}.
                    </caption>
                    <thead class="sr-only">
                        <tr>
                            <th scope="col">Figure</th>
                            <th scope="col">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="field in moneyFields.filter((key) => key !== 'net_salary')"
                            :key="field"
                            class="border-b border-border/60"
                        >
                            <th scope="row" class="py-2 pr-3 text-left font-normal text-muted-foreground">
                                {{ PAYROLL_FIELD_LONG_LABELS[field] }}
                                <span
                                    v-if="field === 'leave_impact'"
                                    class="block text-xs"
                                >{{ leaveImpactSummary(leaveFor(item)) }}</span>
                            </th>
                            <td class="py-2 text-right tabular-nums">{{ amount(item, field) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" class="py-2 pr-3 text-left font-medium">
                                {{ PAYROLL_FIELD_LONG_LABELS.net_salary }}
                            </th>
                            <td class="py-2 text-right font-medium tabular-nums">
                                {{ formatMoney(item.net_salary, currency) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </li>
        </ul>

        <!--
            ── xl and up: the wide sheet ─────────────────────────────────────────────
            A named, focusable scroll region rather than a clipped card (decision 8-30): at
            1280 with the sidebar expanded this does not scroll, but a long name at the narrow
            end of the breakpoint must push the ⋯ off the screen into a scroller a keyboard can
            reach, never off the screen full stop.
        -->
        <div
            class="hidden min-w-0 overflow-x-auto rounded-lg border border-border bg-card shadow-raised xl:block"
            role="region"
            :aria-label="`Payroll figures for ${monthLabel}`"
            tabindex="0"
        >
            <table class="w-full text-sm">
                <caption class="sr-only">Every line of {{ monthLabel }}.</caption>
                <thead>
                    <tr class="border-b border-border">
                        <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">
                            Employee
                        </th>
                        <th
                            v-for="field in moneyFields"
                            :key="field"
                            scope="col"
                            class="px-3 py-3 text-right font-medium text-muted-foreground"
                        >
                            {{ PAYROLL_FIELD_LABELS[field] }}
                        </th>
                        <th v-if="anyEditable()" scope="col" class="px-4 py-3 text-right font-medium text-muted-foreground">
                            <span class="sr-only">Row actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.id" class="border-b border-border/60 last:border-0">
                        <th scope="row" class="px-4 py-3 text-left font-normal">
                            <span class="block truncate">{{ employeeName(item) }}</span>
                            <span
                                v-if="receivesAdminNotes(item) && item.admin_notes"
                                class="mt-1 block max-w-64 truncate text-xs text-muted-foreground"
                                :title="item.admin_notes"
                            >
                                Personal note: {{ item.admin_notes }}
                            </span>
                        </th>

                        <td
                            v-for="field in moneyFields"
                            :key="field"
                            class="px-3 py-3 text-right tabular-nums"
                            :class="field === 'net_salary' ? 'font-medium' : ''"
                        >
                            {{ amount(item, field) }}
                            <!--
                                What the deduction is MADE of, under the deduction itself. A
                                figure nobody can explain is the one that generates the support
                                ticket, so the numerator and the denominator of the division sit
                                where the answer does.
                            -->
                            <span
                                v-if="field === 'leave_impact'"
                                class="mt-1 block text-xs font-normal text-muted-foreground"
                            >
                                {{ leaveImpactSummary(leaveFor(item)) }}
                            </span>
                        </td>

                        <td v-if="anyEditable()" class="px-4 py-3 text-right">
                            <Button
                                v-if="item.permissions.can_update || item.permissions.can_annotate"
                                :id="`${editButtonId(item)}-wide`"
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="emit('edit', item)"
                            >
                                <Pencil aria-hidden="true" />
                                Edit<span class="sr-only"> the figures for {{ employeeName(item) }}</span>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
