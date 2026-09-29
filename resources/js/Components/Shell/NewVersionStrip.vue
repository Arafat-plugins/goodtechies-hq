<script setup lang="ts">
import { RefreshCw } from '@lucide/vue';
import { Button } from '@/Components/ui/button';
import { newVersion } from '@/lib/version';

/**
 * Reliability slice 3: goodERP was updated while this tab was open.
 *
 * Mounted once, by `ShellLive.vue`, beside the connectivity strip. A background read met the new
 * build and was told so (`lib/version.ts`); the polls have stopped, and nothing reloads until the
 * person chooses — so a half-typed form is never thrown away by a deploy. A status, not an alert:
 * read once when it appears, an icon and words, never a colour alone.
 */

function reloadNow(): void {
    window.location.reload();
}
</script>

<template>
    <div role="status" aria-live="polite" class="sticky top-16 z-20" data-testid="new-version-strip">
        <div
            v-if="newVersion"
            class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-border bg-muted px-3 py-2 text-sm text-foreground shadow-flat"
        >
            <RefreshCw class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span class="min-w-0 flex-1">A new version of goodERP is ready.</span>
            <Button type="button" size="sm" variant="outline" @click="reloadNow">Reload now</Button>
        </div>
    </div>
</template>
