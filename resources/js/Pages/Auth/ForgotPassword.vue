<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import { CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

defineOptions({ layout: AuthLayout });

defineProps<{
    status: string | null;
}>();

const form = useForm({
    email: '',
});

function submit(): void {
    if (form.processing) {
        return;
    }

    form.post('/forgot-password', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Reset your password" />

    <CardHeader>
        <CardTitle>Reset your password</CardTitle>
        <CardDescription>Enter your account email. We'll send you a link to choose a new password.</CardDescription>
    </CardHeader>

    <CardContent class="flex flex-col gap-4">
        <Alert v-if="status">
            <CircleCheck class="text-status-done" />
            <AlertDescription class="text-foreground">{{ status }}</AlertDescription>
        </Alert>

        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <div class="flex flex-col gap-2">
                <Label for="email">
                    Email <span class="text-destructive" aria-hidden="true">*</span>
                </Label>
                <Input
                    id="email"
                    v-model="form.email"
                    name="email"
                    type="email"
                    autocomplete="email"
                    required
                    autofocus
                    :aria-invalid="form.errors.email ? true : undefined"
                    :aria-describedby="form.errors.email ? 'email-error' : undefined"
                />
                <p v-if="form.errors.email" id="email-error" class="text-xs text-destructive">
                    {{ form.errors.email }}
                </p>
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">
                Send reset link
            </Button>

            <div class="flex justify-center">
                <Link
                    href="/login"
                    class="text-sm text-primary underline-offset-4 hover:underline focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none rounded-sm"
                >
                    Back to sign in
                </Link>
            </div>
        </form>
    </CardContent>
</template>
