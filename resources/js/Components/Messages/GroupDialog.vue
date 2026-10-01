<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ImagePlus, Search, Settings2, Trash2, UserMinus, UsersRound } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import ConversationAvatar from '@/Components/Messages/ConversationAvatar.vue';
import type { MessagePerson, MessageSendResult, ThreadGroup } from '@/Components/Messages/messages';
import { messageRequest, messagesHref } from '@/Components/Messages/messages';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/**
 * Brief 010: make a message group, or manage one (decision 12-81).
 *
 * **Create** (the rail's *New group*, shown only when the page said `can_manage_groups`): a name,
 * an optional picture and the people from `people` — the same list *New message* offers — then
 * one multipart `POST /messages/groups` and a visit to the new conversation.
 *
 * **Edit** (the thread header's *Manage group*, shown only when the group said `can_manage`):
 * rename or change / remove the picture (`POST /messages/groups/{c}`), add people
 * (`POST …/members`), remove somebody (`DELETE …/members/{user}`, after an inline confirm). Each
 * answers with the group, which goes up as `saved` so the header can redraw it.
 *
 * The server decides everything: who may, the name's length, the picture's type and size. Its
 * 422s are printed under the field they are about. The dialog owns its trigger, so reka puts focus
 * back on it when the dialog closes.
 */

const NAME_MAX = 80;

const props = withDefaults(
    defineProps<{
        mode: 'create' | 'edit';
        people: MessagePerson[];
        group?: ThreadGroup | null;
    }>(),
    { group: null },
);

const emit = defineEmits<{ saved: [group: ThreadGroup] }>();

const uid = useId();
const nameId = `${uid}-name`;
const pictureId = `${uid}-picture`;
const filterId = `${uid}-filter`;
const nameErrorId = `${uid}-name-error`;

const open = ref(false);
const name = ref('');
const file = ref<File | null>(null);
const preview = ref<string | null>(null);
const removePicture = ref(false);
const selected = ref<number[]>([]);
const filter = ref('');
const busy = ref(false);
const confirmingRemoval = ref<number | null>(null);
const members = ref<{ id: number; name: string }[]>([]);
const status = ref('');

const errors = ref<{ name: string | null; avatar: string | null; people: string | null; general: string | null }>({
    name: null,
    avatar: null,
    people: null,
    general: null,
});

const pictureEl = ref<HTMLInputElement | null>(null);

const editing = computed(() => props.mode === 'edit' && props.group !== null);

/** Who the checklist offers: everybody for a new group; everybody not already in it when editing. */
const candidates = computed(() => {
    const inGroup = new Set(members.value.map((member) => member.id));

    return props.people.filter((person) => !editing.value || !inGroup.has(person.id));
});

const visible = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return needle === ''
        ? candidates.value
        : candidates.value.filter((person) => (person.name ?? '').toLowerCase().includes(needle));
});

/** The picture on screen: a newly chosen one, else the group's own unless it is being removed. */
const shownPicture = computed(() => preview.value ?? (removePicture.value ? null : (props.group?.avatar_url ?? null)));

function clearErrors(): void {
    errors.value = { name: null, avatar: null, people: null, general: null };
}

function setPreview(next: File | null): void {
    if (preview.value !== null) {
        URL.revokeObjectURL(preview.value);
    }

    file.value = next;
    preview.value = next === null ? null : URL.createObjectURL(next);
}

function reset(): void {
    name.value = props.group?.name ?? '';
    setPreview(null);
    removePicture.value = false;
    selected.value = [];
    filter.value = '';
    confirmingRemoval.value = null;
    members.value = [...(props.group?.members ?? [])];
    status.value = '';
    clearErrors();
}

watch(open, (isOpen) => {
    if (isOpen) {
        reset();
    } else {
        setPreview(null);
    }
});

watch(
    () => props.group,
    (group) => {
        if (group !== null) {
            members.value = [...group.members];
        }
    },
);

onBeforeUnmount(() => setPreview(null));

function choosePicture(event: Event): void {
    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0] ?? null;

    input.value = '';

    if (chosen === null) {
        return;
    }

    errors.value.avatar = null;
    removePicture.value = false;
    setPreview(chosen);
}

function dropPicture(): void {
    setPreview(null);
    removePicture.value = editing.value && (props.group?.avatar_url ?? null) !== null;
}

function isSelected(id: number): boolean {
    return selected.value.includes(id);
}

function toggle(id: number, checked: boolean): void {
    selected.value = checked ? [...new Set([...selected.value, id])] : selected.value.filter((item) => item !== id);
}

/** A 422's first message per field, into the slot under that field. */
function readErrors(answer: MessageSendResult): void {
    const json = (answer.json ?? {}) as { message?: unknown; errors?: Record<string, unknown> };
    const next = { name: null, avatar: null, people: null, general: null } as typeof errors.value;

    for (const [key, value] of Object.entries(json.errors ?? {})) {
        const text = Array.isArray(value) ? String(value[0] ?? '') : String(value ?? '');
        const slot = key.startsWith('name')
            ? 'name'
            : key.startsWith('avatar') || key.startsWith('remove_avatar')
              ? 'avatar'
              : key.startsWith('member_ids') || key.startsWith('user_ids')
                ? 'people'
                : 'general';

        next[slot] ??= text;
    }

    if (answer.status === 403) {
        next.general = 'You are not allowed to manage this group.';
    } else if (Object.values(next).every((value) => value === null)) {
        next.general = typeof json.message === 'string' && json.message !== '' ? json.message : 'That did not work. Try again.';
    }

    errors.value = next;
}

