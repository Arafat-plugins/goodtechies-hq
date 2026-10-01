<script setup lang="ts">
import { Copy, MoreHorizontal, Pencil, SmilePlus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmojiPicker from '@/Components/Messages/EmojiPicker.vue';
import { QUICK_REACTIONS } from '@/Components/Messages/emoji';
import type { ThreadMessage } from '@/Components/Messages/messages';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { useMenuDialog } from '@/lib/menuFocus';

/**
 * Brief 010: one message's menu — Copy, Edit, Delete and a row of quick reactions.
 *
 * Opened by the `MoreHorizontal` button beside the bubble (revealed on hover and on focus, always
 * visible on a touch screen) or by a right-click / long-press on the bubble, which `MessageRow`
 * forwards through `openMenu()`. Edit and Delete appear only where the server said `can_edit` /
 * `can_delete` — the Policy's answer, not a guess here. Delete asks first.
 *
 * The confirm dialog and the full emoji picker open through `lib/menuFocus.ts` (decision 5-20):
 * the open waits for the menu to finish dismissing, and focus comes back to the `⋯` trigger
 * however the overlay closes.
 */

const props = withDefaults(
    defineProps<{
        message: ThreadMessage;
        /** Which way the menu lines up: toward the bubble. */
        align?: 'start' | 'end';
    }>(),
    { align: 'end' },
);

const emit = defineEmits<{
    copy: [];
    edit: [];
    delete: [];
    react: [emoji: string];
}>();

const menuOpen = ref(false);
const pickerOpen = ref(false);
const confirmOpen = ref(false);
const triggerEl = ref<HTMLButtonElement | null>(null);

/** Decision 5-20: where the keyboard goes when an overlay opened from this menu closes. */
const menu = useMenuDialog(() => triggerEl.value);

const canCopy = computed(() => (props.message.body ?? '') !== '');

function openMenu(): void {
    menuOpen.value = true;
}

defineExpose({ openMenu });

function openPicker(): void {
    menu.openFromMenu(() => {
        pickerOpen.value = true;
    });
}

function askDelete(): void {
    menu.openFromMenu(() => {
        confirmOpen.value = true;
    });
}

/** Edit swaps the bubble for a field that takes focus itself, so only the deferral is wanted. */
function startEdit(): void {
    menu.openFromMenu(() => emit('edit'));
}

/** Every way out of either overlay — a pick, Esc, Keep, Delete — puts the keyboard back. */
watch(pickerOpen, (isOpen) => {
    if (!isOpen) {
        menu.returnFocus();
    }
});

watch(confirmOpen, (isOpen) => {
    if (!isOpen) {
        menu.returnFocus();
    }
});

/** reka's own restore would aim at the unmounted menu item; `returnFocus()` above does it. */
function onConfirmCloseAutoFocus(event: Event): void {
    event.preventDefault();
}

function confirmDelete(): void {
    confirmOpen.value = false;
    emit('delete');
}
</script>

<template>
    <div class="flex shrink-0 items-center">
        <DropdownMenu v-model:open="menuOpen">
            <DropdownMenuTrigger as-child>
                <button
                    ref="triggerEl"
                    type="button"
                    aria-label="Message actions"
                    class="flex size-7 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 hover:bg-accent hover:text-accent-foreground focus-visible:opacity-100 focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none data-[state=open]:opacity-100 pointer-coarse:opacity-100 motion-reduce:transition-none"
                >
                    <MoreHorizontal class="size-4" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                :align="align"
                class="w-60"
            >
                <div role="group" aria-label="React" class="flex items-center gap-0.5 p-0.5">
                    <DropdownMenuItem
                        v-for="emoji in QUICK_REACTIONS"
                        :key="emoji"
                        class="size-8 justify-center p-0 text-lg"
                        :aria-label="`React with ${emoji}`"
                        @select="emit('react', emoji)"
                    >
                        {{ emoji }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="size-8 justify-center p-0"
                        aria-label="More reactions…"
                        @select="openPicker"
                    >
                        <SmilePlus aria-hidden="true" />
                    </DropdownMenuItem>
                </div>

                <DropdownMenuSeparator v-if="canCopy || message.can_edit || message.can_delete" />

                <DropdownMenuItem v-if="canCopy" @select="emit('copy')">
                    <Copy aria-hidden="true" />
                    Copy
                </DropdownMenuItem>
                <DropdownMenuItem v-if="message.can_edit" @select="startEdit">
                    <Pencil aria-hidden="true" />
                    Edit
                </DropdownMenuItem>
                <DropdownMenuItem v-if="message.can_delete" variant="destructive" @select="askDelete">
                    <Trash2 aria-hidden="true" />
                    Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <EmojiPicker v-model:open="pickerOpen" mode="reaction" @pick="emit('react', $event)" />

        <Dialog v-model:open="confirmOpen">
            <DialogContent class="sm:max-w-sm" @close-auto-focus="onConfirmCloseAutoFocus">
                <DialogHeader>
                    <DialogTitle>Delete this message for everyone?</DialogTitle>
                    <DialogDescription>
                        Everybody in this conversation will see "This message was deleted" instead.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="confirmOpen = false">Keep</Button>
                    <Button type="button" variant="destructive" @click="confirmDelete">Delete</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
