<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import type { NotificationRow } from '@/Components/Notifications/notifications';
import {
    exactTime,
    markRead,
    openNotification,
    priorityMark,
    relativeTime,
} from '@/Components/Notifications/notifications';
import { cn } from '@/lib/utils';

/**
 * One notification, in the bell's popover and in the Center. One component, because they are
 * one row: the same sentence, the same deep link, the same two states.
 *
 * **The sentence is the server's.** `summary` arrives already written and already pluralised —
 * "12 new comments in …" is composed by `NotificationType::summary()`, where the count lives.
 * Nothing here reads `count` to build a line, and nothing re-pluralises: `count` is printed
 * nowhere on this row, because it is already spoken inside `summary`.
 *
 * **Read and unread differ by weight and by a mark, never by colour.** The dot is neutral and
 * carries an `sr-only` word, the summary is `font-medium` while unread, and the *Mark read*
 * button exists only on an unread row — three carriers, none of them a hue (DESIGN.md §5.6).
 */

const props = withDefaults(
    defineProps<{
        row: NotificationRow;
        /** The popover's denser spacing. The content is identical. */
        compact?: boolean;
    }>(),
    { compact: false },
);

const priority = computed(() => priorityMark(props.row.priority));

/**
 * When it last happened, not when the group started: a busy thread that grew a minute ago is a
 * minute old to a reader, whatever time its first comment landed. They are the same value on
 * every ungrouped row.
 */
const when = computed(() => props.row.updated_at ?? props.row.created_at);
</script>

<template>
    <li
        :class="
            cn(
                'relative flex items-start gap-2 border-b last:border-b-0',
                compact ? 'px-3 py-2.5' : 'px-4 py-3',
                row.is_read ? '' : 'bg-muted/40',
            )
        "
    >
        <!-- The unread mark: a shape and a word, holding its space when read so nothing shifts. -->
        <span class="mt-1.5 flex size-2 shrink-0 items-center justify-center">
            <span v-if="!row.is_read" class="size-2 rounded-full bg-foreground">
                <span class="sr-only">Unread</span>
            </span>
        </span>

        <component
            :is="row.link === null ? 'div' : 'a'"
            :href="row.link ?? undefined"
            :class="
                cn(
                    'min-w-0 flex-1 rounded-md outline-none',
                    row.link === null
                        ? ''
                        : 'cursor-pointer focus-visible:ring-3 focus-visible:ring-ring/50',
                )
            "
            @click="openNotification(row, $event)"
        >
            <p :class="cn('text-sm break-words', row.is_read ? 'text-muted-foreground' : 'font-medium')">
                {{ row.summary }}
            </p>

            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                <!-- Priority: an arrow by rank and the word beside it. Never a tint. -->
                <span
                    v-if="priority"
                    :class="cn('inline-flex items-center gap-1', priority.loud && 'font-medium text-foreground')"
                >
                    <component :is="priority.icon" class="size-3" aria-hidden="true" />
                    {{ priority.label }}
                </span>

                <span v-if="when" :title="exactTime(when)">{{ relativeTime(when) }}</span>

                <span v-if="row.actor" class="min-w-0 truncate">{{ row.actor.name }}</span>
            </p>
        </component>

        <!--
            The write, as its own control rather than inside the link: a button nested in an
            anchor is not a tab stop anybody can predict. Its accessible name names the row, so
            twenty of them in a list are twenty different buttons to a screen reader.
        -->
        <button
            v-if="!row.is_read"
            type="button"
            :aria-label="`Mark read: ${row.summary}`"
            class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-accent-foreground focus-visible:ring-3 focus-visible:ring-ring/50"
            @click="markRead(row.id)"
        >
            <Check class="size-4" aria-hidden="true" />
        </button>
    </li>
</template>
