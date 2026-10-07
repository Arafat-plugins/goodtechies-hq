<script setup lang="ts">
import { Video } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/Components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import type { Meeting } from '@/Components/Meetings/meetings';

/**
 * *Join* — the one control that leaves the application.
 *
 * It exists only when there is a link, and it is an `<a>` rather than a button because it goes
 * somewhere. `target="_blank"` with `rel="noopener noreferrer"`, because a Meet tab replacing
 * the page somebody was reading is not what they asked for, and because an opened tab must not
 * get a handle back on this one.
 *
 * **The accessible name always names the meeting.** A page with six *Join* links is six
 * identically-named controls to anybody listening to it rather than looking at it, so the
 * `aria-label` is *Join "Buffalo Modular — quarterly review"* while the visible label stays the
 * one word a wide row has space for. In `compact` mode the word disappears and the icon is all
 * that is left, which is the case the tooltip is for — an icon-only control is named and
 * tooltipped or it is not shipped.
 *
 * A cancelled meeting gets no Join. The room may well still be open; going to it is not the
 * thing to do.
 */

const props = withDefaults(
    defineProps<{
        meeting: Meeting;
        /** Icon only — for a calendar chip, where there is no room for a word. */
        compact?: boolean;
    }>(),
    { compact: false },
);

const name = computed(() => `Join “${props.meeting.title}”`);

/**
 * Polish 030: pressing Join answers "Going" for you, so the room does not say "No answer yet"
 * beside somebody who joined. Sent alongside the link opening in its new tab; a failure is
 * silent — the meeting still opens.
 */
function markJoined(): void {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    const token = match ? decodeURIComponent(match[1]) : '';

    void fetch(`/meetings/${props.meeting.id}/join`, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
        .then((response) => {
            if (response.ok) {
                router.reload({ only: ['meeting', 'meetings'] });
            }
        })
        .catch(() => undefined);
}
</script>

<template>
    <TooltipProvider v-if="meeting.has_meet_link && meeting.state !== 'cancelled'" :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    as="a"
                    :href="meeting.meet_link ?? undefined"
                    target="_blank"
                    rel="noopener noreferrer"
                    :variant="compact ? 'ghost' : 'outline'"
                    :size="compact ? 'icon-xs' : 'sm'"
                    :aria-label="name"
                    @click="markJoined"
                >
                    <Video aria-hidden="true" />
                    <span v-if="!compact">Join</span>
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{ name }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
