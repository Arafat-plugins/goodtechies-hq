<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { computed } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import NotificationDefaultRow from '@/Components/Settings/NotificationDefaultRow.vue';
import type { NotificationChannelMeta, NotificationTypeGroup } from '@/Components/Settings/notificationDefaults';
import { channelLabels } from '@/Components/Settings/notificationDefaults';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * Admin → Notifications: the agency's notification defaults
 * (Part D §20 — `notification_preferences (type, channel, enabled)`, *"global defaults set by
 * Admin, Phase 12; the engine reads them"*).
 *
 * These are **defaults for everybody**, not one person's preferences: there is no `user_id` on
 * the table and no per-person screen in the MVP. Somebody's own mail is the bell and the
 * Notification Center at `/notifications`, which is a different screen for a different question.
 *
 * ## One switch, one request
 *
 * Each toggle is its own `PUT /admin/notifications` carrying the pair it is about, and the page
 * shares one form so a second toggle cannot be sent while the first is in flight. There is no
 * page-wide Save, because there is no page-wide state to save: a row exists in the table only
 * when somebody has turned something off (or back on), and absence means default. A grid submit
 * would have had to decide what an absent cell meant, and "absent means off" is the one reading
 * this design is arranged against — it is what would silently stop delivering a notification
 * type added in a later phase.
 *
 * ## Two channels are listed, off, and not switchable
 *
 * `web_push` and `mail` exist as `NotificationChannel` cases so a later phase can turn one on,
 * and no sender is built for either — spec §44 and Part H §1 put both after the MVP. So they are
 * shown (Part E: *"other channels listed but disabled until spec post-MVP Phase 2"*), marked off
 * with the reason on the Channels card, and there is nothing to click. The server refuses a
 * crafted request for one as well, against the type's own `channels()` list — so this is a rule,
 * not a UI state.
 */

const props = defineProps<{
    channels: NotificationChannelMeta[];
    groups: NotificationTypeGroup[];
}>();

const labels = computed(() => channelLabels(props.channels));

const form = useForm<{ type: string; channel: string; enabled: boolean }>({
    type: '',
    channel: '',
    enabled: true,
});

function toggle(type: string, channel: string, enabled: boolean): void {
    form.type = type;
    form.channel = channel;
    form.enabled = enabled;

    form.put('/admin/notifications', { preserveScroll: true, preserveState: true });
}

/** The one error either field can carry — shown once, above the grid. */
const error = computed(() => form.errors.channel ?? form.errors.type ?? form.errors.enabled ?? null);
</script>

<template>
    <Head title="Notification defaults" />

    <PageShell
        title="Notification defaults"
        description="Which events the app tells people about, for the whole agency. Turning one off stops the notification being written at all — nobody is told, and it does not arrive later."
    >
        <div class="flex min-w-0 flex-col gap-4">
            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

            <Card class="min-w-0 gap-4">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Channels</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="flex min-w-0 flex-col divide-y">
                        <div v-for="channel in channels" :key="channel.value" class="flex min-w-0 flex-col gap-1 py-3">
                            <dt class="flex min-w-0 flex-wrap items-center gap-2 text-sm font-medium">
                                {{ channel.label }}
                                <Badge
                                    v-if="!channel.available"
                                    variant="outline"
                                    class="gap-1 text-muted-foreground"
                                >
                                    <Lock class="size-3" aria-hidden="true" />
                                    Not built yet
                                </Badge>
                            </dt>
                            <dd class="text-xs text-muted-foreground">{{ channel.note }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card v-for="group in groups" :key="group.key" class="min-w-0 gap-4">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">{{ group.label }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex min-w-0 flex-col divide-y">
                        <NotificationDefaultRow
                            v-for="type in group.types"
                            :key="type.value"
                            :type="type"
                            :labels="labels"
                            :busy="form.processing"
                            @toggle="toggle"
                        />
                    </div>
                </CardContent>
            </Card>
        </div>
    </PageShell>
</template>
