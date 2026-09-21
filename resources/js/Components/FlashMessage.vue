<script setup lang="ts">
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { flashClaimed } from '@/lib/flashChannel';

const page = usePage();

/**
 * A screen that announces its own writes takes the flash to the toaster instead
 * (`useFlashAsToast`). While one is mounted this renders nothing, so the same sentence is
 * never said twice — DESIGN.md §5.19.
 */
const success = computed(() => (flashClaimed.value ? null : (page.props.flash?.success ?? null)));
const error = computed(() => (flashClaimed.value ? null : (page.props.flash?.error ?? null)));
</script>

<template>
    <div v-if="success || error" class="flex flex-col gap-2">
        <Alert v-if="success">
            <CircleCheck class="text-status-done" />
            <AlertDescription class="text-foreground">{{ success }}</AlertDescription>
        </Alert>
        <Alert v-if="error" variant="destructive">
            <CircleAlert />
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>
    </div>
</template>
