<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import SettingField from '@/Components/Settings/SettingField.vue';
import type { SettingField as Field, SettingValue } from '@/Components/Settings/settings';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * One group of settings, and the one Save that writes them.
 *
 * ## Each section saves only its own keys
 *
 * `PUT /admin/settings` takes a partial payload — every rule in `UpdateSettingsRequest` is
 * `sometimes` — so this card sends the four Timer keys and says nothing about the two General
 * ones. Three reasons, in order of how much they matter:
 *
 *   1. **The audit log stays readable.** One `configuration.changed` row per key that actually
 *      moved, and a key whose value did not change writes nothing at all
 *      (`SettingsService::set()` returns early). A single page-wide Save would still produce
 *      exactly the right rows, but it would invite the opposite habit — re-sending every key on
 *      every save and letting the service sort it out.
 *   2. **An error is attributable.** A refused timezone leaves the Timer group saved and the
 *      General group showing the sentence about what was wrong, instead of one banner over ten
 *      fields.
 *   3. **The button means something.** It is disabled until this group is dirty, so "Save" is
 *      never an act with no effect.
 *
 * A misspelled key cannot quietly do nothing: the Form Request fails an input key it does not
 * recognise by name, so "saved" always means something was written or was already that value.
 */

const props = defineProps<{
    title: string;
    fields: Field[];
    /** The stored values, straight off the page props. */
    values: Record<string, SettingValue>;
}>();

function initial(): Record<string, SettingValue> {
    const state: Record<string, SettingValue> = {};

    for (const field of props.fields) {
        state[field.key] = props.values[field.key] ?? (field.type === 'boolean' ? false : '');
    }

    return state;
}

const form = useForm<Record<string, SettingValue>>(initial());

/**
 * Follow the record after a save, and after anybody else's.
 *
 * The values come back with the redirect, so the boxes show what is STORED rather than what was
 * last typed — and a change somebody abandoned does not sit in the field looking pending. Same
 * contract `EmployeeRoleCard` keeps for its two selects.
 */
watch(
    () => props.values,
    () => {
        const fresh = initial();

        for (const field of props.fields) {
            form[field.key] = fresh[field.key];
        }

        form.defaults(fresh);
        form.clearErrors();
    },
    { deep: true },
);

const errorFor = computed(() => (key: string) => (form.errors as Record<string, string | undefined>)[key]);

function save(): void {
    form.put('/admin/settings', { preserveScroll: true });
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">{{ title }}</CardTitle>
        </CardHeader>

        <CardContent>
            <form class="flex min-w-0 flex-col gap-6" @submit.prevent="save">
                <SettingField
                    v-for="field in fields"
                    :key="field.key"
                    v-model="form[field.key]"
                    :field="field"
                    :error="errorFor(field.key)"
                    :disabled="form.processing"
                />

                <div class="flex min-w-0 items-center gap-3">
                    <Button type="submit" variant="outline" size="sm" :disabled="!form.isDirty || form.processing">
                        {{ form.processing ? 'Saving…' : 'Save' }}
                    </Button>
                    <p v-if="form.isDirty && !form.processing" class="text-xs text-muted-foreground">
                        Not saved yet.
                    </p>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
