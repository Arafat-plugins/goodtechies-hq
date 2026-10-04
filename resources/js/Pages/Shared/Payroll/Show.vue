<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Undo2 } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type {
    PayrollAction,
    PayrollItem,
    PayrollLeaveMap,
    PayrollPeriod,
    PayrollStatusStep,
} from '@/Components/Payroll/payroll';
import { formatMoney, payrollLines, payrollRoutes } from '@/Components/Payroll/payroll';
import PayrollActions from '@/Components/Payroll/PayrollActions.vue';
import PayrollMonthChange from '@/Components/Payroll/PayrollMonthChange.vue';
import PayrollCloseDialog from '@/Components/Payroll/PayrollCloseDialog.vue';
import PayrollItemDialog from '@/Components/Payroll/PayrollItemDialog.vue';
import PayrollItemsTable from '@/Components/Payroll/PayrollItemsTable.vue';
import PayrollStatusTrail from '@/Components/Payroll/PayrollStatusTrail.vue';
import ReverseLockDialog from '@/Components/Payroll/ReverseLockDialog.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * **One month of payroll** — the period detail, and the heart of Phase 9 (Part D §14).
 *
 * One shared page picking its layout from `auth.user.surface`, exactly as the period list and
 * `Pages/Shared/Messages.vue` do; the route gate `can:payroll.draft` is what keeps everybody
 * but an Admin and the Accountant off it.
 *
 * ## The state machine is this screen's spine
 *
 * `PayrollStatusTrail` draws where the month is on Part D §14's ladder, and `PayrollActions`
 * draws what may be done to it from there. **Both read the server's answer and neither computes
 * one**: the six rungs arrive as data, the six permissions are `PayrollPeriodPolicy` resolved
 * per requester, and the state half is `available_transitions` plus `allows_calculation`. No
 * role is named anywhere in this file, and a move this viewer would be refused is never drawn —
 * a control that 403s is worse than no control.
 *
 * Calculate is deliberately **not** disabled after it has run. It computes from
 * `leave_requests` rather than accumulating, so pressing it twice gives the same answer; a
 * greyed-out button would make the one safe, repeatable control on the screen look spent. The
 * button says so, the flash after it says so, and the month's status changes only the first
 * time.
 *
 * ## Two columns of the sheet can never be typed
 *
 * Leave impact is Calculate's, from approved unpaid leave; the net is PostgreSQL's, a generated
 * column (decision 9-3). `PayrollItemsTable` renders both as text for everybody, on both of its
 * layouts, and `PayrollItemDialog` has no input for either.
 *
 * ## Focus
 *
 * Every dialog here is opened by an ordinary button that stays mounted, so reka's own restore
 * is correct — this page does **not** inherit decision 5-20's bug, which is about a dialog
 * opened from a `DropdownMenuItem` the menu has already unmounted. Focus is still returned
 * explicitly, because the paths that matter close a dialog by *navigating*: a saved line and a
 * confirmed transition both redirect back, this component re-renders, and no close handler ever
 * runs. That path leaves focus on `<body>`, which drops a keyboard user at the top of the
 * document after every single edit.
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

const props = defineProps<{
    period: PayrollPeriod;
    /**
     * The top bar's last crumb, in the shape `lib/breadcrumb.ts` looks for. It names the month
     * so the trail reads *Finance / Payroll / September 2026* rather than *… / #1*; nothing in
     * this component renders it.
     */
    crumb: { data: { name: string } };
    items: PayrollItem[];
    /** What each line's leave impact is made of, keyed by item id. */
    leave: PayrollLeaveMap;
    /** The six rungs of the ladder, in order, from the server. */
    statuses: PayrollStatusStep[];
    currency: string;
}>();

/* -------------------------------------------------------------- the sheet */

/**
 * May the five figures still be typed at all?
 *
 * Read off the rows rather than off the status: `PayrollItemPolicy::update()` reads the period's
 * status **and** the role — Part D §14's *"read-only to the Accountant afterwards"* is a
 * different answer for two people at the same status — and `can_update` on each line is that
 * policy already asked for this requester. Deriving it here from `period.status` would be a
 * second copy of that table, and the copy that forgot the Admin's extra month.
 */
const figuresEditable = computed(() => props.items.some((item) => item.permissions.can_update));

const editing = ref<PayrollItem | null>(null);
const lastTrigger = ref<string | null>(null);

function edit(item: PayrollItem): void {
    // Which of the two layouts is on screen decides which trigger to return focus to; the
    // wide one carries the `-wide` suffix. Only one is ever visible, so whichever is found is
    // the right one.
    lastTrigger.value = `payroll-item-edit-${item.id}`;
    editing.value = item;
}

function closeEdit(): void {
    editing.value = null;
    returnFocus();
}

/* --------------------------------------------------- the state machine */

const working = ref<string | null>(null);
const closing = ref<'lock' | 'paid' | null>(null);
const reversing = ref(false);

/**
 * Press a move, or open the confirmation it needs first.
 *
 * The three that need one are the three whose consequence reaches past this screen: locking
 * and paying close the finance ledger, and reversing reopens it. Calculate, Mark reviewed and
 * Approve go straight through — each is either repeatable or undone by the move after it.
 */
function run(action: PayrollAction): void {
    lastTrigger.value = `payroll-action-${action.key}`;

    if (action.confirm === 'lock' || action.confirm === 'paid') {
        closing.value = action.confirm;

        return;
    }

    if (action.confirm === 'reverse') {
        reversing.value = true;

        return;
    }

    post(action.key, action.url);
}

