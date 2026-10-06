<script lang="ts">
export interface ExtensionDevice {
    id: number;
    name: string;
    paired_at: string | null;
    last_seen_at: string | null;
}

export interface ExtensionStatus {
    available: boolean;
    devices: ExtensionDevice[];
}
</script>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Copy, Puzzle } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { xsrfToken } from '@/Components/Messages/messages';
import { timerRoutes } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { toast } from '@/lib/toast';

/**
 * Profile → Connect timer extension (Phase 11, docs/extension-api.md §1–§2). Remote timer users
 * only — the page mounts it behind `extension.available`, and the code endpoint refuses anybody
 * else. A code is single-use and lives ten minutes.
 */
defineProps<{
    extension: ExtensionStatus;
}>();

const code = ref<string | null>(null);
const secondsLeft = ref(0);
const requesting = ref(false);
let countdown: ReturnType<typeof setInterval> | null = null;

function stopCountdown(): void {
    if (countdown !== null) {
        clearInterval(countdown);
        countdown = null;
    }
}

onBeforeUnmount(stopCountdown);

const expiresIn = computed(() => {
    const minutes = Math.floor(secondsLeft.value / 60);
    const seconds = secondsLeft.value % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

async function getCode(): Promise<void> {
    if (requesting.value) {
        return;
    }

    requesting.value = true;

    try {
        const response = await fetch(timerRoutes.extensionCode, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const body = (await response.json()) as { code: string; expires_in_minutes: number };

        stopCountdown();
        code.value = body.code;
        secondsLeft.value = body.expires_in_minutes * 60;
        countdown = setInterval(() => {
            secondsLeft.value = Math.max(0, secondsLeft.value - 1);

            if (secondsLeft.value === 0) {
                stopCountdown();
                code.value = null;
            }
        }, 1000);
    } catch {
        toast.error('Could not get a code. Try again.');
    } finally {
        requesting.value = false;
    }
}

async function copyCode(): Promise<void> {
    if (code.value === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(code.value);
        toast.success('Code copied');
    } catch {
        toast.error('Could not copy the code.');
    }
}

const dateTime = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', hour12: true });

function when(iso: string | null): string {
    if (iso === null) {
        return 'never';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? iso : dateTime.format(date);
}

const confirming = ref<ExtensionDevice | null>(null);
const disconnecting = ref(false);

function disconnect(): void {
    const device = confirming.value;

    if (device === null || disconnecting.value) {
        return;
    }

    router.delete('/profile/extension/devices/' + device.id, {
        preserveScroll: true,
        onStart: () => (disconnecting.value = true),
        onFinish: () => {
            disconnecting.value = false;
            confirming.value = null;
        },
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Connect timer extension</CardTitle>
            <CardDescription>Track your time from a Chrome or Edge extension. The extension and this site show the same timer.</CardDescription>
        </CardHeader>
        <CardContent class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-2">
                <div v-if="code !== null" class="flex min-w-0 flex-wrap items-center gap-2">
                    <p class="text-3xl font-semibold tracking-widest tabular-nums" aria-live="polite">{{ code }}</p>
                    <Button type="button" variant="outline" size="sm" @click="copyCode">
                        <Copy aria-hidden="true" />
                        Copy
                    </Button>
                </div>
                <p v-if="code !== null" class="text-xs text-muted-foreground tabular-nums">Expires in {{ expiresIn }}</p>
                <div>
                    <Button type="button" :disabled="requesting" @click="getCode">Get a code</Button>
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <p class="text-sm font-medium">Install it in Chrome or Edge</p>
                <ol class="flex list-decimal flex-col gap-1 pl-5 text-sm">
                    <li>Download <span class="font-medium break-all">goodtechies-timer-0.1.0.zip</span> from your administrator and unzip it.</li>
                    <li>Open <span class="font-medium">chrome://extensions</span> in Chrome or <span class="font-medium">edge://extensions</span> in Edge.</li>
                    <li>Turn on Developer mode.</li>
                    <li>Click Load unpacked and choose the unpacked folder.</li>
                    <li>Click the extension icon, paste the code and click Connect.</li>
                </ol>
                <p class="text-xs text-muted-foreground">Chrome and Edge warn that this extension can "read and change all your data on all websites". That permission is what lets it notice a playing video or a live call on any page. It reads two yes/no facts from a page and the website's domain name, and nothing else.</p>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <p class="text-sm font-medium">What it records</p>
                <p class="text-sm text-muted-foreground">While your timer is running, this extension records which website domain you are on (for example docs.google.com) and how long, whether you are active, and whether a video or a call is playing. It never records page addresses, page titles, page content, what you type, or screenshots. When the timer is paused or stopped, nothing is recorded.</p>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <p class="text-sm font-medium">Connected</p>
                <ul v-if="extension.devices.length > 0" class="divide-y">
                    <li v-for="device in extension.devices" :key="device.id" class="flex items-center gap-3 py-3">
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
                            Disconnect
                        </Button>
                    </li>
                </ul>
                <p v-else class="text-xs text-muted-foreground">No extension connected</p>
            </div>
        </CardContent>

        <Dialog :open="confirming !== null" @update:open="(open) => !open && !disconnecting && (confirming = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Disconnect this extension?</DialogTitle>
                    <DialogDescription>
                        {{ confirming?.name }} will stop tracking your time. You can connect it again with a new code.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="disconnecting" @click="confirming = null">Cancel</Button>
                    <Button type="button" variant="destructive" :disabled="disconnecting" @click="disconnect">Disconnect</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Card>
</template>
