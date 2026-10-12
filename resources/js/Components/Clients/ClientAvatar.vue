<script setup lang="ts">
/** Polish 049: a client's initials in a colour that stays the same for that client. */
import { computed } from 'vue';
import { initials } from '@/Components/Clients/clientTasks';
import { personTone } from '@/Components/Messages/people';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{ id: number; name: string; size?: 'sm' | 'lg' }>(), { size: 'sm' });

const tone = computed(() => personTone(props.id).avatar);
</script>

<template>
    <span
        :class="
            cn(
                'flex shrink-0 items-center justify-center rounded-full font-semibold',
                size === 'lg' ? 'size-14 text-lg' : 'size-9 text-xs',
                tone,
            )
        "
        aria-hidden="true"
    >
        {{ initials(name) }}
    </span>
</template>
