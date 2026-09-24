<script setup lang="ts">
import { AtSign } from '@lucide/vue';
import { computed, nextTick, ref, useId, watch } from 'vue';
import type { MessagePerson } from '@/Components/Messages/messages';
import { Button } from '@/Components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * The @mention picker: a filter box over a listbox of the people who can read this thread.
 *
 * **Hand-rolled rather than a Combobox primitive**, for one reason: it lives inside a composer
 * whose `Enter` already means something, and it has to hand focus back to the textarea at the
 * exact character it interrupted. A generic combobox owns its own focus and its own `Enter`,
 * and fighting it produced a control where choosing a name moved the caret to the end of the
 * message.
 *
 * ## Keyboard, which is the whole of this component
 *
 * The trigger is a button. Opening moves focus to the filter input, which is the only focusable
 * thing inside the panel — the options are NOT tab stops, they are announced through
 * `aria-activedescendant`, which is the listbox pattern and the reason ↑/↓ can move through
 * fifteen names without fifteen stops between the composer and the Send button.
 *
 *   - `↑` / `↓` move the active option, wrapping, and scroll it into view.
 *   - `Home` / `End` jump to the first and last.
 *   - `Enter` chooses the active one; `Escape` closes and returns focus to the trigger.
 *   - typing filters, and the count is spoken by a polite live region so a screen reader hears
 *     "3 people match" rather than silence.
 *
 * Nothing here is carried by colour: the active option has a filled row AND is the one named by
 * `aria-activedescendant`, and the selected-state is that the name is now in the message, which
 * the reader can see in the textarea.
 */

const props = defineProps<{
    /** The people the server will accept a mention of. The same list it validates against. */
    people: MessagePerson[];
    disabled?: boolean;
}>();

const emit = defineEmits<{ pick: [person: MessagePerson] }>();

const uid = useId();
const listId = `${uid}-list`;
const inputId = `${uid}-filter`;
const statusId = `${uid}-status`;

const open = ref(false);
const query = ref('');
const activeIndex = ref(0);

const triggerEl = ref<InstanceType<typeof Button> | null>(null);
const inputEl = ref<HTMLInputElement | null>(null);
const listEl = ref<HTMLUListElement | null>(null);

const matches = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return props.people;
    }

    return props.people.filter((person) => (person.name ?? '').toLowerCase().includes(needle));
});

const activeId = computed(() => {
    const person = matches.value[activeIndex.value];

    return person === undefined ? undefined : `${uid}-option-${person.id}`;
});

/** Spoken, not drawn: the count is already visible as a list. */
const status = computed(() => {
    if (props.people.length === 0) {
        return 'Nobody else can read this conversation.';
    }

    const total = matches.value.length;

    if (total === 0) {
        return 'No names match.';
    }

    return total === 1 ? '1 person matches.' : `${total} people match.`;
});

watch(matches, () => {
    activeIndex.value = 0;
});

async function show(): Promise<void> {
    if (props.disabled || props.people.length === 0) {
        return;
    }

    open.value = true;
    query.value = '';
    activeIndex.value = 0;

    await nextTick();
    inputEl.value?.focus();
}

function hide(returnFocus = true): void {
    if (!open.value) {
        return;
    }

    open.value = false;

    if (returnFocus) {
        // Back to the control that opened it — DESIGN.md's rule for every overlay.
        triggerEl.value?.$el?.focus?.();
    }
}

function move(delta: number): void {
    const total = matches.value.length;

    if (total === 0) {
        return;
    }

    activeIndex.value = (activeIndex.value + delta + total) % total;
    scrollActiveIntoView();
}

function jump(index: number): void {
    if (matches.value.length === 0) {
        return;
    }

    activeIndex.value = Math.min(Math.max(index, 0), matches.value.length - 1);
    scrollActiveIntoView();
}

function scrollActiveIntoView(): void {
    void nextTick(() => {
        const id = activeId.value;

        if (id === undefined || listEl.value === null) {
            return;
        }

        listEl.value.querySelector(`#${CSS.escape(id)}`)?.scrollIntoView({ block: 'nearest' });
    });
}

function choose(person: MessagePerson | undefined): void {
    if (person === undefined) {
        return;
    }

    emit('pick', person);
    // Not returning focus to the trigger: the caller puts the caret back in the message, which
    // is where somebody who has just typed a name wants to be.
    open.value = false;
}
</script>

<template>
    <div class="relative">
        <!--
            Icon-only, so it carries an `aria-label` AND a tooltip — the accessible name and the
            sighted name being the same words is the rule for every icon-only control here.
        -->
        <TooltipProvider :delay-duration="150">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        ref="triggerEl"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        :disabled="disabled || people.length === 0"
                        :aria-expanded="open"
                        aria-haspopup="listbox"
                        :aria-controls="open ? listId : undefined"
                        aria-label="Mention somebody"
                        @click="open ? hide() : show()"
                    >
                        <AtSign aria-hidden="true" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Mention somebody</TooltipContent>
            </Tooltip>
        </TooltipProvider>

        <div
            v-if="open"
            class="absolute bottom-full left-0 z-20 mb-2 flex w-64 max-w-[calc(100vw-2rem)] flex-col gap-2 rounded-md border bg-popover p-2 text-popover-foreground shadow-md"
            @keydown.esc.prevent.stop="hide()"
        >
            <label :for="inputId" class="sr-only">Find somebody to mention</label>
            <input
                :id="inputId"
                ref="inputEl"
                v-model="query"
                type="text"
                role="combobox"
                autocomplete="off"
                :aria-expanded="true"
                :aria-controls="listId"
                :aria-activedescendant="activeId"
                :aria-describedby="statusId"
                placeholder="Type a name"
                class="w-full min-w-0 rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.home.prevent="jump(0)"
                @keydown.end.prevent="jump(matches.length - 1)"
                @keydown.enter.prevent="choose(matches[activeIndex])"
                @keydown.tab="hide(false)"
            >

            <p :id="statusId" class="sr-only" aria-live="polite">{{ status }}</p>

            <ul
                :id="listId"
                ref="listEl"
                role="listbox"
                aria-label="People you can mention"
                class="max-h-48 min-w-0 overflow-y-auto"
            >
                <li
                    v-for="(person, index) in matches"
                    :id="`${uid}-option-${person.id}`"
                    :key="person.id"
                    role="option"
                    :aria-selected="index === activeIndex"
                    :class="
                        cn(
                            'cursor-pointer rounded-sm px-2 py-1.5 text-sm break-words',
                            index === activeIndex && 'bg-accent text-accent-foreground',
                        )
                    "
                    @click="choose(person)"
                    @mousemove="activeIndex = index"
                >
                    {{ person.name ?? 'Somebody' }}
                </li>

                <li v-if="matches.length === 0" class="px-2 py-1.5 text-sm text-muted-foreground">
                    No names match.
                </li>
            </ul>
        </div>
    </div>
</template>
