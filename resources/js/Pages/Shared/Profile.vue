<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import LoginHistoryCard, { type LoginAttempt } from '@/Components/Profile/LoginHistoryCard.vue';
import PasswordForm from '@/Components/Profile/PasswordForm.vue';
import ProfileDetailsForm, { type ProfileDetails } from '@/Components/Profile/ProfileDetailsForm.vue';
import SessionsCard, { type ActiveSession } from '@/Components/Profile/SessionsCard.vue';
import TwoFactorCard, { type TwoFactorStatus } from '@/Components/Profile/TwoFactorCard.vue';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

// Persistent layout picked from the viewer's own surface (Inertia layout callback, receives page props).
defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

defineProps<{
    profile: ProfileDetails;
    timezones: string[];
    twoFactor: TwoFactorStatus;
    sessions: ActiveSession[];
    loginHistory: LoginAttempt[];
}>();
</script>

<template>
    <Head title="Profile" />

    <div class="flex flex-col gap-6">
        <PageHeader title="Profile" description="Your account, security and sign-in activity." />

        <!-- Below lg the two column wrappers are `contents`, so the order utilities interleave the cards. -->
        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
            <div class="contents lg:col-span-2 lg:flex lg:min-w-0 lg:flex-col lg:gap-4">
                <ProfileDetailsForm class="order-1" :profile="profile" :timezones="timezones" />
                <PasswordForm class="order-3" />
                <SessionsCard class="order-4" :sessions="sessions" />
                <LoginHistoryCard class="order-5" :attempts="loginHistory" />
            </div>
            <div class="contents lg:flex lg:min-w-0 lg:flex-col lg:gap-4">
                <TwoFactorCard class="order-2" :two-factor="twoFactor" />
            </div>
        </div>
    </div>
</template>
