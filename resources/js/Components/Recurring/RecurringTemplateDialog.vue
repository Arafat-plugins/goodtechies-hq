<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarClock, TriangleAlert } from '@lucide/vue';
import { computed, nextTick, ref, useId, watch } from 'vue';
import RecurrenceFields from '@/Components/Recurring/RecurrenceFields.vue';
import type {
    RecurrenceDraft,
    RecurrencePreview,
    RecurringRoutes,
    RecurringTemplate,
} from '@/Components/Recurring/recurring';
import {
    draftFromTemplate,
    emptyRecurrenceDraft,
    formatDate,
    loadPreview,
    ruleFields,
    todayIso,
} from '@/Components/Recurring/recurring';
import { focusField } from '@/Components/Tasks/taskDetail';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Setting a retainer up, and editing it afterwards.
 *
 * One dialog for both, because the two take the same fields — there is nothing an edit can set
 * that a new template could not, which is also why `StoreRecurringTaskRequest` and
 * `UpdateRecurringTaskRequest` are the same class twice. `template` being null is the only
 * difference, and it decides the verb, the endpoint and the heading.
 *
 * ## The next-run preview is asked for, never computed
 *
 * Under the controls sits the answer to "so when does this actually fire?", fetched from
 * `GET …/recurring/preview` every time the rule changes. `RecurrenceRule` answers it — the same
 * object the 00:05 run uses — so the sentence somebody reads before saving is the sentence the
 * engine will act on. A preview worked out in Vue would agree for a month and then quietly stop.
 *
 * The fetch is debounced and versioned: a slow answer for a rule that has since changed is
 * dropped rather than painted over the current one. A 422 is silence, not an error panel — a
 * half-typed custom rule is the normal state of a form somebody is filling in, and the field
 * errors arrive on save.
 *
 * ## The checklist is one field
 *
 * One item per line in a textarea, which is what the column is: `checklist_template` is the
 * template's own text, edited as a whole and never joined to. The engine replays each line
 * through `TaskService::addChecklistItem()`, so a generated item is indistinguishable from a
 * hand-made one.
 */

const props = defineProps<{
    open: boolean;
    routes: RecurringRoutes;
    /** Null for a new template; the row being edited otherwise. */
    template: RecurringTemplate | null;
    /** The project's assignable employees — `Pages/Admin/Projects/Show.vue` already has them. */
    employees: { id: number; name: string | null }[];
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    /** The server accepted the write; the panel re-reads its list. */
    saved: [];
}>();

/**
 * "Nobody" needs a value reka's `Select` will accept: it reserves the empty string for "nothing
 * is selected" and refuses an item that uses it. It becomes `default_assignee_id: null`.
 */
const NOBODY = 'nobody';

const uid = useId();
const ids = {
    title: `${uid}-title`,
    checklist: `${uid}-checklist`,
    assignee: `${uid}-assignee`,
    active: `${uid}-active`,
};

const editing = computed(() => props.template !== null);

const form = useForm<{
    title_template: string;
    checklist: string;
    default_assignee_id: string;
    active: boolean;
}>({
    title_template: '',
    checklist: '',
    default_assignee_id: NOBODY,
    active: true,
});

const draft = ref<RecurrenceDraft>(emptyRecurrenceDraft(todayIso()));
const titleField = ref<{ $el?: unknown } | null>(null);

/** Fill the form from whatever the dialog was opened on. */
function reset(): void {
    const template = props.template;

    form.clearErrors();
    form.title_template = template?.title_template ?? '';
    form.checklist = (template?.checklist_template ?? []).join('\n');
    form.default_assignee_id = template?.default_assignee ? String(template.default_assignee.id) : NOBODY;
    form.active = template?.active ?? true;

    draft.value = template === null
        ? emptyRecurrenceDraft(todayIso())
        : draftFromTemplate(template, todayIso());
}

watch(
    () => [props.open, props.template?.id] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        reset();
        void refreshPreview();
        void nextTick(() => focusField(titleField.value));
    },
    { immediate: true },
);

/* ------------------------------------------------------------------- preview */

const preview = ref<RecurrencePreview | null>(null);
/** Bumped per fetch, so a slow answer for a rule that has moved on cannot land in it. */
const previewToken = ref(0);
let previewTimer: ReturnType<typeof setTimeout> | null = null;

async function refreshPreview(): Promise<void> {
    const mine = ++previewToken.value;
    const result = await loadPreview(props.routes, draft.value);

    if (mine !== previewToken.value) {
        return;
    }

    // A refusal or a half-typed rule leaves the last good preview in place rather than
    // flashing an error at somebody who is mid-keystroke.
    preview.value = result.ok ? result.payload.preview : preview.value;
}

watch(
    draft,
    () => {
        if (previewTimer !== null) {
            clearTimeout(previewTimer);
        }

        previewTimer = setTimeout(() => void refreshPreview(), 250);
    },
    { deep: true },
);

/* -------------------------------------------------------------------- saving */

function submit(): void {
    if (form.processing) {
        return;
    }

    const payload = form.transform((data) => ({
        title_template: data.title_template.trim(),
        // Blank lines are dropped here and again on the server: a trailing newline is not a
        // checklist item, and neither side should be the only one that knows that.
        checklist_template: data.checklist
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== ''),
        default_assignee_id: data.default_assignee_id === NOBODY ? null : Number(data.default_assignee_id),
        active: data.active,
        ...ruleFields(draft.value),
    }));

    const options = {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            emit('update:open', false);
        },
    };

    if (props.template === null) {
        payload.post(props.routes.store, options);
    } else {
        payload.put(props.routes.update(props.template.id), options);
    }
}

