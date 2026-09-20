<script setup lang="ts">
import FlashMessage from '@/Components/FlashMessage.vue';
import AppSidebar from '@/Components/Shell/AppSidebar.vue';
import AppTopBar from '@/Components/Shell/AppTopBar.vue';
import { accountantNav } from '@/navigation/accountant';

const homeHref = '/accountant/dashboard';
</script>

<template>
    <div class="min-h-screen bg-background">
        <AppSidebar :groups="accountantNav" :home-href="homeHref" />
        <div class="flex min-h-screen flex-col lg:pl-64">
            <AppTopBar :groups="accountantNav" :home-href="homeHref" />
            <main class="mx-auto w-full max-w-screen-2xl flex-1 p-4 md:p-6">
                <FlashMessage class="mb-4" />
                <slot />
            </main>
        </div>
    </div>
</template>
