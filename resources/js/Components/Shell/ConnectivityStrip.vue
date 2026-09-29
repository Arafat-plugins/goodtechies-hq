<script setup lang="ts">
import { CloudOff, WifiOff } from '@lucide/vue';
import { computed } from 'vue';
import { connectivity } from '@/lib/net';

/**
 * Reliability slice 2a: one line, for every background reader at once, when goodERP cannot be
 * reached — instead of a bell that quietly stops changing and a board that quietly goes stale.
 *
 * Mounted once, by `ShellLive.vue`, beside the session dialog. It draws `lib/net.ts`'s
 * `connectivity` and nothing else: the pollers have already backed off by themselves, and they
 * retry the moment the browser says the network is back. It disappears on the first answer.
 *
 * A status, not an alert: `role="status"` + `aria-live="polite"` is read once, when it changes,
 * without interrupting. An icon and words, never a colour alone. Nothing is blurred or locked —
 * that is only for a signed-out session; here the page stays fully usable, and a Save that cannot
 * reach the server says so in its own toast.
 */

const message = computed<string | null>(() => {
    if (connectivity.value === 'offline') {
        return "You're offline. goodERP will catch up when your connection is back.";
    }

    if (connectivity.value === 'unreachable') {
        return "Can't reach goodERP right now. Retrying…";
    }

    return null;
});

const icon = computed(() => (connectivity.value === 'offline' ? WifiOff : CloudOff));
</script>

<template>
    <div
        role="status"
        aria-live="polite"
        class="sticky top-16 z-20"
        data-testid="connectivity-strip"
        :data-state="connectivity"
    >
        <p
            v-if="message !== null"
            class="mb-4 flex items-center gap-2 rounded-lg border border-border bg-muted px-3 py-2 text-sm text-foreground shadow-flat"
        >
            <component :is="icon" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span>{{ message }}</span>
        </p>
    </div>
</template>
