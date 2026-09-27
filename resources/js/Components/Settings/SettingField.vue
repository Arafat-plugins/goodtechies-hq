<script setup lang="ts">
import { computed } from 'vue';
import type { SettingField, SettingValue } from '@/Components/Settings/settings';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';

/**
 * One setting, drawn as whatever its `type` says it is.
 *
 * Four types and four controls: a switch for `boolean`, a bounded number box for `integer`, and
 * a text box for `timezone` and `currency`. The bounds on the number box are the server's own —
 * `UpdateSettingsRequest::FIELDS` supplies them and `rules()` there is generated from the same
 * row — so the arrows stop where validation would have refused.
 *
 * ## Why `timezone` is a text box and not a select
 *
 * PHP knows about 420 identifiers. A select of 420 options is worse to use than a box with the
 * shape written under it, and the closed list somebody would reach for instead — a dozen zones
 * an agency might plausibly want — is a decision nobody has recorded and one that would refuse a
 * legitimate value. The server validates against PHP's own list, so a typo is caught with a
 * sentence rather than accepted and then thrown on the next scheduled run.
 *
 * ## The switch does not carry the state by itself
 *
 * DESIGN.md §5.6: colour is never the only carrier. Each boolean field ships the words for both
 * positions (`on` / `off` in `FIELDS`) and the one that is true is printed beside the control.
 */

const props = defineProps<{
    field: SettingField;
    modelValue: SettingValue;
    error?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [SettingValue] }>();

const id = computed(() => `setting-${props.field.key}`);
const helpId = computed(() => `${id.value}-help`);
const errorId = computed(() => `${id.value}-error`);

const describedBy = computed(() => (props.error ? `${errorId.value} ${helpId.value}` : helpId.value));

/** `boolean`. Anything that is not literally true reads as off, including a missing value. */
const switched = computed({
    get: (): boolean => props.modelValue === true,
    set: (value: boolean) => emit('update:modelValue', value),
});

/**
 * `integer`. An empty box stays empty rather than becoming 0 — 0 is a real answer for three of
 * these keys, so silently substituting it would save a value nobody typed. The server answers
 * "required" for the empty one.
 */
const number = computed({
    get: (): string | number => (typeof props.modelValue === 'number' ? props.modelValue : ''),
    set: (value: string | number) => {
        const text = String(value).trim();

        emit('update:modelValue', text === '' ? '' : Number(text));
    },
});

/** `timezone` and `currency`. */
const text = computed({
    get: (): string | number => (typeof props.modelValue === 'string' ? props.modelValue : ''),
    set: (value: string | number) => emit('update:modelValue', String(value)),
});

const stateWords = computed(() =>
    switched.value ? (props.field.on ?? 'On') : (props.field.off ?? 'Off'),
);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2">
        <Label :for="id">{{ field.label }}</Label>

        <div v-if="field.type === 'boolean'" class="flex min-w-0 items-center gap-3">
            <Switch :id="id" v-model="switched" :disabled="disabled" :aria-describedby="describedBy" />
            <span class="min-w-0 text-sm">{{ stateWords }}</span>
        </div>

        <div v-else-if="field.type === 'integer'" class="flex min-w-0 items-center gap-2">
            <Input
                :id="id"
                v-model="number"
                type="number"
                inputmode="numeric"
                step="1"
                :min="field.min"
                :max="field.max"
                :disabled="disabled"
                :aria-describedby="describedBy"
                :aria-invalid="error ? true : undefined"
                class="w-24 tabular-nums"
            />
            <span v-if="field.unit" class="shrink-0 text-sm text-muted-foreground">{{ field.unit }}</span>
        </div>

        <Input
            v-else
            :id="id"
            v-model="text"
            type="text"
            :placeholder="field.placeholder"
            :maxlength="field.type === 'currency' ? 3 : undefined"
            :autocapitalize="field.type === 'currency' ? 'characters' : 'off'"
            autocomplete="off"
            spellcheck="false"
            :disabled="disabled"
            :aria-describedby="describedBy"
            :aria-invalid="error ? true : undefined"
            :class="field.type === 'currency' ? 'w-24 uppercase' : 'w-full sm:max-w-xs'"
        />

        <p v-if="error" :id="errorId" class="text-xs text-destructive">{{ error }}</p>
        <p :id="helpId" class="text-xs text-muted-foreground">{{ field.help }}</p>
    </div>
</template>
