import { CalendarOff, ChartPie, FileText, HandCoins, LayoutDashboard, Receipt, TrendingUp } from '@lucide/vue';
import type { NavGroup } from './types';

export const accountantNav: NavGroup[] = [
    {
        label: 'Finance',
        items: [
            // **This points at `/finance`, not at `/accountant/dashboard`.** The shell's LANDING
            // is still `/accountant/dashboard` — `Surface::Accountant->homeRoute()` says so and
            // a test asserts it — but that page is Phase 0's stub, and the row a person clicks
            // should be the screen that answers the question. Part D §2 fixes this shell's rows
            // at Dashboard · Income · Expenses · Payroll · Financial Reports, and the finance
            // dashboard IS the Dashboard that list means; the stub was the placeholder standing
            // in for it. `activePrefix` is exact, so `/finance/income` lights Income and not
            // this row: `activeItem()` gives a URL to the LONGEST claim, so `/finance/income`
            // belongs to the Income row below and only `/finance` itself lands here. No
            // `activePrefix` is needed or wanted.
            { label: 'Dashboard', href: '/finance', icon: LayoutDashboard },
            // Phase 8. All three point at the SHARED `/finance/*` routes, because whose money
            // it is belongs to the agency and not to the shell — the same reason My Leave below
            // points at `/leave`. Each page picks `AccountantLayout` from the viewer's surface,
            // so this shell imports nothing from `Layouts/AdminLayout.vue` or `Pages/Admin/`.
            //
            // `activePrefix` on Income keeps the row lit on `/finance/income/create` and
            // `/finance/income/{id}/edit`, which are real addresses the form opens at.
            //
            // **Categories is deliberately not a row here.** Part D §2's Accountant sidebar is
            // Dashboard · Income · Expenses · Payroll · Financial Reports and nothing else, and
            // the Accountant may only read that list anyway (decision 8-12) — it is reached
            // from either ledger's header, where somebody is when they wonder what a category
            // is called.
            {
                label: 'Income',
                href: '/finance/income',
                activePrefix: '/finance/income',
                icon: TrendingUp,
            },
            {
                label: 'Expenses',
                href: '/finance/expenses',
                activePrefix: '/finance/expenses',
                icon: Receipt,
            },
            // Phase 9. The SHARED `/payroll` routes, for the reason the three rows above point
            // at `/finance/*`: whose pay it is belongs to the agency and not to the shell. The
            // page picks `AccountantLayout` from the viewer's surface, so this shell still
            // imports nothing from `Layouts/AdminLayout.vue` or `Pages/Admin/`.
            //
            // `activePrefix` keeps the row lit on `/payroll/{period}`, which is where the
            // month's own screen lives and where every transition posts back to.
            //
            // **Salary settings is deliberately not a row here.** Part D §14 puts it on the
            // Admin screen ("Admin → Payroll: … salary settings per employee") and the
            // Accountant may not set a salary, so the row exists on the Admin shell alone.
            { label: 'Payroll', href: '/payroll', activePrefix: '/payroll', icon: HandCoins },
            { label: 'Financial Reports', href: '/finance/report', icon: ChartPie },
        ],
    },
    {
        label: 'Me',
        items: [
            // **The one thing the Accountant surface does beyond finance**, and Part C §1 says
            // so in as many words: "the ACCOUNTANT may apply for own leave and view own
            // payslip. The Accountant shell therefore carries My Leave and My Payslip."
            //
            // It points at the SHARED `/leave`, which is where a fact about the person lives
            // (decision 4-15's shape) — and the page picks `AccountantLayout` from the viewer's
            // surface, so this shell imports nothing from `Layouts/AdminLayout.vue` or
            // `Pages/Admin/` to get it.
            { label: 'My Leave', href: '/leave', icon: CalendarOff },
            // Phase 9, and the second half of the sentence above it: Part C §1 gives *every*
            // role "view own payslip", the Accountant included. It points at the SHARED
            // `/payslip`, which is where a fact about the person lives, and the page picks
            // `AccountantLayout` from the viewer's surface like everything else on this shell.
            //
            // It is NOT behind `payroll.draft` like the Payroll row above: an employee reaches
            // their own line through `PayrollItem` and `payroll.view_own`, never through a
            // period, and the two screens answer two different questions about two different
            // scopes.
            { label: 'My Payslip', href: '/payslip', activePrefix: '/payslip', icon: FileText },
        ],
    },
];
