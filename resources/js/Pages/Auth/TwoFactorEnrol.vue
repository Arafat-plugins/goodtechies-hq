<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Copy, ShieldAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import { CardContent, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { PinInput, PinInputGroup, PinInputSlot } from '@/Components/ui/pin-input';
import { Separator } from '@/Components/ui/separator';

defineOptions({ layout: AuthLayout });

const props = defineProps<{
    qrSvg: string;
    secret: string;
    required: boolean;
}>();

const groupedSecret = computed(() => props.secret.match(/.{1,4}/g)?.join(' ') ?? props.secret);

const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copySecret(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.secret);
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

const digits = ref<number[]>([]);

const form = useForm({
    code: '',
});

const isComplete = computed(() => digits.value.filter((digit) => digit !== undefined).length === 6);

function submit(): void {
    if (form.processing || !isComplete.value) {
        return;
    }

    form.code = digits.value.join('');
    form.post('/two-factor/enrol', {
        onError: () => {
            digits.value = [];
        },
    });
}

function signOut(): void {
    router.post('/logout');
}
</script>

<template>
    <Head title="Set up two-factor authentication" />

    <CardHeader>
        <CardTitle>Set up two-factor authentication</CardTitle>
    </CardHeader>

    <CardContent class="flex flex-col gap-6">
        <Alert v-if="required">
            <ShieldAlert class="text-status-review" />
            <AlertDescription class="text-foreground">
                Your role requires two-factor authentication before you can continue.
            </AlertDescription>
        </Alert>

        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-medium">1. Scan this QR code</h2>
            <p class="text-xs text-muted-foreground">
                Use Google Authenticator, 1Password, Authy or any TOTP app.
            </p>
            <!-- qrSvg is generated server-side from our own data (BaconQrCode). -->
            <div
                class="size-52 self-center rounded-lg border bg-card p-2 *:size-full"
                role="img"
                aria-label="QR code for your authenticator app"
                v-html="qrSvg"
            />
        </section>

        <Separator />

        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-medium">2. Or enter this key</h2>
            <div class="flex items-center gap-2 rounded-md border bg-muted p-2">
                <code class="min-w-0 flex-1 font-mono text-sm break-words">{{ groupedSecret }}</code>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="copied ? 'Key copied' : 'Copy key'"
                    @click="copySecret"
                >
                    <Check v-if="copied" class="text-status-done" />
                    <Copy v-else />
                </Button>
            </div>
            <p class="sr-only" aria-live="polite">{{ copied ? 'Key copied to clipboard' : '' }}</p>
        </section>

        <Separator />

        <form class="flex flex-col gap-3" novalidate @submit.prevent="submit">
            <Label for="enrol-code-0" class="text-sm font-medium">3. Enter the 6-digit code</Label>
            <PinInput
                v-model="digits"
                type="number"
                otp
                :disabled="form.processing"
                class="justify-center"
                @complete="submit"
            >
                <PinInputGroup>
                    <PinInputSlot
                        v-for="index in 6"
                        :id="index === 1 ? 'enrol-code-0' : undefined"
                        :key="index"
                        :index="index - 1"
                        :aria-invalid="form.errors.code ? true : undefined"
                    />
                </PinInputGroup>
            </PinInput>
            <p v-if="form.errors.code" class="text-center text-xs text-destructive">
                {{ form.errors.code }}
            </p>
            <Button type="submit" class="w-full" :disabled="form.processing || !isComplete">
                Confirm
            </Button>
        </form>
    </CardContent>

    <CardFooter class="justify-center border-t">
        <Button type="button" variant="ghost" size="sm" class="text-muted-foreground" @click="signOut">
            Sign out
        </Button>
    </CardFooter>
</template>
