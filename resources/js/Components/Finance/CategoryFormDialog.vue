<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import type { FinanceCategory, FinanceCategoryKind } from '@/Components/Finance/finance';
import { financeRoutes } from '@/Components/Finance/finance';
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

/**
 * Add a category, or rename one. One dialog for both, because they are one field.
 *
 * **There is no side-of-the-ledger control on the rename half**, and that is the interesting
 * part. `kind` is required when adding and is not sent when renaming:
 * `UpdateFinanceCategoryRequest` does not accept it, `FinanceService::updateCategory()` refuses
 * one that differs, and `ON UPDATE RESTRICT` on the composite foreign key refuses it underneath
 * for any category anything is filed under. Moving a category to the other side would move
 * every record under it without touching a single finance row, and every rollup already
 * reported on would silently change — so a category created on the wrong side is deleted and
 * made again, which is one click while nothing is filed under it.
 *
 * Errors are the server's, keyed by field: `UNIQUE (kind, name)` lives in the Form Request
 * beside the index that enforces it, so *"that category already exists on this side of the
 * ledger"* is a sentence the server writes and this only prints. It says *on this side*
 * deliberately — *Other* exists on both and they are different categories (decision 8-1).
 */
const props = defineProps<{
    open: boolean;
    /** The side a new category goes on. Ignored when renaming. */
    kind: FinanceCategoryKind;
    /** Null to add; a category to rename. */
    category: FinanceCategory | null;
}>();

const emit = defineEmits<{ close: [] }>();

const nameId = useId();

const form = useForm({ kind: 'income' as FinanceCategoryKind, name: '' });

const isRename = computed(() => props.category !== null);

const sideWord = computed(() => ((props.category?.kind ?? props.kind) === 'income' ? 'income' : 'expense'));

const title = computed(() => (isRename.value ? 'Rename category' : `Add an ${sideWord.value} category`));

const description = computed(() =>
    isRename.value
        ? `Every record already filed under this category moves with the name — including the ones in months that have already been reported. The change is recorded in the audit log.`
        : `A name money in the ${sideWord.value} ledger can be filed under. It joins the end of the ${sideWord.value} picker and gets its own line in the monthly rollup.`,
);

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (props.category) {
        form
            .transform((data) => ({ name: data.name }))
            .put(financeRoutes.categories.update(props.category.id), options);

        return;
    }

    form.transform((data) => ({ kind: data.kind, name: data.name })).post(financeRoutes.categories.store(), options);
}

watch(
    () => [props.open, props.category?.id] as const,
    ([open]) => {
        form.clearErrors();

        if (!open) {
            return;
        }

        form.kind = props.category?.kind ?? props.kind;
        form.name = props.category?.name ?? '';
    },
    { immediate: true },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value: boolean) => { if (!value) { emit('close'); } }">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="nameId">Name</Label>
                    <Input
                        :id="nameId"
                        v-model="form.name"
                        type="text"
                        maxlength="60"
                        required
                        :placeholder="sideWord === 'income' ? 'Consulting' : 'Travel'"
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    <p v-if="form.errors.kind" class="text-xs text-destructive">{{ form.errors.kind }}</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('close')">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ isRename ? 'Save name' : 'Add category' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
