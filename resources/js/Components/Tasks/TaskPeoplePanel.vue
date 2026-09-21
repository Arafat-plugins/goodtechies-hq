<script setup lang="ts">
import { ArrowLeftRight, Star, UserPlus } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import type { TaskNamedRef } from '@/Components/Tasks/TaskList.vue';
import type { TaskDetail, TaskSurface } from '@/Components/Tasks/taskDetail';
import { focusField, initials, mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/Components/ui/radio-group';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Who the task is assigned to, which of them is primary, and handing it over.
 *
 * Two different abilities, deliberately not merged. **Assigning** is a management decision
 * (`TaskPolicy::assign`, Admin/Manager, and the endpoint only exists on the Admin surface).
 * **Handing over** moves "primary" between the people already on the task, and the current
 * primary may do it themselves — that is the case the rule exists for, somebody going on
 * leave, and needing a manager for it would leave the task uncompletable until Monday.
 *
 * The primary matters because completion is checked against THEIR work summary. That is
 * said here rather than discovered at the Approve button.
 */

const MAX_ASSIGNEES = 2;

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
    /** The assignable employees. Only the Admin detail page is sent this list. */
    employees?: TaskNamedRef[];
}>();

const emit = defineEmits<{ settled: [] }>();

const routes = computed(() => taskRoutes(props.surface, props.task.id));

/** Assignees are written on the Admin surface only — there is no employee endpoint. */
const mayAssign = computed(
    () => props.surface === 'admin' && !props.task.is_archived && (props.employees?.length ?? 0) > 0,
);

/** A hand-off needs somebody else on the task to hand it to. */
const handOffTargets = computed(() => props.task.assignees.filter((assignee) => !assignee.is_primary));
const mayHandOff = computed(() => !props.task.is_archived && handOffTargets.value.length > 0);

/* ------------------------------------------------------------- assigning */

const assignOpen = ref(false);
const chosen = ref<number[]>([]);
const primary = ref<string>('');
const saving = ref(false);
const assignError = ref<string | null>(null);

function openAssign(): void {
    chosen.value = props.task.assignees.map((assignee) => assignee.id);
    primary.value = props.task.primary_assignee ? String(props.task.primary_assignee.id) : '';
    assignError.value = null;
    assignOpen.value = true;
}

function toggle(id: number, on: boolean): void {
    if (on) {
        if (!chosen.value.includes(id)) {
            chosen.value = [...chosen.value, id];
        }
    } else {
        chosen.value = chosen.value.filter((value) => value !== id);
    }

    if (primary.value !== '' && !chosen.value.includes(Number(primary.value))) {
        primary.value = '';
    }
}

/** The picked people, in the order the option list shows them. */
const chosenPeople = computed(() => (props.employees ?? []).filter((employee) => chosen.value.includes(employee.id)));

