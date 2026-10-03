<script lang="ts">
import type { Project } from '@/Components/Projects/ProjectForm.vue';

/**
 * `can_force_delete` from `ProjectResource::permissions()` — true only for an Admin on an
 * archived project. Read through this helper so the list and the detail page ask the same
 * question.
 */
export function canForceDelete(project: Pick<Project, 'permissions'>): boolean {
    const permissions = project.permissions as { can_force_delete?: boolean } | undefined;

    return permissions?.can_force_delete === true;
}
</script>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
 * Deleting an archived project for good. The destructive button stays disabled until the
 * project's name is typed back exactly; the server checks the same thing again and answers a
 * refusal (income, paid time, a wrong name) as `flash.error`, which is shown here, in the
 * dialog, rather than only as a toast behind it.
 */
const props = defineProps<{
    /** The project to delete; null closes the dialog. */
    project: { id: number; name: string } | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const typed = ref('');
const deleting = ref(false);
const failure = ref<string | null>(null);

watch(
    () => props.project?.id,
    () => {
        typed.value = '';
        failure.value = null;
    },
);

const matches = computed(() => props.project !== null && typed.value.trim() === props.project.name);

function close(): void {
    if (deleting.value) {
        return;
    }

    emit('close');
}

function submit(): void {
    const project = props.project;

    if (project === null || !matches.value || deleting.value) {
        return;
    }

    deleting.value = true;
    failure.value = null;

    router.delete(`/admin/projects/${project.id}`, {
        data: { confirm_name: typed.value.trim() },
        preserveScroll: true,
        onError: (errors) => {
            failure.value = errors.confirm_name ?? 'The project could not be deleted.';
        },
        onSuccess: (page) => {
            const error = page.props.flash?.error ?? null;

            if (error !== null) {
                failure.value = error;

                return;
            }

            deleting.value = false;
            emit('close');
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Dialog
        :open="project !== null"
        @update:open="(open) => { if (! open) { close(); } }"
    >
        <DialogContent>
            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="break-words">Delete {{ project?.name }} permanently?</DialogTitle>
                    <DialogDescription>
                        This removes the project, its tasks, files, time and messages for good. It can't be undone.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="delete-project-confirm-name">Type the project name to confirm</Label>
                    <Input
                        id="delete-project-confirm-name"
                        v-model="typed"
                        autocomplete="off"
                        :placeholder="project?.name"
                        :aria-invalid="failure !== null ? true : undefined"
                        aria-describedby="delete-project-error"
                        :disabled="deleting"
                    />
                    <p
                        v-if="failure"
                        id="delete-project-error"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="deleting" @click="close">Cancel</Button>
                    <Button type="submit" variant="destructive" :disabled="!matches || deleting">
                        {{ deleting ? 'Deleting…' : 'Delete permanently' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
