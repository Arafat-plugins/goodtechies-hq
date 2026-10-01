<script setup lang="ts">
import { Smile } from '@lucide/vue';
import { ref, useId, watch } from 'vue';
import { EMOJI_GROUPS, recentEmoji } from '@/Components/Messages/emoji';
import { Button } from '@/Components/ui/button';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';

/**
 * The composer's emoji picker: a ghost smiley that opens a popover of labelled grids.
 *
 * Every emoji is a real `button` (Tab / Shift + Tab reach them, Enter or Space picks one), named
 * by the emoji itself. Picking closes the popover and emits `pick`; the composer inserts it at
 * the caret and takes focus back, so focus lands where the writing is rather than on the
 * trigger. Escape closes without a pick and returns focus to the trigger, as any overlay does.
 */

/*
 * Brief 010, reaction mode: the same grids, opened by the message menu's "More…" rather than by
 * a smiley of its own. There is no trigger — the popover is anchored on the `anchor` slot and
 * its `open` is the caller's (`v-model:open`) — and a pick is a reaction, not text to insert.
 */
const props = withDefaults(
    defineProps<{ disabled?: boolean; mode?: 'compose' | 'reaction'; open?: boolean }>(),
    { disabled: false, mode: 'compose', open: false },
);

const emit = defineEmits<{ pick: [emoji: string]; 'update:open': [open: boolean] }>();

const uid = useId();
const open = ref(props.mode === 'reaction' ? props.open : false);

watch(
    () => props.open,
    (value) => {
        if (props.mode === 'reaction' && value !== open.value) {
            onOpenChange(value);
        }
    },
);
const recent = ref<string[]>([]);
/** Set by a pick, so the popover does not hand focus back to the trigger over the textarea. */
let picked = false;

function onOpenChange(value: boolean): void {
    open.value = value;
    emit('update:open', value);

    if (value) {
        recent.value = recentEmoji();
        picked = false;
    }
}

function choose(emoji: string): void {
    picked = true;
    open.value = false;
    emit('update:open', false);
    emit('pick', emoji);
}

function onCloseAutoFocus(event: Event): void {
    if (picked) {
        event.preventDefault();
    }
}
</script>

<template>
    <Popover :open="open" @update:open="onOpenChange">
        <PopoverAnchor v-if="mode === 'reaction'" as-child>
            <slot name="anchor">
                <span class="block size-0" aria-hidden="true" />
            </slot>
        </PopoverAnchor>
        <PopoverTrigger v-else as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                class="size-8 shrink-0 rounded-full text-muted-foreground hover:text-foreground sm:size-9"
                :disabled="disabled"
                aria-label="Insert emoji"
                data-composer-emoji
            >
                <Smile aria-hidden="true" />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            :align="mode === 'reaction' ? 'center' : 'end'"
            side="top"
            class="max-h-72 w-72 overflow-y-auto p-2"
            :aria-label="mode === 'reaction' ? 'Add a reaction' : 'Emoji'"
            @close-auto-focus="onCloseAutoFocus"
        >
            <div class="flex flex-col gap-2">
                <section v-if="recent.length > 0" :aria-labelledby="`${uid}-recent`">
                    <h3 :id="`${uid}-recent`" class="px-1 pb-1 text-xs font-medium text-muted-foreground">
                        Recent
                    </h3>
                    <div class="grid grid-cols-8">
                        <button
                            v-for="emoji in recent"
                            :key="`recent-${emoji}`"
                            type="button"
                            class="flex size-8 items-center justify-center rounded-md text-lg hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="emoji"
                            @click="choose(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                </section>

                <section
                    v-for="(group, index) in EMOJI_GROUPS"
                    :key="group.label"
                    :aria-labelledby="`${uid}-group-${index}`"
                >
                    <h3 :id="`${uid}-group-${index}`" class="px-1 pb-1 text-xs font-medium text-muted-foreground">
                        {{ group.label }}
                    </h3>
                    <div class="grid grid-cols-8">
                        <button
                            v-for="emoji in group.emoji"
                            :key="emoji"
                            type="button"
                            class="flex size-8 items-center justify-center rounded-md text-lg hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="emoji"
                            @click="choose(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                </section>
            </div>
        </PopoverContent>
    </Popover>
</template>
