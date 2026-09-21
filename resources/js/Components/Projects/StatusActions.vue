<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Option, Project } from '@/Components/Projects/ProjectForm.vue';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

const props = defineProps<{
    project: Project;
    statuses: Option[];
}>();

const CANCELLED = 'cancelled';

/**
 * from => the statuses it may move to. Mirrors `ProjectService::TRANSITIONS`; archiving is
 * not a transition, it has its own endpoint. The server is still the authority — an offer
 * it refuses comes back as `flash.error`.
 */
const TRANSITIONS: Record<string, string[]> = {
    active: ['on_hold', 'completed', 'cancelled'],
    on_hold: ['active', 'completed', 'cancelled'],
    completed: ['cancelled', 'active'],
    cancelled: ['active'],
};

const nextStatuses = computed(() => {
    const allowed = TRANSITIONS[props.project.status] ?? [];

    return props.statuses.filter((status) => allowed.includes(status.value));
});

const changing = ref(false);

function moveTo(status: string): void {
    if (changing.value) {
        return;
    }

    changing.value = true;

    router.post(
        `/admin/projects/${props.project.id}/status`,
        { status },
        {
            preserveScroll: true,
            onFinish: () => {
                changing.value = false;
            },
        },
    );
}

const cancelOpen = ref(false);

const cancelForm = useForm({
    status: CANCELLED,
    reason: '',
});

/** Let the dropdown finish closing before the dialog takes the focus trap. */
function askToCancel(): void {
    setTimeout(() => {
        cancelForm.clearErrors();
        cancelForm.reason = '';
        cancelOpen.value = true;
    }, 0);
}

function confirmCancel(): void {
    if (cancelForm.processing) {
        return;
    }

    cancelForm.post(`/admin/projects/${props.project.id}/status`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelOpen.value = false;
        },
    });
}

function choose(status: string): void {
    if (status === CANCELLED) {
        askToCancel();

        return;
    }

    moveTo(status);
}
</script>

<template>
    <DropdownMenu v-if="nextStatuses.length > 0">
        <DropdownMenuTrigger as-child>
            <Button type="button" variant="outline" size="sm" :disabled="changing">
                Change status
                <ChevronDown aria-hidden="true" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="status in nextStatuses"
                :key="status.value"
                :variant="status.value === CANCELLED ? 'destructive' : 'default'"
                @select="choose(status.value)"
            >
                {{ status.label }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <Dialog v-model:open="cancelOpen">
        <DialogContent>
            <form novalidate @submit.prevent="confirmCancel">
                <DialogHeader>
                    <DialogTitle>Cancel {{ project.name }}?</DialogTitle>
                    <DialogDescription>
                        Cancelling needs a reason — it goes on the project's activity trail.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-2 py-4">
                    <Label for="cancel-reason">
                        Reason <span class="text-destructive" aria-hidden="true">*</span>
                    </Label>
                    <Textarea
                        id="cancel-reason"
                        v-model="cancelForm.reason"
                        rows="3"
                        required
                        :disabled="cancelForm.processing"
                        :aria-invalid="cancelForm.errors.reason ? true : undefined"
                    />
                    <p v-if="cancelForm.errors.reason" class="text-xs text-destructive">
                        {{ cancelForm.errors.reason }}
                    </p>
                    <p v-if="cancelForm.errors.status" class="text-xs text-destructive">
                        {{ cancelForm.errors.status }}
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="cancelForm.processing"
                        @click="cancelOpen = false"
                    >
                        Keep it open
                    </Button>
                    <Button type="submit" variant="destructive" :disabled="cancelForm.processing">
                        {{ cancelForm.processing ? 'Cancelling…' : 'Cancel project' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
