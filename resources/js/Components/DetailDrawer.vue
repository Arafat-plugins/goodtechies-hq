<script setup lang="ts">
import { computed, watch } from 'vue';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { queryParam, syncQuery } from '@/lib/tableState';
import { cn } from '@/lib/utils';

/**
 * The right-hand slide-over a list screen opens on a row.
 *
 * It is deep-linkable: while it is open the URL carries `?detail=<deepLinkId>`, so the
 * view somebody is looking at is the view they can send. The screen does the other half —
 * on load it reads that parameter (`queryParam('detail')`) and opens the matching row.
 *
 * That URL is written with `syncQuery()`, not `pushQuery()`: which record an overlay is
 * showing is browser state, not something the server answers. Slice 3 was this component's
 * first caller and found out why it matters — a `pushQuery()` here fires an Inertia visit,
 * the list underneath swaps its rows for a loading skeleton while the visit is in flight,
 * and the row element that opened the drawer is destroyed. Reka then has nothing to give
 * focus back to on Esc and it lands on `<body>`.
 *
 * Esc, the focus trap and returning focus to the trigger all come from reka's dialog
 * under `ui/sheet`; nothing here re-implements them.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        subtitle?: string;
        /** How wide the panel gets from `sm` up. Below that it is a near-full-width sheet. */
        width?: 'sm' | 'md' | 'lg' | 'xl';
        /** The id written to `?detail=`. Omitted means the drawer does not touch the URL. */
        deepLinkId?: string | number | null;
    }>(),
    { width: 'md' },
);

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const WIDTH_CLASS: Record<'sm' | 'md' | 'lg' | 'xl', string> = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
};

const widthClass = computed(() => WIDTH_CLASS[props.width]);

/** Opening writes the id into the URL; closing takes it back out. */
watch(
    () => [props.open, props.deepLinkId] as const,
    ([open, id]) => {
        if (props.deepLinkId === undefined) {
            return;
        }

        if (open && id !== null && id !== undefined) {
            if (queryParam('detail') !== String(id)) {
                syncQuery({ detail: id });
            }

            return;
        }

        if (!open && queryParam('detail') !== null) {
            syncQuery({ detail: null });
        }
    },
    { immediate: true },
);
</script>

<template>
    <Sheet :open="open" @update:open="(value) => emit('update:open', value)">
        <SheetContent
            side="right"
            :class="cn('w-full gap-0 p-0', widthClass)"
            :aria-describedby="subtitle ? undefined : ''"
        >
            <SheetHeader class="flex-row items-start justify-between gap-3 border-b p-4 pr-12">
                <div class="flex min-w-0 flex-col gap-1">
                    <SheetTitle class="text-base break-words">{{ title }}</SheetTitle>
                    <SheetDescription v-if="subtitle" class="break-words">{{ subtitle }}</SheetDescription>
                </div>
                <div v-if="$slots['header-actions']" class="flex shrink-0 items-center gap-2">
                    <slot name="header-actions" />
                </div>
            </SheetHeader>

            <div class="min-w-0 flex-1 overflow-y-auto p-4">
                <slot />
            </div>

            <SheetFooter v-if="$slots.footer" class="flex-row justify-end gap-2 border-t p-4">
                <slot name="footer" />
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