function post(key: string, url: string): void {
    working.value = key;

    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                working.value = null;
                closing.value = null;
                returnFocus();
            },
        },
    );
}

function confirmClose(): void {
    const kind = closing.value;

    if (!kind || working.value !== null) {
        return;
    }

    post(kind, kind === 'lock' ? payrollRoutes.lock(props.period.id) : payrollRoutes.paid(props.period.id));
}

function cancelClose(): void {
    closing.value = null;
    returnFocus();
}

function closeReversal(): void {
    reversing.value = false;
    returnFocus();
}

/**
 * Put focus back on whatever opened the dialog — or, when that control no longer exists, on
 * the region that replaced it.
 *
 * Two things make this less obvious than it looks, and both were found by pressing Tab in a
 * browser rather than by a test:
 *
 *   1. **The sheet renders a row's Edit control twice** — once in the stacked card list and
 *      once in the wide table — and exactly one of the two is on screen at a given width.
 *      `offsetParent === null` is how the hidden one is skipped.
 *   2. **A transition deletes its own trigger.** Locking a month removes the Lock button and
 *      puts Reverse and Mark paid in its place, so returning focus "to the trigger" lands on
 *      nothing and the browser drops the keyboard on `<body>` — decision 5-20's outcome, by a
 *      different route. So the fallback is the *next steps* region itself, which is where the
 *      answer to "what just happened" now is: it carries `tabindex="-1"`, so it takes focus
 *      programmatically without becoming a tab stop of its own.
 */
const NEXT_STEPS_ID = 'payroll-next-steps';

function returnFocus(): void {
    const id = lastTrigger.value;

    void nextTick(() => {
        const candidates = id ? [document.getElementById(id), document.getElementById(`${id}-wide`)] : [];
        const visible = candidates.find((el): el is HTMLElement => el !== null && el.offsetParent !== null);

        (visible ?? document.getElementById(NEXT_STEPS_ID))?.focus();
    });
}

/* ------------------------------------------------------------ the words */

const summary = computed(() => {
    const count = props.period.items_count ?? props.items.length;
    const total = props.period.net_total === undefined
        ? null
        : formatMoney(props.period.net_total, props.currency);

    return `${payrollLines(count)}${total ? `, ${total} in all` : ''}.`;
});
</script>

<template>
    <Head :title="`Payroll — ${period.label}`" />

    <PageShell
        :title="period.label"
        description="One line per employee: what they are paid, what was added, what came off, and what the database worked the net out to."
        :breadcrumb="[{ label: 'Finance' }, { label: 'Payroll', href: payrollRoutes.index() }, { label: period.label }]"
    >
        <div class="flex min-w-0 flex-col gap-4">
            <!--
                Where the month is, what comes next, and — when nothing comes next for this
                viewer — why. The status is a StatusBadge and also a word, in the trail and in
                the heading line: status is never carried by colour alone (DESIGN.md §5 rule 6).
            -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex min-w-0 flex-wrap items-center gap-2">
                        <span>{{ period.label }}</span>
                        <StatusBadge
                            v-if="period.state && period.status_label"
                            :status="period.state"
                            :label="period.status_label"
                            size="sm"
                        />
                        <PayrollMonthChange
                            v-if="period.permissions.can_change_month && period.month"
                            class="ml-auto"
                            :period-id="period.id"
                            :month="period.month"
                        />
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex min-w-0 flex-col gap-4">
                    <PayrollStatusTrail
                        :statuses="statuses"
                        :current="period.status"
                        :month-label="period.label"
                    />

                    <p class="text-sm text-muted-foreground">
                        {{ summary }}
                        <template v-if="period.closes_the_month">
                            Income and expenses dated in {{ period.label }} are closed while the month is in
                            this state.
                        </template>
                    </p>

                    <!--
                        A reversal is not a footnote: it is why a month that was closed is open
                        again, and the Accountant whose finance edits it unblocked is the person
                        who most needs to read it.
                    -->
                    <p
                        v-if="period.lock_reversal"
                        class="flex min-w-0 items-start gap-2 rounded-md border border-border bg-muted/40 p-3 text-sm"
                    >
                        <Undo2 class="mt-1 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span>
                            <span class="font-medium">The lock on this month was reversed</span>
                            <template v-if="period.lock_reversal.by"> by {{ period.lock_reversal.by }}</template>:
                            “{{ period.lock_reversal.reason }}” The audit log keeps every reversal there has
                            ever been.
                        </span>
                    </p>

                    <!--
                        `tabindex="-1"` so that focus can be put back HERE when a transition has
                        just removed the button that was pressed — see `returnFocus()`. It is
                        not a tab stop: -1 takes focus only from script.
                    -->
                    <div :id="NEXT_STEPS_ID" tabindex="-1" class="min-w-0 outline-none">
                        <PayrollActions :period="period" :working="working" @run="run" />
                    </div>
                </CardContent>
            </Card>

            <PayrollItemsTable
                :items="items"
                :leave="leave"
                :currency="currency"
                :month-label="period.label"
                @edit="edit"
            />
        </div>

        <PayrollItemDialog
            :item="editing"
            :period-id="period.id"
            :month-label="period.label"
            :breakdown="editing ? leave[String(editing.id)] : undefined"
            :currency="currency"
            :figures-editable="figuresEditable"
            @close="closeEdit"
        />

        <PayrollCloseDialog
            :kind="closing"
            :month-label="period.label"
            :working="working !== null"
            @confirm="confirmClose"
            @cancel="cancelClose"
        />

        <ReverseLockDialog
            :open="reversing"
            :period-id="period.id"
            :month-label="period.label"
            @close="closeReversal"
        />
    </PageShell>
</template>
