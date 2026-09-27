<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Video } from '@lucide/vue';
import { computed } from 'vue';
import type { Meeting } from '@/Components/Meetings/meetings';
import { meetingRoutes, meetingTime, meetingTimeRange } from '@/Components/Meetings/meetings';
import { statusToneClass } from '@/Components/StatusBadge.vue';
import { cn } from '@/lib/utils';

/**
 * One meeting inside a calendar cell: a tinted strip with the time and the title on it.
 *
 * ## It is a link, and its accessible name is a whole sentence
 *
 * A chip is four or five visible characters wide at 360 px, so what it *looks* like cannot be
 * what it *says*. The `aria-label` carries the title, the state in words and the full time range
 * — *"Stand-up, Scheduled, 10:00 – 10:30"* — and the truncation is a visual affordance on top of
 * a complete name, never the name itself. It is the same arrangement `TaskCalendar`'s span bars
 * settled on at the Phase 2 close-out.
 *
 * ## The tint is `statusToneClass`, and it is never alone
 *
 * The fill comes from the same map a `StatusBadge` uses, so there is one mapping of a state to a
 * colour in the application and not two. Under §1.4's deuteranope measurement two of the eight
 * status hues sit ΔE 0.16 apart, so a tinted rectangle with no words says nothing: a **cancelled
 * meeting prints "Cancelled"** in the chip itself, and every chip's accessible name says its
 * state regardless (§5.6).
 *
 * A Meet link shows as a small camera glyph, `aria-hidden` — it is repeated in the name.
 */

const props = defineProps<{ meeting: Meeting }>();

const name = computed(
    () =>
        `${props.meeting.title}, ${props.meeting.state_label}, ` +
        `${meetingTimeRange(props.meeting.start_at, props.meeting.end_at)}` +
        (props.meeting.has_meet_link ? ', has a Meet link' : ''),
);
</script>

<template>
    <Link
        :href="meetingRoutes(meeting.id).show"
        :aria-label="name"
        :title="name"
        :class="
            cn(
                'flex w-full min-w-0 items-center gap-1 rounded-sm border px-1.5 py-0.5 text-left text-xs',
                'outline-none transition-colors focus-visible:ring-3 focus-visible:ring-ring hover:brightness-95 dark:hover:brightness-110',
                statusToneClass(meeting.state),
            )
        "
    >
        <span aria-hidden="true" class="shrink-0 font-medium tabular-nums">
            {{ meetingTime(meeting.start_at) }}
        </span>
        <Video v-if="meeting.has_meet_link" class="size-3 shrink-0" aria-hidden="true" />
        <span aria-hidden="true" class="min-w-0 flex-1 truncate">{{ meeting.title }}</span>
        <!-- Cancelled is the one state the strip says in words: grey-plus-strikethrough is not
             enough on its own, and this is the state somebody must not misread. -->
        <span v-if="meeting.state === 'cancelled'" aria-hidden="true" class="shrink-0 font-medium">
            · Cancelled
        </span>
    </Link>
</template>
