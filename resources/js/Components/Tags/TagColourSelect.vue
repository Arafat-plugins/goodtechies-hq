<script setup lang="ts">
import { computed } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { TagColourOption } from '@/Components/Tags/tagManager';
import { Select, SelectContent, SelectItem, SelectTrigger } from '@/Components/ui/select';

/**
 * The tag colour picker: one `Select` whose options are the eight status tones the server
 * sent.
 *
 * Its own file because the panel picks a colour twice — once when a tag is created and once
 * when one is recoloured — and the same fifteen lines of markup written twice is one copy
 * that gets fixed and one that does not (DESIGN.md §5.8). It adds nothing to `Select`; it
 * only fills it.
 *
 * Each option is a `StatusBadge` carrying the colour's NAME as its label, which is both halves
 * of one rule: the swatch is drawn by the component that owns the status-to-colour mapping, so
 * there is no second copy of it here, and the word "Amber" is beside the amber, so nothing on
 * this control is carried by colour alone (DESIGN.md §5.6).
 */

const props = defineProps<{
    modelValue: string;
    options: TagColourOption[];
    /** The `for` target of the caller's `<Label>`. */
    id: string;
    disabled?: boolean;
    /** True when the server refused this field, so the trigger carries `aria-invalid`. */
    invalid?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const chosen = computed(() => props.options.find((option) => option.value === props.modelValue) ?? null);
</script>

<template>
    <Select
        :model-value="modelValue"
        :disabled="disabled"
        @update:model-value="(value) => emit('update:modelValue', String(value ?? ''))"
    >
        <SelectTrigger :id="id" class="w-full" :aria-invalid="invalid ? true : undefined">
            <StatusBadge v-if="chosen" :status="chosen.value" :label="chosen.label" />
            <span v-else class="text-muted-foreground">Choose a colour…</span>
        </SelectTrigger>
        <SelectContent>
            <SelectItem v-for="option in options" :key="option.value" :value="option.value">
                <StatusBadge :status="option.value" :label="option.label" />
            </SelectItem>
        </SelectContent>
    </Select>
</template>
