<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Hash,
    Info,
    Megaphone,
    MessageSquare,
    MessagesSquare,
    PanelRightClose,
    RefreshCw,
    Users,
} from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import ConversationContextPanel from '@/Components/Messages/ConversationContextPanel.vue';
import MessagesRail from '@/Components/Messages/MessagesRail.vue';
import MessageThread from '@/Components/Messages/MessageThread.vue';
import {
    RAIL_POLL_MS,
    THREAD_POLL_MS,
    conversationChannel,
    liveTransportIcon,
    liveTransportLabel,
    liveTransportWord,
    useLiveRefresh,
    useLiveStatus,
} from '@/Components/Messages/live';
import type {
    AnnouncementBanner,
    ConversationSummary,
    ConversationTypeKey,
    MessagePerson,
    ThreadPayload,
} from '@/Components/Messages/messages';
import { conversationRoutes, formatMessageTime, messagesHref } from '@/Components/Messages/messages';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * Messages: the team channel, the announcements channel, the project channels and this
 * person's DMs, as a three-column workspace — rail, conversation, context.
 *
 * **One shared page on one route, not three.** Whose mail a thread is belongs to the person and
 * not to the shell they are in, which is why `routes/shared.php` carries the endpoints and why
 * this picks its layout from the viewer's own surface, exactly as `Pages/Shared/Notifications`
 * and `Pages/Shared/Leave` do. The Accountant never reaches it: the route group is gated on
 * `messages.use` and they hold none — which is "the Accountant has no messaging routes", said
 * as a permission rather than as a role.
 *
 * **Which thread is open lives in the URL** (`?conversation=`), so a thread is bookmarkable, the
 * back button works, and a notification can deep-link to one. The server picks the team channel
 * when nothing is asked for, and — deliberately — also when a stale id is asked for: a bookmark
 * to a project you have since left is not an error screen.
 *
 * ## The three columns share one row, and only the log scrolls
 *
 * From `lg` the workspace is one fixed-height row measured in `svh`, not `vh`, so a phone's
 * address bar cannot slice the composer off the bottom of it. The page header stays put, the
 * page itself does not scroll, and the one thing that does is the message list. Every column
 * carries `min-w-0`, which is what makes a 300-character URL wrap inside a bubble instead of
 * widening the grid it sits in.
 *
 * Below `lg` the page scrolls normally and there is **one** column: `/messages` is the rail and
 * `/messages?conversation=<id>` is the thread, with a Back control between them. The pane a
 * phone is on is therefore in the URL like everything else here, rather than in a piece of
 * component state that the next `Link` click would throw away.
 *
 * ## How it keeps itself current
 *
 * `MessageThread` keeps its own thread current through `Messages/live.ts` — a socket where
 * there is one, a ten-second read where there is not — so this page is responsible only for
 * what `MessageThread` cannot see: the rail's last-message lines, its unread pills and the
 * announcement banner. Those are Inertia props, so they are refreshed with a **partial**
 * reload of exactly those two props, every 15 seconds and again whenever the open thread
 * pings. The Refresh control in the conversation header still reaches the thread through the
 * one method it exposes, which is what that seam is for.
 */

defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

const props = defineProps<{
    conversations: ConversationSummary[];
    active: ThreadPayload | null;
    announcement: AnnouncementBanner | null;
    people: MessagePerson[];
}>();

const page = usePage();

const viewerId = computed(() => page.props.auth.user?.id ?? null);

/**
 * How much of the viewport the shell has already spent, before the workspace gets the rest.
 *
 * Top bar, page padding, the page header and the gap under it — plus, on the one surface that
 * carries it, the sticky timer bar. `canTrackTime` is the same server-answered fact the layout
 * mounts `TimerBar` from, so the two cannot disagree; deriving it from the role here would be
 * the second copy of a policy decision that this repo has already been bitten by twice.
 */
const workspaceHeight = computed(() =>
    page.props.auth.user?.canTrackTime === true
        ? 'lg:h-[calc(100svh-19.5rem)]'
        : 'lg:h-[calc(100svh-15.5rem)]',
);

const activeId = computed(() => props.active?.conversation_id ?? null);

const routes = computed(() =>
    props.active === null ? null : conversationRoutes(props.active.conversation_id),
);

const description = computed(() => {
    const unread = props.conversations.reduce((total, row) => total + row.unread_count, 0);

    if (unread === 0) {
        return 'Everything here is read.';
    }

    return unread === 1 ? '1 unread message.' : `${unread} unread messages.`;
});

