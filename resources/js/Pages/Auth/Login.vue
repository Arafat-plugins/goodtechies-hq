<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import { CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

defineOptions({ layout: AuthLayout });

defineProps<{
    status: string | null;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    if (form.processing) {
        return;
    }

    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <CardHeader>
        <CardTitle>Sign in</CardTitle>
        <CardDescription>Use your GoodTechies account.</CardDescription>
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
                    autocomplete="username"
                    required
                    autofocus
                    :aria-invalid="form.errors.email ? true : undefined"
                    :aria-describedby="form.errors.email ? 'email-error' : undefined"
                />
                <p v-if="form.errors.email" id="email-error" class="text-xs text-destructive">
                    {{ form.errors.email }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <Label for="password">
                    Password <span class="text-destructive" aria-hidden="true">*</span>
                </Label>
                <Input
                    id="password"
                    v-model="form.password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    :aria-invalid="form.errors.password ? true : undefined"
                    :aria-describedby="form.errors.password ? 'password-error' : undefined"
                />
                <p v-if="form.errors.password" id="password-error" class="text-xs text-destructive">
                    {{ form.errors.password }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="remember"
                    name="remember"
                    :model-value="form.remember"
                    @update:model-value="(value) => (form.remember = value === true)"
                />
                <Label for="remember" class="font-normal">Remember me</Label>
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">
                Sign in
            </Button>
        </form>
    </CardContent>
</template>
