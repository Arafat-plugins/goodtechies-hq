<script setup lang="ts">
import { Lock } from '@lucide/vue';
import type { NotificationTypeRow } from '@/Components/Settings/notificationDefaults';
import { Badge } from '@/Components/ui/badge';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';

/**
 * One notification type, with its switch per channel.
 *
 * A channel the type does not name is drawn as a labelled *off* badge and not as a disabled
 * switch. DESIGN.md §5.12 — *never show a disabled control where a hidden one would do* — and a
 * greyed-out switch is the worst of the three options: it looks like something a permission is
 * withholding, and it invites the click that does nothing. Hiding the cell entirely is wrong too,
 * because Part E asks for the other channels to be *"listed but disabled until spec post-MVP
 * Phase 2"*: the reader is entitled to know that browser push and email exist and are off. So
 * they are named, marked off with a padlock, and the *Channels* card above says why.
 *
 * Nothing here decides which channels those are. `switchable` comes from the type's own
 * `channels()` list, so the day a sender is built the cell becomes a switch by itself.
 */

const props = defineProps<{
    type: NotificationTypeRow;
    /** Channel value → label, from the page's `channels` prop. */
    labels: Record<string, string>;
    /** True while any toggle on the page is in flight, so two cannot race. */
    busy?: boolean;
}>();

const emit = defineEmits<{ toggle: [type: string, channel: string, enabled: boolean] }>();

function id(channel: string): string {
    return `notify-${props.type.value.replace(/\./g, '-')}-${channel}`;
}

function label(channel: string): string {
    return props.labels[channel] ?? channel;
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <p class="min-w-0 text-sm">{{ type.label }}</p>

        <div class="flex min-w-0 flex-wrap items-center gap-x-5 gap-y-2 sm:shrink-0 sm:justify-end">
            <template v-for="cell in type.channels" :key="cell.channel">
                <div v-if="cell.switchable" class="flex shrink-0 items-center gap-2">
                    <Switch
                        :id="id(cell.channel)"
                        :model-value="cell.enabled"
                        :disabled="busy"
                        @update:model-value="(value) => emit('toggle', type.value, cell.channel, value === true)"
                    />
                    <!--
                        The words carry the state, not the switch's colour (§5.6) — and the
                        channel's name is in the label so a screen reader hears which switch it
                        is on a row that has three.
                    -->
                    <Label :for="id(cell.channel)" class="text-xs font-normal text-muted-foreground">
                        {{ label(cell.channel) }} {{ cell.enabled ? 'on' : 'off' }}
                    </Label>
                </div>

                <Badge v-else variant="outline" class="shrink-0 gap-1 text-muted-foreground">
                    <Lock class="size-3" aria-hidden="true" />
                    {{ label(cell.channel) }} off
                </Badge>
            </template>
        </div>
    </div>
</template>