/* ------------------------------------------------------------------ the one-column pane */

/**
 * Below `lg` the rail and the thread are two screens, and which one you are on is the URL.
 *
 * `?conversation=` present means the thread; absent means the rail. The server still renders a
 * thread either way — it picks the team channel when nothing is asked for — so this changes
 * nothing about what is fetched, only about what a narrow screen draws.
 */
const showsThread = computed(() => {
    const [, query = ''] = page.url.split('?');

    return new URLSearchParams(query).has('conversation');
});

/* ------------------------------------------------------------------ the context panel */

/** From `xl` the panel is a column; below it, and on a phone, the same panel is a Sheet. */
const isWide = useMediaQuery('(min-width: 80rem)');

const panelOpen = ref(false);

const asColumn = computed(() => panelOpen.value && isWide.value);
const asSheet = computed(() => panelOpen.value && !isWide.value);

/** A conversation this reader may not open has no details to show either. */
watch(activeId, () => {
    panelOpen.value = false;
});

/* ------------------------------------------------------------------ the thread's seam */

const threadEl = ref<InstanceType<typeof MessageThread> | null>(null);

/**
 * The same `refresh()` a realtime transport will call.
 *
 * The header lives here rather than inside `MessageThread` — this screen needs the context
 * toggle and a Back control in the same bar — so the manual refresh reaches the thread through
 * the one method it exposes, which is exactly what that seam is for.
 */
function refresh(): void {
    threadEl.value?.refresh();
}

/* ------------------------------------------------------------------ keeping the rail current */

/**
 * The rail, the unread pills and the announcement banner are Inertia props, so they refresh the
 * Inertia way: a **partial** reload of exactly the two props that can change without a
 * navigation.
 *
 * `active` is deliberately NOT in the list. It is the open thread's payload, and reloading it
 * would hand `MessageThread` a new object — resetting its unread line, re-announcing, and
 * possibly moving the scroll — for a thread that is already keeping itself current through its
 * own channel. `people` is the @mention and new-DM roster and does not move on a message.
 * `preserveState` keeps this page's own component state (which thread is open, the details
 * panel, the thread's composer) and `preserveScroll` keeps the rail where the reader left it.
 */
const railReloading = ref(false);

function reloadRail(): void {
    if (railReloading.value) {
        return;
    }

    railReloading.value = true;

    // `preserveState` and `preserveScroll` are not passed because `router.reload()` forces both
    // to true itself (`doReload`), and Inertia's own types refuse them here to say so. They are
    // the reason this is a `reload` and not a `visit`.
    router.reload({
        only: ['conversations', 'announcement'],
        onFinish: () => {
            railReloading.value = false;
        },
    });
}

/**
 * **There is no per-user inbox channel**, so the rail is poll-only — `null` is that mode, said
 * out loud. A message in a conversation this reader does not have open therefore takes up to
 * `RAIL_POLL_MS` to reach the rail even on a socket build; giving it a socket needs a channel
 * that does not exist yet and is out of this slice.
 */
useLiveRefresh(null, reloadRail, { intervalMs: RAIL_POLL_MS });

const activeChannel = computed(() => conversationChannel(activeId.value));

/**
 * …and one refresh whenever the OPEN thread pings, so the row the reader is looking at is not
 * the last thing on screen to know. `poll: false` because the 15-second poll above already
 * covers this list: two timers for one rail would be two requests for one answer.
 */
useLiveRefresh(activeChannel, reloadRail, { poll: false });

/**
 * What the open thread is doing, for the little indicator in its header. This reads the
 * transport; it subscribes to nothing and starts no timer of its own — the thread below owns
 * the refreshing and this owns only the sentence about it.
 */
const threadTransport = useLiveStatus(activeChannel);

const liveWord = computed(() => liveTransportWord(threadTransport.value, THREAD_POLL_MS));
const liveLabel = computed(() => liveTransportLabel(threadTransport.value, THREAD_POLL_MS));
const liveIcon = computed(() => liveTransportIcon(threadTransport.value));

/* ------------------------------------------------------------------ presentation */

const ICONS: Record<ConversationTypeKey, typeof Hash> = {
    team: Users,
    announcement: Megaphone,
    project: Hash,
    dm: MessageSquare,
    task: MessageSquare,
};

