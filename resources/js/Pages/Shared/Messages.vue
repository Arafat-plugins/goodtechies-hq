<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MessagesSquare, Megaphone, UserPlus } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import ConversationList from '@/Components/Messages/ConversationList.vue';
import MessageThread from '@/Components/Messages/MessageThread.vue';
import type {
    AnnouncementBanner,
    ConversationSummary,
    MessagePerson,
    ThreadPayload,
} from '@/Components/Messages/messages';
import { conversationRoutes, formatMessageTime } from '@/Components/Messages/messages';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * Messages: the team channel, the announcements channel, the project channels and this
 * person's DMs, with one thread open beside them (master prompt Part D §10).
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
 * This page has **no poll and no realtime of its own**. It reads what the server rendered, and
 * `MessageThread` re-reads its own thread when told to; the transport that does the telling is
 * a separate slice and lands on that one method.
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

const uid = useId();
const dmPickerId = `${uid}-dm`;

const chosen = ref<string>('');
const opening = ref(false);

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

/** Start a direct message. A POST, because it may create the conversation. */
function openDirect(): void {
    const id = Number.parseInt(chosen.value, 10);

    if (!Number.isFinite(id) || id <= 0 || opening.value) {
        return;
    }

    opening.value = true;

    router.post(`/messages/direct/${id}`, {}, {
        preserveScroll: true,
        onFinish: () => {
            opening.value = false;
            chosen.value = '';
        },
    });
}
</script>

<template>
    <Head title="Messages" />

    <PageShell title="Messages" :description="description">
        <div class="flex min-w-0 flex-col gap-4">
            <!--
                The announcement banner the plan asks for. It is not dismissed by a button: it
                goes quiet when the announcements channel is read, which is one state and not
                two. `Megaphone` plus the word "Announcement" carry it; the border is third.
            -->
            <Card
                v-if="announcement"
                :class="announcement.is_unread ? 'border-primary' : undefined"
            >
                <CardContent class="flex min-w-0 flex-col gap-2">
                    <p class="flex min-w-0 flex-wrap items-baseline gap-x-2 text-xs text-muted-foreground">
                        <Megaphone class="size-3.5 shrink-0" aria-hidden="true" />
                        <span class="font-medium text-foreground">
                            Announcement{{ announcement.is_unread ? ' — unread' : '' }}
                        </span>
                        <span>{{ announcement.author ?? 'Somebody' }}</span>
                        <span>{{ formatMessageTime(announcement.created_at) }}</span>
                    </p>
                    <p class="min-w-0 text-sm break-words whitespace-pre-line">
                        {{ announcement.body }}
                    </p>
                </CardContent>
            </Card>

            <div class="grid min-w-0 gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                <!--
                    The rail. On a phone it sits above the thread; from `lg` it sits beside it.
                    `min-w-0` on both columns is what keeps a long message from widening the
                    grid instead of wrapping inside it.
                -->
                <div class="flex min-w-0 flex-col gap-4">
                    <Card>
                        <CardContent class="flex min-w-0 flex-col gap-3">
                            <label :for="dmPickerId" class="text-xs font-medium text-muted-foreground">
                                Start a direct message
                            </label>
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <select
                                    :id="dmPickerId"
                                    v-model="chosen"
                                    class="min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 dark:bg-input/30"
                                >
                                    <option value="">Choose somebody</option>
                                    <option
                                        v-for="person in people"
                                        :key="person.id"
                                        :value="String(person.id)"
                                    >
                                        {{ person.name ?? 'Somebody' }}
                                    </option>
                                </select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="shrink-0"
                                    :disabled="chosen === '' || opening"
                                    @click="openDirect"
                                >
                                    <UserPlus aria-hidden="true" />
                                    Open
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent class="min-w-0">
                            <ConversationList
                                v-if="conversations.length > 0"
                                :conversations="conversations"
                                :active-id="activeId"
                            />
                            <EmptyState
                                v-else
                                :icon="MessagesSquare"
                                title="No conversations yet"
                                description="The team channel appears here as soon as it exists."
                            />
                        </CardContent>
                    </Card>
                </div>

                <Card class="min-w-0">
                    <CardContent class="min-w-0">
                        <MessageThread
                            v-if="active && routes"
                            :key="active.conversation_id"
                            :thread="active"
                            :routes="routes"
                            :heading="active.label"
                            :description="
                                active.type === 'announcement'
                                    ? 'Read by everybody. Only an Admin can post here.'
                                    : null
                            "
                            scroll
                        />
                        <EmptyState
                            v-else
                            :icon="MessagesSquare"
                            title="Nothing open"
                            description="Choose a conversation on the left, or start a direct message."
                        />
                    </CardContent>
                </Card>
            </div>
        </div>
    </PageShell>
</template>
