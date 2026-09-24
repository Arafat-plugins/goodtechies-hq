<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PenSquare, Search, UserX } from '@lucide/vue';
import { computed, nextTick, ref, useId, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { MessagePerson } from '@/Components/Messages/messages';
import { initialsOf } from '@/Components/Messages/messages';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/**
 * Start a direct message: a filter over the people this person may write to, and one POST.
 *
 * ## The list is the `people` prop, and nothing else
 *
 * It is exactly what `MessageController@index` put in the page's props — the set the server
 * will accept a `POST /messages/direct/{user}` for. Nothing here fetches a directory and
 * nothing here hard-codes a name: a name offered in this dialog is a name the endpoint answers.
 *
 * ## It owns its own trigger
 *
 * Deliberately, so the dialog's focus restore is reka's and not a hand-rolled one: `Escape`,
 * the close button and a successful send all put focus back on the *New message* button that
 * opened it.
 *
 * A POST rather than a link, because opening a DM may CREATE the conversation — which is also
 * why nothing here guesses the resulting conversation's id or pretends to navigate to it. The
 * server redirects to whichever thread it opened or made.
 */

const props = defineProps<{ people: MessagePerson[] }>();

const uid = useId();
const filterId = `${uid}-filter`;
const statusId = `${uid}-status`;

const open = ref(false);
const query = ref('');
const opening = ref(false);

const filterEl = ref<InstanceType<typeof Input> | null>(null);
const listEl = ref<HTMLUListElement | null>(null);

const matches = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return props.people;
    }

    return props.people.filter((person) => (person.name ?? '').toLowerCase().includes(needle));
});

/** Spoken, not drawn twice: the list below is already the visible answer. */
const status = computed(() => {
    if (props.people.length === 0) {
        return 'There is nobody to message.';
    }

    const total = matches.value.length;

    if (total === 0) {
        return 'No names match.';
    }

    return total === 1 ? '1 person matches.' : `${total} people match.`;
});

watch(open, (isOpen) => {
    if (!isOpen) {
        query.value = '';
    }
});

function focusFilter(event: Event): void {
    event.preventDefault();

    void nextTick(() => {
        (filterEl.value?.$el as HTMLInputElement | undefined)?.focus();
    });
}

/**
 * ↓ from the field walks into the list; ↑/↓ inside it walk the rows. Tab still works.
 *
 * The rows are read out of the DOM rather than out of an array of template refs: the list is
 * filtered as somebody types, and an index into a ref array that the filter has just shortened
 * points at whatever moved into that slot.
 */
function step(from: HTMLElement | null, delta: number): void {
    const rows = Array.from(listEl.value?.querySelectorAll('button') ?? []);

    if (rows.length === 0) {
        return;
    }

    const at = from === null ? -1 : rows.indexOf(from as HTMLButtonElement);
    const next = Math.min(Math.max(at + delta, 0), rows.length - 1);

    rows[next]?.focus();
}

function start(person: MessagePerson): void {
    if (opening.value) {
        return;
    }

    opening.value = true;

    router.post(`/messages/direct/${person.id}`, {}, {
        preserveScroll: true,
        onFinish: () => {
            opening.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" size="sm" class="w-full justify-start">
                <PenSquare aria-hidden="true" />
                New message
            </Button>
        </DialogTrigger>

        <DialogContent
            class="flex max-h-[85svh] flex-col gap-0 p-0"
            @open-auto-focus="focusFilter"
        >
            <DialogHeader class="gap-1 border-b p-4 pr-12 text-left">
                <DialogTitle class="text-base">New message</DialogTitle>
                <DialogDescription>
                    Pick somebody to write to. If you have not spoken before, the conversation is
                    made when you open it.
                </DialogDescription>
            </DialogHeader>

            <div class="flex min-w-0 flex-col gap-2 p-4">
                <Label :for="filterId" class="sr-only">Find somebody</Label>
                <div class="relative min-w-0">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        :id="filterId"
                        ref="filterEl"
                        v-model="query"
                        type="text"
                        autocomplete="off"
                        :aria-describedby="statusId"
                        placeholder="Type a name"
                        class="pl-9"
                        @keydown.down.prevent="step(null, 1)"
                    />
                </div>

                <p :id="statusId" class="sr-only" aria-live="polite">{{ status }}</p>

                <ul
                    v-if="matches.length > 0"
                    ref="listEl"
                    class="-mx-1 flex max-h-80 min-w-0 flex-col gap-0.5 overflow-y-auto px-1"
                >
                    <li v-for="person in matches" :key="person.id" class="min-w-0">
                        <button
                            type="button"
                            :disabled="opening"
                            class="flex w-full min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground disabled:opacity-50 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            @keydown.down.prevent="step($event.currentTarget as HTMLElement, 1)"
                            @keydown.up.prevent="step($event.currentTarget as HTMLElement, -1)"
                            @click="start(person)"
                        >
                            <Avatar class="size-7">
                                <AvatarFallback class="text-xs font-medium">
                                    {{ initialsOf(person.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span class="min-w-0 truncate">{{ person.name ?? 'Somebody' }}</span>
                        </button>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="UserX"
                    :variant="people.length === 0 ? 'empty' : 'filtered'"
                    :title="people.length === 0 ? 'Nobody to message' : 'No names match'"
                    :description="
                        people.length === 0
                            ? 'Direct messages appear here once there is somebody to write to.'
                            : 'Try a shorter piece of the name.'
                    "
                    @clear="query = ''"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
