<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FlashMessage from '@/Components/FlashMessage.vue';
import AppSidebar from '@/Components/Shell/AppSidebar.vue';
import AppTopBar from '@/Components/Shell/AppTopBar.vue';
import { employeeNav } from '@/navigation/employee';

const page = usePage();

const nav = computed(() => employeeNav(page.props.auth.user?.trackingMode));
const homeHref = '/employee/dashboard';
</script>

<template>
    <div class="min-h-screen bg-background">
        <AppSidebar :groups="nav" :home-href="homeHref" />
        <div class="flex min-h-screen flex-col lg:pl-64">
            <AppTopBar :groups="nav" :home-href="homeHref" />
            <main class="mx-auto w-full max-w-screen-2xl flex-1 p-4 md:p-6">
                <FlashMessage class="mb-4" />
                <slot />
            </main>
        </div>
    </div>
</template>
