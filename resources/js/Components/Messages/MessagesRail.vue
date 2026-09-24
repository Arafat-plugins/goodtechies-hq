<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleAlert, MessagesSquare, RefreshCw, Search, SearchX, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import ConversationList from '@/Components/Messages/ConversationList.vue';
import NewMessageDialog from '@/Components/Messages/NewMessageDialog.vue';
import type {
    ConversationSummary,
    MessagePerson,
    MessageSearchResult,
} from '@/Components/Messages/messages';
import {
    MESSAGE_SEARCH_DEBOUNCE_MS,
    MESSAGE_SEARCH_MIN,
    formatMessageTime,
    messagesHref,
    searchMessages,
} from '@/Components/Messages/messages';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/**
 * The rail: search, *New message*, and the conversation list.
 *
 * A component of its own, and not a block inside the page, for one blunt reason — the composer
 * on the right re-renders on every keystroke, and a rail that lived in the page would re-render
 * with it. The rail's props are the page's props and nothing in it changes while somebody types
 * a message.
 *
 * ## Search is real, and its failures are real
 *
 * `GET /messages/search?q=…`, debounced, minimum two characters, and the in-flight request is
 * **aborted** when the term changes — a slow answer for "pro" must not land under "project".
 * Loading, empty and error are three different states with three different sentences: a screen
 * that prints "nothing found" when it means "the request failed" is lying to somebody who is
 * about to conclude the message they are looking for was deleted.
 *
 * Results REPLACE the list while a term is live, and `Escape` clears the field and brings the
 * list back. A result row opens its conversation — there is no endpoint that deep-links to one
 * message, so nothing here pretends to scroll to one.
 */

const props = defineProps<{
    conversations: ConversationSummary[];
    activeId: number | null;
    /** Exactly the people `MessageController@index` said this person may write to. */
    people: MessagePerson[];
}>();

const uid = useId();
const searchId = `${uid}-search`;
const statusId = `${uid}-status`;

const term = ref('');
const results = ref<MessageSearchResult[]>([]);
const hasMore = ref(false);
const searchState = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');

const searchEl = ref<InstanceType<typeof Input> | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;
let inFlight: AbortController | undefined;

/** A term long enough to be a search. Below this the rail simply keeps its list. */
const searching = computed(() => term.value.trim().length >= MESSAGE_SEARCH_MIN);

const status = computed(() => {
    if (!searching.value) {
        return '';
    }

    if (searchState.value === 'loading') {
        return 'Searching.';
    }

    if (searchState.value === 'error') {
        return 'The search failed.';
    }

    const total = results.value.length;

    if (total === 0) {
        return 'No messages match.';
    }

    return total === 1 ? '1 message matches.' : `${total} messages match.`;
});

function cancel(): void {
    clearTimeout(timer);
    inFlight?.abort();
    inFlight = undefined;
}

async function run(query: string): Promise<void> {
    const controller = new AbortController();

    inFlight = controller;
    searchState.value = 'loading';

    try {
        const payload = await searchMessages(query, controller.signal);

        if (controller.signal.aborted) {
            return;
        }

        results.value = payload.results;
        hasMore.value = payload.has_more;
        searchState.value = 'ready';
    } catch (error) {
        // An abort is this component cancelling itself, not a failure to report.
        if (controller.signal.aborted || (error instanceof DOMException && error.name === 'AbortError')) {
            return;
        }

        results.value = [];
        hasMore.value = false;
        searchState.value = 'error';
    } finally {
        if (inFlight === controller) {
            inFlight = undefined;
        }
    }
}

watch(term, (value) => {
    cancel();

    const query = value.trim();

    if (query.length < MESSAGE_SEARCH_MIN) {
        results.value = [];
        hasMore.value = false;
        searchState.value = 'idle';

        return;
    }

    timer = setTimeout(() => void run(query), MESSAGE_SEARCH_DEBOUNCE_MS);
});

onBeforeUnmount(cancel);

function clear(): void {
    term.value = '';

    (searchEl.value?.$el as HTMLInputElement | undefined)?.focus();
}

function retry(): void {
    cancel();

    void run(term.value.trim());
}
</script>

<template>
    <div class="flex min-h-0 min-w-0 flex-col gap-3">
        <div class="flex min-w-0 shrink-0 flex-col gap-2">
            <Label :for="searchId" class="sr-only">Search messages</Label>
            <div class="relative min-w-0">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    :id="searchId"
                    ref="searchEl"
                    v-model="term"
                    type="search"
                    autocomplete="off"
                    :aria-describedby="statusId"
                    placeholder="Search messages"
                    class="px-9"
                    @keydown.esc.prevent="clear"
                />
                <Button
                    v-if="term !== ''"
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    class="absolute top-1/2 right-1.5 -translate-y-1/2"
                    aria-label="Clear the search"
                    @click="clear"
                >
                    <X aria-hidden="true" />
                </Button>
            </div>

            <NewMessageDialog :people="people" />
        </div>

        <p :id="statusId" class="sr-only" aria-live="polite">{{ status }}</p>

        <div class="min-h-0 min-w-0 flex-1 overflow-y-auto pr-1">
            <!-- A live term replaces the list. Nothing here is drawn beside the other. -->
            <template v-if="searching">
                <p
                    v-if="searchState === 'loading'"
                    class="px-2 py-6 text-center text-sm text-muted-foreground"
                >
                    Searching…
                </p>

                <EmptyState
                    v-else-if="searchState === 'error'"
                    :icon="CircleAlert"
                    variant="error"
                    title="Search failed"
                    description="Nothing was searched. Try again in a moment."
                >
                    <template #action>
                        <Button type="button" variant="outline" size="sm" @click="retry">
                            <RefreshCw aria-hidden="true" />
                            Try again
                        </Button>
                    </template>
                </EmptyState>

                <EmptyState
                    v-else-if="results.length === 0"
                    :icon="SearchX"
                    variant="filtered"
                    title="No messages match"
                    description="Try a different word, or a name."
                    @clear="clear"
                />

                <div v-else class="flex min-w-0 flex-col gap-1">
                    <ul aria-label="Search results" class="flex min-w-0 flex-col gap-0.5">
                        <li v-for="row in results" :key="row.message_id" class="min-w-0">
                            <!--
                                Opening the CONVERSATION is the honest action: there is no
                                endpoint that deep-links to one message, so nothing here
                                pretends to scroll to it.
                            -->
                            <Link
                                :href="messagesHref(row.conversation_id)"
                                preserve-scroll
                                class="flex min-w-0 flex-col gap-0.5 rounded-md px-2 py-1.5 text-sm hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <span class="flex min-w-0 items-baseline justify-between gap-2">
                                    <span class="min-w-0 truncate font-medium">
                                        {{ row.conversation_label }}
                                    </span>
                                    <span class="shrink-0 text-xs text-muted-foreground">
                                        {{ formatMessageTime(row.created_at) }}
                                    </span>
                                </span>
                                <span class="min-w-0 truncate text-xs text-muted-foreground">
                                    {{ row.author ?? 'Somebody' }}: {{ row.excerpt }}
                                </span>
                            </Link>
                        </li>
                    </ul>

                    <p v-if="hasMore" class="px-2 py-2 text-xs text-muted-foreground">
                        More messages match than are shown. Narrow the search to see them.
                    </p>
                </div>
            </template>

            <template v-else>
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
            </template>
        </div>
    </div>
</template>
