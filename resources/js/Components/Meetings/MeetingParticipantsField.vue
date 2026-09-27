<script setup lang="ts">
import { Lock } from '@lucide/vue';
import { computed } from 'vue';
import type { MeetingInvitee } from '@/Components/Meetings/meetings';
import { Checkbox } from '@/Components/ui/checkbox';
import { Label } from '@/Components/ui/label';

/**
 * Who is in the room.
 *
 * ## The organiser is there and cannot be taken out
 *
 * `MeetingService::schedule()` seats them whatever the form said — calling a meeting is already
 * saying you will be at it — so the form draws them as a **locked row** rather than leaving them
 * out. Leaving them out would have been a form that quietly disagreed with the record it
 * creates; a disabled checkbox with the reason beside it says the same thing honestly, and the
 * `Lock` glyph has the word *Organiser* next to it rather than standing in for it.
 *
 * ## The list is the server's
 *
 * `people` arrives as a page prop, the way `Pages/Shared/Messages.vue` gets its own — active
 * users holding `meetings.use`, by id and name. Nothing here invents an endpoint and nothing
 * here is hard-coded. The Accountant is absent because they hold none of the key, and if one
 * were posted anyway `MeetingService::syncParticipants()` drops them again on the way in.
 *
 * A checkbox list rather than a combobox: this is an agency of five people, and a list you can
 * read in one glance beats a control you have to search. It grows a scroll box, not a search
 * box, when there are more of them.
 */

const props = defineProps<{
    people: MeetingInvitee[];
    organizer: MeetingInvitee;
    modelValue: number[];
    disabled?: boolean;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [number[]] }>();

const selected = computed(() => new Set(props.modelValue));

function toggle(id: number, checked: boolean): void {
    const next = new Set(props.modelValue);

    if (checked) {
        next.add(id);
    } else {
        next.delete(id);
    }

    emit('update:modelValue', [...next]);
}

const summary = computed(() => {
    const total = props.modelValue.length + 1;

    return total === 1 ? 'Just you' : `${total} people`;
});
</script>

<template>
    <fieldset class="flex min-w-0 flex-col gap-3">
        <legend class="text-sm font-medium">Participants</legend>
        <p class="text-xs text-muted-foreground">
            {{ summary }} in the room. Everyone you tick is notified, and can answer the invitation.
        </p>

        <ul class="flex min-w-0 max-h-72 flex-col gap-2 overflow-y-auto">
            <li class="flex min-w-0 items-center gap-2">
                <Checkbox id="meeting-participant-organizer" :model-value="true" disabled />
                <Label for="meeting-participant-organizer" class="flex min-w-0 items-center gap-1.5 font-normal">
                    <span class="min-w-0 truncate">{{ organizer.name }}</span>
                    <Lock class="size-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="shrink-0 text-xs text-muted-foreground">Organiser — always in</span>
                </Label>
            </li>

            <li v-for="person in people" :key="person.id" class="flex min-w-0 items-center gap-2">
                <Checkbox
                    :id="`meeting-participant-${person.id}`"
                    :model-value="selected.has(person.id)"
                    :disabled="disabled"
                    @update:model-value="(checked) => toggle(person.id, checked === true)"
                />
                <Label :for="`meeting-participant-${person.id}`" class="min-w-0 truncate font-normal">
                    {{ person.name }}
                </Label>
            </li>
        </ul>

        <p v-if="people.length === 0" class="text-xs text-muted-foreground">
            There is nobody else to invite yet.
        </p>

        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
    </fieldset>
</template>
