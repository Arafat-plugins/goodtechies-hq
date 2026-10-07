<script setup lang="ts">
import { Globe } from '@lucide/vue';
import type { ActivitySite, ActivitySiteKind } from '@/Components/Activity/activity';
import EmptyState from '@/Components/EmptyState.vue';
import { formatDuration } from '@/Components/Timer/timer';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

/**
 * The day's time per website domain. Rows that are not a website carry a word instead of a host.
 */
defineProps<{
    sites: ActivitySite[];
    /** Polish 031: how many websites were left out for being under the minimum. */
    hidden?: { count: number; seconds: number; min_minutes: number };
}>();

const KIND_WORDS: Record<Exclude<ActivitySiteKind, 'site'>, string> = {
    // Polish 031: Chrome was not the window in front — the person was in another program
    // (Word, Photoshop, File Explorer…). A Chrome extension cannot see which one.
    other_app: 'Outside Chrome (other programs)',
    browser_internal: 'Browser pages',
    private: 'Private window',
};

function siteName(site: ActivitySite): string {
    return site.kind === 'site' ? site.host : KIND_WORDS[site.kind];
}

const headClass = 'text-xs uppercase text-muted-foreground';
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Websites</CardTitle>
        </CardHeader>
        <CardContent class="min-w-0">
            <Table v-if="sites.length > 0">
                <TableHeader>
                    <TableRow>
                        <TableHead :class="headClass">Website</TableHead>
                        <TableHead :class="[headClass, 'text-right']">Time</TableHead>
                        <TableHead :class="[headClass, 'text-right']">Share</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="site in sites" :key="`${site.kind}-${site.host}`">
                        <TableCell class="max-w-0 truncate font-medium" :title="siteName(site)">
                            {{ siteName(site) }}
                        </TableCell>
                        <TableCell class="text-right tabular-nums">{{ formatDuration(site.seconds) }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ site.share }} %</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState v-else :icon="Globe" title="No website time recorded for this day" />

            <div class="mt-3 flex flex-col gap-1 text-xs text-muted-foreground">
                <p v-if="hidden && hidden.count > 0">
                    {{ hidden.count }} {{ hidden.count === 1 ? 'website' : 'websites' }} under {{ hidden.min_minutes }} minutes not shown
                    ({{ formatDuration(hidden.seconds) }} in all).
                </p>
                <p v-if="sites.some((site) => site.kind === 'other_app')">
                    “Outside Chrome” is time the computer was in use but Chrome was not the window in front — another
                    program was. The timer extension lives inside Chrome, so it cannot see which program that was.
                </p>
            </div>
        </CardContent>
    </Card>
</template>
