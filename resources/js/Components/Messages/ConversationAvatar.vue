<script setup lang="ts">
import { computed } from 'vue';
import PresenceDot from '@/Components/Messages/PresenceDot.vue';
import { initialsOf } from '@/Components/Messages/messages';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { cn } from '@/lib/utils';

/**
 * Brief 010: a conversation's face — a group's picture (`avatar_url`), or the initials of its
 * label on `bg-muted` — with the online dot at its corner for a DM. Used in the rail and in the
 * thread header.
 */

const props = withDefaults(
    defineProps<{
        label: string;
        avatarUrl?: string | null;
        /** Only a DM passes this; a group or channel has no single presence. */
        online?: boolean | null;
        class?: string;
    }>(),
    { avatarUrl: null, online: null, class: undefined },
);

const initials = computed(() => initialsOf(props.label));
</script>

<template>
    <span :class="cn('relative inline-flex size-8 shrink-0', props.class)">
        <Avatar class="size-full">
            <AvatarImage v-if="avatarUrl" :src="avatarUrl" alt="" class="object-cover" />
            <AvatarFallback class="bg-muted text-xs font-medium text-muted-foreground">
                {{ initials }}
            </AvatarFallback>
        </Avatar>
        <PresenceDot
            v-if="online !== null"
            :online="online"
            class="absolute -right-0.5 -bottom-0.5"
        />
    </span>
</template>