/**
 * The server's errors by key.
 *
 * `useForm` types its `errors` off the DATA keys, and the rule's fields are not among them —
 * they are assembled in `transform()` from the draft, which is what keeps one rule object in
 * one place instead of six loose refs. The server still names its refusals `day_of_month`,
 * `anchor` and so on, so they are read through this one widening rather than by giving the form
 * six fields it would not otherwise have.
 */
const errors = computed<Partial<Record<string, string>>>(
    () => form.errors as unknown as Partial<Record<string, string>>,
);

/** The rule fields the editor owns, so `RecurrenceFields` can put a refusal under its control. */
const ruleErrors = computed(() => ({
    frequency: errors.value.frequency,
    day_of_month: errors.value.day_of_month,
    weekday: errors.value.weekday,
    interval_days: errors.value.interval_days,
    anchor: errors.value.anchor,
    due_offset_days: errors.value.due_offset_days,
}));

/** The first checklist error, whichever line it is on — `checklist_template.3` and friends. */
const checklistError = computed(() =>
    Object.entries(errors.value).find(([key]) => key.startsWith('checklist_template'))?.[1],
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[90dvh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ editing ? 'Edit recurring task' : 'New recurring task' }}</DialogTitle>
                <DialogDescription>
                    A standing instruction. Every period it makes one task, on one person’s plate,
                    with this checklist on it.
                </DialogDescription>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="ids.title">Title of the generated task</Label>
                    <Input
                        :id="ids.title"
                        ref="titleField"
                        v-model="form.title_template"
                        :disabled="form.processing"
                        :aria-invalid="form.errors.title_template ? true : undefined"
                        placeholder="abc.com Monthly Maintenance — {period}"
                    />
                    <p class="text-xs text-muted-foreground">
                        <code class="rounded-sm bg-muted px-1 py-0.5">{period}</code>,
                        <code class="rounded-sm bg-muted px-1 py-0.5">{project}</code> and
                        <code class="rounded-sm bg-muted px-1 py-0.5">{date}</code> are filled in when
                        the task is made. Without one of them, every period produces the same title.
                    </p>
                    <p v-if="form.errors.title_template" class="text-xs text-destructive">
                        {{ form.errors.title_template }}
                    </p>
                </div>

                <RecurrenceFields v-model="draft" :errors="ruleErrors" :disabled="form.processing" />

                <!--
                    The preview. Every value in it came from RecurrenceRule on the server; this
                    panel formats and prints, and computes nothing.
                -->
                <div class="flex min-w-0 gap-3 rounded-lg border bg-muted/40 p-4">
                    <CalendarClock class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div v-if="preview" class="flex min-w-0 flex-col gap-1">
                        <p class="text-sm font-medium">
                            Next run {{ formatDate(preview.at) }}, for {{ preview.period_label ?? preview.period }}
                        </p>
                        <p class="text-sm break-words text-muted-foreground">{{ preview.summary }}</p>
                        <p class="text-xs text-muted-foreground">
                            That period runs {{ formatDate(preview.period_start) }} to
                            {{ formatDate(preview.period_end) }}; the task would be due
                            {{ formatDate(preview.due_date) }}.
                        </p>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        Finish the rule and the next run shows up here.
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="ids.checklist">Checklist, one item per line</Label>
                    <Textarea
                        :id="ids.checklist"
                        v-model="form.checklist"
                        rows="6"
                        :disabled="form.processing"
                        :aria-invalid="checklistError ? true : undefined"
                        placeholder="WordPress core updates&#10;Plugin updates&#10;Backup verification"
                    />
                    <p class="text-xs text-muted-foreground">
                        Copied onto every generated task, in this order.
                    </p>
                    <p v-if="checklistError" class="text-xs text-destructive">{{ checklistError }}</p>
                </div>

                <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="ids.assignee">Lands on</Label>
                        <Select v-model="form.default_assignee_id" :disabled="form.processing">
                            <SelectTrigger :id="ids.assignee" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NOBODY">Nobody — leave it unassigned</SelectItem>
                                <SelectItem
                                    v-for="employee in employees"
                                    :key="employee.id"
                                    :value="String(employee.id)"
                                >
                                    {{ employee.name ?? 'Unnamed' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.default_assignee_id" class="text-xs text-destructive">
                            {{ form.errors.default_assignee_id }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="ids.active">Switch</Label>
                        <div class="flex min-w-0 items-center gap-3">
                            <Switch :id="ids.active" v-model="form.active" :disabled="form.processing" />
                            <!--
                                The words carry the state, not the switch's colour: DESIGN.md
                                §5.6, and the same reason the list draws a labelled badge.
                            -->
                            <span class="text-sm">
                                {{ form.active ? 'On — it generates every period' : 'Off — it generates nothing' }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="template?.stop_reason"
                    class="flex min-w-0 gap-2 rounded-lg border border-status-waiting-border bg-status-waiting-bg p-3"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0 text-status-waiting-fg" aria-hidden="true" />
                    <p class="min-w-0 text-sm break-words text-status-waiting-fg">
                        It is switched on and still will not run: {{ template.stop_reason }}
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving…' : editing ? 'Save changes' : 'Create template' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
