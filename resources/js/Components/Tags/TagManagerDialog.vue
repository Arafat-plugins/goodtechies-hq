<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, ShieldAlert, Tags, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, nextTick, ref, useId, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TagColourSelect from '@/Components/Tags/TagColourSelect.vue';
import type { ManagedTag, TagColourOption, TagProjectRef } from '@/Components/Tags/tagManager';
import { groupTags, loadTags, tagDeletePrompt, tagRoutes, tagUsage } from '@/Components/Tags/tagManager';
import { focusField } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Skeleton } from '@/Components/ui/skeleton';

/**
 * Tag management: create a label, rename it, recolour it, remove it.
 *
 * It is a dialog rather than a screen because of when somebody wants it. A tag is created
 * mid-flow — halfway through filtering a board, a word is missing — and a page would take the
 * filters, the scroll position and the train of thought with it. It opens from the Tasks
 * filter bar, so it is on the List, the Board and the Calendar by being in one place.
 *
 * ## Read is JSON, write is Inertia
 *
 * `GET …/tags` answers `{ tags, colours }` as JSON — `ManagesTags` says why: the management
 * panel was a later brief, and a page invented to be thrown away is worse than an endpoint.
 * So the list is fetched when the dialog opens and re-fetched after every write.
 *
 * The writes are ordinary Inertia visits that come back `back()->with('success', …)`, and the
 * sentence they carry is the server's — "Tag deleted: Blocked. It came off 12 tasks." Every
 * Tasks screen already claims the flash channel with `useFlashAsToast()`, so those land in the
 * toaster over the dialog without this component announcing anything itself. A `toast()` here
 * would be the same message said twice (DESIGN.md §5.19).
 *
 * `preserveState` is what keeps this dialog mounted across that redirect; without it the page
 * component remounts and the panel someone was working in closes under them.
 *
 * ## What decides which controls exist
 *
 * `permissions.can_update` / `can_delete`, per row, resolved by `TagPolicy` on the server. Not
 * a role, not an id comparison, and not "is it global". The endpoint asks the same policy
 * again — this only avoids offering a button whose answer is already known to be no.
 */

const props = defineProps<{
    open: boolean;
    /**
     * `/admin/tags` or `/employee/tags`. The two surfaces carry an identical four routes,
     * because a Manager manages tags from the Employee shell, so nothing below varies by
     * surface except this prefix.
     */
    base: string;
    /**
     * The scopes a new tag may be created in. These are the projects the requester can see —
     * the same set `StoreTagRequest` accepts, which is why a scope picked here is never
     * refused as "that project does not exist". Without them the form offers Global alone.
     */
    projects?: TagProjectRef[];
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

/**
 * The "no project" option's value.
 *
 * A sentinel rather than `''`, because reka's `Select` reserves the empty string for "nothing
 * is selected" and refuses an item that uses it. It becomes `project_id: null` on the way out,
 * which is what makes the tag global.
 */
const GLOBAL = 'global';

const uid = useId();
const ids = {
    newName: `${uid}-new-name`,
    newColour: `${uid}-new-colour`,
    newScope: `${uid}-new-scope`,
    editName: `${uid}-edit-name`,
    editColour: `${uid}-edit-colour`,
    confirm: (tag: number) => `${uid}-confirm-${tag}`,
    editor: (tag: number) => `${uid}-editor-${tag}`,
};

const routes = computed(() => tagRoutes(props.base));

/* ------------------------------------------------------------------- the list */

const tags = ref<ManagedTag[]>([]);
const colours = ref<TagColourOption[]>([]);
const loading = ref(false);
const failure = ref<'forbidden' | 'failed' | null>(null);
/** Bumped per load, so a slow answer for a dialog that has since closed cannot land in it. */
const token = ref(0);

const groups = computed(() => groupTags(tags.value));

/**
 * What the create form starts on.
 *
 * The first tone the server sent, not a token name written here: `colour` is `required`, the
 * payload carries no default, and choosing one in Vue would be the third place the list of
 * tones is written down.
 */
const defaultColour = computed(() => colours.value[0]?.value ?? '');

/**
 * The new tag. `project_id` holds the sentinel rather than an id until it is submitted, so a
 * refusal from the server lands on `errors.project_id` and under the field that caused it.
 */
const createForm = useForm<{ name: string; colour: string; project_id: string }>({
    name: '',
    colour: '',
    project_id: GLOBAL,
});

const newNameField = ref<{ $el?: unknown } | null>(null);

async function load(): Promise<void> {
    const mine = ++token.value;
    // A re-read after a write keeps the rows on screen: swapping them for a skeleton makes a
    // rename look like the list reloading from nothing.
    loading.value = tags.value.length === 0;

    const result = await loadTags(props.base);

    if (mine !== token.value) {
        return;
    }

    if (result.ok) {
        tags.value = result.payload.tags;
        colours.value = result.payload.colours;
        failure.value = null;

        if (createForm.colour === '') {
            createForm.colour = defaultColour.value;
        }
    } else {
        failure.value = result.reason;
        tags.value = [];
        colours.value = [];
    }

    loading.value = false;
}

/* ------------------------------------------------------------------ creating */

function create(): void {
    if (createForm.processing) {
        return;
    }

    createForm
        .transform((data) => ({
            name: data.name.trim(),
            colour: data.colour,
            project_id: data.project_id === GLOBAL ? null : Number(data.project_id),
        }))
        .post(routes.value.store, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: async () => {
                // The colour and the scope stay: giving one project three labels is one trip,
                // and re-choosing the project each time is the thing that makes it three.
                createForm.reset('name');
                await load();
                void nextTick(() => focusField(newNameField.value));
            },
        });
}

