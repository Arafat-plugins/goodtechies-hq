<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { TaskDetail, TaskSurface } from '@/Components/Tasks/taskDetail';
import { mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/**
 * The checklist: a title and a tick per line, which is all a checklist item is.
 *
 * Ticking posts one `is_done` through the item endpoint — there is no local optimistic state,
 * because who ticked it and when is written server-side and the row has to come back with it.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
}>();

const emit = defineEmits<{ settled: [] }>();

const routes = computed(() => taskRoutes(props.surface, props.task.id));
const editable = computed(() => props.task.permissions.can_update);

const done = computed(() => props.task.checklist.filter((item) => item.is_done).length);

const title = ref('');
const adding = ref(false);
const busy = ref<number | null>(null);

function add(): void {
    const value = title.value.trim();

    if (value === '' || adding.value) {
        return;
    }

    adding.value = true;

    mutateTask(
        'post',
        routes.value.checklist,
        { title: value },
        {
            onAccepted: () => {
                title.value = '';
            },
            onSettled: () => emit('settled'),
            onFinish: () => {
                adding.value = false;
            },
        },
    );
}

function toggle(id: number, isDone: boolean): void {
    busy.value = id;

    mutateTask(
        'put',
        routes.value.checklistItem(id),
        { is_done: isDone },
        {
            onSettled: () => emit('settled'),
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

function remove(id: number): void {
    busy.value = id;

    mutateTask('delete', routes.value.checklistItem(id), {}, {
        onSettled: () => emit('settled'),
        onFinish: () => {
            busy.value = null;
        },
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Checklist</CardTitle>
            <CardDescription>
                <span class="tabular-nums">{{ done }}</span> of
                <span class="tabular-nums">{{ task.checklist.length }}</span> done
            </CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-3">
            <p v-if="task.checklist.length === 0" class="text-sm text-muted-foreground">
                No checklist yet.
            </p>

            <ul v-else class="flex min-w-0 flex-col gap-2">
                <li v-for="item in task.checklist" :key="item.id" class="flex min-w-0 items-start gap-2">
                    <Checkbox
                        :id="`task-check-${item.id}`"
                        class="mt-0.5 shrink-0"
                        :model-value="item.is_done"
                        :disabled="!editable || busy === item.id"
                        @update:model-value="(value) => toggle(item.id, value === true)"
                    />
                    <Label
                        :for="`task-check-${item.id}`"
                        class="min-w-0 flex-1 flex-col items-start gap-0.5 font-normal"
                    >
                        <span :class="item.is_done ? 'text-muted-foreground line-through' : ''">
                            {{ item.title }}
                        </span>
                        <!-- Not colour alone: the tick, the strike and this line all say it. -->
                        <span v-if="item.is_done && item.completed_by" class="text-xs text-muted-foreground">
                            Done by {{ item.completed_by.name }}
                        </span>
                    </Label>
                    <Button
                        v-if="editable"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0"
                        :disabled="busy === item.id"
                        :aria-label="`Remove ${item.title}`"
                        @click="remove(item.id)"
                    >
                        <X aria-hidden="true" />
                    </Button>
                </li>
            </ul>

            <form v-if="editable" class="flex min-w-0 flex-wrap items-end gap-2" novalidate @submit.prevent="add">
                <div class="flex min-w-0 flex-1 basis-48 flex-col gap-2">
                    <Label :for="`task-check-new-${task.id}`" class="text-xs text-muted-foreground">
                        Add a line
                    </Label>
                    <Input
                        :id="`task-check-new-${task.id}`"
                        v-model="title"
                        :disabled="adding"
                        placeholder="What else is in this?"
                    />
                </div>
                <Button type="submit" size="sm" variant="outline" :disabled="adding || title.trim() === ''">
                    <Plus aria-hidden="true" />
                    Add
                </Button>
            </form>
        </CardContent>
    </Card>
</template>
