<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import type { Option } from '@/Components/Projects/ProjectForm.vue';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/**
 * Polish 026: the Admin renames the project types. One box per type, holding its current name;
 * a box left empty puts the built-in name (shown as the placeholder) back. Saved to
 * `PUT /admin/project-types`, and the Type list on the page refreshes from the answer.
 */
const props = defineProps<{
    open: boolean;
    types: Option[];
    defaults: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const names = ref<Record<string, string>>({});
const saving = ref(false);
const failure = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (open) {
            names.value = Object.fromEntries(props.types.map((type) => [type.value, type.label]));
            failure.value = null;
        }
    },
    { immediate: true },
);

function save(): void {
    if (saving.value) {
        return;
    }

    saving.value = true;
    failure.value = null;

    router.put(
        '/admin/project-types',
        { labels: names.value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                failure.value = Object.values(errors)[0] ?? 'The names could not be saved.';
            },
            onSuccess: () => emit('update:open', false),
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => !saving && emit('update:open', value)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Project type names</DialogTitle>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-3" @submit.prevent="save">
                <div v-for="type in types" :key="type.value" class="flex min-w-0 flex-col gap-1.5">
                    <Label :for="`project-type-name-${type.value}`" class="text-xs text-muted-foreground">
                        {{ defaults[type.value] ?? type.label }}
                    </Label>
                    <Input
                        :id="`project-type-name-${type.value}`"
                        v-model="names[type.value]"
                        maxlength="60"
                        :placeholder="defaults[type.value] ?? type.label"
                        :disabled="saving"
                    />
                </div>

                <p v-if="failure" class="text-xs text-destructive" role="alert">{{ failure }}</p>

                <DialogFooter>
                    <Button type="button" variant="ghost" :disabled="saving" @click="emit('update:open', false)">
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save names' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
