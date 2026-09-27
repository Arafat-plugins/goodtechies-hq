<script setup lang="ts">
import { computed } from 'vue';

/**
 * One value out of an audit row's `old_value` / `new_value`, rendered so that the three ways of
 * having nothing cannot be mistaken for each other.
 *
 * That is the whole reason this is a component and not `{{ String(value) }}`:
 *
 *   - **Not recorded** — the key was not on that side of the pair at all. A created record's
 *     before, a deleted record's after, or a field a later build started writing.
 *   - **Empty** — the key was there and its value was JSON `null`. An allowance that was
 *     deliberately cleared is not the same fact as an allowance nobody ever wrote.
 *   - **Blank** — the key was there and its value was the empty string. A note that was emptied.
 *
 * Each is a WORD, not a dash and not a colour, because a reader working out what changed is
 * exactly the reader who must not have to guess (DESIGN.md §5.6).
 *
 * A nested object or list is printed whole as JSON rather than flattened: the payloads in this
 * table are flat by construction, so nesting means something unusual, and hiding part of it
 * behind a summary is the one thing an audit viewer may not do.
 */
const props = withDefaults(
    defineProps<{
        value: unknown;
        /** False when the key was absent from this side of the pair. */
        present?: boolean;
    }>(),
    { present: true },
);

type Shape = 'absent' | 'null' | 'blank' | 'boolean' | 'number' | 'string' | 'json';

const shape = computed<Shape>(() => {
    if (!props.present) {
        return 'absent';
    }

    if (props.value === null || props.value === undefined) {
        return 'null';
    }

    if (typeof props.value === 'boolean') {
        return 'boolean';
    }

    if (typeof props.value === 'number') {
        return 'number';
    }

    if (typeof props.value === 'string') {
        return props.value === '' ? 'blank' : 'string';
    }

    return 'json';
});

/** Booleans are words too: a `true` in a cell is not an answer to "was it granted". */
const yesNo = computed(() => (props.value === true ? 'Yes' : 'No'));

const json = computed(() => {
    try {
        return JSON.stringify(props.value, null, 2) ?? String(props.value);
    } catch {
        return String(props.value);
    }
});
</script>

<template>
    <span v-if="shape === 'absent'" class="text-sm text-muted-foreground">Not recorded</span>
    <span v-else-if="shape === 'null'" class="text-sm text-muted-foreground italic">Empty</span>
    <span v-else-if="shape === 'blank'" class="text-sm text-muted-foreground italic">Blank</span>
    <span v-else-if="shape === 'boolean'" class="text-sm font-medium">{{ yesNo }}</span>
    <span v-else-if="shape === 'number'" class="text-sm font-medium tabular-nums">{{ value }}</span>
    <span v-else-if="shape === 'string'" class="text-sm break-words">{{ value }}</span>
    <pre
        v-else
        class="max-w-full overflow-x-auto rounded-md bg-muted p-2 font-mono text-xs break-words whitespace-pre-wrap text-foreground"
        >{{ json }}</pre
    >
</template>
