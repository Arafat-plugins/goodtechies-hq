<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Button } from '@/Components/ui/button';
import { CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

defineOptions({ layout: AuthLayout });

const props = defineProps<{
    token: string;
    email: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit(): void {
    if (form.processing) {
        return;
    }

    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Choose a new password" />

    <CardHeader>
        <CardTitle>Choose a new password</CardTitle>
        <CardDescription>At least 12 characters. You'll sign in with it next.</CardDescription>
    </CardHeader>

    <CardContent class="flex flex-col gap-4">
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
                    autocomplete="username"
                    required
                    :aria-invalid="form.errors.email ? true : undefined"
                    :aria-describedby="form.errors.email ? 'email-error' : undefined"
                />
                <p v-if="form.errors.email" id="email-error" class="text-xs text-destructive">
                    {{ form.errors.email }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <Label for="password">
                    New password <span class="text-destructive" aria-hidden="true">*</span>
                </Label>
                <Input
                    id="password"
                    v-model="form.password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    required
                    autofocus
                    :aria-invalid="form.errors.password ? true : undefined"
                    :aria-describedby="form.errors.password ? 'password-error' : undefined"
                />
                <p v-if="form.errors.password" id="password-error" class="text-xs text-destructive">
                    {{ form.errors.password }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <Label for="password_confirmation">
                    Confirm new password <span class="text-destructive" aria-hidden="true">*</span>
                </Label>
                <Input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    :aria-invalid="form.errors.password_confirmation ? true : undefined"
                    :aria-describedby="form.errors.password_confirmation ? 'password_confirmation-error' : undefined"
                />
                <p
                    v-if="form.errors.password_confirmation"
                    id="password_confirmation-error"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.password_confirmation }}
                </p>
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">
                Change password
            </Button>
        </form>
    </CardContent>
</template>