/* ------------------------------------------------- renaming and recolouring */

const editingId = ref<number | null>(null);
const editForm = useForm<{ name: string; colour: string }>({ name: '', colour: '' });

/**
 * The open editor's name field.
 *
 * A function ref, not a string one: the field lives inside the `v-for` over the rows, and Vue
 * collects a named ref under a `v-for` into an array — which is a list of one that has to be
 * unwrapped everywhere it is read. Only one row is ever in edit mode, so one ref is the shape.
 */
const editNameField = ref<{ $el?: unknown } | null>(null);

function keepEditName(instance: unknown): void {
    editNameField.value = instance === null || instance === undefined ? null : (instance as { $el?: unknown });
}

/**
 * The row buttons, by tag id, so focus can go back to the one that opened a form.
 *
 * By id and not by element, because a write re-reads the list and re-renders the row: the
 * button that was clicked is gone by the time focus has to return, and its replacement is
 * whatever the new render put under the same key.
 */
const editButtons = new Map<number, { $el?: unknown }>();

function keepEditButton(id: number, instance: unknown): void {
    if (instance === null || instance === undefined) {
        editButtons.delete(id);

        return;
    }

    editButtons.set(id, instance as { $el?: unknown });
}

function focusRow(id: number): void {
    void nextTick(() => focusField(editButtons.get(id)));
}

function startEdit(tag: ManagedTag): void {
    if (editingId.value === tag.id) {
        cancelEdit();

        return;
    }

    confirmingId.value = null;
    editForm.clearErrors();
    editForm.name = tag.name;
    editForm.colour = tag.colour;
    editingId.value = tag.id;

    void nextTick(() => focusField(editNameField.value));
}

function cancelEdit(): void {
    const id = editingId.value;

    editingId.value = null;

    if (id !== null) {
        focusRow(id);
    }
}

function saveEdit(id: number): void {
    if (editForm.processing) {
        return;
    }

    // Both fields every time. `UpdateTagRequest` takes each of them `sometimes`, so sending
    // the pair is a rename and a recolour in one write rather than two round trips.
    editForm
        .transform((data) => ({ name: data.name.trim(), colour: data.colour }))
        .put(routes.value.update(id), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: async () => {
                editingId.value = null;
                await load();
                focusRow(id);
            },
        });
}

/* ------------------------------------------------------------------ deleting */

/**
 * Which row is asking to be deleted.
 *
 * Inline, in the row, and never a second `Dialog`: a modal opened from inside a modal moves
 * the focus trap somewhere the first one cannot get it back from, and `window.confirm` is a
 * browser chrome string nobody can put a task count into. The row's own button toggles the
 * strip and keeps focus, which is why it carries `aria-expanded` and `aria-controls` — the
 * question appears under the button that asked it.
 */
const confirmingId = ref<number | null>(null);
const deletingId = ref<number | null>(null);

function askDelete(tag: ManagedTag): void {
    if (confirmingId.value === tag.id) {
        confirmingId.value = null;

        return;
    }

    editingId.value = null;
    confirmingId.value = tag.id;
}

function confirmDelete(id: number): void {
    if (deletingId.value !== null) {
        return;
    }

    deletingId.value = id;

    router.delete(routes.value.destroy(id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: async () => {
            confirmingId.value = null;
            await load();
            // The button that held focus went with the row. Focus goes to the top of the
            // panel rather than to <body>, where the dialog's trap has nothing to hold.
            void nextTick(() => focusField(newNameField.value));
        },
        onFinish: () => {
            deletingId.value = null;
        },
    });
}

/* -------------------------------------------------------------------- opening */

watch(
    () => props.open,
    (open) => {
        editingId.value = null;
        confirmingId.value = null;

        if (!open) {
            return;
        }

        createForm.reset();
        createForm.clearErrors();
        createForm.colour = defaultColour.value;
        void load();
    },
    { immediate: true },
);

