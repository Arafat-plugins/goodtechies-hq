import { CalendarOff, ChartPie, FileText, HandCoins, LayoutDashboard, Receipt, TrendingUp } from '@lucide/vue';
import type { NavGroup } from './types';

export const accountantNav: NavGroup[] = [
    {
        label: 'Finance',
        items: [
            { label: 'Dashboard', href: '/accountant/dashboard', icon: LayoutDashboard },
            { label: 'Income', icon: TrendingUp, phase: 8 },
            { label: 'Expenses', icon: Receipt, phase: 8 },
            { label: 'Payroll', icon: HandCoins, phase: 9 },
            { label: 'Financial Reports', icon: ChartPie, phase: 8 },
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
            { label: 'My Payslip', icon: FileText, phase: 9 },
        ],
    },
];
