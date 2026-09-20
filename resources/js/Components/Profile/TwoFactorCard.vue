<script lang="ts">
export interface TwoFactorStatus {
    enabled: boolean;
    required: boolean;
    recoveryCodesLeft: number;
}
</script>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmPasswordDialog from '@/Components/Profile/ConfirmPasswordDialog.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

const props = defineProps<{
    twoFactor: TwoFactorStatus;
}>();

type Action = 'regenerate' | 'disable';

const action = ref<Action | null>(null);
// Last opened action, kept while the dialog animates out so its text does not flip.
const shown = ref<Action>('regenerate');
const form = useForm({ password: '' });

const DIALOGS: Record<Action, { title: string; description: string; confirmLabel: string; destructive: boolean }> = {
    regenerate: {
        title: 'Regenerate recovery codes',
        description: 'Your current recovery codes stop working. Enter your password to continue.',
        confirmLabel: 'Regenerate',
        destructive: false,
    },
    disable: {
        title: 'Turn off two-factor authentication',
        description: 'Signing in will only need your password. Enter your password to continue.',
        confirmLabel: 'Turn off',
        destructive: true,
    },
};

const dialog = computed(() => DIALOGS[shown.value]);

const dialogOpen = computed({
    get: () => action.value !== null,
    set: (open: boolean) => {
        if (!open) {
            close();
        }
    },
});

const lowOnCodes = computed(() => props.twoFactor.recoveryCodesLeft <= 2);

function open(next: Action): void {
    form.reset();
    form.clearErrors();
    shown.value = next;
    action.value = next;
}

function close(): void {
    action.value = null;
    form.reset();
    form.clearErrors();
}

function confirm(password: string): void {
    if (form.processing || action.value === null) {
        return;
    }

    form.password = password;

    const options = {
        preserveScroll: true,
        onSuccess: () => close(),
        onError: () => form.reset('password'),
    };

    if (action.value === 'regenerate') {
        form.post('/profile/two-factor/recovery-codes', options);
    } else {
        form.delete('/profile/two-factor', options);
    }
}
</script>

<template>
    <Card class="min-w-0 gap-4 shadow-xs">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Two-factor authentication</CardTitle>
            <CardDescription>A code from your authenticator app at every sign-in.</CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <dl class="divide-y">
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-sm text-muted-foreground">Status</dt>
                    <dd class="flex items-center gap-2 text-sm font-medium">
                        <span
                            :class="cn('size-2 shrink-0 rounded-full', twoFactor.enabled ? 'bg-status-done' : 'bg-status-waiting')"
                            aria-hidden="true"
                        />
                        {{ twoFactor.enabled ? 'On' : 'Off' }}
                    </dd>
                </div>
                <div v-if="twoFactor.enabled" class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-sm text-muted-foreground">Recovery codes left</dt>
                    <dd
                        :class="cn('text-sm font-medium tabular-nums', lowOnCodes && 'text-status-waiting')"
                    >
                        {{ twoFactor.recoveryCodesLeft }}
                    </dd>
                </div>
            </dl>

            <p v-if="twoFactor.required" class="text-xs text-muted-foreground">Required for your role.</p>
            <p v-if="twoFactor.enabled && lowOnCodes" class="text-xs text-muted-foreground">
                You are running low on recovery codes. Regenerate them and store the new set safely.
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <template v-if="twoFactor.enabled">
                    <Button variant="outline" @click="open('regenerate')">Regenerate recovery codes</Button>
                    <Button v-if="!twoFactor.required" variant="destructive" @click="open('disable')">
                        Turn off
                    </Button>
                </template>
                <Button v-else as-child>
                    <Link href="/two-factor/enrol">Turn on</Link>
                </Button>
            </div>
        </CardContent>

        <ConfirmPasswordDialog
            v-model:open="dialogOpen"
            :title="dialog.title"
            :description="dialog.description"
            :confirm-label="dialog.confirmLabel"
            :destructive="dialog.destructive"
            :error="form.errors.password"
            :processing="form.processing"
            @confirm="confirm"
        />
    </Card>
</template>
