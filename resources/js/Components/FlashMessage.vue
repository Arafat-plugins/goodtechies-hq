<script setup lang="ts">
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/Components/ui/alert';

const page = usePage();

const success = computed(() => page.props.flash?.success ?? null);
const error = computed(() => page.props.flash?.error ?? null);
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
