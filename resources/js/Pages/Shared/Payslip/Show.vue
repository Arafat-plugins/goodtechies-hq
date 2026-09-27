<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Download, Printer } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import LeaveImpactNote from '@/Components/Payroll/LeaveImpactNote.vue';
import type { LeaveExplanation, PayslipRow } from '@/Components/Payroll/payslip';
import { payslipRoutes } from '@/Components/Payroll/payslip';
import PayslipFigures from '@/Components/Payroll/PayslipFigures.vue';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * **One payslip** — the month, the figures, the leave impact, and the two ways to keep it
 * (master prompt Part D §14: *"payslip = browser print view + PDF via dompdf"*, Phase 9).
 *
 * ## Same page, three shells
 *
 * The layout is picked from `auth.user.surface`, exactly as `Pages/Shared/Messages.vue` does —
 * so the Accountant reads their own payslip in the **Accountant** shell, which is the whole
 * point of Part C §1's cell. Nothing here imports from `Pages/Admin/`.
 *
 * The id in the URL was resolved by `PayrollService::findItemFor()` before this page was ever
 * rendered: somebody else's is a 404 and the attempt is in `audit_logs`. There is no ownership
 * check in this file, because a check here would be a second copy of a rule that is already
 * enforced by the query.
 *
 * ## `admin_notes` is not on this page and cannot be
 *
 * Decision 9-10. `PayrollItemResource` leaves the key **absent** for everybody who is not an
 * ADMIN — including for the employee the note is about — so the payload this page receives has
 * no such key. Nothing here renders it and nothing here may be added that would.
 *
 * ## The print view
 *
 * Part D §14 asks for a *browser print view*, and this is it: **a real print stylesheet, not a
 * second page**. Two ideas do all of it.
 *
 *  1. **One element prints.** The `@media print` block hides everything in the document and
 *     then un-hides `[data-payslip-sheet]` and its descendants. That is the one technique that
 *     works without naming a single thing in the app shell — no `aside`, no `header`, no class
 *     from `Layouts/*.vue`, none of which this slice owns and any of which could be renamed.
 *     The sidebar, the top bar, the timer bar, the toaster, the flash message, the skip link
 *     and this page's own buttons all disappear because they are not inside the sheet.
 *  2. **The rules are inert until this page is open.** They are scoped to
 *     `:root[data-print-view='payslip']`, an attribute set on `<html>` in `onMounted` and
 *     removed in `onBeforeUnmount`. A Vue SFC's non-scoped `<style>` is global and stays
 *     loaded once its chunk has been fetched, so without that guard, printing any *other*
 *     Inertia page after visiting this one would print a blank sheet. This is the failure the
 *     guard exists for, and it is the reason the block is an attribute selector rather than
 *     plain `@media print`.
 *
 * **Colour is forced to black on white** inside the sheet — `!important` on the text, the
 * background and the borders — because the app has a dark theme and a browser printing dark
 * mode produces white text on white paper. Raw hex here is not a token violation: DESIGN.md's
 * rule is about Tailwind classes, whose light and dark values `app.css` owns, and **paper has
 * no theme**. There is no `prefers-color-scheme` on a sheet of paper, no dark variant to
 * define, and nothing on this page carries meaning by colour in the first place — the status is
 * a word, the deduction column says *Deducted*, and the provisional banner is a sentence.
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
    subject: { id: number | null; name: string };
    currency: string;
    payslip: PayslipRow;
    leave: LeaveExplanation;
}>();

const monthLabel = computed(() => props.payslip.period?.label ?? 'This month');
const routes = computed(() => payslipRoutes(props.payslip.id));

/** See the class note: the print rules do nothing until this attribute is on `<html>`. */
onMounted(() => {
    document.documentElement.setAttribute('data-print-view', 'payslip');
});

onBeforeUnmount(() => {
    document.documentElement.removeAttribute('data-print-view');
});

function print(): void {
    window.print();
}
</script>

