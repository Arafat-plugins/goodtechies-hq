<script setup lang="ts">
/**
 * The chime switch — reliability slice 5.
 *
 * On by default (12-77); while on, a short chime plays when the bell's unread count or the Messages unread
 * total goes up while goodERP is open (`lib/sound.ts` owns when). It is stored **per browser**,
 * under `hq.sound.<userId>`, because there is no per-person preference store on the server and
 * adding one would need a migration — hence the hint's "Saved on this device".
 *
 * *Play a test sound* is also the gesture a browser wants before it will play audio at all, so
 * pressing it once is what makes the background chime audible on a strict browser.
 */
import { usePage } from '@inertiajs/vue3';
import { Volume2 } from '@lucide/vue';
import { computed, useId } from 'vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { playNotification, readSoundEnabled, writeSoundEnabled } from '@/lib/sound';

const page = usePage();
const uid = useId();
const switchId = `${uid}-sound`;
const hintId = `${uid}-sound-hint`;

const userId = computed<number | null>(() => page.props.auth.user?.id ?? null);

const enabled = computed<boolean>({
    get: () => (userId.value === null ? false : readSoundEnabled(userId.value)),
    set: (on) => {
        if (userId.value !== null) {
            writeSoundEnabled(userId.value, on);
        }
    },
});
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Sound</CardTitle>
            <CardDescription>A cue for when goodERP is open behind another window.</CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="switchId">Play a sound for new notifications and messages</Label>
                <div class="flex min-w-0 items-center gap-3">
                    <Switch :id="switchId" v-model="enabled" :aria-describedby="hintId" />
                    <!-- The words carry the state, not the switch's colour (DESIGN.md §5.6). -->
                    <span class="min-w-0 text-sm">{{ enabled ? 'On' : 'Off' }}</span>
                </div>
                <p :id="hintId" class="text-xs text-muted-foreground">
                    On by default. Plays a sound when something new arrives while goodERP is open. Saved on this device.
                </p>
            </div>
            <div>
                <Button type="button" variant="outline" @click="playNotification()">
                    <Volume2 aria-hidden="true" />
                    Play a test sound
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