/**
 * One line about who can read what is on screen.
 *
 * Each of these is a sentence about `ConversationPolicy`, not a guess about membership: a
 * project channel is readable by whoever may see the project, and nothing is synced anywhere.
 */
const LINES: Record<ConversationTypeKey, string> = {
    team: 'The whole agency can read this.',
    announcement: 'Read by everybody. Only an Admin can post here.',
    project: 'Everybody who can see this project can read and post here.',
    task: 'Everybody who can see this task can read and post here.',
    dm: 'Just the two of you.',
};

const activeIcon = computed(() =>
    props.active?.type == null ? MessagesSquare : ICONS[props.active.type],
);

const activeLine = computed(() =>
    props.active?.type == null ? null : LINES[props.active.type],
);
</script>

<template>
    <Head title="Messages" />

    <PageShell title="Messages" :description="description">
        <!--
            One fixed-height row from `lg`, measured in `svh`. The banner takes what it needs and
            the workspace takes the rest, so an announcement never steals the height the thread
            was going to use for messages.
        -->
        <div :class="cn('flex min-w-0 flex-col gap-3 lg:min-h-[30rem]', workspaceHeight)">
            <!--
                The announcement banner the plan asks for. It is **not dismissed by a button**:
                it goes quiet when the announcements channel is read, which is one state and not
                two. `Megaphone` plus the word "Announcement" carry it; the border is third.
                It is a link into that channel, which is navigation — not a dismissal.
            -->
            <Link
                v-if="announcement"
                :href="messagesHref(announcement.conversation_id)"
                preserve-scroll
                :class="
                    cn(
                        'flex min-w-0 shrink-0 items-start gap-3 rounded-lg border bg-card p-3 shadow-raised',
                        'hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                        announcement.is_unread && 'border-primary',
                    )
                "
            >
                <Megaphone class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="flex min-w-0 flex-wrap items-baseline gap-x-2 text-xs text-muted-foreground">
                        <span class="font-medium text-foreground">
                            Announcement{{ announcement.is_unread ? ' — unread' : '' }}
                        </span>
                        <span>{{ announcement.author ?? 'Somebody' }}</span>
                        <span>{{ formatMessageTime(announcement.created_at) }}</span>
                    </span>
                    <span class="line-clamp-2 min-w-0 text-sm break-words">
                        {{ announcement.body }}
                    </span>
                </span>
            </Link>

            <div
                :class="
                    cn(
                        'grid min-w-0 gap-3 lg:min-h-0 lg:flex-1',
                        'lg:grid-cols-[18rem_minmax(0,1fr)]',
                        asColumn && 'xl:grid-cols-[18rem_minmax(0,1fr)_18rem]',
                    )
                "
            >
                <!--
                    The rail. It IS the page below `lg` — the thread replaces it there rather
                    than sitting under it — and a column beside the thread from `lg`.
                -->
                <Card
                    :class="
                        cn(
                            'min-h-0 min-w-0 gap-0 overflow-hidden py-0 shadow-raised',
                            'lg:flex',
                            showsThread && 'hidden',
                        )
                    "
                >
                    <div class="flex min-h-0 min-w-0 flex-1 flex-col p-3">
                        <MessagesRail
                            :conversations="conversations"
                            :active-id="activeId"
                            :people="people"
                        />
                    </div>
                </Card>

                <!-- The conversation: a header that stays put, the log, the composer. -->
                <Card
                    :class="
                        cn(
                            'min-h-0 min-w-0 gap-0 overflow-hidden py-0 shadow-raised',
                            'lg:flex',
                            !showsThread && 'hidden',
                        )
                    "
                >
                    <template v-if="active && routes">
                        <div class="flex min-w-0 shrink-0 items-center gap-2 border-b p-3">
                            <Button
                                as-child
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0 lg:hidden"
                            >
                                <Link :href="messagesHref()" aria-label="Back to conversations">
                                    <ArrowLeft aria-hidden="true" />
                                </Link>
                            </Button>

                            <component
                                :is="activeIcon"
                                class="hidden size-4 shrink-0 text-muted-foreground lg:block"
                                aria-hidden="true"
                            />

                            <div class="min-w-0 flex-1">
                                <h2 class="min-w-0 truncate text-sm font-medium">
                                    {{ active.label }}
                                </h2>
                                <p v-if="activeLine" class="min-w-0 truncate text-xs text-muted-foreground">
                                    {{ activeLine }}
                                </p>
                            </div>

                            <!--
                                How this thread is keeping itself current. A word and a mark,
                                not a widget: a screen reader always hears the whole sentence
                                and from `sm` the short form is on screen, so the fact is never
                                carried by the icon or by colour alone. It says "Live" only
                                when there is genuinely a socket behind it — on the client's
                                own polling build it says how often it is checking instead.
                            -->
                            <span
                                class="inline-flex min-w-0 shrink-0 items-center gap-1 text-xs text-muted-foreground"
                                :title="liveLabel"
                            >
                                <component :is="liveIcon" class="size-3.5 shrink-0" aria-hidden="true" />
                                <span aria-hidden="true" class="hidden sm:inline">{{ liveWord }}</span>
                                <span class="sr-only">{{ liveLabel }}</span>
                            </span>

                            <TooltipProvider :delay-duration="150">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            class="shrink-0"
                                            aria-label="Refresh this conversation"
                                            @click="refresh"
                                        >
                                            <RefreshCw aria-hidden="true" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Refresh this conversation</TooltipContent>
                                </Tooltip>

                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            class="shrink-0"
                                            :aria-expanded="panelOpen"
                                            :aria-label="
                                                panelOpen
                                                    ? 'Hide conversation details'
                                                    : 'Show conversation details'
                                            "
                                            @click="panelOpen = !panelOpen"
                                        >
                                            <PanelRightClose v-if="panelOpen" aria-hidden="true" />
                                            <Info v-else aria-hidden="true" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {{ panelOpen ? 'Hide details' : 'Show details' }}
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>

                        <div class="flex min-h-0 min-w-0 flex-1 flex-col p-3">
                            <!--
                                `lg:max-h-none` is this page taking responsibility for the
                                thread's height: it has given the column a definite one, so the
                                ceiling the thread carries for auto-height parents (the project
                                Discussion tab, the task panel) must come off here.
                            -->
                            <MessageThread
                                ref="threadEl"
                                :key="active.conversation_id"
                                :thread="active"
                                :routes="routes"
                                scroll
                                class="lg:max-h-none"
                            />
                        </div>
                    </template>

                    <div v-else class="flex min-h-0 min-w-0 flex-1 items-center justify-center p-3">
                        <EmptyState
                            :icon="MessagesSquare"
                            title="Nothing open"
                            description="Choose a conversation, or start a direct message."
                        />
                    </div>
                </Card>

                <!--
                    The context panel as a column, from `xl` and only while it is open. Mounting
                    is what fetches it, so a panel nobody opens costs nothing.
                -->
                <Card
                    v-if="asColumn && active"
                    class="hidden min-h-0 min-w-0 gap-0 overflow-hidden py-0 shadow-raised xl:flex"
                >
                    <div class="flex min-w-0 shrink-0 items-center justify-between gap-2 border-b p-3">
                        <h2 class="min-w-0 truncate text-sm font-medium">Details</h2>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0"
                            aria-label="Hide conversation details"
                            @click="panelOpen = false"
                        >
                            <PanelRightClose aria-hidden="true" />
                        </Button>
                    </div>
                    <div class="min-h-0 min-w-0 flex-1 overflow-y-auto p-3">
                        <ConversationContextPanel
                            :conversation-id="active.conversation_id"
                            :viewer-id="viewerId"
                            :label="active.label"
                        />
                    </div>
                </Card>
            </div>
        </div>

        <!--
            The same panel as an overlay, below `xl`. Reka's dialog owns the focus trap, the
            Escape key and the focus restore back to the control that opened it.
        -->
        <Sheet
            :open="asSheet && active !== null"
            @update:open="(value: boolean) => { panelOpen = value; }"
        >
            <SheetContent side="right" class="w-full gap-0 p-0 sm:max-w-sm">
                <SheetHeader class="gap-1 border-b p-4 pr-12 text-left">
                    <SheetTitle class="text-base">Details</SheetTitle>
                    <SheetDescription>
                        Who is here, what has been shared, and what this conversation is about.
                    </SheetDescription>
                </SheetHeader>
                <div v-if="active" class="min-h-0 flex-1 overflow-y-auto p-4">
                    <ConversationContextPanel
                        :conversation-id="active.conversation_id"
                        :viewer-id="viewerId"
                        :label="active.label"
                    />
                </div>
            </SheetContent>
        </Sheet>
    </PageShell>
</template>