/**
 * Reka focuses the dialog's first focusable node. That is already the name field, but saying
 * so keeps it true when something is added above it.
 */
function focusNewName(event: Event): void {
    event.preventDefault();
    void nextTick(() => focusField(newNameField.value));
}

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="flex max-h-[90svh] w-[calc(100vw-2rem)] max-w-lg flex-col gap-0 p-0 sm:w-full"
            @open-auto-focus="focusNewName"
        >
            <DialogHeader class="gap-1 border-b p-4 pr-12 text-left sm:p-6 sm:pr-12">
                <DialogTitle class="text-xl">Tags</DialogTitle>
                <DialogDescription>
                    Labels for tasks. A global tag is usable on every project; a project tag belongs
                    to one, and only that project's tasks can wear it.
                </DialogDescription>
            </DialogHeader>

            <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 sm:p-6">
                <!-- Creating. Present whenever the list loaded: seeing it at all is
                     TagPolicy::viewAny, which is the same answer as being allowed to create. -->
                <form
                    v-if="failure === null"
                    class="flex min-w-0 flex-col gap-3 rounded-md border p-3"
                    novalidate
                    @submit.prevent="create"
                >
                    <p class="text-sm font-medium">New tag</p>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label :for="ids.newName">
                            Name <span class="text-destructive" aria-hidden="true">*</span>
                        </Label>
                        <Input
                            :id="ids.newName"
                            ref="newNameField"
                            v-model="createForm.name"
                            placeholder="Design, Blocked, SEO…"
                            :disabled="createForm.processing"
                            :aria-invalid="createForm.errors.name ? true : undefined"
                        />
                        <p v-if="createForm.errors.name" class="text-xs text-destructive">
                            {{ createForm.errors.name }}
                        </p>
                    </div>

                    <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                        <div class="flex min-w-0 flex-col gap-2">
                            <Label :for="ids.newColour">
                                Colour <span class="text-destructive" aria-hidden="true">*</span>
                            </Label>
                            <TagColourSelect
                                :id="ids.newColour"
                                v-model="createForm.colour"
                                :options="colours"
                                :disabled="createForm.processing"
                                :invalid="Boolean(createForm.errors.colour)"
                            />
                            <p v-if="createForm.errors.colour" class="text-xs text-destructive">
                                {{ createForm.errors.colour }}
                            </p>
                        </div>

                        <div v-if="projects?.length" class="flex min-w-0 flex-col gap-2">
                            <Label :for="ids.newScope">Scope</Label>
                            <Select v-model="createForm.project_id" :disabled="createForm.processing">
                                <SelectTrigger
                                    :id="ids.newScope"
                                    class="w-full"
                                    :aria-invalid="createForm.errors.project_id ? true : undefined"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="GLOBAL">Global — every project</SelectItem>
                                    <SelectItem
                                        v-for="project in projects"
                                        :key="project.id"
                                        :value="String(project.id)"
                                    >
                                        {{ project.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="createForm.errors.project_id" class="text-xs text-destructive">
                                {{ createForm.errors.project_id }}
                            </p>
                        </div>
                    </div>

                    <Button
                        type="submit"
                        size="sm"
                        class="self-start"
                        :disabled="createForm.processing || colours.length === 0"
                    >
                        <Plus aria-hidden="true" />
                        {{ createForm.processing ? 'Adding…' : 'Add tag' }}
                    </Button>
                </form>

                <!-- The list. -->
                <div v-if="loading" class="flex flex-col gap-2">
                    <Skeleton v-for="row in 3" :key="row" class="h-16 w-full rounded-md" />
                </div>

                <EmptyState
                    v-else-if="failure === 'forbidden'"
                    :icon="ShieldAlert"
                    variant="error"
                    title="Tags are managed by an admin or a manager"
                    description="Ask one of them for the label you need; you can still put an existing tag on a task."
                />

                <EmptyState
                    v-else-if="failure === 'failed'"
                    :icon="TriangleAlert"
                    variant="error"
                    title="The tag list could not be loaded"
                    description="Nothing was changed. Try again in a moment."
                >
                    <template #action>
                        <Button variant="outline" size="sm" @click="load">Try again</Button>
                    </template>
                </EmptyState>

                <EmptyState
                    v-else-if="tags.length === 0"
                    :icon="Tags"
                    title="No tags yet"
                    description="Add one above and it is on the filter bar and every task picker straight away."
                />

                <div v-else class="flex min-w-0 flex-col gap-6">
                    <section v-for="group in groups" :key="group.key" class="flex min-w-0 flex-col gap-2">
                        <div class="flex min-w-0 flex-wrap items-baseline gap-x-2">
                            <h3 class="text-sm font-medium">{{ group.label }}</h3>
                            <p class="text-xs text-muted-foreground">{{ group.description }}</p>
                        </div>

                        <ul class="flex min-w-0 flex-col gap-2">
                            <li
                                v-for="tag in group.tags"
                                :key="tag.id"
                                class="flex min-w-0 flex-col gap-3 rounded-md border p-3"
                            >
                                <div class="flex min-w-0 items-start justify-between gap-3">
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <StatusBadge
                                            class="max-w-full self-start overflow-hidden"
                                            :status="tag.colour"
                                            :label="tag.name"
                                        />
                                        <p class="text-xs text-muted-foreground">
                                            {{ tag.colour_label }} · {{ tagUsage(tag) }}
                                        </p>
                                    </div>

                                    <div
                                        v-if="tag.permissions.can_update || tag.permissions.can_delete"
                                        class="flex shrink-0 items-center gap-1"
                                    >
                                        <Button
                                            v-if="tag.permissions.can_update"
                                            :ref="(instance) => keepEditButton(tag.id, instance)"
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            :aria-label="`Rename or recolour ${tag.name}`"
                                            :aria-expanded="editingId === tag.id"
                                            :aria-controls="ids.editor(tag.id)"
                                            @click="startEdit(tag)"
                                        >
                                            <Pencil aria-hidden="true" />
                                        </Button>
                                        <Button
                                            v-if="tag.permissions.can_delete"
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            :aria-label="`Delete ${tag.name}`"
                                            :aria-expanded="confirmingId === tag.id"
                                            :aria-controls="ids.confirm(tag.id)"
                                            @click="askDelete(tag)"
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    </div>
                                </div>

                                <!-- Rename and recolour, in the row. -->
                                <form
                                    v-if="editingId === tag.id"
                                    :id="ids.editor(tag.id)"
                                    class="flex min-w-0 flex-col gap-3 border-t pt-3"
                                    novalidate
                                    @submit.prevent="saveEdit(tag.id)"
                                >
                                    <div class="flex min-w-0 flex-col gap-2">
                                        <Label :for="ids.editName">Name</Label>
                                        <Input
                                            :id="ids.editName"
                                            :ref="keepEditName"
                                            v-model="editForm.name"
                                            :disabled="editForm.processing"
                                            :aria-invalid="editForm.errors.name ? true : undefined"
                                        />
                                        <p v-if="editForm.errors.name" class="text-xs text-destructive">
                                            {{ editForm.errors.name }}
                                        </p>
                                    </div>

                                    <div class="flex min-w-0 flex-col gap-2">
                                        <Label :for="ids.editColour">Colour</Label>
                                        <TagColourSelect
                                            :id="ids.editColour"
                                            v-model="editForm.colour"
                                            :options="colours"
                                            :disabled="editForm.processing"
                                            :invalid="Boolean(editForm.errors.colour)"
                                        />
                                        <p v-if="editForm.errors.colour" class="text-xs text-destructive">
                                            {{ editForm.errors.colour }}
                                        </p>
                                    </div>

                                    <!--
                                        No scope control. `UpdateTagRequest` refuses `project_id`
                                        outright: moving a tag between scopes would strip it off
                                        every task that can no longer use it, so it is a delete and
                                        a create, and there is no endpoint for it to point at.
                                    -->
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Button type="submit" size="sm" :disabled="editForm.processing">
                                            {{ editForm.processing ? 'Saving…' : 'Save' }}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            :disabled="editForm.processing"
                                            @click="cancelEdit"
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>

                                <!-- Deleting. The count is the question, not a footnote. -->
                                <div
                                    v-if="confirmingId === tag.id"
                                    :id="ids.confirm(tag.id)"
                                    class="flex min-w-0 flex-col gap-2 rounded-md bg-destructive/10 p-3"
                                >
                                    <p class="text-sm font-medium">{{ tagDeletePrompt(tag) }}</p>
                                    <p v-if="tag.task_count > 0" class="text-xs text-muted-foreground">
                                        The label comes off those tasks and each of them keeps a line
                                        saying so. This cannot be undone.
                                    </p>
                                    <p v-else class="text-xs text-muted-foreground">
                                        This cannot be undone.
                                    </p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            size="sm"
                                            :disabled="deletingId !== null"
                                            @click="confirmDelete(tag.id)"
                                        >
                                            <Trash2 aria-hidden="true" />
                                            {{ deletingId === tag.id ? 'Deleting…' : 'Delete' }}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            :disabled="deletingId !== null"
                                            @click="confirmingId = null"
                                        >
                                            Keep it
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

            <DialogFooter class="border-t p-4 sm:p-6">
                <Button type="button" variant="outline" class="w-full sm:w-auto" @click="close">
                    Done
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
