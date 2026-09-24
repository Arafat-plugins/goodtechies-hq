<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleAlert, FolderOpen, ListChecks, RefreshCw, Users } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import AttachmentCard from '@/Components/Messages/AttachmentCard.vue';
import { initialsOf, conversationContextState, loadConversationContext } from '@/Components/Messages/messages';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { Skeleton } from '@/Components/ui/skeleton';

/**
 * What is beside a conversation: who is in it, what has been shared in it, and the project and
 * tasks it hangs off.
 *
 * ## It is fetched when it is opened, and then not again
 *
 * This component is **mounted only while the panel is open**, and it asks on mount — which is
 * the same thing as "nothing is requested until the panel is opened for the first time". The
 * answer is cached per viewer and conversation for the life of the page, in `messages.ts`, so
 * closing and reopening it is one request and not two. `Retry` is the only thing that asks
 * twice, and only after a failure.
 *
 * ## `project` and `tasks` are absent, not null
 *
 * That is the repo's privacy shape: a field this requester may not see is not in the payload at
 * all. So the two optional sections branch on the key being PRESENT — a `!== null` test would
 * draw an empty *Project* heading for somebody who is not allowed to know there is one.
 *
 * ## There is no "Pinned messages" section
 *
 * `messages` has no pin column, there is no endpoint that could set one, and a section that
 * drew an empty box for a feature that does not exist would be a promise the app cannot keep.
 */

const props = defineProps<{
    conversationId: number;
    viewerId: number | null;
    /** For the region's accessible name — "Details about Design team". */
    label: string;
}>();

const state = computed(() => conversationContextState(props.viewerId, props.conversationId).value);

const context = computed(() => state.value.data);

const hasProject = computed(() => context.value !== null && context.value.project !== undefined);
const hasTasks = computed(
    () => context.value !== null && (context.value.tasks?.length ?? 0) > 0,
);

onMounted(() => {
    void loadConversationContext(props.viewerId, props.conversationId);
});

function retry(): void {
    void loadConversationContext(props.viewerId, props.conversationId, true);
}

/**
 * A status as the endpoint sends it — a raw key — printed as words.
 *
 * Deliberately not mapped to one of the eight status colours: the contract carries a bare
 * string with no tone and no label, and this repo's rule is that the screen never maps a status
 * to a colour. Words are what the payload supports, so words are what it gets.
 */
function statusWords(status: string): string {
    const words = status.replace(/[_-]+/g, ' ').trim();

    return words === '' ? '—' : words.charAt(0).toUpperCase() + words.slice(1);
}
</script>

<template>
    <section
        :aria-label="`Details about ${label}`"
        aria-live="polite"
        :aria-busy="state.status === 'loading' || undefined"
        class="flex min-w-0 flex-col gap-5"
    >
        <div v-if="state.status === 'loading'" class="flex min-w-0 flex-col gap-3">
            <p class="text-xs text-muted-foreground">Loading details…</p>
            <Skeleton class="h-4 w-24" />
            <Skeleton class="h-8 w-full" />
            <Skeleton class="h-8 w-full" />
            <Skeleton class="h-4 w-20" />
            <Skeleton class="h-12 w-full" />
        </div>

        <EmptyState
            v-else-if="state.status === 'error'"
            :icon="CircleAlert"
            variant="error"
            title="Details could not be loaded"
            description="Nothing about this conversation was lost — only this panel failed."
        >
            <template #action>
                <Button type="button" variant="outline" size="sm" @click="retry">
                    <RefreshCw aria-hidden="true" />
                    Retry
                </Button>
            </template>
        </EmptyState>

        <template v-else-if="context">
            <!-- Members -->
            <div class="flex min-w-0 flex-col gap-2">
                <h3 class="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    <Users class="size-3.5" aria-hidden="true" />
                    Members
                </h3>

                <ul v-if="context.members.length > 0" class="flex min-w-0 flex-col gap-1">
                    <li
                        v-for="person in context.members"
                        :key="person.id"
                        class="flex min-w-0 items-center gap-2"
                    >
                        <Avatar class="size-6">
                            <AvatarFallback class="text-xs font-medium">
                                {{ initialsOf(person.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span class="min-w-0 truncate text-sm">{{ person.name ?? 'Somebody' }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">Nobody else is in this conversation.</p>
            </div>

            <!-- Shared files -->
            <div class="flex min-w-0 flex-col gap-2">
                <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    Shared files
                </h3>

                <ul v-if="context.files.length > 0" class="flex min-w-0 flex-col gap-2">
                    <li v-for="file in context.files" :key="file.id" class="min-w-0">
                        <AttachmentCard :file="{ ...file, duration_seconds: null }" />
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">Nothing has been shared here yet.</p>
            </div>

            <!-- Project. The key is ABSENT when there is none to show, so presence is the test. -->
            <div v-if="hasProject && context.project" class="flex min-w-0 flex-col gap-2">
                <h3 class="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    <FolderOpen class="size-3.5" aria-hidden="true" />
                    Project
                </h3>

                <Link
                    :href="context.project.href"
                    class="flex min-w-0 flex-col gap-0.5 rounded-md border p-2 hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    <span class="min-w-0 truncate text-sm font-medium">{{ context.project.name }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ statusWords(context.project.status) }}
                    </span>
                </Link>
            </div>

            <!-- Related tasks -->
            <div v-if="hasTasks && context.tasks" class="flex min-w-0 flex-col gap-2">
                <h3 class="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    <ListChecks class="size-3.5" aria-hidden="true" />
                    Related tasks
                </h3>

                <ul class="flex min-w-0 flex-col gap-1">
                    <li v-for="task in context.tasks" :key="task.id" class="min-w-0">
                        <Link
                            :href="task.href"
                            class="flex min-w-0 flex-col gap-0.5 rounded-md border p-2 hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <span class="min-w-0 truncate text-sm">{{ task.title }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ statusWords(task.status) }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>