function ok(answer: MessageSendResult): boolean {
    return answer.status >= 200 && answer.status < 300;
}

function groupFrom(answer: MessageSendResult): ThreadGroup | null {
    const group = (answer.json as { group?: unknown } | null)?.group;

    return group !== null && typeof group === 'object' ? (group as ThreadGroup) : null;
}

async function run(request: () => Promise<MessageSendResult>, done: (answer: MessageSendResult) => void): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;
    clearErrors();

    try {
        const answer = await request();

        if (ok(answer)) {
            done(answer);
        } else {
            readErrors(answer);
        }
    } catch {
        errors.value.general = 'No connection. Nothing was saved.';
    } finally {
        busy.value = false;
    }
}

function adopt(answer: MessageSendResult, said: string): void {
    const group = groupFrom(answer);

    if (group !== null) {
        members.value = [...group.members];
        emit('saved', group);
    }

    status.value = said;
}

/* ------------------------------------------------------------------ create */

function create(): void {
    if (name.value.trim() === '') {
        errors.value.name = 'Give the group a name.';

        return;
    }

    const data = new FormData();

    data.append('name', name.value.trim());
    selected.value.forEach((id) => data.append('member_ids[]', String(id)));

    if (file.value !== null) {
        data.append('avatar', file.value);
    }

    void run(
        () => messageRequest('POST', '/messages/groups', data),
        (answer) => {
            const id = Number((answer.json as { conversation_id?: unknown } | null)?.conversation_id);

            open.value = false;

            if (Number.isFinite(id) && id > 0) {
                router.visit(messagesHref(id), { preserveScroll: true });
            }
        },
    );
}

/* ------------------------------------------------------------------ edit */

function saveDetails(): void {
    const group = props.group;

    if (group === null) {
        return;
    }

    if (name.value.trim() === '') {
        errors.value.name = 'Give the group a name.';

        return;
    }

    const data = new FormData();

    data.append('name', name.value.trim());

    if (file.value !== null) {
        data.append('avatar', file.value);
    } else if (removePicture.value) {
        data.append('remove_avatar', '1');
    }

    void run(
        () => messageRequest('POST', `/messages/groups/${group.id}`, data),
        (answer) => {
            setPreview(null);
            removePicture.value = false;
            adopt(answer, 'Group saved.');
        },
    );
}

function addPeople(): void {
    const group = props.group;

    if (group === null || selected.value.length === 0) {
        return;
    }

    void run(
        () => messageRequest('POST', `/messages/groups/${group.id}/members`, { user_ids: selected.value }),
        (answer) => {
            const count = selected.value.length;

            selected.value = [];
            adopt(answer, count === 1 ? '1 person added.' : `${count} people added.`);
        },
    );
}

function removeMember(member: { id: number; name: string }): void {
    const group = props.group;

    if (group === null) {
        return;
    }

    void run(
        () => messageRequest('DELETE', `/messages/groups/${group.id}/members/${member.id}`, null),
        (answer) => {
            confirmingRemoval.value = null;
            adopt(answer, `${member.name} removed.`);
        },
    );
}

