<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Puzzle } from '@lucide/vue';
import { ref } from 'vue';
import type { ExtensionDevice } from '@/Components/Profile/TimerExtensionCard.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

/**
 * Admin → employee page: this person's connected timer extensions, each with a revoke. The page
 * mounts it only when the server sent the list — a remote timer user the Admin may deactivate.
 */
const props = defineProps<{
    devices: ExtensionDevice[];
    employeeId: number;
}>();

const dateTime = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', hour12: true });

function when(iso: string | null): string {
    if (iso === null) {
        return 'never';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? iso : dateTime.format(date);
}

const confirming = ref<ExtensionDevice | null>(null);
const revoking = ref(false);

function revoke(): void {
    const device = confirming.value;

    if (device === null || revoking.value) {
        return;
    }

    router.delete(`/admin/employees/${props.employeeId}/extension-devices/${device.id}`, {
        preserveScroll: true,
        onStart: () => (revoking.value = true),
        onFinish: () => {
            revoking.value = false;
            confirming.value = null;
        },
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Timer extension</CardTitle>
        </CardHeader>
        <CardContent class="min-w-0">
            <ul v-if="devices.length > 0" class="divide-y">
                <li v-for="device in devices" :key="device.id" class="flex items-center gap-3 py-3">
                    <span class="shrink-0 rounded-md bg-muted p-2 text-muted-foreground" aria-hidden="true">
                        <Puzzle class="size-4" />
                    </span>
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <p class="min-w-0 text-sm font-medium break-words">{{ device.name }}</p>
                        <p class="text-xs break-words text-muted-foreground">
                            Paired {{ when(device.paired_at) }} · Last seen {{ when(device.last_seen_at) }}
                        </p>
                    </div>
                    <Button type="button" variant="ghost" size="sm" class="shrink-0" @click="confirming = device">
                        Revoke
                    </Button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">No extension connected</p>
        </CardContent>

        <Dialog :open="confirming !== null" @update:open="(open) => !open && !revoking && (confirming = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Revoke this extension?</DialogTitle>
                    <DialogDescription>
                        {{ confirming?.name }} will stop tracking time for this person straight away. They can connect it again with a new code.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="revoking" @click="confirming = null">Cancel</Button>
                    <Button type="button" variant="destructive" :disabled="revoking" @click="revoke">Revoke</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Card>
</template>
