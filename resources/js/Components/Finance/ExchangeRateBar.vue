<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';
import type { ExchangeRate } from '@/Components/Finance/finance';
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
import { RadioGroup, RadioGroupItem } from '@/Components/ui/radio-group';

/**
 * The USD→BDT rate every USD figure on the finance screens is converted at (D7), on one line.
 *
 * `auto` follows the live market rate (refreshed every six hours); `manual` is the Admin's own
 * figure. Whoever holds `finance.manage` (`can_edit`) changes it here; the server re-checks.
 */
const props = defineProps<{
    rate: ExchangeRate;
}>();

const open = ref(false);
const mode = ref<'auto' | 'manual'>(props.rate.mode);
const value = ref<string>(props.rate.usd_bdt);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const rateId = useId();
const autoId = useId();
const manualId = useId();

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/** "updated 2 hours ago", or the locale date when it is more than a day old. */
const updatedText = computed((): string | null => {
    if (!props.rate.updated_at) {
        return null;
    }

    const at = new Date(props.rate.updated_at);

    if (Number.isNaN(at.getTime())) {
        return null;
    }

    const minutes = Math.round((at.getTime() - Date.now()) / 60000);

    if (Math.abs(minutes) < 60) {
        return relative.format(minutes, 'minute');
    }

    if (Math.abs(minutes) < 60 * 24) {
        return relative.format(Math.round(minutes / 60), 'hour');
    }

    return at.toLocaleString();
});

const sourceText = computed((): string => {
    if (props.rate.mode === 'manual') {
        return 'Set by admin';
    }

    return updatedText.value ? `Live rate, updated ${updatedText.value}` : 'Live rate';
});

watch(open, (isOpen) => {
    if (isOpen) {
        mode.value = props.rate.mode;
        value.value = props.rate.usd_bdt;
        errors.value = {};
    }
});

function save(): void {
    router.put(
        '/finance/exchange-rate',
        mode.value === 'manual' ? { mode: 'manual', rate: value.value } : { mode: 'auto' },
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
            },
            onFinish: () => {
                processing.value = false;
            },
            onError: (bag) => {
                errors.value = bag;
            },
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-center gap-3 text-sm">
        <p class="min-w-0 text-muted-foreground">
            <span class="font-medium text-foreground tabular-nums">1 USD = {{ rate.usd_bdt }} BDT</span>
            · {{ sourceText }}
        </p>

        <Button v-if="rate.can_edit" variant="outline" size="sm" @click="open = true">Change rate</Button>

        <Dialog v-if="rate.can_edit" :open="open" @update:open="(next: boolean) => (open = next)">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>USD to BDT rate</DialogTitle>
                    <DialogDescription>
                        Every USD amount in the finance reports is converted to BDT at this rate. The original
                        USD figure stays beside it. The change is recorded in the audit log.
                    </DialogDescription>
                </DialogHeader>

                <form class="flex min-w-0 flex-col gap-4" @submit.prevent="save">
                    <RadioGroup v-model="mode" class="gap-2">
                        <div class="flex items-center gap-2">
                            <RadioGroupItem :id="autoId" value="auto" :disabled="processing" />
                            <Label :for="autoId" class="font-normal">Use the live rate</Label>
                        </div>
                        <div class="flex items-center gap-2">
                            <RadioGroupItem :id="manualId" value="manual" :disabled="processing" />
                            <Label :for="manualId" class="font-normal">Set my own rate</Label>
                        </div>
                    </RadioGroup>

                    <div class="flex flex-col gap-2">
                        <Label :for="rateId">BDT for 1 USD</Label>
                        <Input
                            :id="rateId"
                            v-model="value"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="50"
                            max="500"
                            :disabled="mode !== 'manual' || processing"
                            :required="mode === 'manual'"
                        />
                        <p v-if="errors.rate" class="text-xs text-destructive">{{ errors.rate }}</p>
                        <p v-if="errors.mode" class="text-xs text-destructive">{{ errors.mode }}</p>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                        <Button type="submit" :disabled="processing">Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
