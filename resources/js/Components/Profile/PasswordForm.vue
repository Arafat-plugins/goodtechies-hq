<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    if (form.processing) {
        return;
    }

    form.put('/profile/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4 shadow-xs">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Password</CardTitle>
            <CardDescription>Change the password you sign in with.</CardDescription>
        </CardHeader>
        <CardContent>
            <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label for="current-password">
                        Current password <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Input
                        id="current-password"
                        v-model="form.current_password"
                        name="current_password"
                        type="password"
                        autocomplete="current-password"
                        required
                        :aria-invalid="form.errors.current_password ? true : undefined"
                        :aria-describedby="form.errors.current_password ? 'current-password-error' : undefined"
                    />
                    <p v-if="form.errors.current_password" id="current-password-error" class="text-xs text-destructive">
                        {{ form.errors.current_password }}
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <Label for="new-password">
                            New password <span class="text-destructive" aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="new-password"
                            v-model="form.password"
                            name="password"
                            type="password"
                            autocomplete="new-password"
                            required
                            :aria-invalid="form.errors.password ? true : undefined"
                            :aria-describedby="form.errors.password ? 'new-password-error new-password-help' : 'new-password-help'"
                        />
                        <p id="new-password-help" class="text-xs text-muted-foreground">
                            At least 12 characters. Passwords found in known data breaches are refused.
                        </p>
                        <p v-if="form.errors.password" id="new-password-error" class="text-xs text-destructive">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="confirm-password">
                            Confirm new password <span class="text-destructive" aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="confirm-password"
                            v-model="form.password_confirmation"
                            name="password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Updating…' : 'Update password' }}
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
