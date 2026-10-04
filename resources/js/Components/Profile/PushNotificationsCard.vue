<script lang="ts">
export type PushSettings = { vapidPublicKey: string; messages: boolean; alerts: boolean };
</script>

<script setup lang="ts">
/**
 * Notifications on this device — push notifications for new messages and alerts.
 *
 * *Turn on* asks for permission and subscribes this browser to push (`lib/push.ts`), so a
 * notification arrives even when goodERP is closed. That on/off state is **per device** — it is
 * the browser's own push subscription, read back on mount. The Messages and Alerts switches are
 * **per person**, stored on the server (`PUT /profile/push`), and apply to every device that is on.
 */
import { router } from '@inertiajs/vue3';
import { Bell, BellRing } from '@lucide/vue';
import { computed, onMounted, ref, useId } from 'vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { deviceState, inAppWebView, type PushDeviceState, turnOff, turnOn } from '@/lib/push';

const webView = inAppWebView();

const props = defineProps<{
    push: PushSettings;
}>();

const uid = useId();
const messagesId = `${uid}-messages`;
const messagesHintId = `${uid}-messages-hint`;
const alertsId = `${uid}-alerts`;
const alertsHintId = `${uid}-alerts-hint`;

const state = ref<PushDeviceState | 'checking'>('checking');
const busy = ref(false);
const error = ref('');

const configured = computed(() => props.push.vapidPublicKey !== '');

const messages = ref(props.push.messages);
const alerts = ref(props.push.alerts);

onMounted(async () => {
    state.value = await deviceState();
});

async function enable(): Promise<void> {
    busy.value = true;
    error.value = '';

    try {
        state.value = await turnOn(props.push.vapidPublicKey);
    } catch {
        error.value = "Couldn't turn notifications on. Check your connection and try again.";
    } finally {
        busy.value = false;
    }
}

async function disable(): Promise<void> {
    busy.value = true;
    error.value = '';

    try {
        state.value = await turnOff();
    } catch {
        error.value = "Couldn't turn notifications off. Check your connection and try again.";
    } finally {
        busy.value = false;
    }
}

function save(): void {
    router.put('/profile/push', { messages: messages.value, alerts: alerts.value }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Notifications on this device</CardTitle>
            <CardDescription>
                Get a notification on this phone or computer for new messages and alerts, even when goodERP is closed.
            </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <div v-if="state !== 'checking'" class="flex min-w-0 flex-col gap-2">
                <p v-if="!configured" class="text-sm text-muted-foreground">Notifications aren't set up on the server yet.</p>
                <p v-else-if="state === 'unsupported' && webView" class="text-sm text-muted-foreground">
                    goodERP is running in its backup browser because Chrome couldn't open it on this phone, and the backup
                    browser can't show notifications. Install or update Google Chrome, then close and reopen the goodERP app.
                </p>
                <p v-else-if="state === 'unsupported'" class="text-sm text-muted-foreground">
                    This browser can't show notifications. On Android, use the goodERP app or Chrome.
                </p>
                <p v-else-if="state === 'blocked'" class="text-sm text-muted-foreground">
                    Notifications are blocked for goodERP on this device. Allow them in the phone's settings (Apps → goodERP →
                    Notifications) or in the browser's site settings, then reload this page.
                </p>
                <div v-else-if="state === 'off'" class="flex min-w-0 flex-wrap items-center gap-3">
                    <Button type="button" :disabled="busy" @click="enable">
                        <Bell aria-hidden="true" />
                        Turn on
                    </Button>
                </div>
                <div v-else class="flex min-w-0 flex-wrap items-center gap-3">
                    <BellRing class="size-4 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 text-sm">On for this device</span>
                    <Button type="button" variant="ghost" size="sm" :disabled="busy" @click="disable">Turn off</Button>
                </div>
                <p v-if="error !== ''" role="alert" class="text-sm text-destructive">{{ error }}</p>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="messagesId">Messages</Label>
                <div class="flex min-w-0 flex-wrap items-center gap-3">
                    <Switch :id="messagesId" v-model="messages" :aria-describedby="messagesHintId" @update:model-value="save" />
                    <!-- The words carry the state, not the switch's colour (DESIGN.md §5.6). -->
                    <span class="min-w-0 text-sm">{{ messages ? 'On' : 'Off' }}</span>
                </div>
                <p :id="messagesHintId" class="text-xs text-muted-foreground">New messages in your chats.</p>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="alertsId">Alerts</Label>
                <div class="flex min-w-0 flex-wrap items-center gap-3">
                    <Switch :id="alertsId" v-model="alerts" :aria-describedby="alertsHintId" @update:model-value="save" />
                    <span class="min-w-0 text-sm">{{ alerts ? 'On' : 'Off' }}</span>
                </div>
                <p :id="alertsHintId" class="text-xs text-muted-foreground">Tasks, reviews, comments, leave and meetings.</p>
            </div>

            <p class="text-xs text-muted-foreground">
                Turn on once on each phone or computer you use. The two switches apply to all of them.
            </p>
        </CardContent>
    </Card>
</template>
