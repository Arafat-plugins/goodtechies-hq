<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

const open = defineModel<boolean>('open', { default: false });

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmLabel?: string;
        destructive?: boolean;
        /** Validation message for `password`, owned by the parent's form. */
        error?: string;
        processing?: boolean;
    }>(),
    {
        description: undefined,
        confirmLabel: 'Confirm',
        destructive: false,
        error: undefined,
        processing: false,
    },
);

const emit = defineEmits<{
    confirm: [password: string];
}>();

const password = ref('');

// Never keep a typed password around between openings, or after a failed attempt.
watch(open, () => {
    password.value = '';
});

watch(
    () => props.error,
    (error) => {
        if (error) {
            password.value = '';
        }
    },
);

function submit(): void {
    if (props.processing || password.value === '') {
        return;
    }

    emit('confirm', password.value);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription v-if="description">{{ description }}</DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-2">
                    <Label for="confirm-password-input">
                        Password <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Input
                        id="confirm-password-input"
                        v-model="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        autofocus
                        :aria-invalid="error ? true : undefined"
                        :aria-describedby="error ? 'confirm-password-input-error' : undefined"
                    />
                    <p v-if="error" id="confirm-password-input-error" class="text-xs text-destructive">
                        {{ error }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="processing" @click="open = false">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="processing || password === ''"
                    >
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
