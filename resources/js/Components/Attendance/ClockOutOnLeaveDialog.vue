<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, Loader2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import { attendanceRoutes } from '@/Components/Attendance/attendance';
import { clockOutDialogOpen, leaveAllowed, setClockedIn } from '@/Components/Attendance/clockState';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { inlineUploadFailure } from '@/lib/net';

/**
 * "You're currently clocked in — do you also want to clock out?" (decision 12-84).
 *
 * A browser shows no page-made dialog while a tab is closing: only its own "Leave site?" prompt,
 * and nothing runs reliably once the person confirms leaving. So the guard in
 * `Components/Timer/TaskTimerPulse.vue` asks the native prompt, and this opens only when the
 * page SURVIVED it — the person chose Stay/Cancel. Leaving through the native prompt leaves
 * them clocked in: nothing is sent about the clock, ever.
 *
 * It never clocks out on its own; only *Clock Out & Leave* does, and only once pressed.
 * `window.close()` is a best effort — a browser closes only a tab a script opened.
 */

type Stage = 'ask' | 'clocked_out' | 'leaving';

const CLOCK_OUT_FAILED_TEXT = "Couldn't reach goodERP, so you are not clocked out yet.";

const stage = ref<Stage>('ask');
const busy = ref(false);
const failure = ref<string | null>(null);

watch(clockOutDialogOpen, (open) => {
    if (open) {
        stage.value = 'ask';
        failure.value = null;
    }
});

function closeTab(): void {
    try {
        window.close();
    } catch {
        // A tab no script opened stays open; the sentence on screen says what to do.
    }
}

function clockOutAndLeave(): void {
    if (busy.value) {
        return;
    }

    busy.value = true;
    failure.value = null;

    router.post(
        attendanceRoutes.clockOut,
        {},
        {
            preserveScroll: true,
            ...inlineUploadFailure(() => {
                failure.value = CLOCK_OUT_FAILED_TEXT;
            }),
            onSuccess: (page) => {
                // A refused clock-out comes back as the server's own sentence in `flash.error`.
                const error = page.props.flash?.error ?? null;

                if (error !== null && page.props.clock?.clocked_in !== false) {
                    failure.value = error;

                    return;
                }

                setClockedIn(false);
                stage.value = 'clocked_out';
                closeTab();
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function leaveWithoutClockingOut(): void {
    leaveAllowed.value = true;
    stage.value = 'leaving';
    closeTab();
}

function cancel(): void {
    clockOutDialogOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="clockOutDialogOpen">
        <DialogContent data-testid="clock-out-on-leave">
            <template v-if="stage === 'ask'">
                <DialogHeader>
                    <DialogTitle>You're currently clocked in</DialogTitle>
                    <DialogDescription>Do you also want to clock out?</DialogDescription>
                </DialogHeader>

                <p v-if="failure" role="alert" class="flex items-start gap-2 text-sm text-destructive">
                    <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    {{ failure }}
                </p>

                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <Button type="button" class="h-11 sm:h-9" :disabled="busy" @click="clockOutAndLeave">
                        <Loader2 v-if="busy" class="size-4 animate-spin" aria-hidden="true" />
                        Clock Out &amp; Leave
                    </Button>
                    <Button type="button" variant="outline" class="h-11 sm:h-9" :disabled="busy" @click="leaveWithoutClockingOut">
                        Leave Without Clocking Out
                    </Button>
                    <Button type="button" variant="ghost" class="h-11 sm:h-9" :disabled="busy" @click="cancel">Cancel</Button>
                </div>
            </template>

            <DialogHeader v-else>
                <DialogTitle>{{ stage === 'clocked_out' ? "You're clocked out" : 'Leaving goodERP' }}</DialogTitle>
                <DialogDescription>
                    {{ stage === 'clocked_out' ? "You're clocked out — you can close this tab now." : 'You can close this tab now.' }}
                </DialogDescription>
            </DialogHeader>
        </DialogContent>
    </Dialog>
</template>
