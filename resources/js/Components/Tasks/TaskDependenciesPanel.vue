<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { TaskDetail, TaskSibling, TaskStub, TaskSurface } from '@/Components/Tasks/taskDetail';
import { mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

/**
 * What this task waits for, and what waits for it.
 *
 * Dependencies are the plan, so only the Admin surface writes them — there is no
 * `employee/tasks/{task}/dependencies` route at all, which is why `siblings` (the picker's
 * options) is only sent to the Admin detail page. Without it this panel is a read of both
 * directions, which is still worth showing: "waiting on" is the reason a task is not moving.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
    /** The other tasks of this project, titles and ids only. Admin surface only. */
    siblings?: TaskSibling[];
}>();

const emit = defineEmits<{ settled: [] }>();

const routes = computed(() => taskRoutes(props.surface, props.task.id));

/** Writable only where the endpoint exists and the policy says yes. */
const editable = computed(
    () => props.surface === 'admin' && props.task.permissions.can_update && (props.siblings?.length ?? 0) > 0,
);

/** A task already depended on is not offered again. */
const choices = computed(() => {
    const taken = new Set(props.task.dependencies.map((dependency) => dependency.id));

    return (props.siblings ?? []).filter((sibling) => !taken.has(sibling.id));
});

const chosen = ref('');
const adding = ref(false);
const busy = ref<number | null>(null);

function add(): void {
    if (chosen.value === '' || adding.value) {
        return;
    }

    adding.value = true;

    mutateTask(
        'post',
        routes.value.dependencies,
        { depends_on_task_id: Number(chosen.value) },
        {
            onAccepted: () => {
                chosen.value = '';
            },
            onSettled: () => emit('settled'),
            onFinish: () => {
                adding.value = false;
            },
        },
    );
}

function remove(id: number): void {
    busy.value = id;

    mutateTask('delete', routes.value.dependency(id), {}, {
        onSettled: () => emit('settled'),
        onFinish: () => {
            busy.value = null;
        },
    });
}

function href(stub: TaskStub): string {
    return `/${props.surface}/tasks/${stub.id}`;
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Dependencies</CardTitle>
            <CardDescription>What has to happen first, and what is held up by this.</CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-2">
                <p class="text-xs font-medium text-muted-foreground">Waiting on</p>

                <p v-if="task.dependencies.length === 0" class="text-sm text-muted-foreground">
                    Nothing — this task is not blocked.
                </p>

                <ul v-else class="flex min-w-0 flex-col gap-2">
                    <li
                        v-for="dependency in task.dependencies"
                        :key="dependency.id"
                        class="flex min-w-0 flex-wrap items-center gap-2"
                    >
                        <StatusBadge
                            v-if="dependency.status_tone"
                            :status="dependency.status_tone"
                            :label="dependency.status_label ?? undefined"
                            size="sm"
                        />
                        <Link
                            :href="href(dependency)"
                            class="min-w-0 flex-1 text-sm font-medium break-words hover:underline"
                        >
                            {{ dependency.title }}
                        </Link>
                        <Button
                            v-if="editable"
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0"
                            :disabled="busy === dependency.id"
                            :aria-label="`Stop waiting on ${dependency.title}`"
                            @click="remove(dependency.id)"
                        >
                            <X aria-hidden="true" />
                        </Button>
                    </li>
                </ul>

                <form
                    v-if="editable"
                    class="flex min-w-0 flex-wrap items-end gap-2"
                    novalidate
                    @submit.prevent="add"
                >
                    <div class="flex min-w-0 flex-1 basis-48 flex-col gap-2">
                        <Label :for="`task-dependency-${task.id}`" class="text-xs text-muted-foreground">
                            Wait for another task
                        </Label>
                        <Select v-model="chosen">
                            <SelectTrigger :id="`task-dependency-${task.id}`" class="w-full">
                                <SelectValue placeholder="Pick a task…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="sibling in choices"
                                    :key="sibling.id"
                                    :value="String(sibling.id)"
                                >
                                    {{ sibling.title }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button type="submit" size="sm" variant="outline" :disabled="adding || chosen === ''">
                        <Plus aria-hidden="true" />
                        Add
                    </Button>
                </form>
            </div>

            <div v-if="task.dependents.length > 0" class="flex min-w-0 flex-col gap-2 border-t pt-4">
                <p class="text-xs font-medium text-muted-foreground">Blocking</p>
                <ul class="flex min-w-0 flex-col gap-2">
                    <li
                        v-for="dependent in task.dependents"
                        :key="dependent.id"
                        class="flex min-w-0 flex-wrap items-center gap-2"
                    >
                        <StatusBadge
                            v-if="dependent.status_tone"
                            :status="dependent.status_tone"
                            :label="dependent.status_label ?? undefined"
                            size="sm"
                        />
                        <Link
                            :href="href(dependent)"
                            class="min-w-0 flex-1 text-sm font-medium break-words hover:underline"
                        >
                            {{ dependent.title }}
                        </Link>
                    </li>
                </ul>
            </div>
        </CardContent>
    </Card>
</template>
