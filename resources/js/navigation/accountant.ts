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
            { label: 'My Leave', icon: CalendarOff, phase: 5 },
            { label: 'My Payslip', icon: FileText, phase: 9 },
        ],
    },
];
