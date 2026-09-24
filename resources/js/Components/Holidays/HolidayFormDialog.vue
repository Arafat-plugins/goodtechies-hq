<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import type { Holiday } from '@/Components/Holidays/holidays';
import { formatHolidayDate, holidayRoutes } from '@/Components/Holidays/holidays';
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
 * Add a holiday, or correct one. One dialog for both, because they are one act.
 *
 * Correcting is the common case rather than the exception on this screen: `HolidaySeeder` fills
 * the year with the Bangladesh public-holiday list and roughly two thirds of those dates are
 * lunar estimates, so most of what an Admin does here is move a seeded date a day and rename
 * the block around it. A separate "edit" dialog would have been the same four fields twice.
 *
 * The date is a native `type="date"` input, which is the control that knows the viewer's
 * locale and gives a keyboard user a text field they can simply type into. It binds the
 * server's own `YYYY-MM-DD` string — the value format a date input uses regardless of what it
 * displays — so nothing here parses or formats a date.
 *
 * Errors are the server's, keyed by field: the uniqueness rule is `(date, name)` and lives in
 * the Form Request beside the index that enforces it, so "that holiday is already on the
 * calendar for this date" is a sentence the server writes and this only prints.
 */
const props = defineProps<{
    open: boolean;
    /** Null to add; a holiday to correct. */
    holiday: Holiday | null;
    /** The year the list is showing, so a new holiday defaults into it rather than into today. */
    year: number;
    /** Today, `YYYY-MM-DD`, from the server — used only as the default date in the current year. */
    today: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const dateId = useId();
const nameId = useId();

const form = useForm({
    date: '',
    name: '',
});

const isEdit = computed(() => props.holiday !== null);

const title = computed(() => (isEdit.value ? 'Edit holiday' : 'Add a holiday'));

/**
 * What the dialog says under its title.
 *
 * The edit copy names the consequence out loud, because it is not obvious and it is the whole
 * design: a holiday is derived at read time, so moving one changes what the attendance grid
 * says about a day that has already happened. An Admin correcting a seeded Eid estimate in May
 * is editing March, and should be told so before they save rather than after.
 */
const description = computed(() =>
    isEdit.value
        ? 'Moving or renaming a holiday changes what every employee’s attendance reads for that day, including days already past. The change is recorded in the audit log.'
        : 'A holiday is a day nobody is expected in. Nobody is marked absent on it, and every employee’s attendance reads Holiday — except where it is already their day off.',
);

/** The date a new holiday starts on: today when the list is on this year, else 1 January. */
const defaultDate = computed(() =>
    props.today.startsWith(`${props.year}-`) ? props.today : `${props.year}-01-01`,
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.holiday) {
        form.put(holidayRoutes.update(props.holiday.id), options);

        return;
    }

    form.post(holidayRoutes.store(), options);
}

/**
 * Fill the form when the dialog opens, and clear the errors when it closes — so a cancelled
 * edit does not leave a half-filled form, or somebody else's validation message, behind.
 */
watch(
    () => [props.open, props.holiday?.id] as const,
    ([open]) => {
        form.clearErrors();

        if (!open) {
            return;
        }

        form.date = props.holiday?.date ?? defaultDate.value;
        form.name = props.holiday?.name ?? '';
    },
    { immediate: true },
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="dateId">Date</Label>
                    <Input :id="dateId" v-model="form.date" type="date" required />
                    <p v-if="form.date" class="text-xs text-muted-foreground">
                        {{ formatHolidayDate(form.date) }}
                    </p>
                    <p v-if="form.errors.date" class="text-xs text-destructive">
                        {{ form.errors.date }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label :for="nameId">Name</Label>
                    <Input
                        :id="nameId"
                        v-model="form.name"
                        type="text"
                        maxlength="120"
                        required
                        placeholder="Victory Day"
                    />
                    <p class="text-xs text-muted-foreground">
                        What the day is called. Two holidays may share a date — write each one as its own row.
                    </p>
                    <p v-if="form.errors.name" class="text-xs text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ isEdit ? 'Save holiday' : 'Add holiday' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