function saveAssignees(): void {
    if (saving.value) {
        return;
    }

    if (chosen.value.length > MAX_ASSIGNEES) {
        assignError.value = `A task takes at most ${MAX_ASSIGNEES} assignees.`;

        return;
    }

    if (chosen.value.length > 0 && primary.value === '') {
        assignError.value = 'Pick which of them is primary — completion is checked against their summary.';

        return;
    }

    saving.value = true;

    mutateTask(
        'put',
        routes.value.assignees,
        {
            assignee_ids: chosen.value,
            primary_assignee_id: primary.value === '' ? null : Number(primary.value),
        },
        {
            onAccepted: () => {
                assignOpen.value = false;
            },
            onSettled: () => emit('settled'),
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

/* ------------------------------------------------------------ handing over */

const handOffOpen = ref(false);
const target = ref<string>('');
const reason = ref('');
const handing = ref(false);
const handOffError = ref<string | null>(null);
const reasonField = ref<{ $el?: unknown } | null>(null);

function openHandOff(): void {
    target.value = handOffTargets.value.length === 1 ? String(handOffTargets.value[0]!.id) : '';
    reason.value = '';
    handOffError.value = null;
    handOffOpen.value = true;
}

watch(handOffOpen, (open) => {
    if (open) {
        void nextTick(() => focusField(reasonField.value));
    }
});

function handOff(): void {
    if (handing.value) {
        return;
    }

    if (target.value === '') {
        handOffError.value = 'Pick who is taking it on.';

        return;
    }

    if (reason.value.trim() === '') {
        handOffError.value = 'A hand-off has to say why — it is the one waiver of the completion rule.';
        focusField(reasonField.value);

        return;
    }

    handing.value = true;

    mutateTask(
        'post',
        routes.value.handoff,
        { employee_id: Number(target.value), reason: reason.value.trim() },
        {
            onAccepted: () => {
                handOffOpen.value = false;
            },
            onSettled: () => emit('settled'),
            onFinish: () => {
                handing.value = false;
            },
        },
    );
}

defineExpose({ openHandOff });
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Assignees</CardTitle>
            <CardDescription>
                The primary owns completion — the work summary the task is finished on has to be theirs.
            </CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-3">
            <p v-if="task.assignees.length === 0" class="text-sm text-muted-foreground">
                Nobody is assigned to this task.
            </p>

            <ul v-else class="flex min-w-0 flex-col gap-2">
                <li v-for="assignee in task.assignees" :key="assignee.id" class="flex min-w-0 items-center gap-2">
                    <Avatar class="size-7 shrink-0">
                        <AvatarFallback class="text-xs">{{ initials(assignee.name) }}</AvatarFallback>
                    </Avatar>
                    <span class="min-w-0 flex-1 text-sm break-words">{{ assignee.name ?? '—' }}</span>
                    <!-- The word, not just the star: colour and shape are never the only carrier. -->
                    <span
                        v-if="assignee.is_primary"
                        class="inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium text-muted-foreground"
                    >
                        <Star class="size-3" aria-hidden="true" />
                        Primary
                    </span>
                </li>
            </ul>

            <div v-if="mayAssign || mayHandOff" class="flex flex-wrap items-center gap-2">
                <Button v-if="mayAssign" type="button" size="sm" variant="outline" @click="openAssign">
                    <UserPlus aria-hidden="true" />
                    Change assignees
                </Button>
                <Button v-if="mayHandOff" type="button" size="sm" variant="outline" @click="openHandOff">
                    <ArrowLeftRight aria-hidden="true" />
                    Hand over
                </Button>
            </div>
        </CardContent>
    </Card>

    <Dialog v-model:open="assignOpen">
        <DialogContent class="max-w-lg">
            <form novalidate @submit.prevent="saveAssignees">
                <DialogHeader>
                    <DialogTitle>Who is on this task?</DialogTitle>
                    <DialogDescription>
                        At most {{ MAX_ASSIGNEES }} people, and one of them is primary.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex min-w-0 flex-col gap-4 py-4">
                    <ul class="grid max-h-56 gap-2 overflow-y-auto sm:grid-cols-2">
                        <li v-for="employee in employees ?? []" :key="employee.id" class="flex items-center gap-2">
                            <Checkbox
                                :id="`task-assignee-${employee.id}`"
                                :model-value="chosen.includes(employee.id)"
                                :disabled="saving || (chosen.length >= MAX_ASSIGNEES && !chosen.includes(employee.id))"
                                @update:model-value="(value) => toggle(employee.id, value === true)"
                            />
                            <Label :for="`task-assignee-${employee.id}`" class="min-w-0 font-normal">
                                {{ employee.name }}
                            </Label>
                        </li>
                    </ul>

                    <div v-if="chosenPeople.length > 0" class="flex min-w-0 flex-col gap-2">
                        <p class="text-xs font-medium text-muted-foreground">
                            Primary assignee <span class="text-destructive" aria-hidden="true">*</span>
                        </p>
                        <RadioGroup v-model="primary" class="gap-2">
                            <div
                                v-for="person in chosenPeople"
                                :key="person.id"
                                class="flex items-center gap-2"
                            >
                                <RadioGroupItem
                                    :id="`task-primary-${person.id}`"
                                    :value="String(person.id)"
                                    :disabled="saving"
                                />
                                <Label :for="`task-primary-${person.id}`" class="font-normal">
                                    {{ person.name }}
                                </Label>
                            </div>
                        </RadioGroup>
                    </div>

                    <p v-if="assignError" class="text-xs text-destructive">{{ assignError }}</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="saving" @click="assignOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="saving">
                        {{ saving ? 'Saving…' : 'Save assignees' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="handOffOpen">
        <DialogContent class="max-w-lg">
            <form novalidate @submit.prevent="handOff">
                <DialogHeader>
                    <DialogTitle>Hand this task over</DialogTitle>
                    <DialogDescription>
                        They become the primary assignee, so completion is then checked against their
                        work summary. It is audit-logged.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex min-w-0 flex-col gap-4 py-4">
                    <div v-if="handOffTargets.length > 1" class="flex min-w-0 flex-col gap-2">
                        <p class="text-xs font-medium text-muted-foreground">Taking it on</p>
                        <RadioGroup v-model="target" class="gap-2">
                            <div v-for="person in handOffTargets" :key="person.id" class="flex items-center gap-2">
                                <RadioGroupItem
                                    :id="`task-handoff-${person.id}`"
                                    :value="String(person.id)"
                                    :disabled="handing"
                                />
                                <Label :for="`task-handoff-${person.id}`" class="font-normal">
                                    {{ person.name }}
                                </Label>
                            </div>
                        </RadioGroup>
                    </div>
                    <p v-else-if="handOffTargets.length === 1" class="text-sm">
                        <span class="font-medium">{{ handOffTargets[0]!.name }}</span> takes it on.
                    </p>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="task-handoff-reason">
                            Reason <span class="text-destructive" aria-hidden="true">*</span>
                        </Label>
                        <Textarea
                            id="task-handoff-reason"
                            ref="reasonField"
                            v-model="reason"
                            rows="4"
                            :disabled="handing"
                            :aria-invalid="handOffError ? true : undefined"
                        />
                    </div>

                    <p v-if="handOffError" class="text-xs text-destructive">{{ handOffError }}</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="handing" @click="handOffOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="handing">
                        {{ handing ? 'Handing over…' : 'Hand over' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