function submit(): void {
    if (editing.value) {
        saveDetails();
    } else {
        create();
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                v-if="mode === 'create'"
                type="button"
                variant="outline"
                size="sm"
                class="w-full justify-start"
            >
                <UsersRound aria-hidden="true" />
                New group
            </Button>
            <Button
                v-else
                type="button"
                variant="ghost"
                size="sm"
                class="shrink-0"
                aria-label="Manage group"
            >
                <Settings2 aria-hidden="true" />
                <span class="hidden sm:inline">Manage group</span>
            </Button>
        </DialogTrigger>

        <DialogContent class="flex max-h-[85svh] flex-col gap-0 p-0">
            <DialogHeader class="gap-1 border-b p-4 pr-12 text-left">
                <DialogTitle class="text-base">{{ editing ? 'Manage group' : 'New group' }}</DialogTitle>
                <DialogDescription>
                    {{
                        editing
                            ? 'Rename it, change its picture, and choose who is in it.'
                            : 'Name the group, give it a picture if you like, and choose who is in it.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="flex min-h-0 min-w-0 flex-1 flex-col" novalidate @submit.prevent="submit">
                <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
                    <p class="sr-only" aria-live="polite">{{ status }}</p>

                    <p v-if="errors.general" class="text-sm text-destructive" role="alert">{{ errors.general }}</p>

                    <!-- Name -->
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <Label :for="nameId">Name</Label>
                        <Input
                            :id="nameId"
                            v-model="name"
                            type="text"
                            autocomplete="off"
                            required
                            :maxlength="NAME_MAX"
                            :aria-invalid="errors.name !== null ? 'true' : undefined"
                            :aria-describedby="errors.name !== null ? nameErrorId : undefined"
                        />
                        <p v-if="errors.name" :id="nameErrorId" class="text-xs text-destructive">{{ errors.name }}</p>
                    </div>

                    <!-- Picture -->
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-sm font-medium">Picture</span>
                        <div class="flex min-w-0 flex-wrap items-center gap-3">
                            <ConversationAvatar :label="name || 'Group'" :avatar-url="shownPicture" class="size-16" />
                            <input
                                :id="pictureId"
                                ref="pictureEl"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="sr-only"
                                @change="choosePicture"
                            />
                            <Button type="button" variant="outline" size="sm" @click="pictureEl?.click()">
                                <ImagePlus aria-hidden="true" />
                                {{ shownPicture ? 'Change picture' : 'Choose picture' }}
                            </Button>
                            <Button v-if="shownPicture" type="button" variant="ghost" size="sm" @click="dropPicture">
                                <Trash2 aria-hidden="true" />
                                Remove picture
                            </Button>
                        </div>
                        <p v-if="errors.avatar" class="text-xs text-destructive">{{ errors.avatar }}</p>
                    </div>

                    <!-- Current members (edit) -->
                    <section v-if="editing" class="flex min-w-0 flex-col gap-1.5" :aria-labelledby="`${uid}-members`">
                        <h3 :id="`${uid}-members`" class="text-sm font-medium">
                            Members <span class="font-normal text-muted-foreground">({{ members.length }})</span>
                        </h3>
                        <ul class="flex min-w-0 flex-col gap-0.5">
                            <li
                                v-for="member in members"
                                :key="member.id"
                                class="flex min-w-0 flex-wrap items-center gap-2 rounded-md px-1 py-1"
                            >
                                <ConversationAvatar :label="member.name" class="size-7" />
                                <span class="min-w-0 flex-1 truncate text-sm">{{ member.name }}</span>
                                <template v-if="confirmingRemoval === member.id">
                                    <span class="text-xs text-muted-foreground">Remove?</span>
                                    <Button type="button" variant="outline" size="sm" @click="confirmingRemoval = null">Keep</Button>
                                    <Button type="button" variant="destructive" size="sm" :disabled="busy" @click="removeMember(member)">
                                        Remove
                                    </Button>
                                </template>
                                <Button
                                    v-else
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Remove ${member.name} from the group`"
                                    @click="confirmingRemoval = member.id"
                                >
                                    <UserMinus aria-hidden="true" />
                                    <span class="hidden sm:inline">Remove</span>
                                </Button>
                            </li>
                        </ul>
                    </section>

                    <!-- People to add -->
                    <section class="flex min-w-0 flex-col gap-1.5" :aria-labelledby="`${uid}-people`">
                        <div class="flex min-w-0 items-baseline justify-between gap-2">
                            <h3 :id="`${uid}-people`" class="text-sm font-medium">
                                {{ editing ? 'Add people' : 'People' }}
                            </h3>
                            <span class="shrink-0 text-xs text-muted-foreground" aria-live="polite">
                                {{ selected.length }} selected
                            </span>
                        </div>

                        <Label :for="filterId" class="sr-only">Find somebody</Label>
                        <div class="relative min-w-0">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Input
                                :id="filterId"
                                v-model="filter"
                                type="text"
                                autocomplete="off"
                                placeholder="Type a name"
                                class="pl-9"
                            />
                        </div>

                        <ul v-if="visible.length > 0" class="flex max-h-60 min-w-0 flex-col gap-0.5 overflow-y-auto">
                            <li v-for="person in visible" :key="person.id" class="min-w-0">
                                <div class="flex min-w-0 items-center gap-2 rounded-md px-1 py-1 hover:bg-accent">
                                    <Checkbox
                                        :id="`${uid}-person-${person.id}`"
                                        :model-value="isSelected(person.id)"
                                        @update:model-value="(checked) => toggle(person.id, checked === true)"
                                    />
                                    <Label
                                        :for="`${uid}-person-${person.id}`"
                                        class="min-w-0 flex-1 truncate font-normal"
                                    >
                                        {{ person.name ?? 'Somebody' }}
                                    </Label>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="px-1 py-2 text-sm text-muted-foreground">
                            {{ candidates.length === 0 ? 'Everybody is already in this group.' : 'No names match.' }}
                        </p>

                        <p v-if="errors.people" class="text-xs text-destructive">{{ errors.people }}</p>

                        <Button
                            v-if="editing"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="self-start"
                            :disabled="busy || selected.length === 0"
                            @click="addPeople"
                        >
                            {{ selected.length === 1 ? 'Add 1 person' : `Add ${selected.length} people` }}
                        </Button>
                    </section>
                </div>

                <DialogFooter class="border-t p-4">
                    <Button type="button" variant="outline" @click="open = false">
                        {{ editing ? 'Close' : 'Cancel' }}
                    </Button>
                    <Button type="submit" :disabled="busy">
                        {{ editing ? 'Save' : 'Create group' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
