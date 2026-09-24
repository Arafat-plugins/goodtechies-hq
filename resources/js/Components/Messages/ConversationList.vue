<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Hash, Megaphone, MessageSquare, Users } from '@lucide/vue';
import { computed } from 'vue';
import type { ConversationSummary, ConversationTypeKey } from '@/Components/Messages/messages';
import { CONVERSATION_GROUPS, messagesHref } from '@/Components/Messages/messages';
import { cn } from '@/lib/utils';

/**
 * The Messages page's left rail: Team, Announcements, Projects, Direct.
 *
 * ## Links, not buttons
 *
 * Every row is a `Link` to `/messages?conversation=<id>`, so a thread is bookmarkable, the back
 * button works, and a colleague can be sent one — the same reason the Tasks view switcher is
 * links and the Notification Center's tab is a query parameter (§5.10). `preserve-scroll` keeps
 * the rail where it was when the thread beside it changes.
 *
 * ## Unread is never a dot alone
 *
 * An unread row carries a COUNT in a pill, `font-medium` on the label, and an `sr-only` sentence
 * spelling it out. The selected row carries `aria-current="page"`, the brand tint and a rail on
 * its left edge — the shell's own active treatment (DESIGN.md §1.7). Neither state is a hue on
 * its own (DESIGN.md §5.6).
 *
 * ## The rhythm
 *
 * Two lines per row, `py-1`, groups a hairline apart. The rail is the thing somebody scans
 * twenty times an hour, so it is dense on purpose; the label truncates and the excerpt
 * truncates, because a row that wraps is a row whose neighbours move.
 *
 * There is no message count per person anywhere here, and there is not going to be: what the
 * row shows is who spoke last and how much of it this reader has not seen. Part H.
 */

const props = defineProps<{
    conversations: ConversationSummary[];
    activeId: number | null;
}>();

const ICONS: Record<ConversationTypeKey, typeof Hash> = {
    team: Users,
    announcement: Megaphone,
    project: Hash,
    dm: MessageSquare,
    task: MessageSquare,
};

/** The plan's order — Team, Announcements, Projects, Direct — with empty groups dropped. */
const groups = computed(() =>
    CONVERSATION_GROUPS.map((name) => ({
        name,
        rows: props.conversations.filter((row) => row.group === name),
    })).filter((group) => group.rows.length > 0),
);

function iconFor(row: ConversationSummary) {
    return row.type === null ? MessageSquare : ICONS[row.type];
}

function unreadLabel(row: ConversationSummary): string {
    return row.unread_count === 1 ? '1 unread message' : `${row.unread_count} unread messages`;
}
</script>

<template>
    <nav aria-label="Conversations" class="flex min-w-0 flex-col gap-3">
        <section v-for="group in groups" :key="group.name" class="flex min-w-0 flex-col gap-0.5">
            <h2 class="px-2 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {{ group.name }}
            </h2>

            <ul class="flex min-w-0 flex-col">
                <li v-for="row in group.rows" :key="row.id" class="min-w-0">
                    <Link
                        :href="messagesHref(row.id)"
                        preserve-scroll
                        :aria-current="row.id === activeId ? 'page' : undefined"
                        :class="
                            cn(
                                'flex min-w-0 items-center gap-2 rounded-md border-l-2 py-1 pr-2 pl-1.5 text-sm',
                                'focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                                row.id === activeId
                                    ? 'border-brand bg-brand-tint font-medium hover:bg-brand-tint-strong'
                                    : 'border-transparent hover:bg-accent',
                            )
                        "
                    >
                        <component
                            :is="iconFor(row)"
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />

                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                :class="cn('truncate', row.unread_count > 0 && 'font-medium')"
                            >{{ row.label }}</span>
                            <span v-if="row.last_message" class="truncate text-xs text-muted-foreground">
                                {{ row.last_message.is_mine ? 'You' : (row.last_message.author ?? 'Somebody') }}:
                                {{ row.last_message.excerpt }}
                            </span>
                        </span>

                        <span
                            v-if="row.unread_count > 0"
                            class="shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-xs font-medium tabular-nums text-primary-foreground"
                        >
                            {{ row.unread_count > 99 ? '99+' : row.unread_count }}
                            <span class="sr-only">{{ unreadLabel(row) }}</span>
                        </span>
                    </Link>
                </li>
            </ul>
        </section>
    </nav>
</template>
