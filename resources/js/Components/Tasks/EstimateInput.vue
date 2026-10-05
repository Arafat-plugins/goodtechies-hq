<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/Components/ui/input';

/**
 * A task estimate as hours + minutes (client doc 2026-10-05, item 4). The model is still the
 * server's one number, total minutes (`estimated_minutes`), or `null` for "no estimate", so
 * nothing on the server or in the reports changes. Both boxes empty means no estimate.
 *
 * The old single `<Input type="number" v-model>` handed the forms a NUMBER once somebody typed,
 * and both forms called `.trim()` on it — the save threw before it was sent and the button sat
 * on "Saving…" for good. This component only ever emits a number or null.
 */
const props = defineProps<{
    id: string;
    disabled?: boolean;
    describedBy?: string;
}>();

const model = defineModel<number | null>({ required: true });

const hours = computed(() => (model.value === null ? '' : String(Math.floor(model.value / 60))));
const minutes = computed(() => (model.value === null ? '' : String(model.value % 60)));

function whole(value: unknown): number | null {
    const text = String(value ?? '').trim();

    if (text === '') {
        return null;
    }

    const number = Math.floor(Number(text));

    return Number.isFinite(number) && number >= 0 ? number : 0;
}

function update(part: 'hours' | 'minutes', value: unknown): void {
    const h = part === 'hours' ? whole(value) : whole(hours.value);
    const m = part === 'minutes' ? whole(value) : whole(minutes.value);

    model.value = h === null && m === null ? null : (h ?? 0) * 60 + (m ?? 0);
}
</script>

<template>
    <div class="flex min-w-0 items-center gap-2" :aria-describedby="props.describedBy">
        <div class="relative min-w-0 flex-1">
            <Input
                :id="props.id"
                :model-value="hours"
                type="number"
                min="0"
                inputmode="numeric"
                placeholder="0"
                class="pr-10"
                aria-label="Estimate, hours"
                :disabled="props.disabled"
                @update:model-value="update('hours', $event)"
            />
            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted-foreground">h</span>
        </div>
        <div class="relative min-w-0 flex-1">
            <Input
                :id="`${props.id}-minutes`"
                :model-value="minutes"
                type="number"
                min="0"
                max="59"
                step="5"
                inputmode="numeric"
                placeholder="0"
                class="pr-10"
                aria-label="Estimate, minutes"
                :disabled="props.disabled"
                @update:model-value="update('minutes', $event)"
            />
            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted-foreground">m</span>
        </div>
    </div>
</template>
