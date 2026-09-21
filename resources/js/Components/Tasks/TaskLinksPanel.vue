<script setup lang="ts">
import { ExternalLink, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { TaskDetail, TaskSurface } from '@/Components/Tasks/taskDetail';
import { mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/**
 * Reference links.
 *
 * The scheme is the server's business: `StoreTaskLinkRequest` accepts `url:http,https` only,
 * so a `javascript:` URL never reaches this anchor. The anchor still carries
 * `rel="noopener noreferrer"` because the target document should not get a handle on this one.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
}>();

const emit = defineEmits<{ settled: [] }>();

const routes = computed(() => taskRoutes(props.surface, props.task.id));
const editable = computed(() => props.task.permissions.can_update);

const url = ref('');
const label = ref('');
const adding = ref(false);
const busy = ref<number | null>(null);

function add(): void {
    if (url.value.trim() === '' || adding.value) {
        return;
    }

    adding.value = true;

    mutateTask(
        'post',
        routes.value.links,
        { url: url.value.trim(), label: label.value.trim() === '' ? null : label.value.trim() },
        {
            onAccepted: () => {
                url.value = '';
                label.value = '';
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

    mutateTask('delete', routes.value.link(id), {}, {
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
            <CardTitle class="text-sm font-medium">Links</CardTitle>
            <CardDescription>Where the work actually lives.</CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-3">
            <p v-if="task.links.length === 0" class="text-sm text-muted-foreground">No links yet.</p>

            <ul v-else class="flex min-w-0 flex-col gap-2">
                <li v-for="link in task.links" :key="link.id" class="flex min-w-0 items-start gap-2">
                    <ExternalLink class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <a
                        :href="link.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="min-w-0 flex-1 text-sm font-medium break-all hover:underline"
                    >
                        {{ link.label }}
                        <span class="sr-only">(opens in a new tab)</span>
                    </a>
                    <Button
                        v-if="editable"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0"
                        :disabled="busy === link.id"
                        :aria-label="`Remove ${link.label}`"
                        @click="remove(link.id)"
                    >
                        <X aria-hidden="true" />
                    </Button>
                </li>
            </ul>

            <form v-if="editable" class="flex min-w-0 flex-col gap-2" novalidate @submit.prevent="add">
                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="`task-link-url-${task.id}`" class="text-xs text-muted-foreground">URL</Label>
                    <Input
                        :id="`task-link-url-${task.id}`"
                        v-model="url"
                        type="url"
                        inputmode="url"
                        placeholder="https://…"
                        :disabled="adding"
                    />
                </div>
                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="`task-link-label-${task.id}`" class="text-xs text-muted-foreground">
                        Label <span class="font-normal">(optional)</span>
                    </Label>
                    <Input :id="`task-link-label-${task.id}`" v-model="label" :disabled="adding" />
                </div>
                <div>
                    <Button type="submit" size="sm" variant="outline" :disabled="adding || url.trim() === ''">
                        <Plus aria-hidden="true" />
                        Add link
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
