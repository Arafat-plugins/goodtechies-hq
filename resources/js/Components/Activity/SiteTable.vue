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
}>();

const KIND_WORDS: Record<Exclude<ActivitySiteKind, 'site'>, string> = {
    other_app: 'Other apps',
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
        </CardContent>
    </Card>
</template>