<template>
    <Head :title="`${payslip.release.label} — ${monthLabel}`" />

    <PageShell :title="`${monthLabel} — ${payslip.release.label.toLowerCase()}`" :description="payslip.release.note">
        <template #actions>
            <div class="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" @click="print">
                    <Printer aria-hidden="true" />
                    Print
                </Button>
                <!--
                    A real navigation, not a fetch: the PDF endpoint returns a file download, so
                    it must leave Inertia's XHR pipeline. `<a download>` on a same-origin route
                    is the plain-HTML way to say that, and it keeps the control a link — which
                    is what it is.
                -->
                <Button as-child>
                    <a :href="routes.pdf" download>
                        <Download aria-hidden="true" />
                        Download PDF
                    </a>
                </Button>
            </div>
        </template>

        <div class="flex min-w-0 flex-col gap-6">
            <Link
                href="/payslip"
                class="inline-flex w-fit items-center gap-1.5 rounded-md text-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                All my payslips
            </Link>

            <!--
                THE SHEET. Everything inside this element is what a printer gets, and nothing
                outside it is. Keep the two print-only controls out of it.
            -->
            <Card data-payslip-sheet class="min-w-0 gap-6 p-6">
                <!--
                    A provisional month says so across the top, in words, before any figure is
                    read. It is not a tint: this banner has to survive greyscale, a screen
                    reader and a laser printer, which is exactly the journey this page's
                    figures take.
                -->
                <p
                    v-if="!payslip.release.released"
                    class="rounded-md border border-foreground/40 px-4 py-3 text-sm font-semibold"
                >
                    Provisional — not a payslip. {{ payslip.release.note }}
                </p>

                <header class="flex min-w-0 flex-col gap-2 border-b border-border pb-4">
                    <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        GoodTechies HQ — {{ payslip.release.label }}
                    </p>
                    <h2 class="text-xl font-semibold tracking-tight">{{ payslip.employee?.name ?? subject.name }}</h2>
                    <dl class="flex min-w-0 flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                        <div class="flex items-center gap-2">
                            <dt class="text-muted-foreground">Pay period</dt>
                            <dd class="font-medium">{{ monthLabel }}</dd>
                        </div>
                        <div class="flex items-center gap-2">
                            <dt class="text-muted-foreground">Period status</dt>
                            <dd class="flex items-center gap-2 font-medium">
                                <!--
                                    The label is printed beside the badge and never replaced by
                                    it: on paper the tint is gone and the word is all that is
                                    left (DESIGN.md §5.6).
                                -->
                                <StatusBadge
                                    v-if="payslip.period?.state"
                                    :status="payslip.period.state"
                                    :label="payslip.period.status_label ?? undefined"
                                    size="sm"
                                />
                                <span v-else>{{ payslip.period?.status_label ?? 'Unknown' }}</span>
                            </dd>
                        </div>
                    </dl>
                </header>

                <section aria-labelledby="payslip-figures" class="flex min-w-0 flex-col gap-3">
                    <h3 id="payslip-figures" class="text-sm font-semibold tracking-tight">Figures</h3>
                    <PayslipFigures :payslip="payslip" :currency="currency" />
                </section>

                <section aria-labelledby="payslip-leave" class="flex min-w-0 flex-col gap-3 border-t border-border pt-4">
                    <h3 id="payslip-leave" class="text-sm font-semibold tracking-tight">Leave impact</h3>
                    <LeaveImpactNote :leave="leave" :month-label="monthLabel" :currency="currency" />
                </section>

                <p class="border-t border-border pt-4 text-xs text-muted-foreground">
                    Questions about a figure on this page go to an Admin. GoodTechies HQ.
                </p>
            </Card>
        </div>
    </PageShell>
</template>

<style>
/*
 * The browser print view (Part D §14). See this component's script note for why the rules are
 * guarded by an attribute on <html> and why raw colours are correct here.
 */
@media print {
    :root[data-print-view='payslip'] {
        background: #ffffff;
    }

    /* Nothing prints … */
    :root[data-print-view='payslip'] body * {
        visibility: hidden;
    }

    /* … except the sheet and what is inside it. */
    :root[data-print-view='payslip'] [data-payslip-sheet],
    :root[data-print-view='payslip'] [data-payslip-sheet] * {
        visibility: visible;
    }

    /*
     * Lift the sheet out of the shell's padded, offset column so it starts at the top-left of
     * the page rather than wherever the sidebar had pushed it.
     */
    :root[data-print-view='payslip'] [data-payslip-sheet] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        margin: 0;
        padding: 0;
        border: 0;
        box-shadow: none;
    }

    /* Ink on paper. The app's dark theme must not reach a printer. */
    :root[data-print-view='payslip'] body,
    :root[data-print-view='payslip'] [data-payslip-sheet],
    :root[data-print-view='payslip'] [data-payslip-sheet] * {
        color: #000000 !important;
        background: transparent !important;
        background-image: none !important;
        box-shadow: none !important;
        text-shadow: none !important;
    }

    :root[data-print-view='payslip'] [data-payslip-sheet] *[class*='border'] {
        border-color: #000000 !important;
    }

    @page {
        margin: 16mm;
    }
}
</style>
