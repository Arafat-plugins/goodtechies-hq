<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/Components/ui/native-select';

export interface ServiceBoxSettings {
    boxes: { name: string; types: string[] }[];
    types: { value: string; label: string }[];
}

/**
 * Polish 033: the Admin's own service boxes on Admin → Projects by client. Name the boxes (add,
 * rename, remove), then say which box each project type goes in. A type in no box lands in the
 * automatic "Other" box. Saved to `PUT /admin/project-service-boxes`.
 */
const props = defineProps<{
    open: boolean;
    settings: ServiceBoxSettings;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const MAX_BOXES = 12;

let nextId = 0;
const boxes = ref<{ id: number; name: string }[]>([]);
/** Project type → the local id of its box, or '' for "Other". */
const placement = ref<Record<string, string>>({});
const saving = ref(false);
const failure = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        failure.value = null;
        boxes.value = props.settings.boxes.map((box) => ({ id: nextId++, name: box.name }));
        placement.value = Object.fromEntries(
            props.settings.types.map((type) => {
                const at = props.settings.boxes.findIndex((box) => box.types.includes(type.value));

                return [type.value, at >= 0 ? String(boxes.value[at].id) : ''];
            }),
        );
    },
    { immediate: true },
);

function addBox(): void {
    if (boxes.value.length < MAX_BOXES) {
        boxes.value.push({ id: nextId++, name: '' });
    }
}

function removeBox(id: number): void {
    boxes.value = boxes.value.filter((box) => box.id !== id);

    for (const [type, boxId] of Object.entries(placement.value)) {
        if (boxId === String(id)) {
            placement.value[type] = '';
        }
    }
}

function save(): void {
    if (saving.value) {
        return;
    }

    saving.value = true;
    failure.value = null;

    const payload = boxes.value.map((box) => ({
        name: box.name.trim(),
        types: Object.entries(placement.value)
            .filter(([, boxId]) => boxId === String(box.id))
            .map(([type]) => type),
    }));

    router.put(
        '/admin/project-service-boxes',
        { boxes: payload },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                failure.value = Object.values(errors)[0] ?? 'The boxes could not be saved.';
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
        <DialogContent class="max-h-[calc(100svh-2rem)] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Service boxes</DialogTitle>
                <DialogDescription>A client's projects are sorted into these boxes by their project type.</DialogDescription>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-5" @submit.prevent="save">
                <fieldset class="flex min-w-0 flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">Boxes</legend>
                    <div v-for="(box, index) in boxes" :key="box.id" class="flex min-w-0 items-center gap-2">
                        <Label :for="`service-box-${box.id}`" class="sr-only">Box {{ index + 1 }} name</Label>
                        <Input
                            :id="`service-box-${box.id}`"
                            v-model="box.name"
                            maxlength="40"
                            placeholder="Box name"
                            :disabled="saving"
                            class="min-w-0 flex-1"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :disabled="saving || boxes.length === 1"
                            :aria-label="`Remove ${box.name || 'this box'}`"
                            @click="removeBox(box.id)"
                        >
                            <Trash2 aria-hidden="true" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="self-start"
                        :disabled="saving || boxes.length >= MAX_BOXES"
                        @click="addBox"
                    >
                        <Plus aria-hidden="true" />
                        Add box
                    </Button>
                </fieldset>

                <fieldset class="flex min-w-0 flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">Which box each project type goes in</legend>
                    <div v-for="type in settings.types" :key="type.value" class="grid min-w-0 grid-cols-2 items-center gap-2">
                        <Label :for="`service-type-${type.value}`" class="min-w-0 truncate font-normal">{{ type.label }}</Label>
                        <NativeSelect :id="`service-type-${type.value}`" v-model="placement[type.value]" :disabled="saving">
                            <NativeSelectOption v-for="box in boxes" :key="box.id" :value="String(box.id)">
                                {{ box.name.trim() || 'Untitled box' }}
                            </NativeSelectOption>
                            <NativeSelectOption value="">Other (no box)</NativeSelectOption>
                        </NativeSelect>
                    </div>
                </fieldset>

                <p v-if="failure" class="text-xs text-destructive" role="alert">{{ failure }}</p>

                <DialogFooter>
                    <Button type="button" variant="ghost" :disabled="saving" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save boxes' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
