<script setup lang="ts">
import AppWordmark from '@/Components/AppWordmark.vue';
import FlashMessage from '@/Components/FlashMessage.vue';
import { Card } from '@/Components/ui/card';
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center bg-muted p-4 md:p-6">
        <main class="flex w-full max-w-sm flex-col gap-6">
            <AppWordmark class="self-center" />
            <FlashMessage />
            <Card class="w-full shadow-xs">
                <slot />
            </Card>
            <p class="text-center text-xs text-muted-foreground">
                Internal system — authorised staff only
            </p>
        </main>
    </div>
</template>
