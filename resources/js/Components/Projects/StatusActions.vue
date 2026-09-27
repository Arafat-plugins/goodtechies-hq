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
import { useMenuDialog } from '@/lib/menuFocus';

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

/**
 * Decision 5-20: this dialog is opened from a `DropdownMenuItem`, so reka's focus restore aimed at an
 * element the menu had already unmounted and landed on `<body>`. `openFromMenu` captures the *Change
 * status* trigger while the menu is still open and defers the dialog by a tick; `closeCancel` puts
 * the keyboard back on it. The trigger disappears when a cancellation lands — a cancelled project has
 * no further moves — so the fallback matters here, and it is the `<main>` region rather than nothing
 * (see `lib/menuFocus.ts`).
 */
const menu = useMenuDialog();

function askToCancel(): void {
    menu.openFromMenu(() => {
        cancelForm.clearErrors();
        cancelForm.reason = '';
        cancelOpen.value = true;
    });
}

function closeCancel(): void {
    cancelOpen.value = false;
    menu.returnFocus();
}

function confirmCancel(): void {
    if (cancelForm.processing) {
        return;
    }

    cancelForm.post(`/admin/projects/${props.project.id}/status`, {
        preserveScroll: true,
        // `onSuccess`, not "close on submit": a validation failure has to leave the dialog open with
        // the reason still in it (decision 9-27's lesson, one screen over).
        onSuccess: () => {
            closeCancel();
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

    <Dialog :open="cancelOpen" @update:open="(open) => { if (! open) { closeCancel(); } }">
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
                        @click="closeCancel"
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
