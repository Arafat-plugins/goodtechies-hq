<script setup lang="ts">
import { Bell, BellOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { Button } from '@/Components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';

/**
 * The bell. There is no notifications backend until Phase 2, so this component ships its
 * shape and nothing else: the count is a local `0` and the panel is an `EmptyState`. No
 * request is made. Phase 2 replaces the two lines below with the shared prop that carries
 * the unread count and a list; nothing else in the top bar changes.
 */
const unreadCount = ref(0);

/** Anything past 9 would stretch the badge past the icon, so it caps. */
const badgeLabel = computed(() => (unreadCount.value > 9 ? '9+' : String(unreadCount.value)));
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button variant="ghost" size="icon" class="relative size-9">
                <Bell class="size-5" aria-hidden="true" />
                <span
                    v-if="unreadCount > 0"
                    class="absolute -top-0.5 -right-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-xs font-medium tabular-nums text-primary-foreground"
                >
                    {{ badgeLabel }}
                </span>
                <span class="sr-only">
                    Notifications{{ unreadCount > 0 ? `, ${unreadCount} unread` : '' }}
                </span>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-80 p-0">
            <div class="flex h-11 items-center border-b px-4">
                <p class="text-sm font-medium">Notifications</p>
            </div>
            <EmptyState
                :icon="BellOff"
                title="Nothing yet"
                description="Notifications arrive in Phase 2"
            />
        </PopoverContent>
    </Popover>
</template>
