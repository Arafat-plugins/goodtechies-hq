<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, FolderKanban, Hash, Megaphone, MessageSquare, Users, UsersRound } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import ConversationAvatar from '@/Components/Messages/ConversationAvatar.vue';
import {
    type ChatListEntry,
    type ConversationSummary,
    type ConversationTypeKey,
    chatListEntries,
    formatListTime,
    messagesHref,
} from '@/Components/Messages/messages';
import { OPEN_PROPS, cachedThread, finishOpening, startOpening } from '@/Components/Messages/opening';
import { personTone } from '@/Components/Messages/people';
import { isOnline } from '@/Components/Messages/presence';
import { isViewingConversation } from '@/Components/Realtime/shell';
import { cn } from '@/lib/utils';

/**
 * The Messages page's chat list, Telegram-style (2026-10-04, client reference): one list,
 * newest activity on top, a round avatar per row, name + time on the first line, the last
 * message + unread badge on the second. Every row is a `Link` to `/messages?conversation=<id>`
 * that fetches only the thread and the lists (`OPEN_PROPS`, a partial visit), never the page.
 *
 * ## The Projects folder
 *
 * Project channels never sit in the main list. They collapse into ONE "Projects" row that opens
 * like a dropdown, and only projects that actually have a message appear inside it. The folder
 * carries the projects' unread total and moves to wherever its newest project message puts it.
 * It opens by itself when the conversation in front of the reader is one of its projects.
 */

const props = defineProps<{
    conversations: ConversationSummary[];
    activeId: number | null;
}>();

const entries = computed<ChatListEntry[]>(() => chatListEntries(props.conversations));

const folderId = `${useId()}-projects`;
const inFolder = computed(() => props.conversations.some((row) => row.type === 'project' && row.id === props.activeId));
const open = ref(inFolder.value);

watch(inFolder, (value) => {
    if (value) {
        open.value = true;
    }
});

const ICONS: Record<ConversationTypeKey, typeof Hash> = {
    team: Users,
    announcement: Megaphone,
    project: Hash,
    group: UsersRound,
    dm: MessageSquare,
    task: MessageSquare,
};

function iconFor(row: ConversationSummary) {
    return row.type === null ? MessageSquare : ICONS[row.type];
}

/** People get a face; a group gets its picture when it has one; everything else an icon circle. */
function hasFace(row: ConversationSummary): boolean {
    return row.type === 'dm' || (row.type === 'group' && Boolean(row.avatar_url));
}

function tone(row: ConversationSummary) {
    return personTone(row.type === 'dm' ? (row.peer_id ?? row.id) : row.id);
}

/**
 * Messaging polish: the conversation open in front of the reader shows no unread pill — its
 * new messages are appearing in the thread beside this list, and they are marked read as soon
 * as they are drawn. A thread in a hidden or unfocused tab counts again.
 */
function unreadShown(row: ConversationSummary): number {
    return isViewingConversation(row.id) ? 0 : row.unread_count;
}

function unreadLabel(row: ConversationSummary): string {
    return row.unread_count === 1 ? '1 unread message' : `${row.unread_count} unread messages`;
}

function folderUnread(rows: ConversationSummary[]): number {
    return rows.reduce((sum, row) => sum + unreadShown(row), 0);
}

function preview(row: ConversationSummary): string {
    const last = row.last_message;

    if (!last) {
        if (row.type === 'group') {
            return row.member_count === 1 ? '1 member' : `${row.member_count ?? 0} members`;
        }

        return '';
    }

    if (row.type === 'dm') {
        return `${last.is_mine ? 'You: ' : ''}${last.excerpt}`;
    }

    return `${last.is_mine ? 'You' : (last.author ?? 'Somebody')}: ${last.excerpt}`;
}

function folderPreview(rows: ConversationSummary[]): string {
    return rows.length > 0 ? `${rows[0].label}: ${preview(rows[0])}` : '';
}

/**
 * Polish 014 — open a chat the way Telegram does. A chat this tab has shown before is drawn
 * from memory the moment it is tapped (no preview, no shimmer for pictures already loaded),
 * and the server's answer to the same tap quietly brings it up to date. A chat never shown
 * yet keeps the opening preview until its thread arrives.
 */
const page = usePage();

function openChat(id: number): void {
    const cached = cachedThread(id);

    const current = (page.props as { active?: { conversation_id?: number } | null }).active;

    if (cached !== null && current?.conversation_id !== id) {
        (page.props as Record<string, unknown>).active = cached;
    }

    startOpening(id);
}
</script>

