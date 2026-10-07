<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, X } from '@lucide/vue';
import { ref, useId } from 'vue';
import type { AttendanceCorrection } from '@/Components/Attendance/attendance';
import { attendanceRoutes } from '@/Components/Attendance/attendance';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';

/**
 * Polish 029: one waiting correction request — the employee's words, and Approve / Decline.
 * Approve makes the day Present (keeping its times); Decline leaves it and sends the note, if
 * one is typed, to the employee. Used on the roster and in the edit-day dialog.
 */
const props = defineProps<{
    correction: AttendanceCorrection;
    /** "Yaseen · Wed 7 Oct · Late" on the roster; omitted inside a dialog about that day. */
    heading?: string;
}>();

const emit = defineEmits<{ decided: [] }>();

const noteId = useId();
const note = ref('');
const busy = ref<'approve' | 'reject' | null>(null);

function decide(action: 'approve' | 'reject'): void {
    if (busy.value) {
        return;
    }

    busy.value = action;

    router.post(
        action === 'approve'
            ? attendanceRoutes.approveCorrection(props.correction.id)
            : attendanceRoutes.rejectCorrection(props.correction.id),
        { note: note.value.trim() === '' ? null : note.value.trim() },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => emit('decided'),
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2 rounded-md border bg-muted p-3">
        <p v-if="heading" class="text-sm font-medium">{{ heading }}</p>
        <p v-else class="text-xs font-medium text-muted-foreground">Asked to be corrected</p>
        <p class="text-sm break-words whitespace-pre-line">{{ correction.reason }}</p>

        <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
            <Input
                :id="noteId"
                v-model="note"
                class="min-w-0 flex-1"
                maxlength="1000"
                placeholder="Note to the employee (optional)"
                aria-label="Note to the employee"
                :disabled="busy !== null"
            />
            <div class="flex shrink-0 gap-2">
                <Button type="button" size="sm" :disabled="busy !== null" @click="decide('approve')">
                    <Check aria-hidden="true" />
                    {{ busy === 'approve' ? 'Approving…' : 'Approve' }}
                </Button>
                <Button type="button" size="sm" variant="outline" :disabled="busy !== null" @click="decide('reject')">
                    <X aria-hidden="true" />
                    {{ busy === 'reject' ? 'Declining…' : 'Decline' }}
                </Button>
            </div>
        </div>
    </div>
</template>
