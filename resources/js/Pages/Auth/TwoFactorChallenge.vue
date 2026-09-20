<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, ref, useTemplateRef } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Button } from '@/Components/ui/button';
import { CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PinInput, PinInputGroup, PinInputSlot } from '@/Components/ui/pin-input';

defineOptions({ layout: AuthLayout });

const useRecovery = ref(false);
const digits = ref<number[]>([]);
const recoveryInput = useTemplateRef<HTMLElement>('recoveryInput');

const form = useForm({
    code: '',
    recovery_code: '',
});

function submit(): void {
    if (form.processing) {
        return;
    }

    form.transform((data) => (useRecovery.value ? { recovery_code: data.recovery_code } : { code: data.code }))
        .post('/two-factor/challenge', {
            onError: () => {
                digits.value = [];
                form.code = '';
            },
        });
}

function onDigitsComplete(value: number[]): void {
    form.code = value.join('');
    submit();
}

async function toggleMode(): Promise<void> {
    useRecovery.value = !useRecovery.value;
    form.clearErrors();
    digits.value = [];
    form.code = '';
    form.recovery_code = '';

    await nextTick();

    if (useRecovery.value) {
        (recoveryInput.value?.querySelector('input') ?? recoveryInput.value)?.focus();
    } else {
        document.querySelector<HTMLInputElement>('[data-slot="pin-input-slot"]')?.focus();
    }
}
</script>

<template>
    <Head title="Two-factor check" />

    <CardHeader>
        <CardTitle>Two-factor check</CardTitle>
        <CardDescription>
            {{ useRecovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.' }}
        </CardDescription>
    </CardHeader>

    <CardContent>
        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <div v-if="!useRecovery" class="flex flex-col gap-2">
                <Label for="code-0" class="sr-only">Authentication code</Label>
                <PinInput
                    id="code"
                    v-model="digits"
                    type="number"
                    otp
                    :disabled="form.processing"
                    class="justify-center"
                    @complete="onDigitsComplete"
                >
                    <PinInputGroup>
                        <PinInputSlot
                            v-for="index in 6"
                            :id="index === 1 ? 'code-0' : undefined"
                            :key="index"
                            :index="index - 1"
                            :autofocus="index === 1"
                            :aria-invalid="form.errors.code ? true : undefined"
                        />
                    </PinInputGroup>
                </PinInput>
                <p v-if="form.errors.code" class="text-center text-xs text-destructive">
                    {{ form.errors.code }}
                </p>
            </div>

            <div v-else ref="recoveryInput" class="flex flex-col gap-2">
                <Label for="recovery_code">Recovery code</Label>
                <Input
                    id="recovery_code"
                    v-model="form.recovery_code"
                    name="recovery_code"
                    type="text"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    placeholder="XXXXX-XXXXX"
                    class="font-mono"
                    :aria-invalid="form.errors.recovery_code || form.errors.code ? true : undefined"
                />
                <p v-if="form.errors.recovery_code || form.errors.code" class="text-xs text-destructive">
                    {{ form.errors.recovery_code || form.errors.code }}
                </p>
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">
                Verify
            </Button>

            <Button type="button" variant="link" size="sm" class="self-center" @click="toggleMode">
                {{ useRecovery ? 'Use an authenticator code' : 'Use a recovery code' }}
            </Button>
        </form>
    </CardContent>

    <CardFooter class="justify-center border-t">
        <Link href="/login" class="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">
            Back to sign in
        </Link>
    </CardFooter>
</template>