<template>
    <nav aria-label="Conversations" class="flex min-w-0 flex-col">
        <ul class="flex min-w-0 flex-col gap-0.5">
            <li
                v-for="entry in entries"
                :key="entry.kind === 'chat' ? entry.row.id : 'projects'"
                class="min-w-0"
            >
                <Link
                    v-if="entry.kind === 'chat'"
                    :href="messagesHref(entry.row.id)"
                    preserve-scroll
                    preserve-state
                    :only="OPEN_PROPS"
                    @start="openChat(entry.row.id)"
                    @finish="finishOpening(entry.row.id)"
                    :aria-current="entry.row.id === activeId ? 'page' : undefined"
                    :class="
                        cn(
                            'flex min-w-0 items-center gap-3 rounded-lg px-2 py-2',
                            'focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                            entry.row.id === activeId ? 'bg-brand-tint hover:bg-brand-tint-strong' : 'hover:bg-accent',
                        )
                    "
                >
                    <ConversationAvatar
                        v-if="hasFace(entry.row) || entry.row.type === 'dm'"
                        :label="entry.row.label"
                        :avatar-url="entry.row.avatar_url ?? null"
                        :online="entry.row.type === 'dm' ? isOnline(entry.row.peer_id, entry.row.peer_last_seen_at) : null"
                        class="size-12 text-sm"
                    />
                    <span
                        v-else
                        :class="cn('flex size-12 shrink-0 items-center justify-center rounded-full', tone(entry.row).avatar)"
                        aria-hidden="true"
                    >
                        <component :is="iconFor(entry.row)" class="size-5" />
                    </span>

                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="flex min-w-0 items-baseline gap-2">
                            <span :class="cn('min-w-0 flex-1 truncate text-sm', 'font-medium')">{{ entry.row.label }}</span>
                            <span
                                :class="
                                    cn(
                                        'shrink-0 text-xs tabular-nums',
                                        unreadShown(entry.row) > 0 ? 'font-medium text-primary' : 'text-muted-foreground',
                                    )
                                "
                            >{{ formatListTime(entry.row.last_message?.created_at) }}</span>
                        </span>
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">{{ preview(entry.row) }}</span>
                            <span
                                v-if="unreadShown(entry.row) > 0"
                                class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-medium tabular-nums text-primary-foreground"
                            >
                                {{ entry.row.unread_count }}<span class="sr-only">{{ unreadLabel(entry.row) }}</span>
                            </span>
                        </span>
                    </span>
                </Link>

                <template v-else>
                    <button
                        type="button"
                        :aria-expanded="open"
                        :aria-controls="folderId"
                        class="flex w-full min-w-0 items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                        @click="open = !open"
                    >
                        <span
                            class="flex size-12 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                            aria-hidden="true"
                        >
                            <FolderKanban class="size-5" />
                        </span>

                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="flex min-w-0 items-baseline gap-2">
                                <span :class="cn('min-w-0 flex-1 truncate text-sm', 'font-medium')">Projects</span>
                                <span
                                    :class="
                                        cn(
                                            'shrink-0 text-xs tabular-nums',
                                            folderUnread(entry.rows) > 0 ? 'font-medium text-primary' : 'text-muted-foreground',
                                        )
                                    "
                                >{{ formatListTime(entry.latestAt) }}</span>
                            </span>
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">{{ folderPreview(entry.rows) }}</span>
                                <span
                                    v-if="folderUnread(entry.rows) > 0"
                                    class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-medium tabular-nums text-primary-foreground"
                                >
                                    {{ folderUnread(entry.rows) }}<span class="sr-only">{{ folderUnread(entry.rows) === 1 ? '1 unread message in projects' : `${folderUnread(entry.rows)} unread messages in projects` }}</span>
                                </span>
                            </span>
                        </span>

                        <ChevronDown
                            :class="cn('size-4 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none', open && 'rotate-180')"
                            aria-hidden="true"
                        />
                    </button>

                    <ul v-show="open" :id="folderId" class="flex min-w-0 flex-col gap-0.5 pl-4">
                        <li v-for="row in entry.rows" :key="row.id" class="min-w-0">
                            <Link
                                :href="messagesHref(row.id)"
                                preserve-scroll
                                preserve-state
                                :only="OPEN_PROPS"
                                @start="openChat(row.id)"
                                @finish="finishOpening(row.id)"
                                :aria-current="row.id === activeId ? 'page' : undefined"
                                :class="
                                    cn(
                                        'flex min-w-0 items-center gap-3 rounded-lg px-2 py-2',
                                        'focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                                        row.id === activeId ? 'bg-brand-tint hover:bg-brand-tint-strong' : 'hover:bg-accent',
                                    )
                                "
                            >
                                <span
                                    :class="cn('flex size-10 shrink-0 items-center justify-center rounded-full', tone(row).avatar)"
                                    aria-hidden="true"
                                >
                                    <component :is="iconFor(row)" class="size-4" />
                                </span>

                                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span class="flex min-w-0 items-baseline gap-2">
                                        <span :class="cn('min-w-0 flex-1 truncate text-sm', 'font-medium')">{{ row.label }}</span>
                                        <span
                                            :class="
                                                cn(
                                                    'shrink-0 text-xs tabular-nums',
                                                    unreadShown(row) > 0 ? 'font-medium text-primary' : 'text-muted-foreground',
                                                )
                                            "
                                        >{{ formatListTime(row.last_message?.created_at) }}</span>
                                    </span>
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">{{ preview(row) }}</span>
                                        <span
                                            v-if="unreadShown(row) > 0"
                                            class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-medium tabular-nums text-primary-foreground"
                                        >
                                            {{ row.unread_count }}<span class="sr-only">{{ unreadLabel(row) }}</span>
                                        </span>
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </template>
            </li>
        </ul>
    </nav>
</template>
